package cmd

import (
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"syscall"

	"github.com/spf13/cobra"

	"wpicker/internal/client"
	"wpicker/internal/config"
)

// projectFileName links a folder to a site: <folder>/.wpicker/project.json.
// It holds NO secrets and may be committed. It only ever SELECTS one of the
// sites you are logged in to; the address and credentials always come from your
// own store, never from this file.
const projectFileName = "project.json"

type projectLink struct {
	Site string `json:"site"`
	Name string `json:"name,omitempty"`
}

// Where the chosen site came from.
const (
	srcFlag    = "flag"
	srcEnv     = "env"
	srcLink    = "link"
	srcDefault = "default"
	srcOnly    = "only"
)

// resolvedSite is the site a command will act on, and why.
type resolvedSite struct {
	Site    config.Site
	Source  string
	Link    *projectLink
	LinkDir string // folder that holds the link
	Sites   int    // how many sites are stored
}

func (r *resolvedSite) why() string {
	switch r.Source {
	case srcFlag:
		return "--site"
	case srcEnv:
		return "$WPICKER_SITE"
	case srcLink:
		return "linked folder"
	case srcDefault:
		return "default site"
	}
	return "your only site"
}

// writesCmd marks commands that change a site (or what is bound to it).
const writesAnnotation = "wpicker/writes"

var curWrites bool

func commandWrites(cmd *cobra.Command) bool {
	for c := cmd; c != nil; c = c.Parent() {
		if c.Annotations[writesAnnotation] == "true" {
			return true
		}
	}
	return false
}

func markWrites(cmds ...*cobra.Command) {
	for _, c := range cmds {
		if c.Annotations == nil {
			c.Annotations = map[string]string{}
		}
		c.Annotations[writesAnnotation] = "true"
	}
}

// findLink reads the link for a folder. With walk it looks in the parents too
// (like git), never treating the home directory — where wpicker keeps its own
// files — as a project. Without walk only the folder itself counts: a theme
// working folder is exactly the folder holding .wpicker/last-pull.json.
func findLink(start string, walk bool) (string, *projectLink, error) {
	dir, err := filepath.Abs(start)
	if err != nil {
		return "", nil, err
	}
	home, _ := os.UserHomeDir()
	// Looking upwards stops at the home directory. Outside it (/tmp, /srv, a
	// shared machine) only the folder itself counts: a project.json planted in a
	// parent that someone else can write must not choose which of YOUR sites is used.
	underHome := false
	if home != "" {
		// Compare real paths: /var is a symlink to /private/var on macOS, and a
		// home directory can sit behind a link on Linux too.
		rd, e1 := filepath.EvalSymlinks(dir)
		rh, e2 := filepath.EvalSymlinks(home)
		if e1 == nil && e2 == nil {
			underHome = strings.HasPrefix(rd+string(filepath.Separator), rh+string(filepath.Separator))
		}
	}
	for {
		if !sameDir(dir, home) {
			path := filepath.Join(dir, ".wpicker", projectFileName)
			b, err := os.ReadFile(path)
			switch {
			case err == nil:
				var l projectLink
				if json.Unmarshal(b, &l) != nil || l.Site == "" {
					return dir, nil, fmt.Errorf("%s is not a valid link file (remove it, or run `wpicker sites unlink` here)", path)
				}
				return dir, &l, nil
			case errors.Is(err, os.ErrNotExist) || errors.Is(err, syscall.ENOTDIR):
				// nothing here: keep looking
			default:
				return dir, nil, fmt.Errorf("cannot read the link file %s: %v", path, err)
			}
		}
		parent := filepath.Dir(dir)
		if !walk || !underHome || parent == dir || sameDir(dir, home) {
			return "", nil, nil
		}
		dir = parent
	}
}

func writeLink(dir string, site config.Site) error {
	d := filepath.Join(dir, ".wpicker")
	if err := os.MkdirAll(d, 0o755); err != nil {
		return err
	}
	b, _ := json.MarshalIndent(projectLink{Site: site.URL, Name: site.Name}, "", "  ")
	return os.WriteFile(filepath.Join(d, projectFileName), append(b, '\n'), 0o644)
}

