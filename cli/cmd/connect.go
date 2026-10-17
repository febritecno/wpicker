package cmd

import (
	"bufio"
	"context"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"sort"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"wpicker/internal/config"
)

var (
	connectAgents  []string
	connectGlobal  bool
	connectForce   bool
	connectDir     string
	connectRefresh bool
)

// markerPrefix tags a dedicated file as written by WPicker. blockBegin and
// blockEnd fence WPicker's part of a file the user also writes in.
const (
	markerPrefix = "<!-- wpicker-generated"
	blockBegin   = "<!-- wpicker:begin"
	blockEnd     = "<!-- wpicker:end -->"
)

// The markers only count as whole lines: a README or AGENTS.md that merely
// MENTIONS them (in prose or a code fence) must not be mistaken for ours.
var (
	beginLineRe = regexp.MustCompile(`(?m)^<!-- wpicker:begin v[^ \r\n]* -->[ \t]*\r?$`)
	endLineRe   = regexp.MustCompile(`(?m)^<!-- wpicker:end -->[ \t]*\r?$`)
	ownedLineRe = regexp.MustCompile(`(?m)^<!-- wpicker-generated `)
)

// beginMarkers finds WPicker's begin markers that sit on a line of their own
// and OUTSIDE a fenced code block: documentation that shows the marker in a
// ``` fence is not a WPicker block.
func beginMarkers(text string) [][]int {
	var fenced [][2]int
	open, start := false, 0
	for pos := 0; pos < len(text); {
		nl := strings.IndexByte(text[pos:], '\n')
		end := len(text)
		if nl >= 0 {
			end = pos + nl + 1
		}
		line := strings.TrimLeft(text[pos:end], " \t")
		if strings.HasPrefix(line, "```") || strings.HasPrefix(line, "~~~") {
			if open {
				fenced = append(fenced, [2]int{start, end})
			} else {
				start = pos
			}
			open = !open
		}
		pos = end
	}
	if open {
		fenced = append(fenced, [2]int{start, len(text)})
	}
	var out [][]int
next:
	for _, m := range beginLineRe.FindAllStringIndex(text, -1) {
		for _, f := range fenced {
			if m[0] >= f[0] && m[0] < f[1] {
				continue next
			}
		}
		out = append(out, m)
	}
	return out
}

// hasManagedMarker reports whether a dedicated file was written by WPicker:
// the marker is a line of its own near the top, right after the frontmatter.
func hasManagedMarker(text string) bool {
	if len(text) > 2000 {
		text = text[:2000]
	}
	return ownedLineRe.MatchString(text)
}

// siteHintFor names the site for the top of a PROJECT file: the one this folder
// is linked to, or the only one there is. It ignores --site, $WPICKER_SITE and
// the default on purpose: these files get committed and read by other people.
// With several sites and no link it names none, rather than guess.
func siteHintFor(dir string) string {
	store, err := config.LoadStore()
	if err != nil || len(store.Sites) == 0 {
		return ""
	}
	if _, link, _ := findLink(dir, true); link != nil {
		if key, err := config.URLKey(link.Site); err == nil {
			if s := store.ByKey(key); s != nil {
				return "SITE: " + s.URL + "\n"
			}
		}
		return ""
	}
	if len(store.Sites) == 1 {
		return "SITE: " + store.Sites[0].URL + "\n"
	}
	return ""
}

func managedMarker() string {
	return markerPrefix + " v" + Version + " — `wpicker connect` refreshes this file; remove this line to keep your own edits -->"
}

// The description is a plain YAML scalar in frontmatter, so it must not contain
// ": " or " #" (TestFrontmatterOfEveryRuleFormatIsSafeYAML checks).
const skillDescription = "Diagnose and fix a live WordPress or WooCommerce site with the wpicker CLI. It reads the " +
	"site's errors, payment-gateway logs, plugin and theme code and database, then ships a child-theme fix safely. " +
	"Use when the user mentions their WordPress site, WooCommerce, checkout or payment problems, a plugin or " +
	"theme error, a white screen, or asks to look at the live site's logs, code or database, or types /wpicker."

// claudeSkill renders SKILL.md: frontmatter first (Claude Code requires it), then the shared guide.
func claudeSkill(site string) string {
	return "---\nname: wpicker\ndescription: " + skillDescription + "\n---\n\n" +
		"# WPicker\n" + managedMarker() + "\n" + agentGuide(site) + "\n"
}

