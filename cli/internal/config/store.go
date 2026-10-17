package config

import (
	"encoding/json"
	"errors"
	"fmt"
	"net/url"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"time"
)

// SitesFileName is the multi-site store. It is a NEW file on purpose: older
// wpicker binaries only know config.json, rewrite it from 5 flat fields and
// delete it on logout. Keeping the store apart means a stale binary on PATH
// can never destroy it, and config.json stays as the untouched original.
const (
	SitesFileName = "sites.json"
	lockFileName  = "sites.lock"
	StoreVersion  = 1
)

// Site is one WordPress site this machine is paired with.
type Site struct {
	ID          int      `json:"id"`
	Name        string   `json:"name"`
	URL         string   `json:"url"`
	Username    string   `json:"username"`
	AppPassword string   `json:"app_password"`
	DeviceID    string   `json:"device_id"`
	DeviceName  string   `json:"device_name"`
	Added       string   `json:"added,omitempty"`
	Disabled    bool     `json:"disabled,omitempty"`
	Folders     []string `json:"folders,omitempty"`
}

// Store is everything in sites.json.
type Store struct {
	Version int    `json:"version"`
	Default int    `json:"default,omitempty"`
	NextID  int    `json:"next_id"`
	Sites   []Site `json:"sites"`
	// LegacySeen is "<site key>|<device id>" of the login in the original
	// config.json that this store has already taken in (or deliberately dropped).
	// Only a DIFFERENT login there — made later by an older wpicker — is news.
	// File times are not used: a sync tool or a restore can change them freely.
	LegacySeen string `json:"legacy_seen,omitempty"`
}

// URLKey reduces a site address to what identifies it: lower-case host, the
// default port dropped, the path without a trailing slash. The scheme is
// ignored (http and https name the same install) but the stored scheme is what
// the CLI connects with. Subdirectory installs stay distinct from each other.
func URLKey(raw string) (string, error) {
	raw = strings.TrimSpace(raw)
	if raw == "" {
		return "", errors.New("empty site address")
	}
	if !strings.Contains(raw, "://") {
		raw = "https://" + raw
	}
	u, err := url.Parse(raw)
	if err != nil || u.Hostname() == "" {
		return "", fmt.Errorf("not a site address: %q", raw)
	}
	host := strings.ToLower(u.Hostname())
	if strings.Contains(host, ":") {
		host = "[" + host + "]" // IPv6 literal: the brackets are part of the address
	}
	port := u.Port()
	if (u.Scheme == "https" && port == "443") || (u.Scheme == "http" && port == "80") {
		port = ""
	}
	if port != "" {
		host += ":" + port
	}
	return host + strings.TrimRight(u.EscapedPath(), "/"), nil
}

// NormalizeURL returns the address to store and connect with.
func NormalizeURL(raw string) (string, error) {
	key, err := URLKey(raw)
	if err != nil {
		return "", err
	}
	scheme := "https"
	if strings.HasPrefix(strings.ToLower(strings.TrimSpace(raw)), "http://") {
		scheme = "http"
	}
	return scheme + "://" + key, nil
}

// SlugFromURL makes a short default name: "shop.example.com/blog" -> "shop-example-com-blog".
func SlugFromURL(raw string) string {
	key, err := URLKey(raw)
	if err != nil {
		return "site"
	}
	var b strings.Builder
	dash := false
	for _, r := range strings.ToLower(key) {
		if (r >= 'a' && r <= 'z') || (r >= '0' && r <= '9') {
			b.WriteRune(r)
			dash = false
		} else if !dash && b.Len() > 0 {
			b.WriteByte('-')
			dash = true
		}
	}
	return strings.Trim(b.String(), "-")
}

// StorePath is ~/.wpicker/sites.json.
func StorePath() (string, error) {
	d, err := Dir()
	if err != nil {
		return "", err
	}
	return filepath.Join(d, SitesFileName), nil
}

