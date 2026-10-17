package cmd

import (
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"testing"
)

// Every `wpicker …` command the guide tells an agent to run must exist, and
// every flag it names must be a real one. A guide that sends the agent to a
// command that is gone is worse than none.
func TestAgentGuideOnlyNamesRealCommands(t *testing.T) {
	spans := regexp.MustCompile("`(wpicker [^`]+)`").FindAllStringSubmatch(agentGuideBody+agentStub, -1)
	if len(spans) < 15 {
		t.Fatalf("expected the guide to name many commands, found %d", len(spans))
	}
	for _, m := range spans {
		fields := strings.Fields(m[1])
		var words []string
		for _, f := range fields[1:] {
			if strings.HasPrefix(f, "-") || strings.HasPrefix(f, "<") || strings.HasPrefix(f, "[") || strings.HasPrefix(f, "\"") {
				break
			}
			words = append(words, f)
		}
		cmd, _, err := rootCmd.Find(words)
		if err != nil || cmd == rootCmd {
			t.Errorf("guide names %q but there is no such command", m[1])
			continue
		}
		for _, f := range fields[1:] {
			if !strings.HasPrefix(f, "--") {
				continue
			}
			name := strings.TrimPrefix(strings.SplitN(f, "=", 2)[0], "--")
			if cmd.Flags().Lookup(name) == nil && cmd.InheritedFlags().Lookup(name) == nil && rootCmd.PersistentFlags().Lookup(name) == nil {
				t.Errorf("guide uses --%s with %q, which has no such flag", name, cmd.CommandPath())
			}
		}
	}
}

func TestClaudeSkillHasFrontmatterFirst(t *testing.T) {
	s := claudeSkill("")
	if !strings.HasPrefix(s, "---\nname: wpicker\ndescription: ") {
		t.Fatalf("SKILL.md must open with frontmatter, got %q", s[:40])
	}
	end := strings.Index(s[4:], "\n---\n")
	if end < 0 {
		t.Fatal("frontmatter is not closed")
	}
	if strings.Contains(s[:end+4], "\n\n") {
		t.Fatal("blank line inside frontmatter")
	}
	// An unquoted YAML value may not hold ": " or " #": the frontmatter would fail to parse.
	if strings.Contains(skillDescription, ": ") || strings.Contains(skillDescription, " #") || strings.ContainsAny(skillDescription[:1], "\"'[{&*!|>%@`") {
		t.Fatalf("skill description is not a safe plain YAML scalar: %q", skillDescription)
	}
	if !strings.Contains(s, markerPrefix) {
		t.Fatal("generated file must carry the marker")
	}
}

func TestWriteManaged(t *testing.T) {
	path := filepath.Join(t.TempDir(), "a", "b", "SKILL.md")

	if st, err := writeManaged(path, claudeSkill(""), false); err != nil || st != "created" {
		t.Fatalf("first write: %q %v", st, err)
	}
	if st, err := writeManaged(path, claudeSkill(""), false); err != nil || st != "unchanged" {
		t.Fatalf("same content: %q %v", st, err)
	}
	if st, err := writeManaged(path, claudeSkill("SITE: x\n"), false); err != nil || st != "updated" {
		t.Fatalf("refresh of a generated file: %q %v", st, err)
	}

	mine := filepath.Join(t.TempDir(), "SKILL.md")
	if err := os.WriteFile(mine, []byte("my own notes"), 0o644); err != nil {
		t.Fatal(err)
	}
	if _, err := writeManaged(mine, claudeSkill(""), false); err == nil {
		t.Fatal("must not replace a file WPicker did not write")
	}
	if b, _ := os.ReadFile(mine); string(b) != "my own notes" {
		t.Fatal("the user's file was changed")
	}
	if st, err := writeManaged(mine, claudeSkill(""), true); err != nil || st != "updated" {
		t.Fatalf("--force: %q %v", st, err)
	}
}

func TestAlwaysOnStubStaysShortAndPointsToTheGuide(t *testing.T) {
	if !strings.Contains(agentStub, "`wpicker guide`") {
		t.Fatal("the stub must tell the agent how to fetch the full guide")
	}
	if !strings.Contains(agentStubGlobal, "`wpicker guide`") || len(agentStubGlobal) > 800 {
		t.Fatal("the global stub must point at the guide and stay short")
	}
	if len(agentStub) > 1200 {
		t.Fatalf("stub is read in every conversation and must stay short, got %d bytes", len(agentStub))
	}
	if len(agentGuideBody) < 3*len(agentStub) {
		t.Fatal("the full guide should be much larger than the stub, or the split is pointless")
	}
}

