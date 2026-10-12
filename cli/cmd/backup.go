package cmd

import (
	"bufio"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"wpicker/internal/client"
	"wpicker/internal/config"
	"wpicker/internal/migrate"
	"wpicker/internal/tui"
)

var (
	backupNote      string
	backupNoDB      bool
	backupNoPlugins bool
	backupNoThemes  bool
	backupNoUploads bool
	backupOut       string

	restorePath     string
	restoreURL      string
	restoreFile     string
	restoreLatest   bool
	restoreYes      bool
	restoreKeep     bool
	restoreDBHost   string
	restoreDBPort   int
	restoreDBSocket string
	restoreDBName   string
	restoreDBUser   string
	restoreDBPass   string

	cloneKeepRemote bool
)

var backupCmd = &cobra.Command{
	Use:   "backup",
	Short: "Full-site backups: create on the site, restore onto a local WordPress.",
	Long: `A backup is one file holding the site's database, plugins, themes and uploads.
Backups live on the site (WP-Admin → WPicker → Backups) and can be created
there or from here.

  wpicker backup list                      what is on the site
  wpicker backup create --note "pre-launch"
  wpicker backup restore --path ./public   pick one and rebuild it locally
  wpicker clone --path ./public            create a fresh one and restore it`,
}

// backup list ------------------------------------------------------------------

var backupListCmd = &cobra.Command{
	Use:   "list",
	Short: "List the backups stored on the site.",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, _, err := mustClient()
		if err != nil {
			return err
		}
		backups, err := c.ListBackups(ctxFrom(cmd))
		if err != nil {
			return err
		}
		if flagJSON {
			tui.New(os.Stdout, true).EmitJSON(map[string]any{"backups": backups})
			return nil
		}
		if len(backups) == 0 {
			fmt.Println("No backups on the site yet. Create one with `wpicker backup create`.")
			return nil
		}
		printBackups(backups)
		return nil
	},
}

func printBackups(backups []client.Backup) {
	fmt.Printf("%-4s %-24s %-17s %-10s %-32s %s\n", "#", "BACKUP", "CREATED", "SIZE", "CONTAINS", "NOTE")
	for i, b := range backups {
		status := ""
		if !b.Done {
			status = " (incomplete)"
		}
		fmt.Printf("%-4d %-24s %-17s %-10s %-32s %s%s\n", i+1, b.ID, shortTime(b.CreatedAt), humanBytes(b.Bytes), contains(b.Include), b.Label, status)
	}
}

func contains(include map[string]bool) string {
	var parts []string
	for _, key := range []string{"db", "plugins", "themes", "uploads"} {
		if include[key] {
			parts = append(parts, key)
		}
	}
	return strings.Join(parts, ",")
}

func shortTime(iso string) string {
	t, err := time.Parse(time.RFC3339, iso)
	if err != nil {
		return iso
	}
	return t.Local().Format("2006-01-02 15:04")
}

func humanBytes(n int64) string {
	const unit = 1024
	if n < unit {
		return fmt.Sprintf("%d B", n)
	}
	div, exp := int64(unit), 0
	for m := n / unit; m >= unit; m /= unit {
		div *= unit
		exp++
	}
	return fmt.Sprintf("%.1f %cB", float64(n)/float64(div), "KMGT"[exp])
}

// backup create ----------------------------------------------------------------

var backupCreateCmd = &cobra.Command{
	Use:   "create",
	Short: "Create a new backup on the site.",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, _, err := mustClient()
		if err != nil {
			return err
		}
		b, err := createBackup(cmd, c)
		if err != nil {
			return err
		}
		if flagJSON {
			tui.New(os.Stdout, true).EmitJSON(b)
			return nil
		}
		fmt.Printf("✓ Backup %s created (%s, %d tables, %d files).\n", b.ID, humanBytes(b.Bytes), b.TablesDone, b.FilesDone)
		if b.FilesSkipped > 0 {
			fmt.Printf("  %d file(s) could not be read on the server and were left out.\n", b.FilesSkipped)
		}
		return nil
	},
}

