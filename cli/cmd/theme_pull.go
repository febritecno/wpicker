package cmd

import (
	"bufio"
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"

	"github.com/spf13/cobra"

	"wpicker/internal/client"
	"wpicker/internal/sync"
	"wpicker/internal/tui"
)

var (
	pullAll    bool // pull --all
	pullForce  bool // pull --force
	pullDryRun bool // pull --dry-run
	pullKeep   bool // pull --keep-local
	pullTheirs bool // pull --theirs
)

// pullItem is one thing `pull` can do to the working directory.
type pullItem struct {
	sync.Change
	// Merge is true for a file changed on both sides that will be combined
	// line by line instead of one side replacing the other.
	Merge bool `json:"merge"`
	// Overwrites is true when applying discards a local edit.
	Overwrites bool `json:"overwrites_local"`
}

// mergeOutcome is what happened to one file that was merged (or could not be).
type mergeOutcome struct {
	Path      string `json:"path"`
	Conflicts int    `json:"conflicts"`
	// Skipped says why the file was left untouched, when it could not be merged.
	Skipped string `json:"skipped,omitempty"`
	Backup  string `json:"backup,omitempty"`
}

// letter is the status column: C for a conflict, D/A/M for what applying does.
func (it pullItem) letter() string {
	if it.Conflict() {
		return "C"
	}
	return it.effect().Letter()
}

// effect is what applying the item does to the local copy. A local-only edit
// (offered under --force) has no site-side change: it is reverted.
func (it pullItem) effect() sync.Kind {
	switch {
	case it.Remote != "":
		return it.Remote
	case it.Local == sync.Added:
		return sync.Deleted
	}
	return sync.Modified
}

func (it pullItem) note() string {
	switch {
	case it.Merge:
		return "changed on both sides — merged line by line; overlapping lines get conflict markers"
	case it.Conflict():
		return fmt.Sprintf("%s on the site, %s locally — applying discards your version", it.Remote, it.Local)
	case it.Overwrites:
		return fmt.Sprintf("%s locally only — applying restores the site's version", it.Local)
	}
	return ""
}

// syncState is everything pull/status/diff need about both sides.
type syncState struct {
	ctx      context.Context
	root     string
	client   *client.Client
	site     string
	files    *client.FilesResponse
	remote   map[string]string
	sizes    map[string]int64
	manifest *sync.Manifest
	// lastPush says who last pushed each file to the site, when known.
	lastPush map[string]pushInfo
	merges   []mergeOutcome
	status   *sync.Status
}

// pushInfo identifies the push that last touched a file on the site.
type pushInfo struct {
	Device string
	When   string
}

func (p pushInfo) String() string {
	if p.Device == "" {
		return ""
	}
	return p.Device + ", " + shortTime(p.When)
}

// lastPushes maps each file to the most recent applied push that changed it.
// Best effort: an old plugin or a failed request just means no attribution.
func lastPushes(cmd *cobra.Command, c *client.Client) map[string]pushInfo {
	out := map[string]pushInfo{}
	history, err := c.History(ctxFrom(cmd), 100)
	if err != nil {
		return out
	}
	for _, m := range history.Manifests { // newest first
		if m.Status != "applied" {
			continue
		}
		for _, ch := range m.Changes {
			if _, seen := out[ch.Path]; !seen {
				out[ch.Path] = pushInfo{Device: m.DeviceName, When: m.CreatedAt}
			}
		}
	}
	return out
}

// remoteMaps indexes a file listing by path, leaving out ignored paths.
func remoteMaps(f *client.FilesResponse) (hashes map[string]string, sizes map[string]int64) {
	hashes = map[string]string{}
	sizes = map[string]int64{}
	for _, e := range f.Files {
		if sync.IsIgnored(e.Path) {
			continue
		}
		hashes[e.Path] = e.SHA256
		sizes[e.Path] = e.Size
	}
	return hashes, sizes
}