// resolveSite decides which stored site a command uses. Order:
// --site, $WPICKER_SITE, the folder's link, the default (or the only site).
// folderBound commands act on one working folder (theme pull/push); there, an
// explicit choice that disagrees with the folder's link is an error.
func resolveSite(root string, folderBound bool) (*resolvedSite, error) {
	store, err := config.LoadStore()
	if err != nil {
		return nil, err
	}
	if len(store.Sites) == 0 {
		return nil, errors.New("not logged in: run `wpicker login` first")
	}
	linkDir, link, lerr := findLink(root, !folderBound)
	if lerr != nil {
		return nil, lerr
	}
	res := &resolvedSite{Sites: len(store.Sites), Link: link, LinkDir: linkDir}

	pick := func(sel, src string) error {
		s, err := store.Find(sel)
		if err != nil {
			return fmt.Errorf("--site must name a site you are logged in to (id, name or address); %v. Run `wpicker sites` to see them", err)
		}
		res.Site, res.Source = *s, src
		return nil
	}

	switch {
	case flagSite != "":
		if err := pick(flagSite, srcFlag); err != nil {
			return nil, err
		}
	case os.Getenv("WPICKER_SITE") != "":
		if err := pick(os.Getenv("WPICKER_SITE"), srcEnv); err != nil {
			return nil, fmt.Errorf("$WPICKER_SITE: %w", err)
		}
	case link != nil:
		key, kerr := config.URLKey(link.Site)
		var s *config.Site
		if kerr == nil {
			s = store.ByKey(key)
		}
		if s == nil {
			return nil, fmt.Errorf("this folder is linked to %s (from %s), but you are not logged in to that site.\n"+
				"  If you do not recognise that address, do NOT log in: the file may have come with someone else's repository.\n"+
				"  If it is yours: wpicker login %s",
				link.Site, filepath.Join(linkDir, ".wpicker", projectFileName), link.Site)
		}
		res.Site, res.Source = *s, srcLink
	default:
		var s *config.Site
		if store.Default != 0 {
			s = store.ByID(store.Default)
		}
		src := srcDefault
		if len(store.Sites) == 1 {
			s, src = &store.Sites[0], srcOnly // one site: nothing to choose, whatever the default says
		}
		if s == nil {
			names := make([]string, 0, len(store.Sites))
			for _, x := range store.Sorted() {
				names = append(names, fmt.Sprintf("%d %s", x.ID, x.Name))
			}
			return nil, fmt.Errorf("you are logged in to several sites (%s) and none was chosen for this folder.\n"+
				"  Pass --site <name>, or link this folder: wpicker sites link <name>", strings.Join(names, ", "))
		}
		res.Site, res.Source = *s, src
	}

	// An explicit choice must not contradict the folder a folder-bound command works on.
	if folderBound && link != nil && (res.Source == srcFlag || res.Source == srcEnv) {
		if key, err := config.URLKey(link.Site); err == nil {
			if cur, _ := config.URLKey(res.Site.URL); cur != key {
				return nil, fmt.Errorf("this folder is linked to %s, but %s asks for %s.\n"+
					"  Pushing one site's theme to another is almost never what you want. Use another folder, or `wpicker sites unlink` here first.",
					link.Site, map[string]string{srcFlag: "--site", srcEnv: "$WPICKER_SITE"}[res.Source], res.Site.URL)
			}
		}
	}

	// An environment variable left set in a shell is ambient, not a deliberate
	// choice: it may not steer a write away from the folder's link either.
	if curWrites && !folderBound && link != nil && res.Source == srcEnv {
		if key, err := config.URLKey(link.Site); err == nil {
			if cur, _ := config.URLKey(res.Site.URL); cur != key {
				return nil, fmt.Errorf("$WPICKER_SITE names %s, but this folder is linked to %s, and this command changes a site.\n"+
					"  Unset the variable, or pass --site to say you mean it.", res.Site.URL, link.Site)
			}
		}
	}

	if res.Site.Disabled {
		return nil, fmt.Errorf("site %q is stopped. Start it again with: wpicker sites start %d", res.Site.Name, res.Site.ID)
	}

	// With several sites, a command that changes one must not rely on a guess.
	if curWrites && res.Sites > 1 && res.Source == srcDefault {
		return nil, fmt.Errorf("this command changes a site, you have several, and none was chosen for this folder (it would use %q only because it is the default).\n"+
			"  Pass --site <name>, or link this folder: wpicker sites link <name>", res.Site.Name)
	}
	return res, nil
}

