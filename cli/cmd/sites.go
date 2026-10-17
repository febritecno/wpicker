package cmd

import (
	"bufio"
	"context"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"time"
	"unicode/utf8"

	"github.com/spf13/cobra"

	"wpicker/internal/auth"
	"wpicker/internal/client"
	"wpicker/internal/config"
	"wpicker/internal/tui"
)

var (
	sitesOffline   bool
	deleteYes      bool
	deleteLocal    bool
	statusTimeout  = 3 * time.Second
	statusParallel = 8
)

// Stable status values (also what `--json` carries).
const (
	stConnected    = "connected"
	stUnauthorized = "unauthorized"
	stNoPlugin     = "no_plugin"
	stOffline      = "offline"
	stStopped      = "stopped"
	stError        = "error"
	stUnchecked    = "unchecked"
)

func emitJSON(v any) { tui.New(os.Stdout, true).EmitJSON(v) }

// statusFromError turns the outcome of a cheap authenticated request into a status.
func statusFromError(err error) string {
	if err == nil {
		return stConnected
	}
	var re *client.RESTError
	code := ""
	if errors.As(err, &re) {
		code = re.Details.Code
	}
	low := strings.ToLower(err.Error())
	switch {
	case code == "http_401" || code == "rest_not_logged_in" || strings.Contains(low, "http 401") || strings.Contains(low, "incorrect_password") || strings.Contains(low, "invalid_username"),
		code == "rest_forbidden" || code == "wpicker_rest_forbidden" || code == "http_403" || strings.Contains(low, "http 403"):
		return stUnauthorized
	case code == "rest_no_route" || code == "http_404" || strings.Contains(low, "http 404"):
		return stNoPlugin
	case strings.Contains(low, "no such host"), strings.Contains(low, "connection refused"), strings.Contains(low, "timeout"),
		strings.Contains(low, "deadline exceeded"), strings.Contains(low, "network is unreachable"), strings.Contains(low, "certificate"), strings.Contains(low, "x509"):
		return stOffline
	}
	return stError
}

type siteRow struct {
	ID         int      `json:"id"`
	Name       string   `json:"name"`
	URL        string   `json:"url"`
	Username   string   `json:"username"`
	DeviceName string   `json:"device_name"`
	DeviceID   string   `json:"device_id"`
	Status     string   `json:"status"`
	Default    bool     `json:"default"`
	Current    bool     `json:"current"`
	Folders    []string `json:"folders"`
}

// checkStatuses asks every site, in parallel with a short timeout, whether it
// answers with the saved credentials. Stopped sites are not contacted.
func checkStatuses(ctx context.Context, sites []config.Site, offline bool) map[int]string {
	out := make(map[int]string, len(sites))
	var mu sync.Mutex
	sem := make(chan struct{}, statusParallel)
	var wg sync.WaitGroup
	// Fill what needs no request BEFORE any goroutine exists: they write to the
	// same map, and a map must not be written from two goroutines at once.
	var live []config.Site
	for _, s := range sites {
		switch {
		case s.Disabled:
			out[s.ID] = stStopped
		case offline:
			out[s.ID] = stUnchecked
		default:
			live = append(live, s)
		}
	}
	for _, s := range live {
		wg.Add(1)
		go func(s config.Site) {
			defer wg.Done()
			sem <- struct{}{}
			defer func() { <-sem }()
			cctx, cancel := context.WithTimeout(ctx, statusTimeout)
			defer cancel()
			_, err := client.New(s.URL, s.Username, s.AppPassword, nil).ListDevices(cctx)
			mu.Lock()
			out[s.ID] = statusFromError(err)
			mu.Unlock()
		}(s)
	}
	wg.Wait()
	return out
}

// liveFolders keeps the linked folders that still exist and still hold a link.
// Pruned for display only: reading must not rewrite the shared store.
func liveFolders(folders []string) []string {
	var out []string
	for _, f := range folders {
		if _, err := os.Stat(filepath.Join(f, ".wpicker", projectFileName)); err == nil {
			out = append(out, f)
		}
	}
	return out
}