func includeFromFlags() map[string]bool {
	return map[string]bool{
		"db":      !backupNoDB,
		"plugins": !backupNoPlugins,
		"themes":  !backupNoThemes,
		"uploads": !backupNoUploads,
	}
}

// createBackup starts a backup and drives it to completion, one step at a time.
func createBackup(cmd *cobra.Command, c *client.Client) (*client.Backup, error) {
	include := includeFromFlags()
	if contains(include) == "" {
		return nil, errors.New("nothing to back up: every part was excluded")
	}
	b, err := c.StartBackup(ctxFrom(cmd), backupNote, include)
	if err != nil {
		return nil, err
	}
	status := newStatusLine()
	failures := 0
	for !b.Done {
		switch b.Phase {
		case "db":
			status.show("Backing up database: table %d of %d (%d rows)", b.TablesDone, b.TablesTotal, b.RowsDone)
		default:
			status.show("Backing up files: %d of %d (%s)", b.FilesDone, b.FilesTotal, humanBytes(b.Bytes))
		}
		next, err := c.StepBackup(ctxFrom(cmd), b.ID)
		if err != nil {
			// A step is resumable: the job keeps its place on the server.
			if failures++; failures > 3 {
				status.done()
				return nil, fmt.Errorf("backup %s did not finish: %w", b.ID, err)
			}
			time.Sleep(2 * time.Second)
			continue
		}
		failures = 0
		b = next
	}
	status.done()
	return b, nil
}

// backup delete ----------------------------------------------------------------

var backupDeleteCmd = &cobra.Command{
	Use:   "delete <backup-id>",
	Short: "Delete a backup from the site.",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, _, err := mustClient()
		if err != nil {
			return err
		}
		if err := c.DeleteBackup(ctxFrom(cmd), args[0]); err != nil {
			return err
		}
		tui.New(os.Stdout, flagJSON).Linef("✓ Deleted backup %s.", args[0])
		return nil
	},
}

// backup download --------------------------------------------------------------

var backupDownloadCmd = &cobra.Command{
	Use:   "download <backup-id>",
	Short: "Download a backup archive without restoring it.",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, _, err := mustClient()
		if err != nil {
			return err
		}
		b, err := findBackup(cmd, c, args[0])
		if err != nil {
			return err
		}
		dest := backupOut
		if dest == "" {
			dest = "wpicker-" + b.ID + ".tar"
		}
		if err := downloadBackup(cmd, c, b, dest); err != nil {
			return err
		}
		tui.New(os.Stdout, flagJSON).Linef("✓ Saved %s (%s).", dest, humanBytes(b.Bytes))
		return nil
	},
}

func findBackup(cmd *cobra.Command, c *client.Client, id string) (*client.Backup, error) {
	backups, err := c.ListBackups(ctxFrom(cmd))
	if err != nil {
		return nil, err
	}
	var match *client.Backup
	for i := range backups {
		if strings.HasPrefix(backups[i].ID, id) {
			if match != nil {
				return nil, fmt.Errorf("%q matches more than one backup; use the full id", id)
			}
			match = &backups[i]
		}
	}
	if match == nil {
		return nil, fmt.Errorf("no backup %q on the site (see `wpicker backup list`)", id)
	}
	if !match.Done {
		return nil, fmt.Errorf("backup %s is incomplete and cannot be used", match.ID)
	}
	return match, nil
}

func downloadBackup(cmd *cobra.Command, c *client.Client, b *client.Backup, dest string) error {
	status := newStatusLine()
	defer status.done()
	return c.DownloadBackup(ctxFrom(cmd), b.ID, dest, b.Bytes, func(done int64) {
		status.show("Downloading %s of %s", humanBytes(done), humanBytes(b.Bytes))
	})
}

// backup restore ---------------------------------------------------------------

