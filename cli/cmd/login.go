package cmd

import (
	"bufio"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"

	"github.com/spf13/cobra"

	"wpicker/internal/auth"
	"wpicker/internal/config"
)

var loginName string

var loginCmd = &cobra.Command{
	Use:   "login [site-address]",
	Short: "Pair this computer with a WordPress site (add it, or pair it again).",
	Long: `Authenticate against a WordPress site running the WPicker plugin and add it to
your list of sites (` + "`wpicker sites`" + `). Logging in to a second site does not touch the first.

  wpicker login                       asks for the address
  wpicker login https://shop.example  pairs that site (again, if it is already listed)
  wpicker login https://shop.example --name shop

You will be asked for:
  - an admin username + password (used ONLY to pair, never stored),
  - a 6-digit PIN that the plugin shows in WP-Admin → WPicker → Devices.

On success, a WordPress Application Password is stored in ~/` + config.DirName + `/` + config.SitesFileName + `
(readable only by you).`,
	Args: cobra.MaximumNArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		prefill := auth.Prefill{}
		if len(args) == 1 {
			url, err := config.NormalizeURL(args[0])
			if err != nil {
				return err
			}
			prefill.URL = url
			if store, err := config.LoadStore(); err == nil {
				if key, _ := config.URLKey(url); store.ByKey(key) != nil {
					prefill.Username = store.ByKey(key).Username
				}
			}
		}
		return pairAndSave(cmd, prefill, loginName)
	},
}

// pairAndSave runs the pairing and stores the result next to the other sites.
// The store lock is taken only for the write, never while waiting for the PIN.
func pairAndSave(cmd *cobra.Command, prefill auth.Prefill, name string) error {
	// A damaged store must stop us BEFORE the pairing: it creates an Application
	// Password on the site, and a login that cannot be saved would orphan it.
	if _, err := config.LoadStore(); err != nil {
		return err
	}
	if name != "" && !config.ValidName(name) {
		return fmt.Errorf("--name %q would be mistaken for an id or an address: use letters, digits and dashes, not only digits", name)
	}
	res, err := auth.Login(cmd.Context(), prefill, Version, os.Stdin, os.Stdout)
	if err != nil {
		return err
	}
	var saved config.Site
	err = config.Update(func(s *config.Store) error {
		site := s.Upsert(config.Site{
			URL: res.URL, Username: res.Username, AppPassword: res.AppPassword,
			DeviceID: res.DeviceID, DeviceName: res.DeviceName,
		}, name)
		saved = *site
		return nil
	})
	if err != nil {
		return fmt.Errorf("save login: %w", err)
	}
	loadedCfg = nil
	fmt.Printf("✓ Saved as site %d %q. See all of them with `wpicker sites`.\n", saved.ID, saved.Name)
	fmt.Printf("  Credentials: ~/%s/%s (private to you)\n", config.DirName, config.SitesFileName)

	dir, _ := os.Getwd()
	home, _ := os.UserHomeDir()
	total := 0
	if st, err := config.LoadStore(); err == nil {
		total = len(st.Sites)
	}
	offerLink(dir, home, saved, total, isTerminal(os.Stdin), bufio.NewReader(os.Stdin), os.Stdout)
	connectAfterLogin(dir, home)
	return nil
}

// offerLink saves a step: right after logging in from inside a project folder,
// offer to use the new site in that folder. It only matters when there are
// several sites (with one, nothing needs choosing), it never links without a
// yes, and it never touches a folder that already has a link or is home.
// It reports whether it linked.
func offerLink(dir, home string, site config.Site, total int, tty bool, in *bufio.Reader, out io.Writer) bool {
	if total < 2 || sameDir(dir, home) {
		return false
	}
	if _, l, _ := findLink(dir, false); l != nil {
		return false
	}
	if !tty {
		fmt.Fprintf(out, "  To use it in this folder: wpicker sites link %s\n", site.Name)
		return false
	}
	fmt.Fprintf(out, "\nUse %q in this folder (%s)? Commands here will then go to it. [y/N] ", site.Name, tilde(dir))
	ans, _ := in.ReadString('\n')
	if a := strings.ToLower(strings.TrimSpace(ans)); a != "y" && a != "yes" {
		fmt.Fprintf(out, "  Later: wpicker sites link %s\n", site.Name)
		return false
	}
	if err := linkFolder(dir, site); err != nil {
		fmt.Fprintf(out, "  could not link this folder: %v\n", err)
		return false
	}
	fmt.Fprintf(out, "✓ %s now uses %q.\n", tilde(dir), site.Name)
	return true
}

var logoutCmd = &cobra.Command{
	Use:    "logout",
	Short:  "Remove this folder's site from this computer (same as `sites delete --local-only`).",
	Hidden: true,
	RunE: func(cmd *cobra.Command, args []string) error {
		curWrites = true // it forgets a login: never on the strength of a default, whatever called us
		r, err := resolveSite(mustCwd(), false)
		if err != nil {
			return err
		}
		return deleteSite(r.Site, true)
	},
}

var whoamiCmd = &cobra.Command{
	Use:   "whoami",
	Short: "Which site does THIS folder use, and why?",
	Long: `Shows the site, user and device this folder will act on, and where that choice
came from: --site, $WPICKER_SITE, a link in this folder (` + "`wpicker sites link`" + `), the
default site, or your only site.`,
	RunE: func(cmd *cobra.Command, args []string) error {
		cwd := mustCwd()
		r, err := resolveSite(cwd, false)
		if err != nil {
			return err
		}
		linked := ""
		if r.Source == srcLink {
			linked = r.LinkDir
		}
		if flagJSON {
			emitJSON(map[string]any{
				"site": r.Site.URL, "username": r.Site.Username,
				"device_id": r.Site.DeviceID, "device_name": r.Site.DeviceName,
				"id": r.Site.ID, "name": r.Site.Name, "source": r.Source, "linked_folder": linked,
			})
			return nil
		}
		fmt.Printf("Site:    %s  (%d %s)\n", r.Site.URL, r.Site.ID, r.Site.Name)
		fmt.Printf("User:    %s\n", r.Site.Username)
		fmt.Printf("Device:  %s (%s)\n", r.Site.DeviceName, r.Site.DeviceID)
		fmt.Printf("Chosen:  %s\n", r.why())
		if linked != "" {
			fmt.Printf("Folder:  linked (%s)\n", tilde(filepath.Join(linked, ".wpicker", projectFileName)))
		} else if r.Sites > 1 {
			fmt.Printf("Folder:  %s is not linked. Link it: wpicker sites link %s\n", tilde(cwd), r.Site.Name)
		}
		return nil
	},
}

func mustCwd() string {
	d, err := os.Getwd()
	if err != nil {
		return "."
	}
	return d
}

func tilde(p string) string {
	if home, err := os.UserHomeDir(); err == nil && home != "" {
		if p == home {
			return "~"
		}
		if len(p) > len(home) && p[:len(home)+1] == home+string(filepath.Separator) {
			return "~" + p[len(home):]
		}
	}
	return p
}

func init() {
	loginCmd.Flags().StringVar(&loginName, "name", "", "short name for the site (default: made from its address)")
}
