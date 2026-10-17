package cmd

import (
	"context"
	"errors"
	"fmt"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"wpicker/internal/client"
	"wpicker/internal/config"
	"wpicker/internal/tui"
)

// Check statuses, shared with the plugin's checklist.
const (
	stOK   = "ok"
	stWarn = "warn"
	stFail = "fail"
	stInfo = "info"
	stSkip = "skip"
)

type check = client.DoctorCheck

var (
	doctorQuick   bool
	doctorOffline bool
	doctorStrict  bool
)

// groupOrder is the order sections appear in.
var groupOrder = []string{"This computer", "Connection", "Site", "Storage", "Pushing code", "Backups", "Access", "AI agents"}

var doctorCmd = &cobra.Command{
	Use:   "doctor",
	Short: "Check that WPicker itself is set up and working: a checklist with fixes.",
	Long: `Audits WPicker, not your site's code: this computer, the connection, the plugin on
the site, and the instruction files your AI agents read. Every problem comes with
the command or setting that fixes it.

  wpicker doctor              the full checklist (the site requests itself to test the health check and
                              whether its storage folder is open to the web; a few seconds)
  wpicker doctor --quick      skip those two tests
  wpicker doctor --offline    only what can be checked on this computer
  wpicker doctor --json       machine-readable

Marks:  ✓ fine   ! worth fixing   ✗ broken   · for your information   – not checked
Exit status is 1 when something is broken (with --strict, also when something is worth fixing).`,
	Args: cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		var checks []check
		checks = append(checks, computerChecks()...)

		serverVersion := ""
		var cfg *config.Config
		res, rerr := resolveSite(mustCwd(), false)
		if rerr == nil {
			cfg = res.Site.View()
			if c := selectionCheck(res); c.Title != "" {
				checks = append(checks, c)
			}
		}
		switch {
		case doctorOffline:
		case rerr != nil:
			checks = append(checks, selectionProblem(rerr))
		default:
			c, _, err := mustClient()
			if err == nil {
				ctx, cancel := context.WithTimeout(ctxFrom(cmd), 60*time.Second)
				dr, derr := c.Doctor(ctx, !doctorQuick)
				cancel()
				if derr != nil {
					checks = append(checks, classifyConnectError(derr, cfg.Site))
				} else {
					serverVersion = dr.PluginVersion
					checks = append(checks, check{ID: "connect", Group: "Connection", Status: stOK, Title: "Reached " + cfg.Site + " and signed in as " + cfg.Username})
					checks = append(checks, versionCheck(Version, serverVersion))
					checks = append(checks, dr.Checks...)
				}
			}
		}

		dir, _ := os.Getwd()
		home, _ := os.UserHomeDir()
		checks = append(checks, agentChecks(dir, home)...)

		sum := summarize(checks)
		if flagJSON {
			tui.New(os.Stdout, true).EmitJSON(map[string]any{"cli_version": Version, "plugin_version": serverVersion, "checks": checks, "summary": sum})
		} else {
			printChecklist(checks, sum, cfg)
		}
		if sum[stFail] > 0 {
			return fmt.Errorf("%d problem(s) found", sum[stFail])
		}
		if doctorStrict && sum[stWarn] > 0 {
			return fmt.Errorf("%d warning(s) found (--strict)", sum[stWarn])
		}
		return nil
	},
}

func summarize(checks []check) map[string]int {
	sum := map[string]int{stOK: 0, stWarn: 0, stFail: 0, stInfo: 0, stSkip: 0}
	for _, c := range checks {
		sum[c.Status]++
	}
	return sum
}

var marks = map[string]string{stOK: "✓", stWarn: "!", stFail: "✗", stInfo: "·", stSkip: "–"}