// cursorRule renders a Cursor "agent requested" rule: loaded when the
// description matches the task, not in every conversation.
func cursorRule(site string) string {
	return "---\ndescription: " + skillDescription + "\nalwaysApply: false\n---\n\n" +
		"# WPicker\n" + managedMarker() + "\n" + agentGuide(site) + "\n"
}

// windsurfRule is the same idea for Windsurf (the model decides when to load it).
func windsurfRule(site string) string {
	return "---\ntrigger: model_decision\ndescription: " + skillDescription + "\n---\n\n" +
		"# WPicker\n" + managedMarker() + "\n" + agentGuide(site) + "\n"
}

// stubFor picks the always-on pointer: a project file may say "this project",
// a global one is read in every repo and must not.
func stubFor(global bool) string {
	if global {
		return strings.TrimLeft(agentStubGlobal, "\n")
	}
	return strings.TrimLeft(agentStub, "\n")
}

// agentTarget describes one AI agent: where it reads instructions and in what shape.
type agentTarget struct {
	key     string
	name    string
	project string // path under the project dir
	global  string // path under the home dir; "" = no global form
	shared  bool   // a file the user may also write in: insert a marked block instead of owning it
	render  func(site string, global bool) string
	detect  []string // paths (under the project, or "~/"-prefixed) whose presence means the agent is in use
}

func full(f func(string) string) func(string, bool) string {
	return func(site string, _ bool) string { return f(site) }
}

func block(site string, global bool) string {
	return "## WordPress site (WPicker)\n" + stubFor(global)
}

func plain(site string, global bool) string {
	return "# WPicker\n" + managedMarker() + "\n" + stubFor(global)
}

var agentTargets = []agentTarget{
	{key: "claude", name: "Claude Code", project: ".claude/skills/wpicker/SKILL.md", global: ".claude/skills/wpicker/SKILL.md",
		render: full(claudeSkill), detect: []string{".claude", "~/.claude"}},
	{key: "cursor", name: "Cursor", project: ".cursor/rules/wpicker.mdc",
		render: full(cursorRule), detect: []string{".cursor", ".cursorrules"}},
	{key: "codex", name: "Codex and other AGENTS.md readers", project: "AGENTS.md", global: ".codex/AGENTS.md", shared: true,
		render: block, detect: []string{"AGENTS.md", "~/.codex"}},
	{key: "gemini", name: "Gemini CLI", project: "GEMINI.md", global: ".gemini/GEMINI.md", shared: true,
		render: block, detect: []string{"GEMINI.md", "~/.gemini"}},
	{key: "copilot", name: "GitHub Copilot", project: ".github/copilot-instructions.md", shared: true,
		render: block, detect: []string{".github/copilot-instructions.md"}},
	{key: "windsurf", name: "Windsurf", project: ".windsurf/rules/wpicker.md",
		render: full(windsurfRule), detect: []string{".windsurf", ".windsurfrules"}},
	{key: "cline", name: "Cline", project: ".clinerules/wpicker.md",
		render: plain, detect: []string{".clinerules"}},
}

func findTarget(key string) *agentTarget {
	key = strings.ToLower(strings.TrimSpace(key))
	if key == "agents" || key == "agents.md" {
		key = "codex"
	}
	for i := range agentTargets {
		if agentTargets[i].key == key {
			return &agentTargets[i]
		}
	}
	return nil
}

func agentKeys() string {
	keys := make([]string, 0, len(agentTargets))
	for _, t := range agentTargets {
		keys = append(keys, t.key)
	}
	return strings.Join(keys, ", ")
}

// pathFor returns where this agent's file goes, and the directory it must stay inside.
func (t *agentTarget) pathFor(dir, home string, global bool) (path, root string, ok bool) {
	if global {
		if t.global == "" || home == "" {
			return "", "", false
		}
		return filepath.Join(home, t.global), home, true
	}
	return filepath.Join(dir, t.project), dir, true
}