var sitesCmd = &cobra.Command{
	Use:     "sites",
	Aliases: []string{"ls"},
	Short:   "List the sites you are logged in to, and which one this folder uses.",
	Long: `Lists every WordPress site paired with this computer, whether it answers right
now, and the folders linked to it. The row marked → is the one this folder uses.

  wpicker sites                      the list (asks each site, a few seconds at most)
  wpicker sites --offline            the list without contacting anything
  wpicker sites login <id|name>      pair a site again (revoked device, new password)
  wpicker sites stop|start <id>      switch a site off/on without forgetting it
  wpicker sites delete <id>          forget a site (and revoke this computer on it)
  wpicker sites use <id>             make it the default
  wpicker sites link [id]            use this site in this folder from now on
  wpicker sites unlink               stop doing that

Status: connected · unauthorized (login revoked or password changed) · no_plugin
(WPicker is not active there) · offline · stopped · error.`,
	Args: cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		store, err := config.LoadStore()
		if err != nil {
			return err
		}
		if len(store.Sites) == 0 {
			if flagJSON {
				emitJSON(map[string]any{"version": 1, "sites": []siteRow{}})
				return nil
			}
			fmt.Println("No sites yet. Pair one with: wpicker login <site address>")
			return nil
		}
		sites := store.Sorted()
		status := checkStatuses(ctxFrom(cmd), sites, sitesOffline)

		curID := 0
		prev := curWrites
		curWrites = false
		if r, err := resolveSite(mustCwd(), false); err == nil {
			curID = r.Site.ID
		}
		curWrites = prev

		rows := make([]siteRow, 0, len(sites))
		for _, s := range sites {
			folders := liveFolders(s.Folders)
			if folders == nil {
				folders = []string{}
			}
			rows = append(rows, siteRow{
				ID: s.ID, Name: s.Name, URL: s.URL, Username: s.Username,
				DeviceName: s.DeviceName, DeviceID: s.DeviceID, Status: status[s.ID],
				Default: s.ID == store.Default, Current: s.ID == curID, Folders: folders,
			})
		}
		if flagJSON {
			emitJSON(map[string]any{"version": 1, "sites": rows}) // never includes a password
			return nil
		}
		printSites(rows)
		return nil
	},
}

func termWidth() int {
	if c, err := strconv.Atoi(os.Getenv("COLUMNS")); err == nil && c >= 60 {
		return c
	}
	return 110
}

func clip(s string, n int) string {
	if n < 2 || utf8.RuneCountInString(s) <= n {
		return s
	}
	r := []rune(s)
	return string(r[:n-1]) + "…"
}

func printSites(rows []siteRow) {
	hdr := []string{"", "ID", "NAME", "SITE", "USER", "STATUS", "FOLDERS"}
	table := make([][]string, 0, len(rows))
	for _, r := range rows {
		mark := " "
		if r.Current {
			mark = "→"
		}
		name := r.Name
		if r.Default {
			name += " ★"
		}
		folders := "-"
		if n := len(r.Folders); n > 0 {
			folders = tilde(r.Folders[0])
			if n > 1 {
				folders += fmt.Sprintf(" +%d", n-1)
			}
		}
		table = append(table, []string{mark, strconv.Itoa(r.ID), name, r.URL, r.Username, r.Status, folders})
	}
	// Column widths: natural, with SITE and FOLDERS squeezed to fit the terminal.
	w := make([]int, len(hdr))
	for i, h := range hdr {
		w[i] = utf8.RuneCountInString(h)
	}
	for _, row := range table {
		for i, c := range row {
			if n := utf8.RuneCountInString(c); n > w[i] {
				w[i] = n
			}
		}
	}
	over := func() int {
		t := len(w) * 2
		for _, x := range w {
			t += x
		}
		return t - termWidth()
	}
	for _, i := range []int{6, 3} { // FOLDERS first, then SITE
		if o := over(); o > 0 {
			w[i] = max(12, w[i]-o)
		}
	}
	line := func(cells []string) {
		for i, c := range cells {
			c = clip(c, w[i])
			if i > 0 {
				fmt.Print("  ")
			}
			fmt.Print(c + strings.Repeat(" ", w[i]-utf8.RuneCountInString(c)))
		}
		fmt.Println()
	}
	line(hdr)
	for _, row := range table {
		line(row)
	}
	legend := "→ used from this folder"
	for _, r := range rows {
		if r.Default {
			legend += "   ★ default site"
			break
		}
	}
	fmt.Println("\n" + legend)
}