func printChecklist(checks []check, sum map[string]int, cfg *config.Config) {
	title := "WPicker doctor"
	if cfg != nil && cfg.Site != "" && !doctorOffline {
		title += " — " + cfg.Site
	}
	fmt.Println(title)
	byGroup := map[string][]check{}
	for _, c := range checks {
		byGroup[c.Group] = append(byGroup[c.Group], c)
	}
	order := append([]string(nil), groupOrder...)
	for g := range byGroup { // a group the plugin invented is still shown
		known := false
		for _, o := range order {
			known = known || o == g
		}
		if !known {
			order = append(order, g)
		}
	}
	for _, g := range order {
		list := byGroup[g]
		if len(list) == 0 {
			continue
		}
		fmt.Printf("\n%s\n", g)
		for _, c := range list {
			fmt.Printf("  %s %s\n", marks[c.Status], c.Title)
			if c.Status == stOK || c.Status == stSkip {
				continue
			}
			if c.Detail != "" {
				fmt.Printf("      %s\n", c.Detail)
			}
			if c.Fix != "" {
				fmt.Printf("      → %s\n", c.Fix)
			}
		}
	}
	fmt.Printf("\n%d ✓   %d !   %d ✗", sum[stOK], sum[stWarn], sum[stFail])
	switch {
	case sum[stFail] > 0:
		fmt.Println("   — fix the ✗ items first.")
	case sum[stWarn] > 0:
		fmt.Println("   — working, with things worth fixing.")
	default:
		fmt.Println("   — all good.")
	}
}

// --- checks on this computer -------------------------------------------------------

func computerChecks() []check {
	var out []check
	out = append(out, check{ID: "cli", Group: "This computer", Status: stOK, Title: "WPicker CLI " + Version})

	exe, _ := os.Executable()
	onPath, _ := exec.LookPath("wpicker")
	out = append(out, binaryCheck(exe, onPath))

	if home, err := os.UserHomeDir(); err == nil {
		for _, name := range []string{config.SitesFileName, config.FileName} {
			p := filepath.Join(home, config.DirName, name)
			if st, err := os.Stat(p); err == nil {
				out = append(out, configPermsCheck(st.Mode().Perm(), p))
			}
		}
	}
	if cfg, _ := loadConfig(); cfg != nil && cfg.Site != "" {
		out = append(out, schemeCheck(cfg.Site))
	}
	if st, err := os.Stat(filepath.Join(".wpicker", "last-error.json")); err == nil {
		out = append(out, check{ID: "last_error", Group: "This computer", Status: stWarn,
			Title:  "The last push in this folder failed (" + st.ModTime().Format("2006-01-02 15:04") + ")",
			Detail: "Details are in .wpicker/last-error.json.",
			Fix:    "Fix the code and run `wpicker theme push` again, or `wpicker history` to roll back."})
	}
	return out
}

func binaryCheck(exe, onPath string) check {
	same := func(a, b string) bool {
		ra, ea := filepath.EvalSymlinks(a)
		rb, eb := filepath.EvalSymlinks(b)
		if ea != nil || eb != nil {
			return a == b
		}
		return ra == rb
	}
	switch {
	case onPath == "":
		return check{ID: "binary", Group: "This computer", Status: stWarn, Title: "`wpicker` is not on your PATH", Detail: "You are running " + exe + ". AI agents call `wpicker` by name and will not find it.", Fix: "Install it to a folder on your PATH (the install script uses /usr/local/bin)."}
	case exe != "" && !same(exe, onPath):
		return check{ID: "binary", Group: "This computer", Status: stWarn, Title: "Two different wpicker programs", Detail: "This one is " + exe + ", but your shell and your agents run " + onPath + ".", Fix: "Remove the one you do not want, or run `wpicker update` on the one on PATH."}
	}
	return check{ID: "binary", Group: "This computer", Status: stOK, Title: "Installed at " + onPath}
}

func configPermsCheck(mode os.FileMode, path string) check {
	if mode&0o077 != 0 {
		return check{ID: "config_perms", Group: "This computer", Status: stWarn, Title: "Your login file (" + filepath.Base(path) + ") can be read by other users",
			Detail: path + " holds the site's Application Password (mode " + fmt.Sprintf("%04o", mode) + ").", Fix: "chmod 600 " + path}
	}
	return check{ID: "config_perms", Group: "This computer", Status: stOK, Title: filepath.Base(path) + " is private to you"}
}

