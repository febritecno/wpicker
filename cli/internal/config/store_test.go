package config

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"
)

func home(t *testing.T) string {
	t.Helper()
	h := t.TempDir()
	t.Setenv("HOME", h)
	return h
}

func writeLegacy(t *testing.T, site, user, pass, dev string) string {
	t.Helper()
	d, _ := Dir()
	p := filepath.Join(d, FileName)
	b, _ := json.Marshal(Config{Site: site, Username: user, AppPassword: pass, DeviceID: dev, DeviceName: "laptop"})
	if err := os.WriteFile(p, b, 0o600); err != nil {
		t.Fatal(err)
	}
	return p
}

func TestURLKeyIdentity(t *testing.T) {
	same := [][]string{
		{"https://Example.com", "example.com/", "http://example.com", "https://example.com:443/", "HTTPS://EXAMPLE.COM///"},
		{"http://x.test:8080/blog/", "https://x.test:8080/blog"},
	}
	for _, group := range same {
		want, err := URLKey(group[0])
		if err != nil {
			t.Fatal(err)
		}
		for _, raw := range group {
			if got, _ := URLKey(raw); got != want {
				t.Errorf("%q -> %q, want %q", raw, got, want)
			}
		}
	}
	distinct := [][2]string{
		{"example.com/a", "example.com/b"},       // multisite / subdirectory installs
		{"example.com", "www.example.com"},       // www is not folded
		{"example.com:8080", "example.com"},      // port matters
		{"example.com", "example.com.evil.test"}, // lookalike host
		{"example.com", "example.com@evil.test"}, // userinfo trick: host is evil.test
	}
	for _, p := range distinct {
		a, _ := URLKey(p[0])
		b, _ := URLKey(p[1])
		if a == b {
			t.Errorf("%q and %q must be different sites", p[0], p[1])
		}
	}
	for _, bad := range []string{"", "   ", "http://", "://x"} {
		if _, err := URLKey(bad); err == nil {
			t.Errorf("%q should be rejected", bad)
		}
	}
}

func TestNormalizeKeepsTheSchemeTheUserGave(t *testing.T) {
	if got, _ := NormalizeURL("shop.example.com/"); got != "https://shop.example.com" {
		t.Fatal(got)
	}
	if got, _ := NormalizeURL("http://wpicker.local/"); got != "http://wpicker.local" {
		t.Fatal(got)
	}
}

func TestSlug(t *testing.T) {
	for in, want := range map[string]string{
		"https://shop.example.com/blog": "shop-example-com-blog",
		"http://wpicker.local":          "wpicker-local",
		"localhost:8080":                "localhost-8080",
	} {
		if got := SlugFromURL(in); got != want {
			t.Errorf("%q -> %q, want %q", in, got, want)
		}
	}
}

func TestAnOldLoginIsImportedAndTheOldFileIsNeverTouched(t *testing.T) {
	home(t)
	p := writeLegacy(t, "https://shop.example.com", "root", "pw1", "dev-1")
	before, _ := os.ReadFile(p)
	st, _ := os.Stat(p)

	s, err := LoadStore()
	if err != nil {
		t.Fatal(err)
	}
	if len(s.Sites) != 1 || s.Sites[0].ID != 1 || s.Sites[0].Name != "shop-example-com" || s.Sites[0].AppPassword != "pw1" {
		t.Fatalf("not imported: %+v", s.Sites)
	}
	if _, err := os.Stat(filepath.Join(filepath.Dir(p), SitesFileName)); err == nil {
		t.Fatal("loading must not write sites.json")
	}

	if err := Update(func(s *Store) error { s.Sites[0].Disabled = true; return nil }); err != nil {
		t.Fatal(err)
	}
	after, _ := os.ReadFile(p)
	st2, _ := os.Stat(p)
	if string(before) != string(after) || !st.ModTime().Equal(st2.ModTime()) {
		t.Fatal("config.json (the original) was modified")
	}
	s2, _ := LoadStore()
	if len(s2.Sites) != 1 || !s2.Sites[0].Disabled {
		t.Fatalf("change not persisted: %+v", s2.Sites)
	}
}