func init() {
	sitesCmd.Flags().BoolVar(&sitesOffline, "offline", false, "do not contact the sites (status shows \"unchecked\")")
}

// --- selecting a site for a subcommand ------------------------------------------------

func pickSite(sel string) (config.Site, error) {
	store, err := config.LoadStore()
	if err != nil {
		return config.Site{}, err
	}
	s, err := store.Find(sel)
	if err != nil {
		return config.Site{}, fmt.Errorf("%v. Run `wpicker sites` to see them", err)
	}
	return *s, nil
}

var sitesLoginCmd = &cobra.Command{
	Use:   "login <id|name>",
	Short: "Pair a listed site again (login revoked, password changed).",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		s, err := pickSite(args[0])
		if err != nil {
			return err
		}
		return pairAndSave(cmd, auth.Prefill{URL: s.URL, Username: s.Username, Fixed: true}, s.Name)
	},
}

func setDisabled(sel string, disabled bool) error {
	s, err := pickSite(sel)
	if err != nil {
		return err
	}
	if err := config.Update(func(st *config.Store) error {
		if x := st.ByID(s.ID); x != nil {
			x.Disabled = disabled
		}
		return nil
	}); err != nil {
		return err
	}
	loadedCfg = nil
	if disabled {
		fmt.Printf("✓ Stopped %q. Its login is kept; commands will refuse to use it until `wpicker sites start %d`.\n", s.Name, s.ID)
	} else {
		fmt.Printf("✓ Started %q.\n", s.Name)
	}
	return nil
}

var sitesStopCmd = &cobra.Command{
	Use: "stop <id|name>", Short: "Stop using a site without forgetting it.", Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error { return setDisabled(args[0], true) },
}

var sitesStartCmd = &cobra.Command{
	Use: "start <id|name>", Short: "Use a stopped site again.", Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error { return setDisabled(args[0], false) },
}

var sitesUseCmd = &cobra.Command{
	Use: "use <id|name>", Short: "Make a site the default (used when a folder is not linked).", Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		s, err := pickSite(args[0])
		if err != nil {
			return err
		}
		if err := config.Update(func(st *config.Store) error { st.Default = s.ID; return nil }); err != nil {
			return err
		}
		loadedCfg = nil
		fmt.Printf("✓ %q is now the default site.\n", s.Name)
		return nil
	},
}

var sitesDeleteCmd = &cobra.Command{
	Use:     "delete <id|name>",
	Aliases: []string{"rm", "remove"},
	Short:   "Forget a site, and revoke this computer's access on it.",
	Long: `Removes the site and its saved login from this computer. First this computer's
device is revoked on the site itself, so the saved password stops working there.
If the site cannot be reached, nothing is deleted (the saved login is the only way
to revoke later) unless you pass --local-only; then revoke it by hand in WP-Admin →
WPicker → Devices.`,
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		s, err := pickSite(args[0])
		if err != nil {
			return err
		}
		if !deleteYes {
			if !isTerminal(os.Stdin) {
				return errors.New("refusing to delete without confirmation: add --yes")
			}
			fmt.Fprintf(os.Stderr, "Delete site %d %q (%s)? [y/N] ", s.ID, s.Name, s.URL)
			ans, _ := bufio.NewReader(os.Stdin).ReadString('\n')
			if a := strings.ToLower(strings.TrimSpace(ans)); a != "y" && a != "yes" {
				return errors.New("not deleted")
			}
		}
		return deleteSite(s, deleteLocal)
	},
}

// goneOnServer: the device is already unusable there, so there is nothing to revoke.
func goneOnServer(err error) bool {
	return statusFromError(err) == stUnauthorized || statusFromError(err) == stNoPlugin
}