func TestUpsertBlockKeepsTheUsersText(t *testing.T) {
	path := filepath.Join(t.TempDir(), "AGENTS.md")
	mine := "# My project\n\nUse tabs.\n"
	if err := os.WriteFile(path, []byte(mine), 0o644); err != nil {
		t.Fatal(err)
	}

	if st, err := upsertBlock(path, "first"); err != nil || st != "updated" {
		t.Fatalf("append: %q %v", st, err)
	}
	b, _ := os.ReadFile(path)
	if !strings.HasPrefix(string(b), mine) || !strings.Contains(string(b), "first") {
		t.Fatalf("user text lost or block missing:\n%s", b)
	}

	if st, err := upsertBlock(path, "first"); err != nil || st != "unchanged" {
		t.Fatalf("second identical run: %q %v", st, err)
	}

	if st, err := upsertBlock(path, "second"); err != nil || st != "updated" {
		t.Fatalf("refresh: %q %v", st, err)
	}
	b, _ = os.ReadFile(path)
	if strings.Contains(string(b), "first") || !strings.Contains(string(b), "second") || strings.Count(string(b), blockBegin) != 1 {
		t.Fatalf("block not replaced in place:\n%s", b)
	}
	if !strings.HasPrefix(string(b), mine) {
		t.Fatal("user text changed on refresh")
	}

	// Text the user adds after the block survives a refresh.
	os.WriteFile(path, append(b, []byte("\n## Later\nkeep me\n")...), 0o644)
	upsertBlock(path, "third")
	b, _ = os.ReadFile(path)
	if !strings.Contains(string(b), "keep me") || !strings.Contains(string(b), "third") {
		t.Fatalf("text after the block was lost:\n%s", b)
	}
}

func TestUpsertBlockRefusesABrokenBlock(t *testing.T) {
	path := filepath.Join(t.TempDir(), "AGENTS.md")
	os.WriteFile(path, []byte("x\n"+blockBegin+" v1 -->\nno end\n"), 0o644)
	if _, err := upsertBlock(path, "body"); err == nil {
		t.Fatal("a block with no end marker must not be guessed at")
	}
	if b, _ := os.ReadFile(path); !strings.Contains(string(b), "no end") {
		t.Fatal("file was modified")
	}
}

func TestAgentRegistry(t *testing.T) {
	keys, paths := map[string]bool{}, map[string]bool{}
	for _, a := range agentTargets {
		if keys[a.key] {
			t.Errorf("duplicate agent key %q", a.key)
		}
		keys[a.key] = true
		if a.project == "" || filepath.IsAbs(a.project) || strings.Contains(a.project, "..") {
			t.Errorf("%s: bad project path %q", a.key, a.project)
		}
		if a.global != "" && (filepath.IsAbs(a.global) || strings.Contains(a.global, "..")) {
			t.Errorf("%s: bad global path %q", a.key, a.global)
		}
		if paths[a.project] {
			t.Errorf("two agents write %s", a.project)
		}
		paths[a.project] = true
		out := a.render("", false)
		if a.shared {
			if strings.Contains(out, markerPrefix) {
				t.Errorf("%s: a shared block must not carry the whole-file marker", a.key)
			}
		} else if !strings.Contains(out, markerPrefix) {
			t.Errorf("%s: an owned file must carry the marker, or it can never be refreshed", a.key)
		}
	}
	if findTarget("agents") == nil || findTarget("AGENTS.md") == nil || findTarget("nope") != nil {
		t.Error("alias lookup")
	}
}

func TestFrontmatterOfEveryRuleFormatIsSafeYAML(t *testing.T) {
	for _, s := range []string{claudeSkill(""), cursorRule(""), windsurfRule("")} {
		if !strings.HasPrefix(s, "---\n") {
			t.Fatal("rule must open with frontmatter")
		}
		fm := s[4 : 4+strings.Index(s[4:], "\n---\n")]
		for _, line := range strings.Split(fm, "\n") {
			k, v, ok := strings.Cut(line, ": ")
			if !ok || k == "" {
				t.Errorf("frontmatter line is not 'key: value': %q", line)
			}
			if strings.Contains(v, ": ") || strings.Contains(v, " #") {
				t.Errorf("frontmatter value is not a safe plain scalar: %q", line)
			}
		}
	}
}

func TestDetectAgents(t *testing.T) {
	dir, home := t.TempDir(), t.TempDir()
	if got := detectAgents(dir, home); len(got) != 0 {
		t.Fatalf("empty project: %d found", len(got))
	}
	os.MkdirAll(filepath.Join(dir, ".cursor"), 0o755)
	os.WriteFile(filepath.Join(dir, "GEMINI.md"), nil, 0o644)
	os.MkdirAll(filepath.Join(home, ".claude"), 0o755)
	var keys []string
	for _, a := range detectAgents(dir, home) {
		keys = append(keys, a.key)
	}
	if strings.Join(keys, ",") != "claude,cursor,gemini" {
		t.Fatalf("detected %v", keys)
	}
}