// announce says which site is in use whenever there is more than one to confuse.
func announce(r *resolvedSite) {
	if r.Sites > 1 && !flagJSON {
		fmt.Fprintf(os.Stderr, "site: %s (%s)\n", r.Site.Name, r.why())
	}
}

// loadConfig is the best-effort view of the site for the current folder; an
// empty Config means "none yet" (callers that need one use mustClient).
func loadConfig() (*config.Config, error) {
	if loadedCfg != nil {
		return loadedCfg, nil
	}
	cwd, _ := os.Getwd()
	prev := curWrites
	curWrites = false // a read-only peek must not trip the write guard
	r, err := resolveSite(cwd, false)
	curWrites = prev
	if err != nil {
		return &config.Config{}, nil
	}
	loadedCfg = r.Site.View()
	return loadedCfg, nil
}

// mustClient returns a client for the site this folder uses.
func mustClient() (*client.Client, *config.Config, error) {
	cwd, _ := os.Getwd()
	return mustClientAt(cwd, false)
}

// lastResolved is what the most recent mustClient/mustClientAt chose, for
// commands that also need to know where the choice came from.
var lastResolved *resolvedSite

// mustClientAt is mustClient for a command that works on a specific folder.
func mustClientAt(root string, folderBound bool) (*client.Client, *config.Config, error) {
	r, err := resolveSite(root, folderBound)
	if err != nil {
		return nil, nil, err
	}
	lastResolved = r
	announce(r)
	cfg := r.Site.View()
	loadedCfg = cfg
	return client.New(cfg.Site, cfg.Username, cfg.AppPassword, nil), cfg, nil
}

// linkFolder remembers that dir works with site: the file in the folder, and
// the folder in the site's list (for `wpicker sites`).
func linkFolder(dir string, site config.Site) error {
	abs, err := filepath.Abs(dir)
	if err != nil {
		return err
	}
	if err := writeLink(abs, site); err != nil {
		return err
	}
	return config.Update(func(s *config.Store) error {
		// A folder belongs to one site at a time: moving it takes it off the previous one's list.
		for i := range s.Sites {
			if s.Sites[i].ID != site.ID {
				s.Sites[i].RemoveFolder(abs)
			}
		}
		if x := s.ByID(site.ID); x != nil {
			x.AddFolder(abs)
		}
		return nil
	})
}

// autoLinkTheme links a theme working folder to the site it was just used with,
// when it has no link yet. Existing folders (they hold last-pull.json) get
// linked the first time any theme command runs, so upgrading does not trap
// anyone with several sites in refusals.
func autoLinkTheme(root string, r *resolvedSite, creating bool) {
	if _, l, _ := findLink(root, false); l != nil {
		return
	}
	if !creating {
		if _, err := os.Stat(filepath.Join(root, ".wpicker", "last-pull.json")); err != nil {
			return
		}
		// An existing folder does not record which site it came from. Pinning it to
		// whatever this one run picked could send its theme to the wrong site for
		// good, so only the unambiguous case is linked on its own.
		if r.Source != srcOnly {
			if r.Sites > 1 && !flagJSON {
				fmt.Fprintf(os.Stderr, "note: this folder is not linked to a site. If it came from %q: wpicker sites link %s\n", r.Site.Name, r.Site.Name)
			}
			return
		}
	}
	if err := linkFolder(root, r.Site); err != nil {
		fmt.Fprintf(os.Stderr, "note: could not link this folder to %s: %v\n", r.Site.Name, err)
	}
}

func init() {
	markWrites(themeCmd, rollbackCmd, pageCmd, backupCreateCmd, backupDeleteCmd, backupRestoreCmd, cloneCmd, deviceRevokeCmd, logoutCmd)
}