// saveIfDirty persists what computing the status revealed: paths both sides
// already agree on, and held pushes that have since been approved. Without
// it, the next local edit to such a file would look like a conflict.
func saveIfDirty(root string, manifest *sync.Manifest, st *sync.Status, remote map[string]string, sizes map[string]int64) error {
	if !st.Dirty() {
		return nil
	}
	st.Settle(root, manifest, remote, sizes)
	return sync.SaveManifest(root, manifest)
}

func loadSyncState(cmd *cobra.Command) (*syncState, error) {
	root := pullOut
	if root == "" {
		root = "."
	}
	// The site follows the working folder (--out), not the shell's directory.
	c, cfg, err := mustClientAt(root, true)
	if err != nil {
		return nil, err
	}
	files, err := c.ListFiles(ctxFrom(cmd))
	if err != nil {
		return nil, err
	}
	// First successful contact: remember which site this folder belongs to.
	autoLinkTheme(root, lastResolved, cmd.Name() == "pull")
	manifest, err := sync.LoadManifest(root)
	if err != nil {
		return nil, fmt.Errorf("load local manifest: %w", err)
	}
	s := &syncState{ctx: ctxFrom(cmd), root: root, client: c, site: c.Site, files: files, manifest: manifest}
	if s.site == "" {
		s.site = cfg.Site
	}
	s.remote, s.sizes = remoteMaps(files)
	if s.status, err = sync.ComputeStatus(root, manifest, s.remote); err != nil {
		return nil, err
	}
	if err := saveIfDirty(root, manifest, s.status, s.remote, s.sizes); err != nil {
		return nil, err
	}
	// Only worth a request when there is something on the site to attribute.
	if len(s.status.Incoming)+len(s.status.Conflicts) > 0 {
		s.lastPush = lastPushes(cmd, c)
	}
	return s, nil
}

// theme status ---------------------------------------------------------------

var statusCmd = &cobra.Command{
	Use:   "status",
	Short: "Show what differs between the local copy and the site.",
	Long: `Compares the working directory, the site, and the last pull, like ` + "`git status`" + `:

  To push    changed locally only        → ` + "`wpicker theme push`" + `
  To pull    changed on the site only    → ` + "`wpicker theme pull`" + `
  Conflicts  changed on both sides       → pull the ones where the site wins,
                                           then push`,
	RunE: func(cmd *cobra.Command, args []string) error {
		s, err := loadSyncState(cmd)
		if err != nil {
			return err
		}
		p := tui.New(os.Stdout, flagJSON)
		if flagJSON {
			p.EmitJSON(s.status)
			return nil
		}
		st := s.status
		fmt.Printf("Site: %s (%s)\n", s.site, s.files.Stylesheet)
		if st.Clean() {
			fmt.Println("In sync — nothing to push or pull.")
			return nil
		}
		section := func(title string, list []sync.Change, letter func(sync.Change) string) {
			if len(list) == 0 {
				return
			}
			fmt.Println(title)
			for _, ch := range list {
				line := fmt.Sprintf("  %s  %s", letter(ch), ch.Path)
				// Who changed it on the site — the other half of a conflict.
				if who := s.lastPush[ch.Path].String(); who != "" && ch.Remote != "" {
					line += "   (" + who + ")"
				}
				if ch.Local != "" && ch.Local != sync.Deleted && unresolved(s.root, ch.Path) {
					line += "   ← has conflict markers: resolve before pushing"
				}
				fmt.Println(line)
			}
		}
		section("To push (changed locally):", st.Outgoing, func(c sync.Change) string { return c.Local.Letter() })
		section("To pull (changed on the site):", st.Incoming, func(c sync.Change) string { return c.Remote.Letter() })
		section("Conflicts (changed on both sides):", st.Conflicts, func(sync.Change) string { return "C" })
		if len(st.Conflicts) > 0 {
			fmt.Println("  `wpicker theme pull` merges them line by line.")
		}
		return nil
	},
}

