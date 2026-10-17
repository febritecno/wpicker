package cmd

import (
	"bufio"
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/spf13/cobra"

	"wpicker/internal/auth"
	"wpicker/internal/config"
)

// fresh gives a test an empty home, a clean environment and its own working folder.
func fresh(t *testing.T) (home, work string) {
	t.Helper()
	home = t.TempDir()
	t.Setenv("HOME", home)
	t.Setenv("WPICKER_SITE", "")
	work = filepath.Join(home, "work")
	os.MkdirAll(work, 0o755)
	old, _ := os.Getwd()
	os.Chdir(work)
	flagSite, loadedCfg, curWrites, flagJSON = "", nil, false, false
	t.Cleanup(func() {
		os.Chdir(old)
		flagSite, loadedCfg, curWrites, flagJSON = "", nil, false, false
	})
	return home, work
}

func addSite(t *testing.T, url, name string) config.Site {
	t.Helper()
	var out config.Site
	err := config.Update(func(s *config.Store) error {
		out = *s.Upsert(config.Site{URL: url, Username: "root", AppPassword: "SECRET-" + name, DeviceID: "dev-" + name, DeviceName: "laptop"}, name)
		return nil
	})
	if err != nil {
		t.Fatal(err)
	}
	return out
}

func mkdir(t *testing.T, p string) string {
	t.Helper()
	if err := os.MkdirAll(p, 0o755); err != nil {
		t.Fatal(err)
	}
	return p
}

func TestOneSiteNeedsNoSetup(t *testing.T) {
	_, work := fresh(t)
	addSite(t, "https://a.example", "a")
	r, err := resolveSite(work, false)
	if err != nil || r.Source != srcOnly || r.Site.Name != "a" {
		t.Fatalf("%+v %v", r, err)
	}
	curWrites = true
	if _, err := resolveSite(work, false); err != nil {
		t.Fatalf("with a single site even a write is unambiguous: %v", err)
	}
}

func TestNotLoggedIn(t *testing.T) {
	_, work := fresh(t)
	if _, err := resolveSite(work, false); err == nil || !strings.Contains(err.Error(), "wpicker login") {
		t.Fatalf("%v", err)
	}
}

func TestSeveralSitesAndNoChoiceAsksInsteadOfGuessing(t *testing.T) {
	_, work := fresh(t)
	addSite(t, "https://a.example", "a")
	addSite(t, "https://b.example", "b")
	config.Update(func(s *config.Store) error { s.Default = 0; return nil })

	_, err := resolveSite(work, false)
	if err == nil || !strings.Contains(err.Error(), "several sites") || !strings.Contains(err.Error(), "1 a") || !strings.Contains(err.Error(), "2 b") {
		t.Fatalf("want a list of the sites, got %v", err)
	}
	flagSite = "b"
	if r, err := resolveSite(work, false); err != nil || r.Site.Name != "b" || r.Source != srcFlag {
		t.Fatalf("--site: %+v %v", r, err)
	}
}

func TestADefaultServesReadsButNeverWrites(t *testing.T) {
	_, work := fresh(t)
	addSite(t, "https://a.example", "a")
	addSite(t, "https://b.example", "b") // a is the default (first)

	if r, err := resolveSite(work, false); err != nil || r.Source != srcDefault || r.Site.Name != "a" {
		t.Fatalf("a read may use the default: %+v %v", r, err)
	}
	curWrites = true
	_, err := resolveSite(work, false)
	if err == nil || !strings.Contains(err.Error(), "changes a site") || !strings.Contains(err.Error(), "wpicker sites link") {
		t.Fatalf("a write must not rest on the default: %v", err)
	}
	flagSite = "a"
	if _, err := resolveSite(work, false); err != nil {
		t.Fatalf("an explicit choice is fine: %v", err)
	}
	flagSite = ""
	site, _ := pickSite("a")
	if err := linkFolder(work, site); err != nil {
		t.Fatal(err)
	}
	if r, err := resolveSite(work, false); err != nil || r.Source != srcLink {
		t.Fatalf("a link is fine: %+v %v", r, err)
	}
}

func TestLinkFollowsTheFolderUpwardsExceptForThemeFolders(t *testing.T) {
	_, work := fresh(t)
	addSite(t, "https://a.example", "a")
	b := addSite(t, "https://b.example", "b")
	config.Update(func(s *config.Store) error { s.Default = 1; return nil })
	if err := linkFolder(work, b); err != nil {
		t.Fatal(err)
	}
	sub := mkdir(t, filepath.Join(work, "src", "deep"))

	if r, err := resolveSite(sub, false); err != nil || r.Site.Name != "b" || r.Source != srcLink {
		t.Fatalf("a subfolder uses its parent's link: %+v %v", r, err)
	}
	// A theme working folder is exactly the folder with .wpicker/last-pull.json: a
	// link in a parent says nothing about it.
	if r, err := resolveSite(sub, true); err != nil || r.Source == srcLink {
		t.Fatalf("a theme folder must not inherit a parent's link: %+v %v", r, err)
	}
}