// detectAgents returns the agents that already have a footprint in the project or home directory.
func detectAgents(dir, home string) []*agentTarget {
	var out []*agentTarget
	for i := range agentTargets {
		for _, d := range agentTargets[i].detect {
			p := filepath.Join(dir, d)
			if strings.HasPrefix(d, "~/") {
				if home == "" {
					continue
				}
				p = filepath.Join(home, strings.TrimPrefix(d, "~/"))
			}
			if _, err := os.Lstat(p); err == nil {
				out = append(out, &agentTargets[i])
				break
			}
		}
	}
	return out
}

// resolveWithin follows every symbolic link in path (or in its deepest
// existing parent, when the file is yet to be created) and refuses a result
// outside root. A link INSIDE the project, such as AGENTS.md -> CLAUDE.md, is
// fine; one that leads out, such as AGENTS.md -> ~/.zshrc, is not written to.
func resolveWithin(path, root string) (string, error) {
	realRoot, err := filepath.EvalSymlinks(root)
	if err != nil {
		return "", err
	}
	existing, rest := path, ""
	for {
		if _, err := os.Lstat(existing); err == nil {
			break
		}
		parent := filepath.Dir(existing)
		if parent == existing {
			return "", fmt.Errorf("cannot resolve %s", path)
		}
		rest = filepath.Join(filepath.Base(existing), rest)
		existing = parent
	}
	real, err := filepath.EvalSymlinks(existing)
	if err != nil {
		return "", err
	}
	real = filepath.Join(real, rest)
	if real != realRoot && !strings.HasPrefix(real, realRoot+string(filepath.Separator)) {
		return "", fmt.Errorf("%s leads outside %s through a symbolic link; not writing there", path, root)
	}
	return real, nil
}

// writeFile replaces path atomically (temp file in the same directory, then
// rename), keeping the file's permissions: a failed write never leaves the
// user's file truncated.
func writeFile(path, content string) error {
	dir := filepath.Dir(path)
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return err
	}
	mode := os.FileMode(0o644)
	if st, err := os.Stat(path); err == nil {
		mode = st.Mode().Perm()
	}
	tmp, err := os.CreateTemp(dir, ".wpicker-*.tmp")
	if err != nil {
		return err
	}
	name := tmp.Name()
	if _, err := tmp.WriteString(content); err != nil {
		tmp.Close()
		os.Remove(name)
		return err
	}
	if err := tmp.Close(); err != nil {
		os.Remove(name)
		return err
	}
	if err := os.Chmod(name, mode); err != nil {
		os.Remove(name)
		return err
	}
	if err := os.Rename(name, path); err != nil {
		os.Remove(name)
		return err
	}
	return nil
}

// writeManaged writes content to path unless a file WPicker did not write is
// already there. It returns what happened: "created", "updated" or "unchanged".
func writeManaged(path, content string, force bool) (string, error) {
	status := "created"
	if old, err := os.ReadFile(path); err == nil {
		switch {
		case string(old) == content:
			return "unchanged", nil
		case hasManagedMarker(string(old)) || force:
			status = "updated"
		default:
			return "", fmt.Errorf("%s already exists and was not written by WPicker; use --force to replace it, or remove it", path)
		}
	} else if !errors.Is(err, os.ErrNotExist) {
		return "", err
	}
	return status, writeFile(path, content)
}

// upsertBlock puts body between WPicker's markers in a file the user may also
// write in. Their text is never touched; running it again replaces the block.
// A file with two begin markers, or one with no end marker, is refused rather
// than guessed at.
func upsertBlock(path, body string) (string, error) {
	block := blockBegin + " v" + Version + " -->\n" + strings.TrimRight(body, "\n") + "\n" + blockEnd + "\n"
	old, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return "created", writeFile(path, block)
	}
	if err != nil {
		return "", err
	}
	text := string(old)
	var next string
	begins := beginMarkers(text)
	switch {
	case len(begins) > 1:
		return "", fmt.Errorf("%s has %d WPicker blocks; remove all but one by hand", path, len(begins))
	case len(begins) == 1:
		b := begins[0][0]
		e := endLineRe.FindStringIndex(text[b:])
		if e == nil {
			return "", fmt.Errorf("%s has a WPicker block with no end marker; fix or remove it by hand", path)
		}
		after := b + e[1]
		if text[after-1] == '\r' {
			after-- // the \r belongs to the line ending that follows, which stays as it was
		}
		repl := strings.TrimRight(block, "\n")
		if strings.Contains(text, "\r\n") {
			repl = strings.ReplaceAll(repl, "\n", "\r\n")
		}
		next = text[:b] + repl + text[after:]
	default:
		sep := ""
		switch {
		case text == "":
		case strings.HasSuffix(text, "\n"):
			sep = "\n"
		default:
			sep = "\n\n"
		}
		next = text + sep + block
	}
	if next == text {
		return "unchanged", nil
	}
	return "updated", writeFile(path, next)
}

