package cmd

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func files(t *testing.T, root string) []string {
	t.Helper()
	var out []string
	filepath.Walk(root, func(p string, i os.FileInfo, err error) error {
		if err == nil && !i.IsDir() {
			rel, _ := filepath.Rel(root, p)
			out = append(out, rel)
		}
		return nil
	})
	return out
}

func TestRefreshCreatesNothing(t *testing.T) {
	dir, home := t.TempDir(), t.TempDir()
	if n := refreshManaged(dir, home); n != 0 {
		t.Fatalf("refreshed %d files in an empty project", n)
	}
	if got := append(files(t, dir), files(t, home)...); len(got) != 0 {
		t.Fatalf("refresh created files: %v", got)
	}
}

func TestRefreshUpdatesAStaleFileWPickerWrote(t *testing.T) {
	dir, home := t.TempDir(), t.TempDir()
	skill := filepath.Join(dir, ".claude", "skills", "wpicker", "SKILL.md")
	os.MkdirAll(filepath.Dir(skill), 0o755)
	os.WriteFile(skill, []byte("---\nname: wpicker\n---\n"+markerPrefix+" v0.0.1 -->\nold guide\n"), 0o644)

	if n := refreshManaged(dir, home); n != 1 {
		t.Fatalf("refreshed %d, want 1", n)
	}
	b, _ := os.ReadFile(skill)
	if strings.Contains(string(b), "old guide") || !strings.Contains(string(b), "v"+Version) || !strings.Contains(string(b), "wpicker logs") {
		t.Fatalf("not refreshed:\n%s", b)
	}
	if n := refreshManaged(dir, home); n != 0 {
		t.Fatalf("second refresh changed %d files; it must be idempotent", n)
	}
}

func TestRefreshNeverTouchesAFileTheUserWrote(t *testing.T) {
	dir, home := t.TempDir(), t.TempDir()
	mine := filepath.Join(dir, ".cursor", "rules", "wpicker.mdc")
	os.MkdirAll(filepath.Dir(mine), 0o755)
	os.WriteFile(mine, []byte("my rule"), 0o644)
	refreshManaged(dir, home)
	if b, _ := os.ReadFile(mine); string(b) != "my rule" {
		t.Fatalf("user file changed: %q", b)
	}
}

func TestRefreshOnlyRewritesOurBlockInASharedFile(t *testing.T) {
	dir, home := t.TempDir(), t.TempDir()
	path := filepath.Join(dir, "AGENTS.md")
	os.WriteFile(path, []byte("# Mine\nkeep\n\n"+blockBegin+" v0.0.1 -->\nold\n"+blockEnd+"\n\nafter\n"), 0o644)
	refreshManaged(dir, home)
	b, _ := os.ReadFile(path)
	s := string(b)
	if !strings.Contains(s, "# Mine\nkeep") || !strings.Contains(s, "after") || strings.Contains(s, "\nold\n") || !strings.Contains(s, "wpicker guide") {
		t.Fatalf("shared file mishandled:\n%s", s)
	}
	// A shared file with no block of ours is left alone entirely.
	other := filepath.Join(dir, "GEMINI.md")
	os.WriteFile(other, []byte("just mine\n"), 0o644)
	refreshManaged(dir, home)
	if b, _ := os.ReadFile(other); string(b) != "just mine\n" {
		t.Fatalf("a file with no WPicker block was modified: %q", b)
	}
}

func TestLoginNeverWritesIntoTheProjectItself(t *testing.T) {
	dir, home := t.TempDir(), t.TempDir()
	os.MkdirAll(filepath.Join(dir, ".cursor"), 0o755)
	os.WriteFile(filepath.Join(dir, "AGENTS.md"), []byte("# theirs\n"), 0o644)

	connectAfterLogin(dir, home) // stdin is not a terminal under go test: it may only print a hint

	if b, _ := os.ReadFile(filepath.Join(dir, "AGENTS.md")); string(b) != "# theirs\n" {
		t.Fatalf("login edited a tracked file: %q", b)
	}
	got := files(t, dir)
	if len(got) != 1 || got[0] != "AGENTS.md" {
		t.Fatalf("login created files in the project: %v", got)
	}
	if got := files(t, home); len(got) != 0 {
		t.Fatalf("login wrote into the home directory: %v", got)
	}
}

