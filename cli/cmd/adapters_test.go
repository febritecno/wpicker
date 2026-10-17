package cmd

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func resetConnect() {
	connectAgents, connectProject, connectGlobal, connectForce, connectRefresh, connectDir = nil, false, false, false, false, ""
}

func put(t *testing.T, path, content string) {
	t.Helper()
	mkdir(t, filepath.Dir(path))
	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		t.Fatal(err)
	}
}

func adapterFile(t *testing.T, home, name, body string) {
	t.Helper()
	put(t, filepath.Join(home, ".wpicker", "adapters", name), body)
}

func runConnect(t *testing.T, work string, args ...string) (string, error) {
	t.Helper()
	connectDir = work
	var err error
	out := capture(t, func() { err = connectCmd.RunE(connectCmd, args) })
	return out, err
}

func exists(p string) bool { _, err := os.Lstat(p); return err == nil }

// --- default is global ---------------------------------------------------------------------

func TestConnectWithNoArgumentsGoesGlobalToWhatIsInstalled(t *testing.T) {
	home, work := fresh(t)
	resetConnect()
	mkdir(t, filepath.Join(home, ".claude"))
	mkdir(t, filepath.Join(home, ".config", "opencode"))

	out, err := runConnect(t, work)
	if err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if !exists(filepath.Join(home, ".claude", "skills", "wpicker", "SKILL.md")) {
		t.Errorf("Claude Code skill missing:\n%s", out)
	}
	if !exists(filepath.Join(home, ".config", "opencode", "AGENTS.md")) {
		t.Errorf("OpenCode global file missing:\n%s", out)
	}
	if got := files(t, work); len(got) != 0 {
		t.Fatalf("a global connect wrote into the project: %v", got)
	}
	if exists(filepath.Join(home, ".codex")) || exists(filepath.Join(home, ".gemini")) {
		t.Fatal("it created settings for agents that are not installed")
	}
}

func TestConnectWithNothingInstalledWritesNothing(t *testing.T) {
	home, work := fresh(t)
	resetConnect()
	out, err := runConnect(t, work)
	if err != nil {
		t.Fatal(err)
	}
	if got := append(files(t, home), files(t, work)...); len(got) != 0 {
		t.Fatalf("created files: %v", got)
	}
	if !strings.Contains(out, "wpicker connect claude") {
		t.Fatalf("it should say how to name one:\n%s", out)
	}
}

func TestPerProjectToolsAreMentionedNotWrittenUnlessNamed(t *testing.T) {
	home, work := fresh(t)
	resetConnect()
	mkdir(t, filepath.Join(home, ".claude"))
	mkdir(t, filepath.Join(work, ".cursor"))
	out, _ := runConnect(t, work)
	if !strings.Contains(out, "wpicker connect cursor") {
		t.Fatalf("it should point at the tool found in the project:\n%s", out)
	}
	if exists(filepath.Join(work, ".cursor", "rules", "wpicker.mdc")) {
		t.Fatal("a project file was written without being asked")
	}
}

func TestNamingAnAgentWithNoGlobalSettingWritesTheProject(t *testing.T) {
	home, work := fresh(t)
	resetConnect()
	if _, err := runConnect(t, work, "cursor"); err != nil {
		t.Fatal(err)
	}
	if !exists(filepath.Join(work, ".cursor", "rules", "wpicker.mdc")) {
		t.Fatal("project rule missing")
	}
	if got := files(t, home); len(got) != 0 && !(len(got) == 1 && strings.HasPrefix(got[0], "work")) {
		for _, f := range got {
			if !strings.HasPrefix(f, "work") {
				t.Fatalf("Cursor has no global setting, yet the home directory changed: %v", got)
			}
		}
	}
}

func TestProjectFlagWritesTheProjectInsteadOfGlobal(t *testing.T) {
	home, work := fresh(t)
	resetConnect()
	connectProject = true
	if _, err := runConnect(t, work, "claude"); err != nil {
		t.Fatal(err)
	}
	if !exists(filepath.Join(work, ".claude", "skills", "wpicker", "SKILL.md")) {
		t.Fatal("project skill missing")
	}
	if exists(filepath.Join(home, ".claude")) {
		t.Fatal("--project also wrote globally")
	}
}

func TestPositionalNamesAndTheAgentFlagAreTheSame(t *testing.T) {
	home, work := fresh(t)
	resetConnect()
	connectAgents = []string{"claude"}
	if _, err := runConnect(t, work, "opencode,codex"); err != nil {
		t.Fatal(err)
	}
	for _, p := range []string{".claude/skills/wpicker/SKILL.md", ".config/opencode/AGENTS.md", ".codex/AGENTS.md"} {
		if !exists(filepath.Join(home, p)) {
			t.Errorf("missing ~/%s", p)
		}
	}
}