// LoadStore reads the store. When sites.json is missing, or when the original
// config.json holds a login the store does not know yet (one made with an
// older binary), that login is imported in memory; it is written out the next
// time something changes the store.
func LoadStore() (*Store, error) {
	p, err := StorePath()
	if err != nil {
		return nil, err
	}
	s := &Store{Version: StoreVersion, NextID: 1}
	data, err := os.ReadFile(p)
	switch {
	case err == nil:
		if err := json.Unmarshal(data, s); err != nil {
			return nil, fmt.Errorf("parse %s: %w", p, err)
		}
		if s.Version > StoreVersion {
			return nil, fmt.Errorf("%s was written by a newer wpicker (store version %d, this one understands %d): update wpicker rather than risk losing its fields", p, s.Version, StoreVersion)
		}
		if s.NextID < 1 {
			s.NextID = 1
		}
	case !errors.Is(err, os.ErrNotExist):
		return nil, err
	}
	_ = os.Chmod(p, 0o600)
	s.importLegacy()
	return s, nil
}

// legacyMark identifies the login currently in the original config.json.
func legacyMark() (mark string, old *Config) {
	old, err := Load()
	if err != nil || !old.IsLoggedIn() {
		return "", nil
	}
	key, err := URLKey(old.Site)
	if err != nil {
		return "", nil
	}
	return key + "|" + old.DeviceID, old
}

// importLegacy folds a login from the original config.json into the store,
// in memory (it is written the next time the store changes). It acts only on a
// login the store has not seen yet: one made by an older wpicker binary.
func (s *Store) importLegacy() {
	mark, old := legacyMark()
	if mark == "" || mark == s.LegacySeen {
		return
	}
	key, _ := URLKey(old.Site)
	if existing := s.ByKey(key); existing != nil {
		// The same site, paired again by an older binary: its login is the newer one.
		if old.DeviceID != existing.DeviceID {
			existing.Username, existing.AppPassword = old.Username, old.AppPassword
			existing.DeviceID, existing.DeviceName = old.DeviceID, old.DeviceName
			existing.Disabled = false
		}
		return
	}
	url, _ := NormalizeURL(old.Site)
	first := len(s.Sites) == 0
	s.Sites = append(s.Sites, Site{
		ID: s.NextID, Name: s.uniqueName(SlugFromURL(url)), URL: url,
		Username: old.Username, AppPassword: old.AppPassword,
		DeviceID: old.DeviceID, DeviceName: old.DeviceName,
		Added: time.Now().UTC().Format(time.RFC3339),
	})
	if first && s.Default == 0 {
		s.Default = s.NextID
	}
	s.NextID++
}

// ByKey finds a site by URLKey.
func (s *Store) ByKey(key string) *Site {
	for i := range s.Sites {
		if k, err := URLKey(s.Sites[i].URL); err == nil && k == key {
			return &s.Sites[i]
		}
	}
	return nil
}

// Find resolves an id, a name or a site address to exactly one stored site.
func (s *Store) Find(sel string) (*Site, error) {
	sel = strings.TrimSpace(sel)
	if sel == "" {
		return nil, errors.New("no site given")
	}
	if n, err := strconv.Atoi(sel); err == nil {
		for i := range s.Sites {
			if s.Sites[i].ID == n {
				return &s.Sites[i], nil
			}
		}
	}
	for i := range s.Sites {
		if strings.EqualFold(s.Sites[i].Name, sel) {
			return &s.Sites[i], nil
		}
	}
	if key, err := URLKey(sel); err == nil {
		if site := s.ByKey(key); site != nil {
			return site, nil
		}
	}
	shown := sel
	if strings.Contains(sel, "@") {
		shown = "(an address with credentials in it)" // never echo a password back
	}
	return nil, fmt.Errorf("%q is not a site you are logged in to", shown)
}

// ValidName: a name must never be mistaken for an id or an address in `--site`.
func ValidName(n string) bool {
	if n == "" {
		return false
	}
	if _, err := strconv.Atoi(n); err == nil {
		return false
	}
	return !strings.ContainsAny(n, "./:@ \t\\")
}

func (s *Store) uniqueName(base string) string {
	if !ValidName(base) {
		base = "site-" + strings.Trim(strings.NewReplacer(".", "-", "/", "-", ":", "-", "@", "-", " ", "-").Replace(base), "-")
	}
	if base == "" || base == "site-" {
		base = "site"
	}
	name := base
	for n := 2; s.nameTaken(name); n++ {
		name = fmt.Sprintf("%s-%d", base, n)
	}
	return name
}

func (s *Store) nameTaken(name string) bool {
	for _, x := range s.Sites {
		if strings.EqualFold(x.Name, name) {
			return true
		}
	}
	return false
}