// theme pull -----------------------------------------------------------------

var pullCmd = &cobra.Command{
	Use:   "pull [path...]",
	Short: "List what changed on the site and apply the changes you choose.",
	Long: `Lists the files that changed on the site since your last pull and applies the
ones you pick. Nothing you edited locally is thrown away unless you ask for it.

A file changed both locally and on the site is MERGED line by line, the way
git does it: edits to different lines are combined; where both sides changed
the same lines, both versions are written between conflict markers

    <<<<<<< yours
    color: red;
    =======
    color: blue;
    >>>>>>> site (ani-laptop, 2026-10-13 14:02)

for you (or your editor's merge view, or an AI agent) to settle. ` + "`push`" + ` refuses
files that still contain markers. Your pre-merge copy is kept in
.wpicker/merge-backup/.

  wpicker theme pull                 interactive: shows the list, asks which to apply
  wpicker theme pull style.css inc/a.php   apply only these
  wpicker theme pull --all           apply everything, merging where needed
  wpicker theme pull --dry-run       show the list only

Choosing a side outright instead of merging:

  wpicker theme pull --theirs index.php      take the site's version
  wpicker theme pull --keep-local index.php  keep yours; the next push overwrites the site's
  wpicker theme pull --force                 make local identical to the site, discarding
                                             local edits to files that exist on the site

Without a terminal (scripts, AI agents, --json) a bare ` + "`pull`" + ` behaves like --all.
A file deleted on one side and edited on the other cannot be merged; it is
left alone until you choose a side.`,
	RunE: func(cmd *cobra.Command, args []string) error {
		s, err := loadSyncState(cmd)
		if err != nil {
			return err
		}
		if pullKeep {
			return keepLocal(s, args)
		}
		items := pullItems(s.status, pullForce, pullTheirs)

		interactive := !flagJSON && !pullAll && !pullForce && len(args) == 0 && isTerminal(os.Stdin)
		// A first pull into an empty directory has nothing to protect.
		if interactive && len(s.manifest.Files) == 0 && len(s.status.Outgoing) == 0 && len(s.status.Conflicts) == 0 {
			interactive = false
		}

		if len(items) == 0 {
			return finishPull(s, nil, nil)
		}
		if !flagJSON {
			printPullList(os.Stderr, s.site, items)
		}
		if pullDryRun {
			if flagJSON {
				tui.New(os.Stdout, true).EmitJSON(map[string]any{"available": items})
			}
			return nil
		}

		var chosen []pullItem
		switch {
		case len(args) > 0:
			if chosen, err = pickByPath(items, args); err != nil {
				return err
			}
		case pullForce:
			chosen = items
		case interactive:
			if chosen, err = promptPull(s, items, os.Stdin, os.Stderr); err != nil {
				return err
			}
		default:
			for _, it := range items {
				if !it.Overwrites {
					chosen = append(chosen, it)
				}
			}
		}

		var applied []pullItem
		for _, it := range chosen {
			done, err := applyPull(s, it)
			if err != nil {
				return err
			}
			if done {
				applied = append(applied, it)
			}
		}
		return finishPull(s, items, applied)
	},
}

// pullItems lists what pull can apply: site-side changes first, then
// conflicts, then (only with force) local-only edits to revert.
func pullItems(st *sync.Status, force, theirs bool) []pullItem {
	var items []pullItem
	for _, ch := range st.Incoming {
		items = append(items, pullItem{Change: ch})
	}
	for _, ch := range st.Conflicts {
		// Both sides still have the file: it can be merged, unless the user
		// asked for the site's version outright.
		merge := ch.Local != sync.Deleted && ch.Remote != sync.Deleted && !force && !theirs
		items = append(items, pullItem{Change: ch, Merge: merge, Overwrites: !merge})
	}
	if force {
		for _, ch := range st.Outgoing {
			// A file that exists only locally is left alone even by --force.
			if ch.Local != sync.Added {
				items = append(items, pullItem{Change: ch, Overwrites: true})
			}
		}
	}
	return items
}