func TestUnconfiguredHereCountsProjectFootprintsOnly(t *testing.T) {
	dir, home := t.TempDir(), t.TempDir()
	if got := unconfiguredHere(dir, home); len(got) != 0 {
		t.Fatalf("empty project: %d", len(got))
	}
	os.MkdirAll(filepath.Join(home, ".claude"), 0o755) // installed for the user, not a Claude Code project
	if got := unconfiguredHere(dir, home); len(got) != 0 {
		t.Fatalf("a home-only footprint counted as a project: %d", len(got))
	}
	os.MkdirAll(filepath.Join(dir, ".cursor"), 0o755)
	got := unconfiguredHere(dir, home)
	if len(got) != 1 || got[0].key != "cursor" {
		t.Fatalf("want cursor only, got %v", got)
	}
	// Once WPicker wrote its file there, it is no longer "unconfigured".
	applyTargets(got, dir, home, false, false)
	if got := unconfiguredHere(dir, home); len(got) != 0 {
		t.Fatalf("still unconfigured after connecting: %d", len(got))
	}
}

func TestNothingIsWrittenThroughASymlinkThatLeavesTheProject(t *testing.T) {
	dir, home, outside := t.TempDir(), t.TempDir(), t.TempDir()
	rc := filepath.Join(outside, ".zshrc")
	os.WriteFile(rc, []byte("export A=1\n"), 0o644)

	// AGENTS.md -> a file outside the project (what a hostile clone could ship).
	os.Symlink(rc, filepath.Join(dir, "AGENTS.md"))
	codex := findTarget("codex")
	if _, failed := applyTargets([]*agentTarget{codex}, dir, home, false, false); failed != 1 {
		t.Fatal("a symlink leading out of the project must be refused")
	}
	if b, _ := os.ReadFile(rc); string(b) != "export A=1\n" {
		t.Fatalf("the file behind the link was modified: %q", b)
	}

	// .github -> a directory outside the project: the parent is the link.
	os.Symlink(outside, filepath.Join(dir, ".github"))
	if _, failed := applyTargets([]*agentTarget{findTarget("copilot")}, dir, home, false, false); failed != 1 {
		t.Fatal("a linked parent directory leading out must be refused")
	}
	if got := files(t, outside); len(got) != 1 {
		t.Fatalf("something was created outside the project: %v", got)
	}

	// The same for refresh: a link that points out is never followed.
	refreshManaged(dir, home)
	if b, _ := os.ReadFile(rc); string(b) != "export A=1\n" {
		t.Fatal("refresh wrote through the link")
	}
}

func TestASymlinkInsideTheProjectStillWorks(t *testing.T) {
	dir, home := t.TempDir(), t.TempDir()
	real := filepath.Join(dir, "CLAUDE.md")
	os.WriteFile(real, []byte("# shared notes\n"), 0o644)
	os.Symlink(real, filepath.Join(dir, "AGENTS.md"))
	if _, failed := applyTargets([]*agentTarget{findTarget("codex")}, dir, home, false, false); failed != 0 {
		t.Fatal("AGENTS.md -> CLAUDE.md inside the repo is common and must keep working")
	}
	if b, _ := os.ReadFile(real); !strings.Contains(string(b), "# shared notes") || !strings.Contains(string(b), blockBegin) {
		t.Fatalf("block not added to the real file:\n%s", b)
	}
	if st, _ := os.Lstat(filepath.Join(dir, "AGENTS.md")); st.Mode()&os.ModeSymlink == 0 {
		t.Fatal("the symlink itself was replaced by a regular file")
	}
}