func TestALoginMadeByAnOlderBinaryLaterIsPickedUp(t *testing.T) {
	home(t)
	Update(func(s *Store) error {
		s.Upsert(Site{URL: "https://a.example", Username: "u", AppPassword: "pa", DeviceID: "da"}, "a")
		return nil
	})
	writeLegacy(t, "https://b.example", "u", "pb", "db") // old binary logs in to a different site

	s, _ := LoadStore()
	if len(s.Sites) != 2 || s.Sites[1].URL != "https://b.example" {
		t.Fatalf("second login not imported: %+v", s.Sites)
	}
}

func TestADeletedSiteDoesNotComeBackFromTheOldFile(t *testing.T) {
	home(t)
	writeLegacy(t, "https://a.example", "u", "pa", "da")
	if err := Update(func(s *Store) error { s.Remove(s.Sites[0].ID); return nil }); err != nil {
		t.Fatal(err)
	}
	s, _ := LoadStore()
	if len(s.Sites) != 0 {
		t.Fatalf("a deleted site was resurrected: %+v", s.Sites)
	}
}

func TestFind(t *testing.T) {
	s := &Store{NextID: 1}
	s.Upsert(Site{URL: "https://shop.example.com"}, "shop")
	s.Upsert(Site{URL: "https://blog.example.com/wp"}, "blog")
	for _, sel := range []string{"1", "shop", "SHOP", "shop.example.com", "http://shop.example.com/"} {
		if got, err := s.Find(sel); err != nil || got.Name != "shop" {
			t.Errorf("%q: %v %v", sel, got, err)
		}
	}
	if got, err := s.Find("blog.example.com/wp"); err != nil || got.Name != "blog" {
		t.Error("path-qualified address")
	}
	for _, sel := range []string{"3", "nope", "blog.example.com", "https://evil.test", ""} {
		if got, err := s.Find(sel); err == nil {
			t.Errorf("%q must not match anything, got %+v", sel, got)
		}
	}
}

func TestUpsertKeepsIdentityOnRePairAndNamesStayUnique(t *testing.T) {
	s := &Store{NextID: 1}
	s.Upsert(Site{URL: "https://a.example", Username: "u", AppPassword: "old", DeviceID: "d1"}, "")
	s.Upsert(Site{URL: "https://other.example"}, "a-example") // name collides with a's generated name
	if s.Sites[1].Name == s.Sites[0].Name {
		t.Fatal("two sites share a name")
	}
	s.ByID(1).AddFolder("/work/a")
	again := s.Upsert(Site{URL: "http://A.example/", Username: "u2", AppPassword: "new", DeviceID: "d2"}, "ignored")
	if again.URL != "http://a.example" {
		t.Fatalf("the address was stored as typed: %q", again.URL)
	}
	if again.ID != 1 || again.Name != "a-example" || again.AppPassword != "new" || len(again.Folders) != 1 || len(s.Sites) != 2 {
		t.Fatalf("re-pair changed identity or duplicated: %+v", s.Sites)
	}
	if s.Default != 1 {
		t.Fatal("the first site becomes the default")
	}
}

func TestRemoveRepointsTheDefault(t *testing.T) {
	s := &Store{NextID: 1}
	s.Upsert(Site{URL: "https://a.example"}, "a")
	s.Upsert(Site{URL: "https://b.example"}, "b")
	s.Remove(1)
	if s.Default != 2 || len(s.Sites) != 1 {
		t.Fatalf("default=%d sites=%d", s.Default, len(s.Sites))
	}
	s.Upsert(Site{URL: "https://c.example"}, "c")
	s.Upsert(Site{URL: "https://d.example"}, "d")
	s.Remove(2)
	if s.Default != 0 {
		t.Fatal("with several sites left and the default gone, nothing is chosen for the user")
	}
	if s.NextID != 5 {
		t.Fatal("ids are never reused")
	}
}