func TestTheHomeDirectoryIsNeverAProject(t *testing.T) {
	home, work := fresh(t)
	addSite(t, "https://a.example", "a")
	b := addSite(t, "https://b.example", "b")
	// wpicker keeps its own files in ~/.wpicker; a project.json there must not apply to every folder under home.
	writeLink(home, b)
	if r, err := resolveSite(work, false); err != nil || r.Source == srcLink {
		t.Fatalf("home was treated as a project: %+v %v", r, err)
	}
}

func TestALinkToASiteYouAreNotLoggedInToNeverBorrowsCredentials(t *testing.T) {
	_, work := fresh(t)
	addSite(t, "https://mine.example", "mine")
	os.MkdirAll(filepath.Join(work, ".wpicker"), 0o755)
	os.WriteFile(filepath.Join(work, ".wpicker", projectFileName), []byte(`{"site":"https://evil.example","name":"mine"}`), 0o644)

	r, err := resolveSite(work, false)
	if err == nil || r != nil {
		t.Fatalf("a link to an unknown site must not resolve: %+v", r)
	}
	msg := err.Error()
	if !strings.Contains(msg, "https://evil.example") || !strings.Contains(msg, "do NOT log in") {
		t.Fatalf("the warning must show the address and say not to log in blindly:\n%s", msg)
	}
	if strings.Contains(msg, "SECRET") {
		t.Fatal("an error message contained a password")
	}
}

func TestLookalikeAddressesAreNotTheSameSite(t *testing.T) {
	_, work := fresh(t)
	addSite(t, "https://example.com", "real")
	for _, fake := range []string{"https://example.com.evil.test", "https://examp1e.com", "https://example.com@evil.test", "https://www.example.com", "https://example.com:8443"} {
		os.MkdirAll(filepath.Join(work, ".wpicker"), 0o755)
		os.WriteFile(filepath.Join(work, ".wpicker", projectFileName), []byte(fmt.Sprintf(`{"site":%q,"name":"real"}`, fake)), 0o644)
		if r, err := resolveSite(work, false); err == nil {
			t.Errorf("%q resolved to %q", fake, r.Site.URL)
		}
	}
	// The same site written differently is the same site.
	for _, same := range []string{"http://EXAMPLE.com/", "https://example.com:443", "example.com"} {
		os.WriteFile(filepath.Join(work, ".wpicker", projectFileName), []byte(fmt.Sprintf(`{"site":%q}`, same)), 0o644)
		if r, err := resolveSite(work, false); err != nil || r.Site.Name != "real" {
			t.Errorf("%q should be the real site: %v", same, err)
		}
	}
}

func TestTheSiteFlagCannotBorrowAnotherSitesPassword(t *testing.T) {
	_, work := fresh(t)
	addSite(t, "https://mine.example", "mine")
	flagSite = "https://attacker.example"
	_, err := resolveSite(work, false)
	if err == nil || !strings.Contains(err.Error(), "must name a site you are logged in to") {
		t.Fatalf("an address you never logged in to must be refused: %v", err)
	}
	flagSite = "http://MINE.example/"
	if r, err := resolveSite(work, false); err != nil || r.Site.Name != "mine" {
		t.Fatalf("a stored site by address still works: %v", err)
	}
	// The password in use always comes from the store.
	_, cfg, err := mustClientAt(work, false)
	if err != nil || cfg.Site != "https://mine.example" {
		t.Fatalf("connected to %q: %v", cfg.Site, err)
	}
}

func TestAnExplicitSiteMayNotContradictAThemeFolder(t *testing.T) {
	_, work := fresh(t)
	a := addSite(t, "https://a.example", "a")
	addSite(t, "https://b.example", "b")
	linkFolder(work, a)

	for name, set := range map[string]func(){
		"--site":        func() { flagSite = "b" },
		"$WPICKER_SITE": func() { t.Setenv("WPICKER_SITE", "b") },
	} {
		flagSite = ""
		t.Setenv("WPICKER_SITE", "")
		set()
		_, err := resolveSite(work, true)
		if err == nil || !strings.Contains(err.Error(), "linked to") || !strings.Contains(err.Error(), name) {
			t.Errorf("%s: pushing a's theme to b must be refused, got %v", name, err)
		}
	}
	// ...but the same choice is fine for something that is not about this folder.
	flagSite = "b"
	if _, err := resolveSite(work, false); err != nil {
		t.Fatalf("not folder-bound: %v", err)
	}
	// ...and agreeing with the link is fine.
	flagSite = "a"
	if _, err := resolveSite(work, true); err != nil {
		t.Fatalf("agreeing with the link: %v", err)
	}
}