func printPullList(w io.Writer, site string, items []pullItem) {
	fmt.Fprintf(w, "Changes on %s not in your local copy:\n", site)
	for i, it := range items {
		line := fmt.Sprintf("  %2d) %s  %s", i+1, it.letter(), it.Path)
		if n := it.note(); n != "" {
			line += "   (" + n + ")"
		}
		fmt.Fprintln(w, line)
	}
}

// pickByPath selects the items named on the command line.
func pickByPath(items []pullItem, paths []string) ([]pullItem, error) {
	byPath := map[string]pullItem{}
	for _, it := range items {
		byPath[it.Path] = it
	}
	var chosen []pullItem
	for _, p := range paths {
		it, ok := byPath[filepath.ToSlash(filepath.Clean(p))]
		if !ok {
			return nil, fmt.Errorf("%s: no change to pull for this path (see `wpicker theme status`)", p)
		}
		chosen = append(chosen, it)
	}
	return chosen, nil
}

// promptPull asks which listed changes to apply.
func promptPull(s *syncState, items []pullItem, in io.Reader, out io.Writer) ([]pullItem, error) {
	r := bufio.NewReader(in)
	for {
		fmt.Fprint(out, "Apply which? [a]ll (merging where needed) · numbers (1,3 or 2-4) · d <n> to view diff · [q]uit > ")
		line, err := r.ReadString('\n')
		if err != nil && line == "" {
			return nil, nil
		}
		line = strings.TrimSpace(line)
		switch {
		case line == "" || line == "q" || line == "n":
			return nil, nil
		case line == "a":
			var chosen []pullItem
			for _, it := range items {
				if !it.Overwrites {
					chosen = append(chosen, it)
				}
			}
			return chosen, nil
		case strings.HasPrefix(line, "d"):
			n, err := strconv.Atoi(strings.TrimSpace(strings.TrimPrefix(line, "d")))
			if err != nil || n < 1 || n > len(items) {
				fmt.Fprintf(out, "  usage: d <1-%d>\n", len(items))
				continue
			}
			if err := showDiff(s, items[n-1].Change, out); err != nil {
				fmt.Fprintf(out, "  %v\n", err)
			}
			continue
		}
		picks, err := parseSelection(line, len(items))
		if err != nil {
			fmt.Fprintf(out, "  %v\n", err)
			continue
		}
		var chosen []pullItem
		for _, i := range picks {
			chosen = append(chosen, items[i])
		}
		return chosen, nil
	}
}

// parseSelection turns "1,3 5-7" into zero-based indexes, in order, deduplicated.
func parseSelection(s string, max int) ([]int, error) {
	var out []int
	seen := map[int]bool{}
	for _, tok := range strings.FieldsFunc(s, func(r rune) bool { return r == ',' || r == ' ' }) {
		lo, hi, isRange := strings.Cut(tok, "-")
		a, err := strconv.Atoi(lo)
		b := a
		if err == nil && isRange {
			b, err = strconv.Atoi(hi)
		}
		if err != nil || a < 1 || b > max || a > b {
			return nil, fmt.Errorf("%q is not a number or range between 1 and %d", tok, max)
		}
		for i := a; i <= b; i++ {
			if !seen[i] {
				seen[i] = true
				out = append(out, i-1)
			}
		}
	}
	if len(out) == 0 {
		return nil, errors.New("nothing selected")
	}
	return out, nil
}