// ownedByWPicker says whether a file at path was written by WPicker (a
// dedicated file with the marker, or a shared file holding our block).
func ownedByWPicker(path string, shared bool) bool {
	b, err := os.ReadFile(path)
	if err != nil {
		return false
	}
	if shared {
		return len(beginMarkers(string(b))) > 0
	}
	return hasManagedMarker(string(b))
}

// sameDir reports whether two paths are the same directory.
func sameDir(a, b string) bool {
	if a == "" || b == "" {
		return false
	}
	ra, ea := filepath.EvalSymlinks(a)
	rb, eb := filepath.EvalSymlinks(b)
	if ea != nil || eb != nil {
		return filepath.Clean(a) == filepath.Clean(b)
	}
	return ra == rb
}

// runningAsRoot is true under sudo. Files written then would belong to root
// inside the user's home and could not be refreshed or removed later.
func runningAsRoot() bool { return runtime.GOOS != "windows" && os.Geteuid() == 0 }

// writeTarget writes one agent's file and returns what happened.
func writeTarget(t *agentTarget, path, root string, global, force bool) (string, error) {
	real, err := resolveWithin(path, root)
	if err != nil {
		return "", err
	}
	site := siteHintFor(root)
	if global {
		site = "" // a global file is used with many sites
	}
	if t.shared {
		return upsertBlock(real, t.render(site, global))
	}
	return writeManaged(real, t.render(site, global), force)
}

// applyTargets writes each agent's file and prints one line per agent. It
// returns how many were written or already current, and how many failed.
// Run from the home directory, a "project" file is the global one.
func applyTargets(targets []*agentTarget, dir, home string, global, force bool) (done, failed int) {
	if sameDir(dir, home) {
		global = true
	}
	for _, t := range targets {
		path, root, ok := t.pathFor(dir, home, global)
		if !ok {
			fmt.Printf("%-34s skipped: no global location (use it per project)\n", t.name)
			continue
		}
		st, err := writeTarget(t, path, root, global, force)
		if err != nil {
			failed++
			fmt.Printf("%-34s FAILED: %v\n", t.name, err)
			continue
		}
		done++
		fmt.Printf("%-34s %s: %s\n", t.name, st, path)
	}
	return done, failed
}

// refreshManaged rewrites, with the current guide, the agent files WPicker
// wrote earlier in this project and in the home directory. It creates nothing
// and says nothing when there is nothing to refresh, so it is safe to run
// after every login or upgrade.
func refreshManaged(dir, home string) int {
	n := 0
	seen := map[string]bool{}
	for i := range agentTargets {
		t := &agentTargets[i]
		for _, global := range []bool{false, true} {
			path, root, ok := t.pathFor(dir, home, global)
			if !ok || seen[path] || !ownedByWPicker(path, t.shared) {
				continue
			}
			seen[path] = true
			isGlobal := global || sameDir(dir, home)
			st, err := writeTarget(t, path, root, isGlobal, false)
			switch {
			case err != nil:
				fmt.Printf("%-34s could not refresh %s: %v\n", t.name, path, err)
			case st != "unchanged":
				n++
				fmt.Printf("%-34s %s: %s\n", t.name, st, path)
			}
		}
	}
	return n
}

// unconfiguredHere lists agents with a footprint in THIS project that do not
// have a WPicker file yet. A footprint in the home directory alone is not a project.
func unconfiguredHere(dir, home string) []*agentTarget {
	var out []*agentTarget
	for _, t := range detectAgents(dir, home) {
		inProject := false
		for _, d := range t.detect {
			if strings.HasPrefix(d, "~/") {
				continue
			}
			if _, err := os.Lstat(filepath.Join(dir, d)); err == nil {
				inProject = true
			}
		}
		if !inProject {
			continue
		}
		if p, _, ok := t.pathFor(dir, home, false); ok && !ownedByWPicker(p, t.shared) {
			out = append(out, t)
		}
	}
	return out
}