func TestAStoppedSiteIsRefused(t *testing.T) {
	_, work := fresh(t)
	addSite(t, "https://a.example", "a")
	if err := setDisabled("a", true); err != nil {
		t.Fatal(err)
	}
	if _, err := resolveSite(work, false); err == nil || !strings.Contains(err.Error(), "wpicker sites start 1") {
		t.Fatalf("%v", err)
	}
	if err := setDisabled("a", false); err != nil {
		t.Fatal(err)
	}
	if _, err := resolveSite(work, false); err != nil {
		t.Fatal(err)
	}
}

func TestAutoLinkOnlyWhereItIsSafe(t *testing.T) {
	_, work := fresh(t)
	a := addSite(t, "https://a.example", "a")
	r := &resolvedSite{Site: a, Source: srcOnly, Sites: 1}

	// A folder that is not a theme folder is left alone by a plain command...
	autoLinkTheme(work, r, false)
	if _, l, _ := findLink(work, false); l != nil {
		t.Fatal("linked a folder that was never pulled into")
	}
	// ...an existing theme folder (has last-pull.json) is linked on first use...
	os.MkdirAll(filepath.Join(work, ".wpicker"), 0o755)
	os.WriteFile(filepath.Join(work, ".wpicker", "last-pull.json"), []byte("{}"), 0o644)
	autoLinkTheme(work, r, false)
	_, l, _ := findLink(work, false)
	if l == nil || l.Site != "https://a.example" {
		t.Fatalf("not linked: %+v", l)
	}
	if s, _ := pickSite("a"); len(s.Folders) != 1 {
		t.Fatalf("the site does not list the folder: %v", s.Folders)
	}
	// ...a pull creates the link in a new folder, and never replaces an existing one.
	fresh2 := mkdir(t, filepath.Join(work, "new"))
	autoLinkTheme(fresh2, r, true)
	if _, l, _ := findLink(fresh2, false); l == nil {
		t.Fatal("pull did not link its folder")
	}
	b := addSite(t, "https://b.example", "b")
	autoLinkTheme(work, &resolvedSite{Site: b, Source: srcFlag, Sites: 2}, true)
	if _, l, _ := findLink(work, false); l.Site != "https://a.example" {
		t.Fatalf("an existing link was replaced: %+v", l)
	}
}

func TestLinkAndUnlinkCommands(t *testing.T) {
	_, work := fresh(t)
	addSite(t, "https://a.example", "a")
	addSite(t, "https://b.example", "b")

	if err := sitesLinkCmd.RunE(sitesLinkCmd, nil); err == nil {
		t.Fatal("with several sites, link needs to be told which")
	}
	if err := sitesLinkCmd.RunE(sitesLinkCmd, []string{"2"}); err != nil {
		t.Fatal(err)
	}
	if r, _ := resolveSite(work, false); r.Site.Name != "b" || r.Source != srcLink {
		t.Fatalf("%+v", r)
	}
	if err := sitesUnlinkCmd.RunE(sitesUnlinkCmd, nil); err != nil {
		t.Fatal(err)
	}
	if _, l, _ := findLink(work, false); l != nil {
		t.Fatal("still linked")
	}
	if s, _ := pickSite("b"); len(s.Folders) != 0 {
		t.Fatalf("the registry kept the folder: %v", s.Folders)
	}
	setDisabled("b", true)
	if err := sitesLinkCmd.RunE(sitesLinkCmd, []string{"b"}); err == nil {
		t.Fatal("linking a stopped site must be refused")
	}
}

func TestLogoutNeverDeletesEverything(t *testing.T) {
	fresh(t)
	addSite(t, "https://a.example", "a")
	addSite(t, "https://b.example", "b")
	config.Update(func(s *config.Store) error { s.Default = 0; return nil })
	if err := logoutCmd.RunE(logoutCmd, nil); err == nil {
		t.Fatal("logout with several sites and no choice must stop")
	}
	if s, _ := config.LoadStore(); len(s.Sites) != 2 {
		t.Fatal("something was deleted")
	}
}

// --- live status and delete, against a pretend WordPress -----------------------------

func site(t *testing.T, h http.HandlerFunc) *httptest.Server {
	t.Helper()
	s := httptest.NewServer(h)
	t.Cleanup(s.Close)
	return s
}