var backupRestoreCmd = &cobra.Command{
	Use:   "restore [backup-id]",
	Short: "Rebuild a backup on a local WordPress (files + database).",
	Long: `Downloads a backup and applies it to a local WordPress install:

  1. the local database and wp-content parts are saved aside first,
  2. plugins, themes and uploads are unpacked into --path,
  3. the database dump is loaded,
  4. the site's URL and paths are rewritten to the local ones, keeping
     PHP-serialized values valid.

Without a backup id you pick from the list. The local install must already
exist (wp-config.php in --path); database settings are read from it and can
be overridden with the --db-* flags. Sites managed by the Local app are
detected and their MySQL socket is used automatically.

This replaces the local site. The site the backup came from is not touched.`,
	Args: cobra.MaximumNArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		archive := restoreFile
		cleanup := func() {}
		if archive == "" {
			c, _, err := mustClient()
			if err != nil {
				return err
			}
			b, err := chooseBackup(cmd, c, args)
			if err != nil || b == nil {
				return err
			}
			if archive, cleanup, err = fetchBackup(cmd, c, b); err != nil {
				return err
			}
		} else if len(args) > 0 {
			return errors.New("give either a backup id or --file, not both")
		}
		defer cleanup()
		return restoreArchive(cmd, archive)
	},
}

// chooseBackup resolves which backup to restore: the id given, --latest, or
// an interactive pick. It returns nil (and no error) if the user backs out.
func chooseBackup(cmd *cobra.Command, c *client.Client, args []string) (*client.Backup, error) {
	if len(args) == 1 {
		return findBackup(cmd, c, args[0])
	}
	backups, err := c.ListBackups(ctxFrom(cmd))
	if err != nil {
		return nil, err
	}
	var ready []client.Backup
	for _, b := range backups {
		if b.Done {
			ready = append(ready, b)
		}
	}
	if len(ready) == 0 {
		return nil, errors.New("no finished backups on the site; create one with `wpicker backup create` or use `wpicker clone`")
	}
	if restoreLatest {
		return &ready[0], nil
	}
	if flagJSON || !isTerminal(os.Stdin) {
		return nil, errors.New("which backup? pass its id, or --latest")
	}

	printBackups(ready)
	r := bufio.NewReader(os.Stdin)
	for {
		fmt.Fprintf(os.Stderr, "Restore which? [1-%d, q to quit] > ", len(ready))
		line, err := r.ReadString('\n')
		line = strings.TrimSpace(line)
		if (err != nil && line == "") || line == "q" || line == "" {
			return nil, nil
		}
		if n, err := strconv.Atoi(line); err == nil && n >= 1 && n <= len(ready) {
			return &ready[n-1], nil
		}
		fmt.Fprintf(os.Stderr, "  enter a number between 1 and %d\n", len(ready))
	}
}

// fetchBackup downloads a backup into ~/.wpicker/backups, where an interrupted
// download can be resumed. The returned cleanup removes it unless --keep-archive.
func fetchBackup(cmd *cobra.Command, c *client.Client, b *client.Backup) (string, func(), error) {
	dir, err := config.Dir()
	if err != nil {
		return "", nil, err
	}
	dir = filepath.Join(dir, "backups")
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return "", nil, err
	}
	archive := filepath.Join(dir, b.ID+".tar")
	if err := downloadBackup(cmd, c, b, archive); err != nil {
		return "", nil, err
	}
	cleanup := func() {
		if !restoreKeep {
			os.Remove(archive)
		}
	}
	return archive, cleanup, nil
}