func schemeCheck(site string) check {
	u, err := url.Parse(site)
	if err != nil || u.Host == "" {
		return check{ID: "scheme", Group: "This computer", Status: stFail, Title: "The saved site URL is not valid: " + site, Fix: "Run `wpicker login` again."}
	}
	host := strings.ToLower(u.Hostname())
	local := host == "localhost" || host == "127.0.0.1" || host == "::1" || strings.HasSuffix(host, ".local") || strings.HasSuffix(host, ".test") || strings.HasSuffix(host, ".localhost")
	if u.Scheme == "https" {
		return check{ID: "scheme", Group: "This computer", Status: stOK, Title: "Talking to the site over HTTPS"}
	}
	if local {
		return check{ID: "scheme", Group: "This computer", Status: stInfo, Title: "Talking to a local site over HTTP (fine)"}
	}
	return check{ID: "scheme", Group: "This computer", Status: stWarn, Title: "Talking to the site over plain HTTP",
		Detail: "Your Application Password travels unencrypted.", Fix: "Log in again using the https:// address."}
}

// --- connection ----------------------------------------------------------------------

func versionCheck(cli, plugin string) check {
	switch {
	case plugin == "":
		return check{ID: "versions", Group: "Connection", Status: stInfo, Title: "CLI " + cli + " (the plugin did not report its version)"}
	case cli == plugin:
		return check{ID: "versions", Group: "Connection", Status: stOK, Title: "CLI and plugin are both " + cli}
	}
	return check{ID: "versions", Group: "Connection", Status: stWarn, Title: "CLI is " + cli + " but the plugin is " + plugin,
		Detail: "Mismatched versions can disagree about what a request means.", Fix: "Update whichever is older: `wpicker update`, or the plugin in WP-Admin → Plugins."}
}

// classifyConnectError turns a failed request into one actionable line.
func classifyConnectError(err error, site string) check {
	c := check{ID: "connect", Group: "Connection", Status: stFail}
	var re *client.RESTError
	code, msg := "", err.Error()
	if errors.As(err, &re) {
		code, msg = re.Details.Code, re.Details.Message
	}
	low := strings.ToLower(err.Error())
	switch {
	case code == "http_401" || strings.Contains(low, "http 401") || strings.Contains(low, "incorrect_password") || strings.Contains(low, "invalid_username") || code == "rest_not_logged_in":
		c.Title, c.Detail = "The site rejected the saved login", "The device was revoked, the Application Password was deleted, or the user was removed."
		c.Fix = "Run `wpicker login` again."
	case code == "wpicker_rest_forbidden" || code == "rest_forbidden" || code == "http_403" || strings.Contains(low, "http 403"):
		c.Title, c.Detail = "Signed in, but this user may not manage WPicker", msg
		c.Fix = "Log in as an administrator."
	case code == "rest_no_route" || code == "http_404" || strings.Contains(low, "http 404"):
		c.Title, c.Detail = "The WPicker plugin did not answer (404)", "It is deactivated, a security plugin or server rule blocks /wp-json/wpicker/, or permalinks are broken."
		c.Fix = "Activate WPicker in WP-Admin → Plugins, and check that " + strings.TrimRight(site, "/") + "/wp-json/ opens."
	case strings.Contains(low, "no such host") || strings.Contains(low, "connection refused") || strings.Contains(low, "deadline exceeded") || strings.Contains(low, "timeout") || strings.Contains(low, "i/o timeout"):
		c.Title, c.Detail = "Cannot reach "+site, msg
		c.Fix = "Check the address and your connection; for a local site, check that it is running."
	case strings.Contains(low, "certificate") || strings.Contains(low, "x509"):
		c.Title, c.Detail = "The site's HTTPS certificate is not trusted", msg
		c.Fix = "Fix or renew the certificate."
	case strings.Contains(low, "http 5"):
		c.Title, c.Detail = "The site answered with a server error", msg
		c.Fix = "Run `wpicker logs --since 15m --level error` if that works, or look at the hosting error log."
	default:
		c.Title, c.Detail = "Could not get an answer from the site", msg
		c.Fix = "Run again with --verbose, or check the site in a browser."
	}
	return c
}

// --- AI agent files --------------------------------------------------------------------

var versionInMarker = regexp.MustCompile(`wpicker(?:-generated|:begin) v([0-9][0-9A-Za-z.\-]*)`)

