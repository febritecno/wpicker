package cmd

import (
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"wpicker/internal/client"
)

func TestConfigPermissions(t *testing.T) {
	if c := configPermsCheck(0o600, "/x/sites.json"); c.Status != stOK || !strings.Contains(c.Title, "sites.json") {
		t.Fatalf("0600 should be fine: %+v", c)
	}
	c := configPermsCheck(0o644, "/x/config.json")
	if c.Status != stWarn || !strings.Contains(c.Fix, "chmod 600 /x/config.json") {
		t.Fatalf("0644 must warn with the exact fix: %+v", c)
	}
}

func TestSchemeCheck(t *testing.T) {
	cases := map[string]string{
		"https://example.com":   stOK,
		"http://example.com":    stWarn,
		"http://wpicker.local":  stInfo,
		"http://localhost:8080": stInfo,
		"http://127.0.0.1":      stInfo,
		"not a url":             stFail,
		"":                      stFail,
	}
	for site, want := range cases {
		if got := schemeCheck(site).Status; got != want {
			t.Errorf("%q: got %s, want %s", site, got, want)
		}
	}
}

func TestVersionCheck(t *testing.T) {
	if versionCheck("1.1.0", "1.1.0").Status != stOK {
		t.Fatal("equal versions")
	}
	if c := versionCheck("1.1.0", "1.0.0"); c.Status != stWarn || c.Fix == "" {
		t.Fatalf("mismatch: %+v", c)
	}
	if versionCheck("1.1.0", "").Status != stInfo {
		t.Fatal("unknown plugin version must not be a warning")
	}
}

func TestBinaryCheck(t *testing.T) {
	dir := t.TempDir()
	a, b := filepath.Join(dir, "a"), filepath.Join(dir, "b")
	os.WriteFile(a, nil, 0o755)
	os.WriteFile(b, nil, 0o755)
	if binaryCheck(a, a).Status != stOK {
		t.Fatal("same binary")
	}
	if binaryCheck(a, "").Status != stWarn {
		t.Fatal("not on PATH must warn: agents call it by name")
	}
	if c := binaryCheck(a, b); c.Status != stWarn || !strings.Contains(c.Detail, b) {
		t.Fatalf("two copies: %+v", c)
	}
	link := filepath.Join(dir, "link")
	os.Symlink(a, link)
	if binaryCheck(a, link).Status != stOK {
		t.Fatal("a symlink to the same binary is not a second copy")
	}
}

func TestEveryConnectionFailureSaysWhatToDo(t *testing.T) {
	rest := func(code, msg string) error {
		return &client.RESTError{Details: client.ErrorDetail{Code: code, Message: msg}}
	}
	cases := []struct {
		name string
		err  error
		want string
	}{
		{"revoked", rest("http_401", "x"), "rejected the saved login"},
		{"forbidden", rest("rest_forbidden", "no"), "may not manage"},
		{"plugin off", rest("rest_no_route", "no route"), "did not answer"},
		{"plain 404", errors.New("http 404: <html>"), "did not answer"},
		{"down", errors.New("request GET /doctor: dial tcp: lookup nope: no such host"), "Cannot reach"},
		{"refused", errors.New("connect: connection refused"), "Cannot reach"},
		{"cert", errors.New("tls: failed to verify certificate: x509: certificate has expired"), "certificate"},
		{"500", errors.New("http 502: bad gateway"), "server error"},
		{"other", errors.New("something odd"), "Could not get an answer"},
	}
	for _, c := range cases {
		got := classifyConnectError(c.err, "https://s.example")
		if got.Status != stFail || !strings.Contains(got.Title, c.want) || got.Fix == "" {
			t.Errorf("%s: %+v", c.name, got)
		}
	}
}

func TestAgentChecks(t *testing.T) {
	dir, home := t.TempDir(), t.TempDir()

	got := agentChecks(dir, home)
	if len(got) != 1 || got[0].Status != stInfo || !strings.Contains(got[0].Fix, "wpicker connect") {
		t.Fatalf("nothing installed: %+v", got)
	}

	applyTargets([]*agentTarget{findTarget("claude")}, dir, home, false, false)
	got = agentChecks(dir, home)
	if len(got) != 1 || got[0].Status != stOK {
		t.Fatalf("freshly written file must be current: %+v", got)
	}

	// An older WPicker wrote it.
	p := filepath.Join(dir, ".claude/skills/wpicker/SKILL.md")
	b, _ := os.ReadFile(p)
	os.WriteFile(p, []byte(strings.Replace(string(b), "wpicker-generated v"+Version, "wpicker-generated v0.0.1", 1)), 0o644)
	got = agentChecks(dir, home)
	if got[0].Status != stWarn || !strings.Contains(got[0].Fix, "connect --refresh") {
		t.Fatalf("stale file must warn: %+v", got[0])
	}

	// A file the user wrote is none of doctor's business.
	os.WriteFile(filepath.Join(dir, "GEMINI.md"), []byte("mine"), 0o644)
	for _, c := range agentChecks(dir, home) {
		if c.ID == "agent_gemini" {
			t.Fatalf("reported on a file WPicker did not write: %+v", c)
		}
	}

	// A refreshed file is current again, and doctor changed nothing on disk.
	before, _ := os.ReadFile(p)
	refreshManaged(dir, home)
	agentChecks(dir, home)
	after, _ := os.ReadFile(p)
	if string(before) == string(after) {
		t.Fatal("refresh should have updated the stale file")
	}
	if agentChecks(dir, home)[0].Status != stOK {
		t.Fatal("not current after refresh")
	}
}

func TestDoctorCheckingDoesNotWrite(t *testing.T) {
	dir, home := t.TempDir(), t.TempDir()
	agentChecks(dir, home)
	if got := append(files(t, dir), files(t, home)...); len(got) != 0 {
		t.Fatalf("an audit created files: %v", got)
	}
}

func TestSummarizeAndGroupOrder(t *testing.T) {
	s := summarize([]check{{Status: stOK}, {Status: stOK}, {Status: stWarn}, {Status: stFail}})
	if s[stOK] != 2 || s[stWarn] != 1 || s[stFail] != 1 {
		t.Fatalf("%v", s)
	}
	seen := map[string]bool{}
	for _, g := range groupOrder {
		if seen[g] {
			t.Fatalf("duplicate group %q", g)
		}
		seen[g] = true
	}
	// Every group the plugin can send must be one the CLI knows how to place.
	for _, g := range []string{"Site", "Storage", "Pushing code", "Backups", "Access"} {
		if !seen[g] {
			t.Errorf("plugin group %q is not in groupOrder", g)
		}
	}
}