// applyPull brings one local file up to date with the site and records it in
// the manifest. It reports false if the file was left untouched (a merge that
// could not be attempted).
func applyPull(s *syncState, it pullItem) (bool, error) {
	hash, onSite := s.remote[it.Path]
	if !onSite {
		if err := sync.RemoveFile(s.root, it.Path); err != nil {
			return false, fmt.Errorf("remove %s: %w", it.Path, err)
		}
		delete(s.manifest.Files, it.Path)
		sync.RemoveBase(s.root, it.Path)
		return true, nil
	}
	file, err := s.client.ReadFile(s.ctx, it.Path)
	if err != nil {
		return false, fmt.Errorf("read %s: %w", it.Path, err)
	}
	if it.Merge {
		return mergePull(s, it, file.Contents)
	}
	if err := sync.WriteFileAtomic(s.root, it.Path, file.Contents); err != nil {
		return false, fmt.Errorf("write %s: %w", it.Path, err)
	}
	return true, s.agree(it.Path, hash, file.Contents)
}

// agree records that local and site are now known to match on rel as far as
// the site's content goes: that content becomes the ancestor for later merges.
func (s *syncState) agree(rel, hash string, siteContent []byte) error {
	s.manifest.Files[rel] = sync.ManifestFile{SHA256: hash, Size: int64(len(siteContent))}
	return sync.SaveBase(s.root, rel, siteContent)
}

// mergePull combines the local and the site's version of a file changed on
// both sides. Afterwards the site's version is the ancestor, so the merged
// file is simply a local change waiting to be pushed.
func mergePull(s *syncState, it pullItem, theirs []byte) (bool, error) {
	skip := func(why string) (bool, error) {
		s.merges = append(s.merges, mergeOutcome{Path: it.Path, Skipped: why})
		return false, nil
	}
	ours, err := sync.ReadFile(s.root, it.Path)
	if err != nil {
		return false, fmt.Errorf("read %s: %w", it.Path, err)
	}
	if !sync.IsText(ours) || !sync.IsText(theirs) {
		return skip("not a text file — choose a side with --theirs or --keep-local")
	}

	var base []byte // stays empty when both sides created the file independently
	if it.Local != sync.Added || it.Remote != sync.Added {
		var ok bool
		if base, ok = sync.LoadBase(s.root, it.Path, s.manifest); !ok {
			return skip("no copy of the last common version on record — choose a side with --theirs or --keep-local")
		}
	}

	theirsLabel := "site"
	if who := s.lastPush[it.Path].String(); who != "" {
		theirsLabel += " (" + who + ")"
	}
	result := sync.Merge3(base, ours, theirs, "yours", theirsLabel)

	backup, err := sync.BackupBeforeMerge(s.root, it.Path, ours)
	if err != nil {
		return false, fmt.Errorf("back up %s before merging: %w", it.Path, err)
	}
	if err := sync.WriteFileAtomic(s.root, it.Path, result.Merged); err != nil {
		return false, fmt.Errorf("write %s: %w", it.Path, err)
	}
	s.merges = append(s.merges, mergeOutcome{Path: it.Path, Conflicts: result.Conflicts, Backup: backup})
	return true, s.agree(it.Path, s.remote[it.Path], theirs)
}

// unresolved reports whether a local file still has conflict markers in it.
func unresolved(root, rel string) bool {
	data, err := sync.ReadFile(root, rel)
	return err == nil && sync.IsText(data) && sync.HasConflictMarkers(data)
}

