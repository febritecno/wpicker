package cmd

import (
	"bufio"
	"context"
	"fmt"
	"io"
	"os"
	"os/signal"
	"path/filepath"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"wpicker/internal/auth"
	"wpicker/internal/config"
)

var (
	loginName      string
	loginDevice    string
	loginPassword  bool
	loginNoBrowser bool
)

var loginCmd = &cobra.Command{
	Use:   "login [site-address]",
	Short: "Pair this computer with a WordPress site (add it, or pair it again).",
	Long: `Connects this computer to a WordPress site running the WPicker plugin and adds it
to your list of sites (` + "`wpicker sites`" + `). Logging in to a second site does not touch the first.

  wpicker login https://shop.example.com

It prints a link and opens it in your browser. There you log in to WordPress as
usual (so your password and any two-factor plugin apply), see which site and which
device are asking, check that the code matches the one printed here, and press
Approve. This window then finishes by itself. Nothing is typed into the terminal,
and your password never reaches this program.

  wpicker login                                asks only for the site address
  wpicker login https://shop.example --no-browser   print the link, do not open it
  wpicker login https://shop.example --name shop    a short name for the site
  wpicker login https://shop.example --device work-mac

Only approve a request you started yourself, a moment ago, on your own computer.
Never approve a link somebody sent you.

Older plugin (before 1.3.0), or a site where you prefer it: pair with the admin
password and the PIN shown in WP-Admin instead:

  wpicker login https://shop.example --password

Either way a revocable WordPress Application Password is stored in ~/` + config.DirName + `/` + config.SitesFileName + `
(readable only by you); your own password is never stored.`,
	Args: cobra.MaximumNArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		o := loginOpts{Name: loginName, Device: loginDevice, Password: loginPassword, NoBrowser: loginNoBrowser}
		if len(args) == 1 {
			o.Site = args[0]
			if url, err := config.NormalizeURL(o.Site); err == nil {
				o.Site = url
				if store, err := config.LoadStore(); err == nil {
					if key, _ := config.URLKey(url); store.ByKey(key) != nil {
						o.Username = store.ByKey(key).Username
					}
				}
			}
		}
		return runLogin(cmd, o)
	},
}

// deviceWait replaces the polling clock in tests; nil means the real one.
var deviceWait func(ctx context.Context, d time.Duration) error

// loginOpts is one pairing: what is known and how to pair.
type loginOpts struct {
	Site      string
	Fixed     bool   // the site is a listed one being paired again: do not ask for it
	Username  string // for the password flow
	Name      string // short name for the site
	Device    string
	Password  bool // use the older username + password + PIN flow
	NoBrowser bool
}

// runLogin pairs and stores the result next to the other sites. The store lock
// is taken only for the write, never while waiting for a person or the network.
func runLogin(cmd *cobra.Command, o loginOpts) error {
	// A damaged store must stop us BEFORE the pairing: it creates an Application
	// Password on the site, and a login that cannot be saved would orphan it.
	if _, err := config.LoadStore(); err != nil {
		return err
	}
	if o.Name != "" && !config.ValidName(o.Name) {
		return fmt.Errorf("--name %q would be mistaken for an id or an address: use letters, digits and dashes, not only digits", o.Name)
	}

	ctx := cmd.Context()
	if ctx == nil {
		ctx = context.Background()
	}
	var res *auth.Result
	var err error
	if o.Password {
		res, err = auth.Login(ctx, auth.Prefill{URL: o.Site, Username: o.Username, Fixed: o.Fixed}, Version, os.Stdin, os.Stdout)
	} else {
		do := auth.DeviceOptions{Site: o.Site, Fixed: o.Fixed, Device: o.Device, CLIVersion: Version, Wait: deviceWait}
		// Open the browser only for a person at a terminal: never for a script or an agent.
		if !o.NoBrowser && isTerminal(os.Stdout) && isTerminal(os.Stdin) {
			do.OpenBrowser = auth.OpenBrowser
		}
		stop := cancelOnInterrupt(ctx)
		res, err = auth.DeviceLogin(stop.ctx, do, os.Stdin, os.Stdout)
		stop.done()
	}
	if err != nil {
		return err
	}
	return saveLogin(res, o.Name)
}

// saveLogin stores a completed pairing as a site and says so.
func saveLogin(res *auth.Result, name string) error {
	var saved config.Site
	err := config.Update(func(s *config.Store) error {
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
	fmt.Printf("  Saved as site %d %q. See all of them with `wpicker sites`.\n", saved.ID, saved.Name)

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
	loginCmd.Flags().StringVar(&loginDevice, "device", "", "name for this computer (default: its host name)")
	loginCmd.Flags().BoolVar(&loginPassword, "password", false, "pair with the admin password and a PIN instead of approving in the browser")
	loginCmd.Flags().BoolVar(&loginNoBrowser, "no-browser", false, "print the link but do not open the browser")
	sitesLoginCmd.Flags().StringVar(&loginDevice, "device", "", "name for this computer (default: its host name)")
	sitesLoginCmd.Flags().BoolVar(&loginPassword, "password", false, "pair with the admin password and a PIN instead of approving in the browser")
	sitesLoginCmd.Flags().BoolVar(&loginNoBrowser, "no-browser", false, "print the link but do not open the browser")
}

// interrupt lets Ctrl-C stop a wait without killing the process mid-write.
type interrupt struct {
	ctx  context.Context
	done func()
}

func cancelOnInterrupt(parent context.Context) interrupt {
	ctx, cancel := context.WithCancel(parent)
	ch := make(chan os.Signal, 1)
	signal.Notify(ch, os.Interrupt)
	go func() {
		select {
		case <-ch:
			cancel()
		case <-ctx.Done():
		}
	}()
	return interrupt{ctx: ctx, done: func() { signal.Stop(ch); cancel() }}
}