func TestMarkersMentionedInProseAreNotOurs(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "AGENTS.md")
	doc := "# Docs\n\nWPicker adds `" + blockBegin + " v1 -->` and `" + blockEnd + "` around its text.\n\n```\n" + blockBegin + " v1 -->\n```\n"
	os.WriteFile(path, []byte(doc), 0o644)
	if ownedByWPicker(path, true) {
		t.Fatal("prose mentioning the marker was taken for a WPicker block")
	}
	if _, err := upsertBlock(path, "body"); err != nil {
		t.Fatal(err)
	}
	b, _ := os.ReadFile(path)
	if !strings.HasPrefix(string(b), doc) {
		t.Fatalf("the user's text was not preserved verbatim:\n%s", b)
	}
}

func TestTwoBlocksOrBlockWithoutEndAreRefusedNotGuessedAt(t *testing.T) {
	path := filepath.Join(t.TempDir(), "AGENTS.md")
	two := blockBegin + " v1 -->\na\n" + blockEnd + "\nmid\n" + blockBegin + " v1 -->\nb\n" + blockEnd + "\n"
	os.WriteFile(path, []byte(two), 0o644)
	if _, err := upsertBlock(path, "x"); err == nil {
		t.Fatal("two blocks must be refused")
	}
	if b, _ := os.ReadFile(path); string(b) != two {
		t.Fatal("file modified despite the refusal")
	}
}

func TestCRLFFilesKeepTheirLineEndings(t *testing.T) {
	path := filepath.Join(t.TempDir(), "AGENTS.md")
	os.WriteFile(path, []byte("# Mine\r\n\r\n"+blockBegin+" v1 -->\r\nold\r\n"+blockEnd+"\r\nafter\r\n"), 0o644)
	if _, err := upsertBlock(path, "line one\nline two"); err != nil {
		t.Fatal(err)
	}
	b, _ := os.ReadFile(path)
	if strings.Contains(strings.ReplaceAll(string(b), "\r\n", ""), "\n") {
		t.Fatalf("mixed line endings after refresh:\n%q", b)
	}
	if !strings.Contains(string(b), "after") {
		t.Fatal("text after the block lost")
	}
}

func TestOnlyATopLineMarkerMakesADedicatedFileOurs(t *testing.T) {
	if !hasManagedMarker("---\nname: x\n---\n" + markerPrefix + " v1 -->\n") {
		t.Fatal("a real marker line was not recognised")
	}
	if hasManagedMarker("my notes about `" + markerPrefix + "` in prose\n") {
		t.Fatal("a marker mentioned mid-line must not count")
	}
	if hasManagedMarker(strings.Repeat("x\n", 2000) + markerPrefix + " v1 -->\n") {
		t.Fatal("a marker deep in a long file must not count")
	}
}

func TestWritesKeepPermissionsAndLeaveNoTempFiles(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "AGENTS.md")
	os.WriteFile(path, []byte("# mine\n"), 0o600)
	if _, err := upsertBlock(path, "body"); err != nil {
		t.Fatal(err)
	}
	if st, _ := os.Stat(path); st.Mode().Perm() != 0o600 {
		t.Fatalf("permissions changed to %v", st.Mode().Perm())
	}
	for _, f := range files(t, dir) {
		if strings.HasSuffix(f, ".tmp") {
			t.Fatalf("temp file left behind: %s", f)
		}
	}
}

func TestGlobalStubDoesNotClaimTheProjectIsWordPress(t *testing.T) {
	g, p := block("", true), block("", false)
	if strings.Contains(g, "This project") || !strings.Contains(g, "wpicker guide") || !strings.Contains(g, "Otherwise ignore") {
		t.Fatalf("global stub is wrong:\n%s", g)
	}
	if !strings.Contains(p, "This project") {
		t.Fatal("project stub lost its wording")
	}
}

func TestSiteHintNeverNamesTheAdminUser(t *testing.T) {
	if strings.Contains(siteHintFor(t.TempDir()), "USER") {
		t.Fatal("these files get committed: the admin username must not be in them")
	}
}

func TestNoInitCommand(t *testing.T) {
	for _, c := range rootCmd.Commands() {
		if c.Name() == "init" {
			t.Fatal("init was removed: login and connect set agents up")
		}
	}
}