func envelope(w http.ResponseWriter, code int, body string) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(code)
	io.WriteString(w, body)
}

func TestStatusFromError(t *testing.T) {
	for want, in := range map[string]error{
		stUnauthorized: fmt.Errorf("http 401: nope"),
		stNoPlugin:     fmt.Errorf("http 404: nothing"),
		stOffline:      fmt.Errorf("dial tcp: lookup x: no such host"),
		stError:        fmt.Errorf("something odd"),
	} {
		if got := statusFromError(in); got != want {
			t.Errorf("%v -> %s, want %s", in, got, want)
		}
	}
	if statusFromError(nil) != stConnected {
		t.Fatal("nil is connected")
	}
}

func TestCheckStatusesTellsSitesApart(t *testing.T) {
	_, _ = fresh(t)
	var stoppedHits int32
	ok := site(t, func(w http.ResponseWriter, r *http.Request) { envelope(w, 200, `{"ok":true,"data":{"devices":[]}}`) })
	denied := site(t, func(w http.ResponseWriter, r *http.Request) {
		envelope(w, 401, `{"code":"rest_not_logged_in","message":"no"}`)
	})
	noplugin := site(t, func(w http.ResponseWriter, r *http.Request) {
		envelope(w, 404, `{"code":"rest_no_route","message":"no route"}`)
	})
	stopped := site(t, func(w http.ResponseWriter, r *http.Request) { atomic.AddInt32(&stoppedHits, 1) })
	dead := httptest.NewServer(nil)
	deadURL := dead.URL
	dead.Close()

	sites := []config.Site{
		{ID: 1, URL: ok.URL}, {ID: 2, URL: denied.URL}, {ID: 3, URL: noplugin.URL},
		{ID: 4, URL: stopped.URL, Disabled: true}, {ID: 5, URL: deadURL},
	}
	got := checkStatuses(t.Context(), sites, false)
	want := map[int]string{1: stConnected, 2: stUnauthorized, 3: stNoPlugin, 4: stStopped, 5: stOffline}
	for id, w := range want {
		if got[id] != w {
			t.Errorf("site %d: %s, want %s", id, got[id], w)
		}
	}
	if atomic.LoadInt32(&stoppedHits) != 0 {
		t.Fatal("a stopped site was contacted")
	}

	var hits int32
	counting := site(t, func(w http.ResponseWriter, r *http.Request) { atomic.AddInt32(&hits, 1) })
	off := checkStatuses(t.Context(), []config.Site{{ID: 1, URL: counting.URL}}, true)
	if off[1] != stUnchecked || atomic.LoadInt32(&hits) != 0 {
		t.Fatalf("--offline must not contact anything: %v hits=%d", off, hits)
	}
}

func TestDeleteRevokesFirstAndKeepsTheLoginIfItCannot(t *testing.T) {
	fresh(t)
	var revoked atomic.Value
	server := site(t, func(w http.ResponseWriter, r *http.Request) {
		if r.Method == "DELETE" {
			revoked.Store(r.URL.Path)
			envelope(w, 200, `{"ok":true,"data":{}}`)
			return
		}
		envelope(w, 200, `{"ok":true,"data":{}}`)
	})
	s := addSite(t, server.URL, "live")
	if err := deleteSite(s, false); err != nil {
		t.Fatal(err)
	}
	if p, _ := revoked.Load().(string); !strings.HasSuffix(p, "/device/dev-live") {
		t.Fatalf("the device was not revoked on the site: %q", p)
	}
	if st, _ := config.LoadStore(); len(st.Sites) != 0 {
		t.Fatal("not removed locally")
	}

	// The site is broken: nothing is deleted, because the saved login is the only way to revoke later.
	broken := site(t, func(w http.ResponseWriter, r *http.Request) { envelope(w, 500, `{"code":"boom","message":"x"}`) })
	s2 := addSite(t, broken.URL, "broken")
	err := deleteSite(s2, false)
	if err == nil || !strings.Contains(err.Error(), "--local-only") {
		t.Fatalf("%v", err)
	}
	if st, _ := config.LoadStore(); len(st.Sites) != 1 {
		t.Fatal("deleted despite failing to revoke")
	}
	if err := deleteSite(s2, true); err != nil {
		t.Fatal(err)
	}
	if st, _ := config.LoadStore(); len(st.Sites) != 0 {
		t.Fatal("--local-only did not delete")
	}
}