func TestConcurrentUpdatesLoseNothing(t *testing.T) {
	home(t)
	const writers, each = 8, 12
	var wg sync.WaitGroup
	for w := 0; w < writers; w++ {
		wg.Add(1)
		go func(w int) {
			defer wg.Done()
			for i := 0; i < each; i++ {
				err := Update(func(s *Store) error {
					s.Upsert(Site{URL: "https://s" + string(rune('a'+w)) + string(rune('a'+i)) + ".example"}, "")
					return nil
				})
				if err != nil {
					t.Error(err)
				}
			}
		}(w)
	}
	wg.Wait()
	s, err := LoadStore()
	if err != nil {
		t.Fatal(err)
	}
	if len(s.Sites) != writers*each {
		t.Fatalf("lost updates: %d of %d sites survived", len(s.Sites), writers*each)
	}
	seen := map[int]bool{}
	for _, x := range s.Sites {
		if seen[x.ID] {
			t.Fatalf("duplicate id %d", x.ID)
		}
		seen[x.ID] = true
	}
}

func TestTheStoreIsPrivateAndLeavesNoTempFiles(t *testing.T) {
	h := home(t)
	Update(func(s *Store) error { s.Upsert(Site{URL: "https://a.example", AppPassword: "secret"}, "a"); return nil })
	d := filepath.Join(h, DirName)
	st, err := os.Stat(filepath.Join(d, SitesFileName))
	if err != nil || st.Mode().Perm() != 0o600 {
		t.Fatalf("sites.json mode: %v %v", st, err)
	}
	entries, _ := os.ReadDir(d)
	for _, e := range entries {
		if e.Name() != SitesFileName && e.Name() != "sites.lock" {
			t.Errorf("stray file left behind: %s", e.Name())
		}
	}
}

func TestACorruptStoreIsAnErrorNotAFreshStart(t *testing.T) {
	h := home(t)
	d, _ := Dir()
	os.WriteFile(filepath.Join(d, SitesFileName), []byte("{not json"), 0o600)
	if _, err := LoadStore(); err == nil {
		t.Fatal("a damaged store must not be silently replaced by an empty one")
	}
	if err := Update(func(s *Store) error { return nil }); err == nil {
		t.Fatal("Update must refuse to overwrite a damaged store")
	}
	if b, _ := os.ReadFile(filepath.Join(h, DirName, SitesFileName)); string(b) != "{not json" {
		t.Fatal("the damaged file was overwritten")
	}
}

func touchFuture(t *testing.T, p string) {
	t.Helper()
	f := time.Now().Add(2 * time.Hour)
	if err := os.Chtimes(p, f, f); err != nil {
		t.Fatal(err)
	}
}

func TestARePairedSiteIsNotRevertedWhenTheOldFileIsMerelyTouched(t *testing.T) {
	home(t)
	p := writeLegacy(t, "https://a.example", "u", "OLD", "D1")
	Update(func(s *Store) error { return nil }) // take the old login in
	Update(func(s *Store) error {               // the new binary pairs the same site again
		s.Upsert(Site{URL: "https://a.example", Username: "u", AppPassword: "NEW", DeviceID: "D2"}, "")
		return nil
	})
	// A sync tool, a restore or clock skew makes config.json look newer. Nothing in it changed.
	touchFuture(t, p)
	s, _ := LoadStore()
	if len(s.Sites) != 1 || s.Sites[0].AppPassword != "NEW" || s.Sites[0].DeviceID != "D2" {
		t.Fatalf("good credentials were replaced by stale ones: %+v", s.Sites)
	}
	Update(func(*Store) error { return nil })
	s, _ = LoadStore()
	if s.Sites[0].AppPassword != "NEW" {
		t.Fatal("the stale login was written back to disk")
	}
}

func TestARealRePairByAnOlderBinaryIsStillPickedUp(t *testing.T) {
	home(t)
	writeLegacy(t, "https://a.example", "u", "P1", "D1")
	Update(func(s *Store) error { return nil })
	writeLegacy(t, "https://a.example", "u", "P3", "D3") // an old binary logs in again
	s, _ := LoadStore()
	if s.Sites[0].AppPassword != "P3" || s.Sites[0].DeviceID != "D3" {
		t.Fatalf("a newer login in config.json was ignored: %+v", s.Sites[0])
	}
}