// connectAfterLogin keeps login enough on its own without editing a repo
// behind the user's back: it refreshes what WPicker wrote before, and for the
// agents found in this folder it ASKS (or, when not interactive, says how).
// Nothing here can fail the login.
func connectAfterLogin(dir, home string) {
	if runningAsRoot() {
		return
	}
	refreshManaged(dir, home)
	if sameDir(dir, home) {
		return
	}
	todo := unconfiguredHere(dir, home)
	if len(todo) == 0 {
		return
	}
	names := make([]string, len(todo))
	for i, t := range todo {
		names[i] = t.name
	}
	list := strings.Join(names, ", ")
	if !isTerminal(os.Stdin) {
		fmt.Printf("\nAI agents found in this folder (%s). Run `wpicker connect` here to teach them WPicker.\n", list)
		return
	}
	fmt.Printf("\nAI agents found in this folder: %s.\nAdd WPicker instructions to this project? [y/N] ", list)
	answer, _ := bufio.NewReader(os.Stdin).ReadString('\n')
	if a := strings.ToLower(strings.TrimSpace(answer)); a == "y" || a == "yes" {
		applyTargets(todo, dir, home, false, false)
	}
}

var connectCmd = &cobra.Command{
	Use:   "connect",
	Short: "Teach AI agents (Claude Code, Cursor, Codex, Gemini, Copilot, …) to use WPicker.",
	Long: `Writes the instructions an AI agent needs to explore and fix this WordPress site
with WPicker: which commands to run, in what order, and what is off limits.

  wpicker connect                         the agents it finds in this project (else AGENTS.md)
  wpicker connect --agent claude          Claude Code skill, so /wpicker works
  wpicker connect --agent claude,cursor   several
  wpicker connect --agent all             every supported agent
  wpicker connect --agent claude --global for every project, not just this one
  wpicker connect --refresh               only update the files WPicker wrote earlier

Supported: ` + "`" + `claude, cursor, codex (AGENTS.md), gemini, copilot, windsurf, cline` + "`" + `.

Agents that load rules on demand (Claude Code, Cursor, Windsurf) get the full
guide. Agents that read a file in every conversation get a short pointer instead,
and fetch the full guide with ` + "`wpicker guide`" + ` only when the task is about the site.

Files shared with you (AGENTS.md, GEMINI.md, Copilot instructions) only get a
marked block; the rest of the file is never touched. A dedicated file you wrote
yourself is not replaced without --force. Nothing is written through a symbolic
link that leads outside the project.

Run it as yourself, not with sudo: files written as root would be stuck in your
home directory. Rule file formats change between agent releases: if one stops
working, tell us.`,
	Args: cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		dir := connectDir
		if dir == "" {
			dir, _ = os.Getwd()
		}
		home, _ := os.UserHomeDir()

		if connectRefresh {
			if len(connectAgents) > 0 || connectGlobal || connectForce {
				return errors.New("--refresh works on its own: it updates the files WPicker already wrote")
			}
			if runningAsRoot() {
				fmt.Println("Not refreshing as root (files in your home would become root-owned). Run `wpicker connect --refresh` as your own user.")
				return nil
			}
			refreshManaged(dir, home)
			return nil
		}
		if runningAsRoot() {
			return errors.New("run this as your own user, not root or sudo: the files would end up owned by root in your home directory")
		}

		global := connectGlobal || sameDir(dir, home)
		var targets []*agentTarget
		if len(connectAgents) == 0 {
			targets = detectAgents(dir, home)
			if global {
				targets = filterGlobal(targets)
			}
			switch {
			case len(targets) == 0 && global:
				// Nothing installed that reads a global file: do not create ~/.codex or ~/.gemini for it.
				fmt.Println("No Claude Code, Codex or Gemini CLI found in your home directory, so nothing was installed globally.")
				fmt.Println("Per project instead: `wpicker connect` (or `wpicker connect --agent all`) in the project folder.")
				return nil
			case len(targets) == 0:
				targets = []*agentTarget{findTarget("codex")}
				fmt.Println("No agent found in this project; writing AGENTS.md, which many agents read.")
			default:
				names := make([]string, len(targets))
				for i, t := range targets {
					names[i] = t.name
				}
				fmt.Printf("Found: %s\n", strings.Join(names, ", "))
			}
		} else {
			seen := map[string]bool{}
			for _, raw := range connectAgents {
				for _, k := range strings.Split(raw, ",") {
					if strings.TrimSpace(k) == "" {
						continue
					}
					if strings.EqualFold(strings.TrimSpace(k), "all") {
						for i := range agentTargets {
							addTarget(&targets, seen, &agentTargets[i])
						}
						continue
					}
					t := findTarget(k)
					if t == nil {
						return fmt.Errorf("unknown agent %q; choose from: %s, all", k, agentKeys())
					}
					addTarget(&targets, seen, t)
				}
			}
			if len(targets) == 0 {
				return fmt.Errorf("no agent named; choose from: %s, all", agentKeys())
			}
		}
		sort.SliceStable(targets, func(i, j int) bool { return targets[i].key < targets[j].key })

		done, failed := applyTargets(targets, dir, home, connectGlobal, connectForce)
		if done == 0 && failed == 0 {
			return errors.New("none of those agents has a global location; run it without --global inside a project")
		}

		connectCheck(cmd)
		for _, t := range targets {
			if t.key == "claude" {
				fmt.Println("\nIn Claude Code: ask about the site (\"why is checkout failing?\") or type /wpicker.")
				fmt.Println("To skip the permission prompts for the read-only commands, allow these in /permissions:")
				fmt.Println("  Bash(wpicker map:*)  Bash(wpicker logs:*)  Bash(wpicker find:*)  Bash(wpicker files:*)  Bash(wpicker db:*)  Bash(wpicker guide:*)")
				fmt.Println("Leave `wpicker theme push` and `wpicker rollback` on ask: they change the live site.")
			}
		}
		if failed > 0 {
			return fmt.Errorf("%d agent(s) could not be connected", failed)
		}
		return nil
	},
}