func TestDeletingASiteWhoseLoginIsAlreadyDeadJustWorks(t *testing.T) {
	fresh(t)
	gone := site(t, func(w http.ResponseWriter, r *http.Request) {
		envelope(w, 401, `{"code":"rest_not_logged_in","message":"x"}`)
	})
	s := addSite(t, gone.URL, "gone")
	if err := deleteSite(s, false); err != nil {
		t.Fatalf("a login the site already rejects needs no revoking: %v", err)
	}
}

func TestDeleteRemovesTheOriginalConfigFileSoTheSecretDoesNotLinger(t *testing.T) {
	home, _ := fresh(t)
	d, _ := config.Dir()
	legacy := filepath.Join(d, config.FileName)
	b, _ := json.Marshal(config.Config{Site: "https://old.example", Username: "u", AppPassword: "OLD-SECRET", DeviceID: "old-dev"})
	os.WriteFile(legacy, b, 0o600)
	st, _ := config.LoadStore() // imports it
	if len(st.Sites) != 1 {
		t.Fatal("not imported")
	}
	config.Update(func(*config.Store) error { return nil }) // persist the import
	if err := deleteSite(st.Sites[0], true); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(legacy); err == nil {
		t.Fatalf("the old file with the password is still in %s", home)
	}
	if s2, _ := config.LoadStore(); len(s2.Sites) != 0 {
		t.Fatal("it came back")
	}
}

func TestDeleteRepointsTheDefault(t *testing.T) {
	fresh(t)
	addSite(t, "https://a.example", "a")
	b := addSite(t, "https://b.example", "b")
	a, _ := pickSite("a")
	deleteSite(a, true)
	if st, _ := config.LoadStore(); st.Default != b.ID {
		t.Fatalf("default = %d", st.Default)
	}
}

// --- what is printed -------------------------------------------------------------------

func capture(t *testing.T, fn func()) string {
	t.Helper()
	old := os.Stdout
	r, w, _ := os.Pipe()
	os.Stdout = w
	done := make(chan string)
	go func() { b, _ := io.ReadAll(r); done <- string(b) }()
	fn()
	w.Close()
	os.Stdout = old
	return <-done
}

func TestSitesJSONHasAStableShapeAndNoSecrets(t *testing.T) {
	_, work := fresh(t)
	a := addSite(t, "https://a.example", "a")
	addSite(t, "https://b.example", "b")
	linkFolder(work, a)
	flagJSON, sitesOffline = true, true
	t.Cleanup(func() { sitesOffline = false })

	out := capture(t, func() {
		if err := sitesCmd.RunE(sitesCmd, nil); err != nil {
			t.Fatal(err)
		}
	})
	if strings.Contains(out, "SECRET") || strings.Contains(out, "app_password") {
		t.Fatalf("a secret is in the JSON:\n%s", out)
	}
	var doc struct {
		Version int `json:"version"`
		Sites   []struct {
			ID      int      `json:"id"`
			Name    string   `json:"name"`
			URL     string   `json:"url"`
			Status  string   `json:"status"`
			Default bool     `json:"default"`
			Current bool     `json:"current"`
			Folders []string `json:"folders"`
		} `json:"sites"`
	}
	if err := json.Unmarshal([]byte(out), &doc); err != nil {
		t.Fatalf("%v\n%s", err, out)
	}
	if doc.Version != 1 || len(doc.Sites) != 2 || doc.Sites[0].Status != stUnchecked || !doc.Sites[0].Current || !doc.Sites[0].Default || len(doc.Sites[0].Folders) != 1 || doc.Sites[1].Current {
		t.Fatalf("%+v", doc)
	}
}

func TestSitesTableShowsTheFolderAndFitsTheWidth(t *testing.T) {
	_, work := fresh(t)
	long := strings.Repeat("sub", 30) + ".example.com"
	a := addSite(t, "https://"+long, "a")
	linkFolder(work, a)
	t.Setenv("COLUMNS", "80")
	flagJSON, sitesOffline = false, true
	t.Cleanup(func() { sitesOffline = false })
	out := capture(t, func() { sitesCmd.RunE(sitesCmd, nil) })
	for _, line := range strings.Split(out, "\n") {
		if n := len([]rune(line)); n > 90 {
			t.Errorf("line is %d wide for COLUMNS=80: %q", n, line)
		}
	}
	for _, want := range []string{"ID", "NAME", "STATUS", "FOLDERS", "→", "unchecked"} {
		if !strings.Contains(out, want) {
			t.Errorf("missing %q in:\n%s", want, out)
		}
	}
}

