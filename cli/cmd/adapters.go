package cmd

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"

	"github.com/spf13/cobra"

	"wpicker/internal/config"
)

// Adapters are the things `wpicker connect` can connect: the built-in agents in
// connect.go, and any the user describes in ~/.wpicker/adapters/*.json.
//
// User adapters are data, not code: a JSON file says where an instruction file
// goes and what shape it has. They are read ONLY from the user's own directory,
// never from a project, so a cloned repository cannot register one and choose
// where WPicker writes.

const adaptersSubdir = "adapters"
const maxAdapterFile = 16 << 10

var adapterKeyRe = regexp.MustCompile(`^[a-z][a-z0-9-]{1,30}$`)

type adapterSpec struct {
	Key         string   `json:"key"`
	Name        string   `json:"name"`
	Project     string   `json:"project"`
	Global      string   `json:"global"`
	Mode        string   `json:"mode"`
	Content     string   `json:"content"`
	Frontmatter string   `json:"frontmatter"`
	Detect      []string `json:"detect"`
}

// safeRel accepts only a relative path that cannot climb out of the folder it is joined to.
func safeRel(p string) error {
	switch {
	case p == "":
		return errors.New("empty path")
	case len(p) > 200 || strings.ContainsRune(p, 0):
		return errors.New("path too long or contains a NUL")
	case filepath.IsAbs(p) || strings.HasPrefix(p, "~") || strings.HasPrefix(p, "/") || strings.HasPrefix(p, `\`):
		return errors.New("path must be relative (to the project, or to your home directory)")
	}
	for _, part := range strings.FieldsFunc(p, func(r rune) bool { return r == '/' || r == '\\' }) {
		if part == ".." {
			return errors.New("path must not contain \"..\"")
		}
	}
	return nil
}

// buildAdapter validates a spec and turns it into a target.
func buildAdapter(spec adapterSpec, taken map[string]bool) (*agentTarget, error) {
	if !adapterKeyRe.MatchString(spec.Key) {
		return nil, fmt.Errorf("key %q must be 2-31 lowercase letters, digits or dashes, starting with a letter", spec.Key)
	}
	if taken[spec.Key] {
		return nil, fmt.Errorf("key %q is already used by another adapter", spec.Key)
	}
	if strings.TrimSpace(spec.Name) == "" || len(spec.Name) > 60 {
		return nil, errors.New("name is required (at most 60 characters)")
	}
	if spec.Project == "" && spec.Global == "" {
		return nil, errors.New("give a \"project\" path, a \"global\" path, or both")
	}
	for label, p := range map[string]string{"project": spec.Project, "global": spec.Global} {
		if p != "" {
			if err := safeRel(p); err != nil {
				return nil, fmt.Errorf("%s: %v", label, err)
			}
		}
	}
	mode, content, fm := spec.Mode, spec.Content, spec.Frontmatter
	if mode == "" {
		mode = "file"
	}
	if mode != "file" && mode != "block" {
		return nil, fmt.Errorf("mode %q must be \"file\" or \"block\"", mode)
	}
	if content == "" {
		content = map[string]string{"block": "stub", "file": "guide"}[mode]
	}
	if content != "stub" && content != "guide" {
		return nil, fmt.Errorf("content %q must be \"stub\" or \"guide\"", content)
	}
	if fm == "" {
		fm = "none"
	}
	if fm != "none" && fm != "skill" && fm != "cursor" && fm != "windsurf" {
		return nil, fmt.Errorf("frontmatter %q must be none, skill, cursor or windsurf", fm)
	}
	if mode == "block" && fm != "none" {
		return nil, errors.New("frontmatter only makes sense for mode \"file\": a block lives inside someone else's file")
	}
	var detect []string
	for _, d := range spec.Detect {
		rel := strings.TrimPrefix(d, "~/")
		if err := safeRel(rel); err != nil {
			return nil, fmt.Errorf("detect %q: %v", d, err)
		}
		detect = append(detect, d)
	}
	if spec.Project == "" && spec.Global != "" && len(detect) == 0 {
		detect = []string{"~/" + filepath.Dir(spec.Global)}
	}

	t := &agentTarget{
		key: spec.Key, name: strings.TrimSpace(spec.Name),
		project: spec.Project, global: spec.Global,
		shared: mode == "block", detect: detect, custom: true,
	}
	t.render = func(site string, global bool) string {
		body := stubFor(global)
		if content == "guide" {
			body = agentGuide(site) + "\n"
		}
		if t.shared {
			return "## WordPress site (WPicker)\n" + strings.TrimRight(body, "\n") + "\n"
		}
		head := ""
		switch fm {
		case "skill":
			head = "---\nname: wpicker\ndescription: " + skillDescription + "\n---\n\n"
		case "cursor":
			head = "---\ndescription: " + skillDescription + "\nalwaysApply: false\n---\n\n"
		case "windsurf":
			head = "---\ntrigger: model_decision\ndescription: " + skillDescription + "\n---\n\n"
		}
		return head + "# WPicker\n" + managedMarker() + "\n" + body
	}
	return t, nil
}

func adaptersPath() string {
	d, err := config.Dir()
	if err != nil {
		return ""
	}
	return filepath.Join(d, adaptersSubdir)
}

// loadAdapters reads the user's adapter files. Bad ones are skipped and reported;
// one broken file never hides the others or the built-ins.
func loadAdapters() (ok []*agentTarget, problems []string) {
	dir := adaptersPath()
	entries, err := os.ReadDir(dir)
	if err != nil {
		return nil, nil
	}
	taken := map[string]bool{"all": true, "list": true, "remove": true}
	for i := range agentTargets {
		taken[agentTargets[i].key] = true
	}
	sort.Slice(entries, func(i, j int) bool { return entries[i].Name() < entries[j].Name() })
	for _, e := range entries {
		if e.IsDir() || !strings.HasSuffix(strings.ToLower(e.Name()), ".json") {
			continue
		}
		path := filepath.Join(dir, e.Name())
		fail := func(format string, a ...any) {
			problems = append(problems, fmt.Sprintf("%s: %s", e.Name(), fmt.Sprintf(format, a...)))
		}
		fi, err := os.Lstat(path)
		if err != nil || !fi.Mode().IsRegular() {
			fail("not a regular file")
			continue
		}
		if fi.Size() > maxAdapterFile {
			fail("larger than %d bytes", maxAdapterFile)
			continue
		}
		b, err := os.ReadFile(path)
		if err != nil {
			fail("%v", err)
			continue
		}
		dec := json.NewDecoder(bytes.NewReader(b))
		dec.DisallowUnknownFields() // a misspelt field is an error, not a silent default
		var spec adapterSpec
		if err := dec.Decode(&spec); err != nil {
			fail("%v", err)
			continue
		}
		t, err := buildAdapter(spec, taken)
		if err != nil {
			fail("%v", err)
			continue
		}
		taken[t.key] = true
		ok = append(ok, t)
	}
	return ok, problems
}

// allTargets is every adapter: built in first, then the user's.
func allTargets() []*agentTarget {
	out := make([]*agentTarget, 0, len(agentTargets)+4)
	for i := range agentTargets {
		out = append(out, &agentTargets[i])
	}
	custom, _ := loadAdapters()
	return append(out, custom...)
}

// --- state of an adapter, for `list` ---------------------------------------------------

// adapterState describes one adapter's file in one scope.
func adapterState(t *agentTarget, dir, home string, global bool) string {
	path, root, ok := t.pathFor(dir, home, global)
	if !ok {
		return "n/a"
	}
	if _, err := os.Lstat(path); err != nil {
		return "-"
	}
	if _, err := resolveWithin(path, root); err != nil {
		return "blocked (symlink)"
	}
	if !ownedByWPicker(path, t.shared) {
		if t.shared {
			return "-" // the file is yours; no WPicker block in it
		}
		return "yours (not touched)"
	}
	b, _ := os.ReadFile(path)
	if m := versionInMarker.FindSubmatch(b); m == nil || string(m[1]) != Version {
		v := "?"
		if m != nil {
			v = string(m[1])
		}
		return "stale (v" + v + ")"
	}
	return "current"
}

type adapterRow struct {
	Key      string `json:"key"`
	Name     string `json:"name"`
	Custom   bool   `json:"custom"`
	Detected bool   `json:"detected"`
	Global   string `json:"global"`
	Project  string `json:"project"`
}

var connectListCmd = &cobra.Command{
	Use:     "list",
	Aliases: []string{"ls"},
	Short:   "Show every adapter and whether it is connected, current or stale.",
	Args:    cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		dir := connectDir
		if dir == "" {
			dir = mustCwd()
		}
		home, _ := os.UserHomeDir()
		det := map[string]bool{}
		for _, t := range detectAgents(dir, home) {
			det[t.key] = true
		}
		custom, problems := loadAdapters()
		_ = custom
		var rows []adapterRow
		for _, t := range allTargets() {
			rows = append(rows, adapterRow{
				Key: t.key, Name: t.name, Custom: t.custom, Detected: det[t.key],
				Global:  adapterState(t, dir, home, true),
				Project: adapterState(t, dir, home, false),
			})
		}
		if flagJSON {
			emitJSON(map[string]any{"version": 1, "adapters": rows, "problems": problems})
			return nil
		}
		fmt.Printf("%-10s  %-34s  %-8s  %-18s  %s\n", "ADAPTER", "NAME", "FOUND", "GLOBAL", "THIS PROJECT")
		for _, r := range rows {
			found := "no"
			if r.Detected {
				found = "yes"
			}
			name := r.Name
			if r.Custom {
				name += " (custom)"
			}
			fmt.Printf("%-10s  %-34s  %-8s  %-18s  %s\n", r.Key, clip(name, 34), found, r.Global, r.Project)
		}
		fmt.Println("\nconnect one: wpicker connect <adapter>      refresh stale ones: wpicker connect --refresh")
		for _, p := range problems {
			fmt.Printf("\n! ignored adapter file %s\n", p)
		}
		return nil
	},
}

// --- remove --------------------------------------------------------------------------

// removeBlock cuts WPicker's marked block out of a shared file and leaves the rest as it was.
func removeBlock(path string) (string, error) {
	b, err := os.ReadFile(path)
	if err != nil {
		return "", err
	}
	text := string(b)
	begins := beginMarkers(text)
	if len(begins) != 1 {
		return "", fmt.Errorf("%s: expected exactly one WPicker block, found %d; remove it by hand", path, len(begins))
	}
	start := begins[0][0]
	e := endLineRe.FindStringIndex(text[start:])
	if e == nil {
		return "", fmt.Errorf("%s: WPicker block has no end marker; remove it by hand", path)
	}
	end := start + e[1]
	// The block, and the one line break that ends its last line.
	if strings.HasPrefix(text[end:], "\r\n") {
		end += 2
	} else if strings.HasPrefix(text[end:], "\n") {
		end++
	}
	before, after := text[:start], text[end:]
	switch {
	case strings.TrimSpace(after) == "":
		// The block was last: undo the blank line `connect` put before it.
		if strings.TrimSpace(before) != "" {
			before = strings.TrimRight(before, "\r\n") + "\n"
		}
		after = ""
	case strings.HasSuffix(before, "\n\n") && strings.HasPrefix(after, "\n"):
		after = after[1:] // do not leave two blank lines where there was one block
	}
	next := before + after
	if strings.TrimSpace(next) == "" {
		return "removed (the file held only our block)", os.Remove(path)
	}
	return "removed block", writeFile(path, next)
}

func removeTarget(t *agentTarget, path, root string) (string, error) {
	if _, err := os.Lstat(path); err != nil {
		return "", nil
	}
	real, err := resolveWithin(path, root)
	if err != nil {
		return "", err
	}
	if !ownedByWPicker(real, t.shared) {
		return "", nil
	}
	if t.shared {
		return removeBlock(real)
	}
	if err := os.Remove(real); err != nil {
		return "", err
	}
	_ = os.Remove(filepath.Dir(real)) // only succeeds when the folder is now empty (e.g. skills/wpicker)
	return "removed", nil
}

var connectRemoveCmd = &cobra.Command{
	Use:   "remove <adapter...|all>",
	Short: "Take WPicker out of an agent again (global and this project).",
	Long: `Removes what ` + "`wpicker connect`" + ` wrote for the named adapters, in your home directory and
in this project. Dedicated files are deleted only if WPicker wrote them; in a shared
file (AGENTS.md, …) only WPicker's marked block is cut out and everything else stays.`,
	Args: cobra.MinimumNArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		dir := connectDir
		if dir == "" {
			dir = mustCwd()
		}
		home, _ := os.UserHomeDir()
		var targets []*agentTarget
		seen := map[string]bool{}
		for _, raw := range args {
			for _, k := range strings.Split(raw, ",") {
				k = strings.TrimSpace(k)
				if strings.EqualFold(k, "all") {
					for _, t := range allTargets() {
						addTarget(&targets, seen, t)
					}
				} else if t := findTarget(k); t != nil {
					addTarget(&targets, seen, t)
				} else if k != "" {
					return fmt.Errorf("unknown adapter %q; choose from: %s, all", k, agentKeys())
				}
			}
		}
		removed, failed := 0, 0
		paths := map[string]bool{}
		for _, t := range targets {
			for _, global := range []bool{true, false} {
				path, root, ok := t.pathFor(dir, home, global)
				if !ok || paths[path] {
					continue
				}
				paths[path] = true
				st, err := removeTarget(t, path, root)
				switch {
				case err != nil:
					failed++
					fmt.Printf("%-34s FAILED: %v\n", t.name, err)
				case st != "":
					removed++
					fmt.Printf("%-34s %s: %s\n", t.name, st, path)
				}
			}
		}
		if removed == 0 && failed == 0 {
			fmt.Println("Nothing of WPicker's found for those adapters.")
		}
		if failed > 0 {
			return fmt.Errorf("%d could not be removed", failed)
		}
		return nil
	},
}

func init() {
	connectCmd.AddCommand(connectListCmd, connectRemoveCmd)
}