func TestTheOldGlobalFlagStillWorks(t *testing.T) {
	home, work := fresh(t)
	resetConnect()
	connectGlobal = true // what install.sh used to pass
	mkdir(t, filepath.Join(home, ".claude"))
	if _, err := runConnect(t, work); err != nil {
		t.Fatal(err)
	}
	if !exists(filepath.Join(home, ".claude", "skills", "wpicker", "SKILL.md")) {
		t.Fatal("--global is accepted and means the default")
	}
}

func TestUnknownAdapterListsTheChoices(t *testing.T) {
	_, work := fresh(t)
	resetConnect()
	_, err := runConnect(t, work, "vim")
	if err == nil || !strings.Contains(err.Error(), "opencode") || !strings.Contains(err.Error(), "wpicker connect list") {
		t.Fatalf("%v", err)
	}
}

func TestRefreshStandsAlone(t *testing.T) {
	_, work := fresh(t)
	resetConnect()
	connectRefresh = true
	if _, err := runConnect(t, work, "claude"); err == nil {
		t.Fatal("--refresh with names must be refused")
	}
}

// --- your own adapters ---------------------------------------------------------------------

func TestACustomAdapterIsConnectedLikeABuiltInOne(t *testing.T) {
	home, work := fresh(t)
	resetConnect()
	adapterFile(t, home, "mytool.json", `{"key":"mytool","name":"My Tool","global":".mytool/notes.md","project":"MYTOOL.md","mode":"block","detect":["~/.mytool"]}`)

	custom, problems := loadAdapters()
	if len(custom) != 1 || len(problems) != 0 || !custom[0].custom {
		t.Fatalf("%v %v", custom, problems)
	}
	put(t, filepath.Join(home, ".mytool", "notes.md"), "# my notes\n")
	if _, err := runConnect(t, work, "mytool"); err != nil {
		t.Fatal(err)
	}
	b, _ := os.ReadFile(filepath.Join(home, ".mytool", "notes.md"))
	if !strings.HasPrefix(string(b), "# my notes\n") || !strings.Contains(string(b), blockBegin) || !strings.Contains(string(b), "wpicker guide") {
		t.Fatalf("the block was not added next to the user's text:\n%s", b)
	}
	// ...and it is found by detection with no arguments.
	resetConnect()
	os.Remove(filepath.Join(home, ".mytool", "notes.md"))
	put(t, filepath.Join(home, ".mytool", "notes.md"), "# fresh\n")
	if out, _ := runConnect(t, work); !strings.Contains(out, "My Tool") {
		t.Fatalf("not detected:\n%s", out)
	}
}

func TestBadAdapterFilesAreRefusedWithAReason(t *testing.T) {
	home, _ := fresh(t)
	bad := map[string]string{
		"traversal.json":  `{"key":"trav","name":"T","global":"../../etc/passwd"}`,
		"absolute.json":   `{"key":"abs","name":"A","project":"/etc/cron.d/x"}`,
		"tilde.json":      `{"key":"tilde","name":"T","global":"~/x"}`,
		"unknown.json":    `{"key":"unk","name":"U","project":"U.md","modee":"block"}`,
		"builtin.json":    `{"key":"claude","name":"Fake Claude","project":"X.md"}`,
		"reserved.json":   `{"key":"list","name":"L","project":"L.md"}`,
		"badkey.json":     `{"key":"Bad Key!","name":"B","project":"B.md"}`,
		"nopath.json":     `{"key":"nopath","name":"N"}`,
		"blockfm.json":    `{"key":"blockfm","name":"B","project":"B.md","mode":"block","frontmatter":"skill"}`,
		"badmode.json":    `{"key":"badmode","name":"B","project":"B.md","mode":"exec"}`,
		"badcontent.json": `{"key":"badcont","name":"B","project":"B.md","content":"everything"}`,
		"baddetect.json":  `{"key":"baddet","name":"B","project":"B.md","detect":["../../x"]}`,
		"notjson.json":    `{broken`,
		"huge.json":       `{"key":"huge","name":"H","project":"H.md","pad":"` + strings.Repeat("x", 20000) + `"}`,
	}
	for name, body := range bad {
		adapterFile(t, home, name, body)
	}
	adapterFile(t, home, "good.json", `{"key":"good","name":"Good","project":"GOOD.md","mode":"block"}`)
	adapterFile(t, home, "readme.txt", "not an adapter")

	ok, problems := loadAdapters()
	if len(ok) != 1 || ok[0].key != "good" {
		t.Fatalf("exactly the good one should load (and one broken file must not hide it): %v", ok)
	}
	if len(problems) != len(bad) {
		t.Fatalf("want %d problems, got %d:\n%s", len(bad), len(problems), strings.Join(problems, "\n"))
	}
	for _, p := range problems {
		if !strings.Contains(p, ".json:") {
			t.Errorf("a problem must name its file: %q", p)
		}
	}
	if findTarget("claude").custom {
		t.Fatal("a custom file replaced a built-in adapter")
	}
}