// restoreArchive applies a backup archive to the local WordPress in --path.
func restoreArchive(cmd *cobra.Command, archive string) error {
	ctx := ctxFrom(cmd)
	out := tui.New(os.Stdout, flagJSON)

	manifest, err := migrate.ReadManifest(archive)
	if err != nil {
		return err
	}

	wpRoot := restorePath
	if wpRoot == "" {
		wpRoot = "."
	}
	if wpRoot, err = filepath.Abs(wpRoot); err != nil {
		return err
	}
	if _, err := os.Stat(filepath.Join(wpRoot, "wp-config.php")); err != nil {
		return fmt.Errorf("%s has no wp-config.php. Point --path at an existing local WordPress install (create an empty site first)", wpRoot)
	}

	withDB := manifest.Include["db"]
	var db *migrate.DB
	var cfg migrate.DBConfig
	newURL := strings.TrimRight(restoreURL, "/")
	if withDB {
		if cfg, err = localDBConfig(cmd, wpRoot); err != nil {
			return err
		}
		if db, err = migrate.Open(ctx, cfg); err != nil {
			return fmt.Errorf("%w\n  Set the connection with --db-host/--db-port or --db-socket, --db-name, --db-user, --db-pass", err)
		}
		defer db.Close()
		if newURL == "" {
			newURL = db.SiteURL(ctx, cfg.Prefix)
		}
		if newURL == "" {
			return errors.New("could not tell the local site's address (no WordPress in the database yet): pass --url, e.g. --url http://mysite.local")
		}
	}

	var parts []string
	for _, key := range []string{"plugins", "themes", "uploads"} {
		if manifest.Include[key] {
			parts = append(parts, migrate.ContentParts[key]...)
		}
	}

	// Say exactly what is about to be replaced, and get a yes.
	if !flagJSON {
		fmt.Fprintf(os.Stderr, "Backup of %s (WordPress %s), taken %s\n", manifest.SiteURL, manifest.WPVersion, shortTime(manifest.CreatedAt))
		fmt.Fprintf(os.Stderr, "Restoring into %s\n", wpRoot)
		if withDB {
			fmt.Fprintf(os.Stderr, "  database   %s — tables %s* are REPLACED\n", cfg.Where(), manifest.TablePrefix)
			fmt.Fprintf(os.Stderr, "  address    %s → %s\n", manifest.SiteURL, newURL)
		}
		for _, p := range parts {
			fmt.Fprintf(os.Stderr, "  wp-content/%s is REPLACED\n", p)
		}
	}
	if !restoreYes {
		if flagJSON || !isTerminal(os.Stdin) {
			return errors.New("this replaces the local site; re-run with --yes to confirm")
		}
		fmt.Fprint(os.Stderr, "The current local database and folders are saved aside first. Continue? [y/N] > ")
		line, _ := bufio.NewReader(os.Stdin).ReadString('\n')
		if a := strings.ToLower(strings.TrimSpace(line)); a != "y" && a != "yes" {
			fmt.Fprintln(os.Stderr, "Nothing changed.")
			return nil
		}
	}

	// 1. Save the current local state aside.
	saved := filepath.Join(wpRoot, "wpicker-before-restore-"+time.Now().Format("20060102-150405"))
	if err := os.MkdirAll(filepath.Join(saved, "wp-content"), 0o755); err != nil {
		return err
	}
	status := newStatusLine()
	if withDB {
		status.show("Saving the current local database…")
		if _, err := db.Dump(ctx, cfg.Prefix, filepath.Join(saved, "database.sql")); err != nil {
			status.done()
			os.RemoveAll(saved) // nothing has been moved into it yet
			return fmt.Errorf("save local database: %w", err)
		}
	}
	for _, p := range parts {
		from := filepath.Join(wpRoot, "wp-content", p)
		if _, err := os.Stat(from); err == nil {
			if err := os.Rename(from, filepath.Join(saved, "wp-content", p)); err != nil {
				status.done()
				return fmt.Errorf("move %s aside: %w", from, err)
			}
		}
	}

	// 2. Unpack files and the dump.
	dump := filepath.Join(saved, "incoming.sql")
	extracted, err := migrate.Extract(archive, wpRoot, dump, func(files int, bytes int64) {
		status.show("Unpacking files: %d (%s)", files, humanBytes(bytes))
	})
	if err != nil {
		status.done()
		return fmt.Errorf("%w\n  The previous files are in %s", err, saved)
	}
	defer os.Remove(dump)

	// 3. Load the database and point it at the local address.
	statements, rewritten := 0, 0
	var deactivated []string
	if withDB && extracted.DumpPath != "" {
		statements, err = db.Import(ctx, dump, func(n int) {
			status.show("Loading database: %d statements", n)
		})
		if err != nil {
			status.done()
			return fmt.Errorf("load database: %w\n  The previous database is in %s", err, filepath.Join(saved, "database.sql"))
		}
		if manifest.TablePrefix != cfg.Prefix {
			if err := migrate.SetTablePrefix(wpRoot, manifest.TablePrefix); err != nil {
				status.done()
				return fmt.Errorf("the backup uses table prefix %q but wp-config.php has %q, and it could not be updated: %w", manifest.TablePrefix, cfg.Prefix, err)
			}
		}
		status.show("Rewriting %s → %s…", manifest.SiteURL, newURL)
		rewritten, err = db.Rewrite(ctx, manifest.TablePrefix, migrate.Rewrites{
			OldURLs:    []string{manifest.SiteURL, manifest.HomeURL},
			NewURL:     newURL,
			OldPath:    manifest.ABSPath,
			NewPath:    wpRoot,
			OldContent: manifest.ContentDir,
			NewContent: filepath.Join(wpRoot, "wp-content"),
		})
		if err != nil {
			status.done()
			return fmt.Errorf("rewrite addresses: %w", err)
		}
		for _, name := range []string{"siteurl", "home"} {
			if err := db.SetOption(ctx, manifest.TablePrefix, name, newURL); err != nil {
				status.done()
				return err
			}
		}
		if deactivated, err = db.Settle(ctx, manifest.TablePrefix, newURL); err != nil {
			status.done()
			return fmt.Errorf("settle the restored database: %w", err)
		}
	}
	status.done()

	if flagJSON {
		out.EmitJSON(map[string]any{
			"restored":       true,
			"path":           wpRoot,
			"url":            newURL,
			"files":          extracted.Files,
			"statements":     statements,
			"rows_rewritten": rewritten,
			"deactivated":    deactivated,
			"saved_previous": saved,
		})
		return nil
	}
	fmt.Printf("✓ Restored into %s\n", wpRoot)
	fmt.Printf("  %d files unpacked", extracted.Files)
	if withDB {
		fmt.Printf(", %d SQL statements loaded, %d rows rewritten to %s", statements, rewritten, newURL)
	}
	fmt.Println(".")
	fmt.Printf("  Previous local state saved in %s\n", saved)
	if len(deactivated) > 0 {
		fmt.Printf("  Switched off (they force HTTPS, and %s is not HTTPS): %s\n", newURL, strings.Join(deactivated, ", "))
	}
	if withDB {
		fmt.Println("  Log in with the accounts of the site the backup came from.")
	}
	if manifest.Stylesheet != "" && manifest.Include["themes"] {
		fmt.Printf("  To keep working on the theme: cd %s && wpicker theme status\n", filepath.Join(wpRoot, "wp-content", "themes", manifest.Stylesheet))
	}
	return nil
}