// agentChecks audits the instruction files WPicker wrote for agents.
func agentChecks(dir, home string) []check {
	var out []check
	seen := map[string]bool{}
	found := 0
	for _, t := range allTargets() {
		for _, global := range []bool{false, true} {
			path, root, ok := t.pathFor(dir, home, global)
			if !ok || seen[path] {
				continue
			}
			seen[path] = true
			if _, err := os.Lstat(path); err != nil {
				continue
			}
			if !ownedByWPicker(path, t.shared) {
				continue
			}
			found++
			where := path
			if global {
				where = "~/" + t.global
			} else {
				where = t.project
			}
			if _, err := resolveWithin(path, root); err != nil {
				out = append(out, check{ID: "agent_" + t.key, Group: "AI agents", Status: stWarn, Title: t.name + ": " + where + " leads outside its folder",
					Detail: "It is a symbolic link pointing elsewhere, so WPicker will not refresh it.", Fix: "Replace the link with a real file, then run `wpicker connect`."})
				continue
			}
			b, _ := os.ReadFile(path)
			m := versionInMarker.FindSubmatch(b)
			switch {
			case m == nil:
				out = append(out, check{ID: "agent_" + t.key, Group: "AI agents", Status: stWarn, Title: t.name + ": " + where + " has no version", Fix: "Run `wpicker connect --refresh`."})
			case string(m[1]) != Version:
				out = append(out, check{ID: "agent_" + t.key, Group: "AI agents", Status: stWarn, Title: t.name + ": " + where + " is from WPicker " + string(m[1]) + ", this is " + Version,
					Detail: "The agent is following out-of-date instructions.", Fix: "Run `wpicker connect --refresh`."})
			default:
				out = append(out, check{ID: "agent_" + t.key, Group: "AI agents", Status: stOK, Title: t.name + ": " + where + " is current"})
			}
		}
	}
	if found == 0 {
		out = append(out, check{ID: "agents", Group: "AI agents", Status: stInfo, Title: "No AI agent has WPicker instructions yet",
			Detail: "Agents will not know how to use `wpicker` on their own.", Fix: "Run `wpicker connect` (every agent found on this computer), or `wpicker connect <agent>`."})
	}
	_, problems := loadAdapters()
	for _, p := range problems {
		out = append(out, check{ID: "adapter", Group: "AI agents", Status: stWarn, Title: "An adapter file in ~/.wpicker/adapters was ignored",
			Detail: p, Fix: "Fix or delete that file; see `wpicker connect --help` for the format."})
	}
	return out
}

func init() {
	doctorCmd.Flags().BoolVar(&doctorQuick, "quick", false, "skip the tests where the site requests itself (health check, storage folder exposure)")
	doctorCmd.Flags().BoolVar(&doctorOffline, "offline", false, "only check this computer; do not contact the site")
	doctorCmd.Flags().BoolVar(&doctorStrict, "strict", false, "exit 1 for warnings too (for scripts and CI)")
	rootCmd.AddCommand(doctorCmd)
}

// selectionCheck reports which site this folder uses when that is worth saying.
func selectionCheck(r *resolvedSite) check {
	c := check{ID: "site", Group: "Connection"}
	switch {
	case r.Source == srcLink:
		c.Status, c.Title = stOK, fmt.Sprintf("This folder is linked to site %d %q", r.Site.ID, r.Site.Name)
	case r.Sites > 1 && r.Source == srcDefault:
		c.Status, c.Title = stWarn, fmt.Sprintf("This folder is not linked to a site (using the default, %q)", r.Site.Name)
		c.Detail = "You have several sites. Commands that change a site refuse to use the default."
		c.Fix = "wpicker sites link " + r.Site.Name
	case r.Sites > 1:
		c.Status, c.Title = stInfo, fmt.Sprintf("Using site %q (%s)", r.Site.Name, r.why())
	}
	return c
}

// selectionProblem turns "no site could be chosen" into one checklist line.
func selectionProblem(err error) check {
	msg := err.Error()
	title, detail, _ := strings.Cut(msg, "\n")
	c := check{ID: "login", Group: "Connection", Status: stFail, Title: title, Detail: strings.TrimSpace(detail)}
	switch {
	case strings.HasPrefix(msg, "not logged in"):
		c.Title, c.Fix = "Not logged in to any site", "Run `wpicker login <site address>`."
	case strings.Contains(msg, "is stopped"):
		c.Fix = "Start it again with `wpicker sites start <id>`."
	case strings.Contains(msg, "several sites"):
		c.Fix = "`wpicker sites link <name>` in this folder, or pass --site."
	case strings.Contains(msg, "you are not logged in to that site"):
		c.Fix = "`wpicker sites`, then `wpicker login <address>` only if you recognise the address."
	}
	return c
}