func TestADeletedSiteStaysDeletedEvenIfTheOldFileChangesTime(t *testing.T) {
	home(t)
	p := writeLegacy(t, "https://a.example", "u", "P", "D1")
	Update(func(s *Store) error { s.Remove(s.Sites[0].ID); return nil })
	touchFuture(t, p)
	if s, _ := LoadStore(); len(s.Sites) != 0 {
		t.Fatalf("resurrected: %+v", s.Sites)
	}
}

func TestTheImportedSiteIsTheDefaultSoALaterLoginDoesNotStealIt(t *testing.T) {
	home(t)
	writeLegacy(t, "https://old.example", "u", "P", "D1")
	Update(func(s *Store) error {
		s.Upsert(Site{URL: "https://new.example"}, "new")
		return nil
	})
	s, _ := LoadStore()
	if def := s.ByID(s.Default); def == nil || def.URL != "https://old.example" {
		t.Fatalf("reads in unlinked folders would silently switch to the new site: default=%d", s.Default)
	}
}

func TestANewerStoreIsNotRewrittenByAnOlderBinary(t *testing.T) {
	home(t)
	d, _ := Dir()
	os.WriteFile(filepath.Join(d, SitesFileName), []byte(`{"version":99,"next_id":2,"sites":[],"future_field":1}`), 0o600)
	if _, err := LoadStore(); err == nil {
		t.Fatal("loaded a store from the future")
	}
	if err := Update(func(*Store) error { return nil }); err == nil {
		t.Fatal("would have dropped fields it does not know")
	}
}

func TestNamesCannotBeMistakenForIdsOrAddresses(t *testing.T) {
	for _, bad := range []string{"2", "007", "shop.example.com", "a/b", "x y", "a:b", "u@h", ""} {
		if ValidName(bad) {
			t.Errorf("%q should be refused as a name", bad)
		}
	}
	for _, good := range []string{"shop", "my-blog", "blog2", "Shop_2"} {
		if !ValidName(good) {
			t.Errorf("%q should be fine", good)
		}
	}
	s := &Store{NextID: 1}
	s.Upsert(Site{URL: "https://a.example"}, "2")
	s.Upsert(Site{URL: "https://b.example"}, "b.example.com")
	for _, x := range s.Sites {
		if !ValidName(x.Name) {
			t.Errorf("stored a name that could shadow an id or address: %q", x.Name)
		}
		if got, err := s.Find(x.Name); err != nil || got.ID != x.ID {
			t.Errorf("name %q does not find its own site: %v", x.Name, err)
		}
	}
	if got, _ := s.Find("2"); got == nil || got.ID != 2 {
		t.Error("\"2\" is an id")
	}
}

func TestIPv6AndSchemeCase(t *testing.T) {
	a, err := URLKey("https://[::1]:8080/")
	if err != nil || a != "[::1]:8080" {
		t.Fatalf("%q %v", a, err)
	}
	if got, _ := NormalizeURL("https://[::1]:8080"); got != "https://[::1]:8080" {
		t.Fatalf("the address would not connect: %q", got)
	}
	if got, _ := NormalizeURL("HTTP://Example.COM/"); got != "http://example.com" {
		t.Fatalf("%q", got)
	}
}

func TestCredentialsInAnAddressAreNeverStoredNorEchoed(t *testing.T) {
	got, _ := NormalizeURL("https://admin:hunter2@example.com/wp")
	if strings.Contains(got, "hunter2") || strings.Contains(got, "admin") {
		t.Fatalf("a password was kept: %q", got)
	}
	s := &Store{NextID: 1}
	s.Upsert(Site{URL: "https://example.com"}, "x")
	_, err := s.Find("https://admin:hunter2@evil.example")
	if err == nil || strings.Contains(err.Error(), "hunter2") {
		t.Fatalf("a password was echoed: %v", err)
	}
}