func addTarget(list *[]*agentTarget, seen map[string]bool, t *agentTarget) {
	if !seen[t.key] {
		seen[t.key] = true
		*list = append(*list, t)
	}
}

func filterGlobal(in []*agentTarget) []*agentTarget {
	var out []*agentTarget
	for _, t := range in {
		if t.global != "" {
			out = append(out, t)
		}
	}
	return out
}

// connectCheck tells the user whether the agent will be able to see the site.
// It never fails the command (the files are already written) and never waits long.
func connectCheck(cmd *cobra.Command) {
	c, cfg, err := mustClient()
	if err != nil {
		fmt.Println("\nNot logged in yet — run `wpicker login` so the agent can reach the site.")
		return
	}
	ctx, cancel := context.WithTimeout(ctxFrom(cmd), 10*time.Second)
	defer cancel()
	m, err := c.Map(ctx)
	switch {
	case err == nil:
		fmt.Printf("\nConnected to %s — WordPress %s, PHP %s, %d plugins.\n", cfg.Site, m.WordPress.Version, m.WordPress.PHP, len(m.Plugins))
	case strings.Contains(err.Error(), "wpicker_inspect_disabled"):
		fmt.Printf("\nLogged in to %s, but \"Diagnostic access\" is off, so the agent cannot read logs, code or the database.\n", cfg.Site)
		fmt.Println("An administrator turns it on in WP-Admin → WPicker → Settings.")
	default:
		fmt.Printf("\nCould not reach %s: %v\n", cfg.Site, err)
	}
}

func init() {
	connectCmd.Flags().StringSliceVar(&connectAgents, "agent", nil, "agents to connect: "+agentKeysStatic()+", or all (default: the ones found in this project)")
	connectCmd.Flags().BoolVar(&connectGlobal, "global", false, "install in your home directory so every project has it (claude, codex, gemini)")
	connectCmd.Flags().BoolVar(&connectForce, "force", false, "replace a dedicated file that WPicker did not write")
	connectCmd.Flags().BoolVar(&connectRefresh, "refresh", false, "only refresh the files WPicker wrote earlier (project and home); create nothing")
	connectCmd.Flags().StringVar(&connectDir, "dir", "", "project directory (default: the current one)")
	rootCmd.AddCommand(connectCmd)
}

func agentKeysStatic() string { return "claude, cursor, codex, gemini, copilot, windsurf, cline" }