func TestWhoamiKeepsItsOldJSONKeysAndAddsNewOnes(t *testing.T) {
	_, work := fresh(t)
	a := addSite(t, "https://a.example", "a")
	linkFolder(work, a)
	flagJSON = true
	out := capture(t, func() {
		if err := whoamiCmd.RunE(whoamiCmd, nil); err != nil {
			t.Fatal(err)
		}
	})
	var m map[string]any
	if err := json.Unmarshal([]byte(out), &m); err != nil {
		t.Fatal(err)
	}
	for _, k := range []string{"site", "username", "device_id", "device_name", "id", "name", "source", "linked_folder"} {
		if _, ok := m[k]; !ok {
			t.Errorf("whoami --json lost the key %q: %s", k, out)
		}
	}
	if m["source"] != "link" || strings.Contains(out, "SECRET") {
		t.Fatalf("%s", out)
	}
}

func TestAnnouncementGoesToStderrAndOnlyWithSeveralSites(t *testing.T) {
	_, work := fresh(t)
	addSite(t, "https://a.example", "a")
	capErr := func(fn func()) string {
		old := os.Stderr
		r, w, _ := os.Pipe()
		os.Stderr = w
		done := make(chan string)
		go func() { b, _ := io.ReadAll(r); done <- string(b) }()
		fn()
		w.Close()
		os.Stderr = old
		return <-done
	}
	var buf bytes.Buffer
	if got := capErr(func() { mustClientAt(work, false) }); got != "" {
		t.Fatalf("one site needs no announcement: %q", got)
	}
	addSite(t, "https://b.example", "b")
	got := capErr(func() { mustClientAt(work, false) })
	if !strings.HasPrefix(got, "site: a (default site)") {
		t.Fatalf("%q", got)
	}
	flagJSON = true
	if got := capErr(func() { mustClientAt(work, false) }); got != "" {
		t.Fatalf("--json keeps stderr clean too: %q", got)
	}
	_ = buf
}

// --- fixes from the final review ---------------------------------------------------------

func TestCheckStatusesWithAStoppedSiteAfterRunningOnesHasNoRace(t *testing.T) {
	fresh(t)
	ok := site(t, func(w http.ResponseWriter, r *http.Request) {
		time.Sleep(20 * time.Millisecond) // still in flight while the loop goes on
		envelope(w, 200, `{"ok":true,"data":{"devices":[]}}`)
	})
	var sites []config.Site
	for i := 1; i <= 12; i++ {
		sites = append(sites, config.Site{ID: i, URL: ok.URL, Disabled: i%3 == 0})
	}
	got := checkStatuses(t.Context(), sites, false)
	for _, s := range sites {
		want := stConnected
		if s.Disabled {
			want = stStopped
		}
		if got[s.ID] != want {
			t.Errorf("site %d: %s, want %s", s.ID, got[s.ID], want)
		}
	}
}

func TestCommandsThatChangeASiteAreAllGuarded(t *testing.T) {
	for _, c := range []*cobra.Command{themeCmd, pushCmd, rollbackCmd, pageCmd, pageCreateCmd, backupCreateCmd, backupDeleteCmd, backupRestoreCmd, cloneCmd, deviceRevokeCmd, logoutCmd} {
		if !commandWrites(c) {
			t.Errorf("%s changes a site (or forgets a login) but is not marked as a write", c.CommandPath())
		}
	}
	for _, c := range []*cobra.Command{logsCmd, mapCmd, findCmd, dbCmd, remoteFilesCmd, backupListCmd, backupDownloadCmd, doctorCmd, historyCmd} {
		if commandWrites(c) {
			t.Errorf("%s only reads and must work with just a default", c.CommandPath())
		}
	}
}

func TestDebugOnNeedsAChosenSiteButStatusDoesNot(t *testing.T) {
	fresh(t)
	addSite(t, "http://127.0.0.1:9", "a") // nothing listens: any request fails fast
	addSite(t, "http://127.0.0.1:10", "b")
	err := debugCmd.RunE(debugCmd, []string{"on"})
	if err == nil || !strings.Contains(err.Error(), "changes a site") {
		t.Fatalf("debug on must not rest on the default: %v", err)
	}
	err = debugCmd.RunE(debugCmd, []string{"status"})
	if err != nil && strings.Contains(err.Error(), "changes a site") {
		t.Fatalf("debug status only looks: %v", err)
	}
	flagSite = "a"
	err = debugCmd.RunE(debugCmd, []string{"off"})
	if err != nil && strings.Contains(err.Error(), "changes a site") {
		t.Fatalf("an explicit choice is fine: %v", err)
	}
}