func TestTwoAdaptersWithTheSameKeyKeepTheFirst(t *testing.T) {
	home, _ := fresh(t)
	adapterFile(t, home, "a.json", `{"key":"dup","name":"First","project":"A.md"}`)
	adapterFile(t, home, "b.json", `{"key":"dup","name":"Second","project":"B.md"}`)
	ok, problems := loadAdapters()
	if len(ok) != 1 || ok[0].name != "First" || len(problems) != 1 {
		t.Fatalf("%v %v", ok, problems)
	}
}

func TestAdaptersAreNeverReadFromAProject(t *testing.T) {
	_, work := fresh(t)
	put(t, filepath.Join(work, ".wpicker", "adapters", "evil.json"), `{"key":"evil","name":"Evil","project":"EVIL.md"}`)
	if findTarget("evil") != nil {
		t.Fatal("a cloned repository registered an adapter")
	}
}

func TestAnAdapterSymlinkIsNotFollowed(t *testing.T) {
	home, _ := fresh(t)
	outside := filepath.Join(t.TempDir(), "x.json")
	os.WriteFile(outside, []byte(`{"key":"linked","name":"L","project":"L.md"}`), 0o644)
	mkdir(t, filepath.Join(home, ".wpicker", "adapters"))
	os.Symlink(outside, filepath.Join(home, ".wpicker", "adapters", "linked.json"))
	ok, problems := loadAdapters()
	if len(ok) != 0 || len(problems) != 1 {
		t.Fatalf("%v %v", ok, problems)
	}
}

func TestCustomFileAdaptersCarryTheMarkerAndValidFrontmatter(t *testing.T) {
	home, work := fresh(t)
	resetConnect()
	adapterFile(t, home, "skillish.json", `{"key":"skillish","name":"Skillish","global":".skillish/wpicker.md","mode":"file","content":"guide","frontmatter":"skill"}`)
	if _, err := runConnect(t, work, "skillish"); err != nil {
		t.Fatal(err)
	}
	b, _ := os.ReadFile(filepath.Join(home, ".skillish", "wpicker.md"))
	if !strings.HasPrefix(string(b), "---\nname: wpicker\ndescription: ") || !hasManagedMarker(string(b)) || !strings.Contains(string(b), "Diagnose first") {
		t.Fatalf("%.200s", b)
	}
	// so refresh and remove can find it later
	if !ownedByWPicker(filepath.Join(home, ".skillish", "wpicker.md"), false) {
		t.Fatal("not recognised as ours")
	}
}

// --- list and remove -----------------------------------------------------------------------

func TestListShowsCurrentStaleAndYours(t *testing.T) {
	home, work := fresh(t)
	resetConnect()
	mkdir(t, filepath.Join(home, ".claude"))
	runConnect(t, work, "claude")
	put(t, filepath.Join(work, ".cursor", "rules", "wpicker.mdc"), "my own rule")

	flagJSON = true
	connectDir = work
	out := capture(t, func() { connectListCmd.RunE(connectListCmd, nil) })
	var doc struct {
		Adapters []adapterRow `json:"adapters"`
	}
	if err := json.Unmarshal([]byte(out), &doc); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	by := map[string]adapterRow{}
	for _, r := range doc.Adapters {
		by[r.Key] = r
	}
	if by["claude"].Global != "current" || !by["claude"].Detected {
		t.Errorf("claude: %+v", by["claude"])
	}
	if by["cursor"].Project != "yours (not touched)" || by["cursor"].Global != "n/a" {
		t.Errorf("cursor: %+v", by["cursor"])
	}
	if by["opencode"].Global != "-" {
		t.Errorf("opencode: %+v", by["opencode"])
	}

	// An older WPicker wrote the Claude file.
	p := filepath.Join(home, ".claude", "skills", "wpicker", "SKILL.md")
	b, _ := os.ReadFile(p)
	os.WriteFile(p, []byte(strings.Replace(string(b), "wpicker-generated v"+Version, "wpicker-generated v0.0.1", 1)), 0o644)
	flagJSON = false
	text := capture(t, func() { connectListCmd.RunE(connectListCmd, nil) })
	if !strings.Contains(text, "stale (v0.0.1)") {
		t.Fatalf("a stale file should say so:\n%s", text)
	}
}