// Upsert adds a paired site, or replaces the credentials of the one with the
// same address (keeping its id, name and folders). It returns the stored site;
// the pointer is only valid until the next Upsert or Remove.
func (s *Store) Upsert(in Site, preferredName string) *Site {
	if norm, err := NormalizeURL(in.URL); err == nil {
		in.URL = norm
	}
	key, _ := URLKey(in.URL)
	if cur := s.ByKey(key); cur != nil {
		cur.URL, cur.Username, cur.AppPassword = in.URL, in.Username, in.AppPassword
		cur.DeviceID, cur.DeviceName = in.DeviceID, in.DeviceName
		cur.Disabled = false
		return cur
	}
	if in.Name = strings.TrimSpace(preferredName); !ValidName(in.Name) || s.nameTaken(in.Name) {
		in.Name = s.uniqueName(firstNonEmpty(in.Name, SlugFromURL(in.URL)))
	}
	in.ID = s.NextID
	in.Added = time.Now().UTC().Format(time.RFC3339)
	s.NextID++
	s.Sites = append(s.Sites, in)
	if s.Default == 0 {
		s.Default = in.ID
	}
	return &s.Sites[len(s.Sites)-1]
}

func firstNonEmpty(a, b string) string {
	if a != "" {
		return a
	}
	return b
}

// Remove deletes a site and fixes the default.
func (s *Store) Remove(id int) {
	out := s.Sites[:0]
	for _, x := range s.Sites {
		if x.ID != id {
			out = append(out, x)
		}
	}
	s.Sites = out
	if s.Default == id {
		s.Default = 0
		if len(s.Sites) == 1 {
			s.Default = s.Sites[0].ID
		}
	}
}

// ByID returns the site with this id.
func (s *Store) ByID(id int) *Site {
	for i := range s.Sites {
		if s.Sites[i].ID == id {
			return &s.Sites[i]
		}
	}
	return nil
}

// Sorted returns the sites ordered by id.
func (s *Store) Sorted() []Site {
	out := append([]Site(nil), s.Sites...)
	sort.Slice(out, func(i, j int) bool { return out[i].ID < out[j].ID })
	return out
}

// AddFolder remembers that a folder is linked to the site.
func (site *Site) AddFolder(dir string) {
	for _, f := range site.Folders {
		if f == dir {
			return
		}
	}
	site.Folders = append(site.Folders, dir)
}

// RemoveFolder forgets a linked folder.
func (site *Site) RemoveFolder(dir string) {
	out := site.Folders[:0]
	for _, f := range site.Folders {
		if f != dir {
			out = append(out, f)
		}
	}
	site.Folders = out
}

// View is the single-site view the commands work with.
func (site *Site) View() *Config {
	return &Config{
		ID: site.ID, Name: site.Name,
		Site: site.URL, Username: site.Username, AppPassword: site.AppPassword,
		DeviceID: site.DeviceID, DeviceName: site.DeviceName,
	}
}

// Update runs fn on a freshly loaded store under an exclusive lock and writes
// the result atomically. Hold it only for the read-modify-write, never across
// a prompt or a network call, so concurrent wpicker processes (agents start
// several at once) cannot lose each other's changes.
func Update(fn func(*Store) error) error {
	d, err := Dir()
	if err != nil {
		return err
	}
	unlock, err := lockFile(filepath.Join(d, lockFileName))
	if err != nil {
		return err
	}
	defer unlock()

	s, err := LoadStore()
	if err != nil {
		return err
	}
	if err := fn(s); err != nil {
		return err
	}
	// Whatever is in config.json now has been taken in (or deliberately dropped).
	if mark, _ := legacyMark(); mark != "" {
		s.LegacySeen = mark
	}
	return s.write()
}

func (s *Store) write() error {
	p, err := StorePath()
	if err != nil {
		return err
	}
	s.Version = StoreVersion
	data, err := json.MarshalIndent(s, "", "  ")
	if err != nil {
		return err
	}
	tmp, err := os.CreateTemp(filepath.Dir(p), ".sites-*.json")
	if err != nil {
		return err
	}
	name := tmp.Name()
	if err := os.Chmod(name, 0o600); err != nil {
		tmp.Close()
		os.Remove(name)
		return err
	}
	if _, err := tmp.Write(data); err != nil {
		tmp.Close()
		os.Remove(name)
		return err
	}
	if err := tmp.Close(); err != nil {
		os.Remove(name)
		return err
	}
	if err := os.Rename(name, p); err != nil {
		os.Remove(name)
		return err
	}
	return nil
}