func TestLogoutDoesNotQuietlyDropTheDefault(t *testing.T) {
	fresh(t)
	addSite(t, "https://a.example", "a")
	addSite(t, "https://b.example", "b")
	if err := logoutCmd.RunE(logoutCmd, nil); err == nil {
		t.Fatal("logout in an unlinked folder with two sites must stop")
	}
	if s, _ := config.LoadStore(); len(s.Sites) != 2 {
		t.Fatal("a site was dropped")
	}
}

func TestAnUnreadableLinkFileIsAnErrorNotSilentlyIgnored(t *testing.T) {
	_, work := fresh(t)
	addSite(t, "https://a.example", "a")
	addSite(t, "https://b.example", "b")
	// project.json as a directory (or any file that cannot be read) must not fall through to the default.
	mkdir(t, filepath.Join(work, ".wpicker", projectFileName))
	if _, err := resolveSite(work, false); err == nil || !strings.Contains(err.Error(), "cannot read the link file") {
		t.Fatalf("%v", err)
	}
	os.RemoveAll(filepath.Join(work, ".wpicker"))
	os.MkdirAll(filepath.Join(work, ".wpicker"), 0o755)
	os.WriteFile(filepath.Join(work, ".wpicker", projectFileName), []byte("{broken"), 0o644)
	if _, err := resolveSite(work, false); err == nil || !strings.Contains(err.Error(), "not a valid link file") {
		t.Fatalf("%v", err)
	}
	// ...and `sites unlink` can remove it.
	if err := sitesUnlinkCmd.RunE(sitesUnlinkCmd, nil); err != nil {
		t.Fatal(err)
	}
	if _, err := resolveSite(work, false); err != nil && strings.Contains(err.Error(), "link file") {
		t.Fatalf("the broken link is still in the way: %v", err)
	}
}

func TestALinkInAParentOutsideHomeIsNeverFollowed(t *testing.T) {
	fresh(t)
	addSite(t, "https://a.example", "a")
	b := addSite(t, "https://b.example", "b")
	outside, err := os.MkdirTemp("", "wpicker-outside-*") // not under the test home: think /tmp on a shared machine
	if err != nil {
		t.Fatal(err)
	}
	defer os.RemoveAll(outside)
	writeLink(outside, b)
	child := mkdir(t, filepath.Join(outside, "project"))
	if r, err := resolveSite(child, false); err != nil || r.Source == srcLink {
		t.Fatalf("a link planted in a parent outside your home chose a site: %+v %v", r, err)
	}
	if r, err := resolveSite(outside, false); err != nil || r.Source != srcLink || r.Site.Name != "b" {
		t.Fatalf("a link in the folder itself still works: %+v %v", r, err)
	}
}

func TestAnAmbientEnvVarCannotSteerAWriteAwayFromTheLink(t *testing.T) {
	_, work := fresh(t)
	a := addSite(t, "https://a.example", "a")
	addSite(t, "https://b.example", "b")
	linkFolder(work, a)
	t.Setenv("WPICKER_SITE", "b")
	curWrites = true
	if _, err := resolveSite(work, false); err == nil || !strings.Contains(err.Error(), "$WPICKER_SITE") {
		t.Fatalf("rollback/page/restore must not follow a forgotten variable: %v", err)
	}
	curWrites = false
	if r, err := resolveSite(work, false); err != nil || r.Site.Name != "b" {
		t.Fatalf("a read may follow it: %+v %v", r, err)
	}
	flagSite = "b" // the flag is a deliberate act
	curWrites = true
	if _, err := resolveSite(work, false); err != nil {
		t.Fatalf("--site is deliberate: %v", err)
	}
}

func TestAnExistingThemeFolderIsOnlyAutoLinkedWhenThereWasNothingToChoose(t *testing.T) {
	_, work := fresh(t)
	a := addSite(t, "https://a.example", "a")
	b := addSite(t, "https://b.example", "b")
	os.MkdirAll(filepath.Join(work, ".wpicker"), 0o755)
	os.WriteFile(filepath.Join(work, ".wpicker", "last-pull.json"), []byte("{}"), 0o644)

	// `theme status --site b` once, in a folder that was pulled from a: do not pin it to b.
	autoLinkTheme(work, &resolvedSite{Site: b, Source: srcFlag, Sites: 2}, false)
	if _, l, _ := findLink(work, false); l != nil {
		t.Fatalf("a folder pulled from a was pinned to b by one --site: %+v", l)
	}
	autoLinkTheme(work, &resolvedSite{Site: a, Source: srcOnly, Sites: 1}, false)
	if _, l, _ := findLink(work, false); l == nil || l.Site != "https://a.example" {
		t.Fatalf("the unambiguous case should link: %+v", l)
	}
}