// localDBConfig works out how to reach the local database: wp-config.php,
// then the Local app's per-site MySQL, then the --db-* flags on top.
func localDBConfig(cmd *cobra.Command, wpRoot string) (migrate.DBConfig, error) {
	cfg, err := migrate.ReadWPConfig(wpRoot)
	if err != nil {
		return cfg, fmt.Errorf("read %s: %w", filepath.Join(wpRoot, "wp-config.php"), err)
	}
	if cfg.Socket == "" && restoreDBSocket == "" && restoreDBHost == "" && restoreDBPort == 0 {
		if socket, port, ok := migrate.LocalAppSocket(wpRoot); ok {
			cfg.Socket = socket
			if socket == "" {
				cfg.Host, cfg.Port = "127.0.0.1", port
			}
		}
	}
	if restoreDBSocket != "" {
		cfg.Socket = restoreDBSocket
	}
	if restoreDBHost != "" {
		cfg.Host, cfg.Socket = restoreDBHost, ""
	}
	if restoreDBPort != 0 {
		cfg.Port, cfg.Socket = restoreDBPort, ""
	}
	if restoreDBName != "" {
		cfg.Name = restoreDBName
	}
	if restoreDBUser != "" {
		cfg.User = restoreDBUser
	}
	// An empty --db-pass is a legitimate value, so test "was it given".
	if cmd.Flags().Changed("db-pass") {
		cfg.Pass = restoreDBPass
	}
	return cfg, nil
}

// clone ------------------------------------------------------------------------