// finishPull saves the manifest and reports what was applied and what is left.
func finishPull(s *syncState, items, chosen []pullItem) error {
	s.status.Settle(s.root, s.manifest, s.remote, s.sizes)
	s.manifest.Stylesheet = s.files.Stylesheet
	if err := sync.SaveManifest(s.root, s.manifest); err != nil {
		return err
	}

	applied := map[string]bool{}
	for _, it := range chosen {
		applied[it.Path] = true
	}
	// Non-nil so --json prints [] rather than null.
	skipped, conflicts := []pullItem{}, []pullItem{}
	if chosen == nil {
		chosen = []pullItem{}
	}
	for _, it := range items {
		switch {
		case applied[it.Path]:
		case it.Conflict():
			conflicts = append(conflicts, it)
		default:
			skipped = append(skipped, it)
		}
	}

	merges := s.merges
	if merges == nil {
		merges = []mergeOutcome{}
	}
	if flagJSON {
		tui.New(os.Stdout, true).EmitJSON(map[string]any{
			"applied":   chosen,
			"skipped":   skipped,
			"conflicts": conflicts,
			"merged":    merges,
		})
		return nil
	}
	merged := map[string]mergeOutcome{}
	for _, m := range merges {
		merged[m.Path] = m
	}

	switch {
	case len(items) == 0:
		fmt.Println("Already up to date.")
	case len(chosen) == 0:
		fmt.Println("Nothing applied.")
	default:
		fmt.Printf("✓ Pulled %d file(s).\n", len(chosen))
		for _, it := range chosen {
			m, wasMerged := merged[it.Path]
			switch {
			case wasMerged && m.Conflicts > 0:
				fmt.Printf("  C  %s   merged, %d conflict(s) marked in the file\n", it.Path, m.Conflicts)
			case wasMerged:
				fmt.Printf("  M  %s   merged cleanly with your changes\n", it.Path)
			default:
				fmt.Printf("  %s  %s\n", it.effect().Letter(), it.Path)
			}
		}
	}

	open := 0
	for _, m := range merges {
		if m.Conflicts > 0 {
			open++
		}
	}
	if open > 0 {
		fmt.Printf("%d file(s) need your attention: both sides changed the same lines.\n", open)
		fmt.Println("  Open them, settle each <<<<<<< … >>>>>>> block (your editor's merge view works), then `wpicker theme push`.")
		fmt.Println("  Your versions from before the merge are in .wpicker/merge-backup/.")
	}
	if len(skipped) > 0 {
		fmt.Printf("%d change(s) on the site left unapplied; run `wpicker theme pull` again to take them.\n", len(skipped))
	}
	if len(conflicts) > 0 {
		fmt.Printf("%d conflict(s) left as they are locally:\n", len(conflicts))
		for _, it := range conflicts {
			why := ""
			if m, ok := merged[it.Path]; ok && m.Skipped != "" {
				why = "   (" + m.Skipped + ")"
			} else if !it.Merge {
				why = fmt.Sprintf("   (%s locally, %s on the site: cannot be merged)", it.Local, it.Remote)
			}
			fmt.Printf("  C  %s%s\n", it.Path, why)
		}
		fmt.Println("  Take the site's version:  wpicker theme pull --theirs <path>")
		fmt.Println("  Keep your version:        wpicker theme pull --keep-local <path>   (then push)")
	}
	return nil
}

// keepLocal resolves conflicts in favour of the local copy. It only moves the
// base: the manifest is told the site's current version has been seen, so the
// file stops being a conflict and becomes an ordinary local change to push.
func keepLocal(s *syncState, paths []string) error {
	if len(paths) == 0 {
		return errors.New("--keep-local needs the conflicting path(s) to resolve")
	}
	conflicts := map[string]bool{}
	for _, ch := range s.status.Conflicts {
		conflicts[ch.Path] = true
	}
	for _, p := range paths {
		rel := filepath.ToSlash(filepath.Clean(p))
		if !conflicts[rel] {
			return fmt.Errorf("%s: not in conflict (see `wpicker theme status`)", p)
		}
		if hash, onSite := s.remote[rel]; onSite {
			// The site's current version becomes the ancestor, so a later
			// merge compares against what was actually seen and overruled.
			file, err := s.client.ReadFile(s.ctx, rel)
			if err != nil {
				return fmt.Errorf("read %s: %w", rel, err)
			}
			if err := s.agree(rel, hash, file.Contents); err != nil {
				return err
			}
		} else {
			delete(s.manifest.Files, rel)
			sync.RemoveBase(s.root, rel)
		}
	}
	if err := sync.SaveManifest(s.root, s.manifest); err != nil {
		return err
	}
	tui.New(os.Stdout, flagJSON).Linef("✓ Kept your version of %d file(s). `wpicker theme push` will now overwrite the site's.", len(paths))
	return nil
}