func TestLinkingHomeIsRefusedAndUnlinkNamesTheInheritedLink(t *testing.T) {
	home, work := fresh(t)
	a := addSite(t, "https://a.example", "a")
	os.Chdir(home)
	if err := sitesLinkCmd.RunE(sitesLinkCmd, []string{"a"}); err == nil {
		t.Fatal("home cannot be linked; findLink ignores it, so a success message would be a lie")
	}
	os.Chdir(work)
	linkFolder(work, a)
	sub := mkdir(t, filepath.Join(work, "sub"))
	os.Chdir(sub)
	out := capture(t, func() { sitesUnlinkCmd.RunE(sitesUnlinkCmd, nil) })
	if !strings.Contains(out, "inherits") || !strings.Contains(out, "work") {
		t.Fatalf("it should say the link comes from the parent: %q", out)
	}
	if _, l, _ := findLink(work, false); l == nil {
		t.Fatal("the parent's link was removed from a subfolder")
	}
}

func TestLoginRefusesANameThatCouldShadowAnId(t *testing.T) {
	fresh(t)
	loginName = "2"
	defer func() { loginName = "" }()
	if err := pairAndSave(loginCmd, auth.Prefill{}, "2"); err == nil || !strings.Contains(err.Error(), "--name") {
		t.Fatalf("%v", err)
	}
}

func TestLoginStopsBeforePairingWhenTheStoreIsBroken(t *testing.T) {
	home, _ := fresh(t)
	d, _ := config.Dir()
	os.WriteFile(filepath.Join(d, config.SitesFileName), []byte("{broken"), 0o600)
	_ = home
	// If it got as far as the password prompt it would read stdin; the point is that it never does.
	err := pairAndSave(loginCmd, auth.Prefill{}, "")
	if err == nil || !strings.Contains(err.Error(), "parse") {
		t.Fatalf("expected the broken store to be reported first: %v", err)
	}
}

func TestOfferLinkAfterLogin(t *testing.T) {
	home, work := fresh(t)
	a := addSite(t, "https://a.example", "a")
	b := addSite(t, "https://b.example", "b")
	ask := func(dir, answer string, total int, tty bool) (bool, string) {
		var out bytes.Buffer
		ok := offerLink(dir, home, b, total, tty, bufio.NewReader(strings.NewReader(answer)), &out)
		return ok, out.String()
	}

	if ok, out := ask(work, "y\n", 1, true); ok || out != "" {
		t.Fatalf("with one site there is nothing to choose: %v %q", ok, out)
	}
	if ok, out := ask(home, "y\n", 2, true); ok || out != "" {
		t.Fatalf("home is never a project: %v %q", ok, out)
	}
	if ok, out := ask(work, "y\n", 2, false); ok || !strings.Contains(out, "wpicker sites link b") {
		t.Fatalf("without a terminal it only says how: %v %q", ok, out)
	}
	if _, l, _ := findLink(work, false); l != nil {
		t.Fatal("linked without being asked")
	}
	if ok, _ := ask(work, "n\n", 2, true); ok {
		t.Fatal("a no must not link")
	}
	if ok, _ := ask(work, "\n", 2, true); ok {
		t.Fatal("the default answer is no")
	}
	if _, l, _ := findLink(work, false); l != nil {
		t.Fatal("linked on a no")
	}
	if ok, _ := ask(work, "yes\n", 2, true); !ok {
		t.Fatal("yes should link")
	}
	if r, _ := resolveSite(work, false); r == nil || r.Site.Name != "b" || r.Source != srcLink {
		t.Fatalf("%+v", r)
	}
	// An existing link is never replaced by a question.
	linkFolder(work, a)
	if ok, out := ask(work, "y\n", 2, true); ok || out != "" {
		t.Fatalf("a linked folder was asked again: %v %q", ok, out)
	}
	if _, l, _ := findLink(work, false); l.Site != "https://a.example" {
		t.Fatal("the link was replaced")
	}
}

func TestRelinkingAFolderMovesItBetweenSites(t *testing.T) {
	_, work := fresh(t)
	old := addSite(t, "https://old.example", "old")
	neu := addSite(t, "https://new.example", "new")
	if err := linkFolder(work, old); err != nil {
		t.Fatal(err)
	}
	if err := linkFolder(work, neu); err != nil { // the shop moved: point the folder at the new address
		t.Fatal(err)
	}
	o, _ := pickSite("old")
	n, _ := pickSite("new")
	if len(o.Folders) != 0 || len(n.Folders) != 1 {
		t.Fatalf("the folder is listed under both sites: old=%v new=%v", o.Folders, n.Folders)
	}
	if r, _ := resolveSite(work, false); r.Site.Name != "new" {
		t.Fatalf("%+v", r)
	}
}