func TestListReportsIgnoredAdapterFiles(t *testing.T) {
	home, work := fresh(t)
	adapterFile(t, home, "oops.json", `{"key":"oops"}`)
	connectDir = work
	out := capture(t, func() { connectListCmd.RunE(connectListCmd, nil) })
	if !strings.Contains(out, "ignored adapter file oops.json") {
		t.Fatalf("%s", out)
	}
}

func TestBlockRoundTripRestoresTheFileExactly(t *testing.T) {
	for name, original := range map[string]string{
		"ends with newline":  "# Mine\n\nUse tabs.\n",
		"empty file":         "",
		"block between text": "# Top\n\n<BLOCK>\n\n# Bottom\n",
	} {
		path := filepath.Join(t.TempDir(), "AGENTS.md")
		seed := original
		if strings.Contains(original, "<BLOCK>") {
			seed = strings.Replace(original, "<BLOCK>\n", blockBegin+" v1 -->\nbody\n"+blockEnd+"\n", 1)
		}
		os.WriteFile(path, []byte(seed), 0o644)
		if !strings.Contains(original, "<BLOCK>") {
			if _, err := upsertBlock(path, "body"); err != nil {
				t.Fatal(err)
			}
		}
		st, err := removeBlock(path)
		if err != nil {
			t.Fatalf("%s: %v", name, err)
		}
		got, rerr := os.ReadFile(path)
		want := strings.Replace(original, "<BLOCK>\n\n", "", 1)
		if original == "" {
			if rerr == nil || !strings.Contains(st, "only our block") {
				t.Errorf("%s: a file that held only our block should be deleted: %q %v", name, st, rerr)
			}
			continue
		}
		if strings.Contains(original, "<BLOCK>") {
			want = "# Top\n\n# Bottom\n"
		}
		if string(got) != want {
			t.Errorf("%s: not restored.\nwant %q\ngot  %q", name, want, got)
		}
	}
}

func TestRemoveTakesOutOnlyWhatWPickerWrote(t *testing.T) {
	home, work := fresh(t)
	resetConnect()
	mkdir(t, filepath.Join(home, ".claude"))
	put(t, filepath.Join(home, ".codex", "AGENTS.md"), "# My Codex notes\n")
	runConnect(t, work, "claude", "codex")
	put(t, filepath.Join(work, ".cursor", "rules", "wpicker.mdc"), "my own rule")

	connectDir = work
	out := capture(t, func() {
		if err := connectRemoveCmd.RunE(connectRemoveCmd, []string{"claude,codex,cursor"}); err != nil {
			t.Error(err)
		}
	})
	if exists(filepath.Join(home, ".claude", "skills", "wpicker", "SKILL.md")) || exists(filepath.Join(home, ".claude", "skills", "wpicker")) {
		t.Errorf("skill (and its now-empty folder) should be gone:\n%s", out)
	}
	if !exists(filepath.Join(home, ".claude")) {
		t.Error("the user's own ~/.claude folder must stay")
	}
	if b, _ := os.ReadFile(filepath.Join(home, ".codex", "AGENTS.md")); string(b) != "# My Codex notes\n" {
		t.Errorf("the user's notes did not survive: %q", b)
	}
	if b, _ := os.ReadFile(filepath.Join(work, ".cursor", "rules", "wpicker.mdc")); string(b) != "my own rule" {
		t.Errorf("a file WPicker did not write was removed: %q", b)
	}
	// Running it again is quiet and harmless.
	out2 := capture(t, func() { connectRemoveCmd.RunE(connectRemoveCmd, []string{"claude"}) })
	if !strings.Contains(out2, "Nothing of WPicker's found") {
		t.Errorf("%s", out2)
	}
}

func TestRemoveRefusesUnknownAdaptersAndNeverFollowsALinkOut(t *testing.T) {
	home, work := fresh(t)
	resetConnect()
	connectDir = work
	if err := connectRemoveCmd.RunE(connectRemoveCmd, []string{"vim"}); err == nil {
		t.Fatal("unknown adapter")
	}
	outside := filepath.Join(t.TempDir(), "keep.md")
	os.WriteFile(outside, []byte(blockBegin+" v1 -->\nx\n"+blockEnd+"\nprecious\n"), 0o644)
	os.Symlink(outside, filepath.Join(work, "AGENTS.md"))
	_ = home
	capture(t, func() { connectRemoveCmd.RunE(connectRemoveCmd, []string{"codex"}) })
	if b, _ := os.ReadFile(outside); !strings.Contains(string(b), "precious") || !strings.Contains(string(b), blockBegin) {
		t.Fatalf("remove reached through a symlink: %q", b)
	}
}