var cloneCmd = &cobra.Command{
	Use:   "clone",
	Short: "Copy the whole site onto a local WordPress: back up, download, restore.",
	Long: `Creates a fresh backup on the site, downloads it and restores it into the
local WordPress at --path — the one-command way to get a working copy of the
site on your machine. See ` + "`wpicker backup restore --help`" + ` for what restoring does.

The backup is removed from the site afterwards unless --keep-remote is given.`,
	Args: cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		c, _, err := mustClient()
		if err != nil {
			return err
		}
		if backupNote == "" {
			backupNote = "wpicker clone"
		}
		b, err := createBackup(cmd, c)
		if err != nil {
			return err
		}
		if !cloneKeepRemote {
			defer c.DeleteBackup(ctxFrom(cmd), b.ID)
		}
		archive, cleanup, err := fetchBackup(cmd, c, b)
		if err != nil {
			return err
		}
		defer cleanup()
		return restoreArchive(cmd, archive)
	},
}

// statusLine shows one progress line that overwrites itself on a terminal and
// stays quiet otherwise (scripts, agents, --json).
type statusLine struct {
	live  bool
	shown bool
	last  time.Time
}

func newStatusLine() *statusLine {
	return &statusLine{live: !flagJSON && isTerminal(os.Stderr)}
}

func (s *statusLine) show(format string, args ...any) {
	// Callers report every file and statement; a terminal needs far fewer.
	if !s.live || time.Since(s.last) < 80*time.Millisecond {
		return
	}
	s.last = time.Now()
	fmt.Fprintf(os.Stderr, "\r\033[K"+format, args...)
	s.shown = true
}

func (s *statusLine) done() {
	if s.shown {
		fmt.Fprint(os.Stderr, "\r\033[K")
		s.shown = false
	}
}

func addIncludeFlags(c *cobra.Command) {
	c.Flags().StringVar(&backupNote, "note", "", "note shown next to the backup in the list")
	c.Flags().BoolVar(&backupNoDB, "no-db", false, "leave the database out")
	c.Flags().BoolVar(&backupNoPlugins, "no-plugins", false, "leave plugins out")
	c.Flags().BoolVar(&backupNoThemes, "no-themes", false, "leave themes out")
	c.Flags().BoolVar(&backupNoUploads, "no-uploads", false, "leave uploads (media) out")
}

func addRestoreFlags(c *cobra.Command) {
	c.Flags().StringVar(&restorePath, "path", "", "local WordPress directory, the one with wp-config.php (default: current dir)")
	c.Flags().StringVar(&restoreURL, "url", "", "address of the local site (default: the one already in its database)")
	c.Flags().BoolVar(&restoreYes, "yes", false, "do not ask before replacing the local site")
	c.Flags().BoolVar(&restoreKeep, "keep-archive", false, "keep the downloaded archive in ~/.wpicker/backups")
	c.Flags().StringVar(&restoreDBHost, "db-host", "", "database host (default: from wp-config.php)")
	c.Flags().IntVar(&restoreDBPort, "db-port", 0, "database port")
	c.Flags().StringVar(&restoreDBSocket, "db-socket", "", "database unix socket")
	c.Flags().StringVar(&restoreDBName, "db-name", "", "database name")
	c.Flags().StringVar(&restoreDBUser, "db-user", "", "database user")
	c.Flags().StringVar(&restoreDBPass, "db-pass", "", "database password")
}

func init() {
	addIncludeFlags(backupCreateCmd)
	addIncludeFlags(cloneCmd)
	addRestoreFlags(backupRestoreCmd)
	addRestoreFlags(cloneCmd)

	backupDownloadCmd.Flags().StringVar(&backupOut, "out", "", "file to save to (default: wpicker-<id>.tar)")
	backupRestoreCmd.Flags().StringVar(&restoreFile, "file", "", "restore from a local archive instead of downloading one")
	backupRestoreCmd.Flags().BoolVar(&restoreLatest, "latest", false, "restore the newest backup without asking which")
	cloneCmd.Flags().BoolVar(&cloneKeepRemote, "keep-remote", false, "leave the backup on the site afterwards")

	backupCmd.AddCommand(backupListCmd, backupCreateCmd, backupRestoreCmd, backupDownloadCmd, backupDeleteCmd)
	rootCmd.AddCommand(backupCmd, cloneCmd)
}