// isTerminal reports whether f is an interactive terminal. /dev/null is a
// character device too, and is what scripts and agents commonly hand us.
func isTerminal(f *os.File) bool {
	fi, err := f.Stat()
	if err != nil || fi.Mode()&os.ModeCharDevice == 0 {
		return false
	}
	if null, err := os.Stat(os.DevNull); err == nil && os.SameFile(fi, null) {
		return false
	}
	return true
}

// theme diff -----------------------------------------------------------------

var diffCmd = &cobra.Command{
	Use:   "diff [path...]",
	Short: "Show line-by-line differences between the local copy and the site.",
	Long: `Shows a unified diff for each file that differs (all of them, or just the
paths given). Lines starting with "-" are your local copy, "+" the site.`,
	RunE: func(cmd *cobra.Command, args []string) error {
		s, err := loadSyncState(cmd)
		if err != nil {
			return err
		}
		all := append(append(append([]sync.Change{}, s.status.Outgoing...), s.status.Incoming...), s.status.Conflicts...)
		var want []sync.Change
		if len(args) == 0 {
			want = all
		}
		for _, p := range args {
			p = filepath.ToSlash(filepath.Clean(p))
			found := false
			for _, ch := range all {
				if ch.Path == p {
					want, found = append(want, ch), true
				}
			}
			if !found {
				fmt.Fprintf(os.Stderr, "%s: identical locally and on the site\n", p)
			}
		}
		for _, ch := range want {
			if err := showDiff(s, ch, os.Stdout); err != nil {
				return err
			}
		}
		return nil
	},
}

// showDiff prints `diff -u local site` for one path, using the system diff.
func showDiff(s *syncState, ch sync.Change, out io.Writer) error {
	diffBin, err := exec.LookPath("diff")
	if err != nil {
		return errors.New("no `diff` program found on this system")
	}

	local := filepath.Join(s.root, filepath.FromSlash(ch.Path))
	if _, err := os.Stat(local); err != nil {
		local = os.DevNull
	}

	site := os.DevNull
	if _, onSite := s.remote[ch.Path]; onSite {
		file, err := s.client.ReadFile(s.ctx, ch.Path)
		if err != nil {
			return fmt.Errorf("read %s: %w", ch.Path, err)
		}
		tmp, err := os.CreateTemp("", "wpicker-diff-*")
		if err != nil {
			return err
		}
		defer os.Remove(tmp.Name())
		if _, err := tmp.Write(file.Contents); err != nil {
			return err
		}
		tmp.Close()
		site = tmp.Name()
	}

	c := exec.Command(diffBin, "-u", "--label", "local/"+ch.Path, "--label", "site/"+ch.Path, local, site)
	c.Stdout = out
	c.Stderr = out
	// diff exits 1 when the files differ; only >1 is a failure.
	if err := c.Run(); err != nil {
		var ee *exec.ExitError
		if !errors.As(err, &ee) || ee.ExitCode() > 1 {
			return fmt.Errorf("diff %s: %w", ch.Path, err)
		}
	}
	return nil
}

func init() {
	for _, c := range []*cobra.Command{pullCmd, statusCmd, diffCmd} {
		c.Flags().StringVar(&pullOut, "out", "", "local theme directory (default: current dir)")
	}
	pullCmd.Flags().BoolVar(&pullAll, "all", false, "apply every change without asking, merging files changed on both sides")
	pullCmd.Flags().BoolVar(&pullForce, "force", false, "make local identical to the site, discarding local edits")
	pullCmd.Flags().BoolVar(&pullTheirs, "theirs", false, "for files changed on both sides, take the site's version instead of merging")
	pullCmd.Flags().BoolVar(&pullKeep, "keep-local", false, "resolve the named conflicts in favour of the local copy")
	pullCmd.Flags().BoolVar(&pullDryRun, "dry-run", false, "list the changes without applying any")

	themeCmd.AddCommand(pullCmd, statusCmd, diffCmd)
}