func deleteSite(s config.Site, localOnly bool) error {
	if !localOnly {
		ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
		defer cancel()
		err := client.New(s.URL, s.Username, s.AppPassword, nil).RevokeDevice(ctx, s.DeviceID)
		if err != nil && !goneOnServer(err) {
			return fmt.Errorf("could not revoke this device on %s: %v\n"+
				"  Nothing was deleted: the saved login is the only way to revoke it later.\n"+
				"  Try again when the site is reachable, or force it with --local-only (then revoke in WP-Admin → WPicker → Devices).", s.URL, err)
		}
	}
	folders := liveFolders(s.Folders)
	if err := config.Update(func(st *config.Store) error { st.Remove(s.ID); return nil }); err != nil {
		return err
	}
	removeLegacyLogin(s)
	loadedCfg = nil
	fmt.Printf("✓ Deleted site %d %q.\n", s.ID, s.Name)
	if localOnly {
		fmt.Printf("  This computer's device was NOT revoked on the site: do it in WP-Admin → WPicker → Devices (%q).\n", s.DeviceName)
	}
	if len(folders) > 0 {
		fmt.Printf("  %d folder(s) are still linked to it and will say you are not logged in; `wpicker sites unlink` in each, or ignore.\n", len(folders))
	}
	return nil
}

// removeLegacyLogin deletes the original config.json when it holds this very
// login, so the secret does not stay behind (and cannot be imported back).
func removeLegacyLogin(s config.Site) {
	old, err := config.Load()
	if err != nil || !old.IsLoggedIn() {
		return
	}
	a, _ := config.URLKey(old.Site)
	b, _ := config.URLKey(s.URL)
	if a == b && old.DeviceID == s.DeviceID {
		if p, err := config.Path(); err == nil {
			_ = os.Remove(p)
		}
	}
}

var sitesLinkCmd = &cobra.Command{
	Use:   "link [id|name]",
	Short: "Use a site in this folder from now on.",
	Long: `Writes .wpicker/project.json here, so every wpicker command run in this folder
(or below it) uses that site, whatever the default is. The file holds no secrets
and may be committed; it only picks one of the sites you are logged in to.`,
	Args: cobra.MaximumNArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		var s config.Site
		if len(args) == 1 {
			var err error
			if s, err = pickSite(args[0]); err != nil {
				return err
			}
		} else {
			store, err := config.LoadStore()
			if err != nil {
				return err
			}
			switch {
			case len(store.Sites) == 1:
				s = store.Sites[0]
			default:
				return errors.New("which site? `wpicker sites link <id|name>` (see `wpicker sites`)")
			}
		}
		cwd := mustCwd()
		if home, _ := os.UserHomeDir(); sameDir(cwd, home) {
			return errors.New("your home folder cannot be linked (wpicker keeps its own files there); go into a project folder first")
		}
		if s.Disabled {
			return fmt.Errorf("site %q is stopped; start it first: wpicker sites start %d", s.Name, s.ID)
		}
		if err := linkFolder(cwd, s); err != nil {
			return err
		}
		fmt.Printf("✓ %s now uses site %d %q (%s).\n", tilde(cwd), s.ID, s.Name, s.URL)
		return nil
	},
}

var sitesUnlinkCmd = &cobra.Command{
	Use:   "unlink",
	Short: "Stop using a fixed site in this folder.",
	Args:  cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		cwd, err := filepath.Abs(mustCwd())
		if err != nil {
			return err
		}
		file := filepath.Join(cwd, ".wpicker", projectFileName)
		if _, err := os.Stat(file); err != nil {
			// Not here. Say so, and say where the link in force comes from, if any.
			if dir, link, _ := findLink(cwd, true); link != nil {
				fmt.Printf("This folder has no link of its own; it inherits the one in %s. Run `wpicker sites unlink` there.\n", tilde(dir))
			} else {
				fmt.Println("This folder is not linked.")
			}
			return nil
		}
		if err := os.Remove(file); err != nil { // also removes a broken or unreadable link file
			return err
		}
		_ = config.Update(func(st *config.Store) error {
			for i := range st.Sites {
				st.Sites[i].RemoveFolder(cwd)
			}
			return nil
		})
		fmt.Printf("✓ %s is no longer linked.\n", tilde(cwd))
		return nil
	},
}

func init() {
	sitesDeleteCmd.Flags().BoolVarP(&deleteYes, "yes", "y", false, "do not ask for confirmation")
	sitesDeleteCmd.Flags().BoolVar(&deleteLocal, "local-only", false, "delete here even if the device cannot be revoked on the site")
	sitesCmd.AddCommand(sitesLoginCmd, sitesStopCmd, sitesStartCmd, sitesUseCmd, sitesDeleteCmd, sitesLinkCmd, sitesUnlinkCmd)
	rootCmd.AddCommand(sitesCmd)
}
