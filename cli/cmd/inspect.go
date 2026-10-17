package cmd

import (
	"errors"
	"fmt"
	"os"
	"strconv"
	"strings"
	"unicode/utf8"

	"github.com/spf13/cobra"

	"wpicker/internal/tui"
)

var (
	logsSince  string
	logsLevel  string
	logsGrep   string
	logsSource string
	logsLimit  int
	logsTrace  bool

	filesDepth int
	catLines   string
	grepRegex  bool
	grepCase   bool
	grepExt    string
	grepLimit  int

	dbLimit int
)

const inspectHelp = `
These commands read from the site; they change nothing on it. They work only
while "Diagnostic access" is enabled in WP-Admin → WPicker → Settings, and
every use is listed there.`

// logs -------------------------------------------------------------------------

var logsCmd = &cobra.Command{
	Use:   "logs",
	Short: "Show the site's PHP, WordPress and WooCommerce/payment errors, grouped.",
	Long: `Reads the site's logs — PHP's error log, wp-content/debug.log, the log written
by "WordPress debug logging", and WooCommerce's logs (which is where payment
gateways such as Stripe and PayPal write) — and shows each distinct problem
once, with how often and when it happened, most recent first. Order numbers,
amounts and request ids are ignored when grouping, and card numbers, keys and
tokens are masked before anything leaves the site.

  wpicker logs                          everything in the logs
  wpicker logs --since 30m              the last 30 minutes
  wpicker logs --level error            gateway/WooCommerce errors and PHP fatals
  wpicker logs --source wc              all WooCommerce logs (payments included)
  wpicker logs --source wc:stripe       one WooCommerce log (names are in "wpicker status")
  wpicker logs --grep checkout --trace  entries mentioning "checkout", with details

Levels, most severe first: fatal (PHP crashes), error (failed payments, gateway
errors), warning, notice, deprecated, info.

If the logs are empty, the site may not be logging at all: turn it on with
` + "`wpicker debug on`" + `, reproduce the problem, and look again.
` + inspectHelp,
	Args: cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		c, _, err := mustClient()
		if err != nil {
			return err
		}
		minutes, err := parseMinutes(logsSince)
		if err != nil {
			return err
		}
		res, err := c.Logs(ctxFrom(cmd), minutes, logsLevel, logsGrep, logsSource, logsLimit)
		if err != nil {
			return err
		}
		if flagJSON {
			tui.New(os.Stdout, true).EmitJSON(res)
			return nil
		}

		if len(res.Entries) == 0 {
			fmt.Println("No matching log entries.")
			if !res.DebugLogging && !res.WPDebugLog {
				fmt.Println("  The site is only logging what PHP logs by default. For warnings, notices and")
				fmt.Println("  WordPress deprecations run `wpicker debug on`, reproduce the problem, then look again.")
			}
			return nil
		}
		for _, e := range res.Entries {
			where := ""
			if e.File != "" {
				where = fmt.Sprintf("  %s:%d", e.File, e.Line)
			}
			times := shortTime(e.Last)
			if e.Count > 1 {
				times = fmt.Sprintf("×%d, %s → %s", e.Count, shortTime(e.First), shortTime(e.Last))
			}
			fmt.Printf("%-10s %s\n", strings.ToUpper(e.Level), e.Message)
			src := ""
			if strings.HasPrefix(e.Source, "wc:") {
				src = "  [" + e.Source + "]"
			}
			fmt.Printf("           %s%s%s\n", times, where, src)
			if logsTrace {
				for _, t := range e.Trace {
					fmt.Printf("             %s\n", t)
				}
			}
		}
		if res.Total > res.Count {
			fmt.Printf("\n%d more distinct entries not shown (raise --limit, or narrow with --since / --level / --grep).\n", res.Total-res.Count)
		}
		return nil
	},
}

// parseMinutes accepts "45", "45m", "2h" or "1d".
func parseMinutes(s string) (int, error) {
	s = strings.TrimSpace(s)
	if s == "" {
		return 0, nil
	}
	unit := 1
	switch s[len(s)-1] {
	case 'm':
		s = s[:len(s)-1]
	case 'h':
		unit, s = 60, s[:len(s)-1]
	case 'd':
		unit, s = 1440, s[:len(s)-1]
	}
	n, err := strconv.Atoi(s)
	if err != nil || n < 0 {
		return 0, errors.New("--since takes a duration such as 30m, 2h or 1d")
	}
	return n * unit, nil
}

// debug ------------------------------------------------------------------------

var debugCmd = &cobra.Command{
	Use:   "debug [on|off|status]",
	Short: "Turn the site's WordPress debug logging on or off.",
	Long: `Switches "WordPress debug logging" on the site: every PHP error, warning,
notice and WordPress deprecation is written to a private log that
` + "`wpicker logs`" + ` reads. Nothing is shown to visitors, and wp-config.php is not
touched. It costs a little speed: turn it off when you are done.

  wpicker debug on
  ...reproduce the problem...
  wpicker logs --since 10m
  wpicker debug off
` + inspectHelp,
	Args:      cobra.MaximumNArgs(1),
	ValidArgs: []string{"on", "off", "status"},
	RunE: func(cmd *cobra.Command, args []string) error {
		action := "status"
		if len(args) == 1 {
			action = args[0]
		}
		// on/off change a setting on the site; status only looks.
		curWrites = action == "on" || action == "off"
		c, _, err := mustClient()
		if err != nil {
			return err
		}
		switch action {
		case "on", "off":
			if err := c.SetDebugLogging(ctxFrom(cmd), action == "on"); err != nil {
				return err
			}
		case "status":
		default:
			return errors.New("use: wpicker debug on | off | status")
		}

		st, err := c.InspectStatus(ctxFrom(cmd))
		if err != nil {
			return err
		}
		if flagJSON {
			tui.New(os.Stdout, true).EmitJSON(st)
			return nil
		}
		state := "off"
		if st.DebugLogging {
			state = "ON"
		}
		fmt.Printf("WordPress debug logging: %s\n", state)
		fmt.Printf("WP_DEBUG in wp-config.php: %v   WP_DEBUG_LOG: %v   PHP %s\n", st.WPDebug, st.WPDebugLog, st.PHPVersion)
		if len(st.LogSources) == 0 {
			fmt.Println("No log files on the site yet.")
		}
		for name, bytes := range st.LogSources {
			fmt.Printf("  log %-5s %s\n", name, humanBytes(bytes))
		}
		return nil
	},
}

// files ------------------------------------------------------------------------

var remoteFilesCmd = &cobra.Command{
	Use:   "files",
	Short: "Read the site's code: list, print and search files under wp-content.",
	Long: `Read-only access to everything under the site's wp-content — plugins,
themes, must-use plugins, uploads — for reading code while diagnosing.

  wpicker files ls                         top of wp-content
  wpicker files ls plugins/woocommerce --depth 2
  wpicker files cat themes/my-theme/functions.php
  wpicker files cat plugins/x/x.php --lines 120-180
  wpicker files grep "add_action( 'init'" plugins

Paths are relative to wp-content. Not available: wp-config.php (it is outside
wp-content), backups and snapshots, key and credential files, database dumps.
To CHANGE theme files use ` + "`wpicker theme pull`" + ` / ` + "`push`" + `.
` + inspectHelp,
}

var filesLsCmd = &cobra.Command{
	Use:   "ls [path]",
	Short: "List a directory under wp-content.",
	Args:  cobra.MaximumNArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, _, err := mustClient()
		if err != nil {
			return err
		}
		path := ""
		if len(args) == 1 {
			path = args[0]
		}
		res, err := c.ListRemote(ctxFrom(cmd), path, filesDepth)
		if err != nil {
			return err
		}
		if flagJSON {
			tui.New(os.Stdout, true).EmitJSON(res)
			return nil
		}
		for _, e := range res.Entries {
			switch e.Type {
			case "dir":
				fmt.Printf("%10s  %s  %s/\n", "", shortTime(e.Modified), e.Path)
			case "link":
				fmt.Printf("%10s  %s  %s -> (symbolic link, not followed)\n", "", shortTime(e.Modified), e.Path)
			default:
				fmt.Printf("%10s  %s  %s\n", humanBytes(e.Size), shortTime(e.Modified), e.Path)
			}
		}
		if res.Capped {
			fmt.Println("…listing cut at 2000 entries; list a sub-directory or lower --depth.")
		}
		return nil
	},
}

var filesCatCmd = &cobra.Command{
	Use:   "cat <path>",
	Short: "Print a text file under wp-content.",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, _, err := mustClient()
		if err != nil {
			return err
		}
		from, to := 1, 0
		if catLines != "" {
			a, b, ok := strings.Cut(catLines, "-")
			if from, err = strconv.Atoi(a); err != nil || from < 1 {
				return errors.New("--lines takes a range such as 40-90, or a single line number")
			}
			to = from
			if ok {
				if to, err = strconv.Atoi(b); err != nil || to < from {
					return errors.New("--lines takes a range such as 40-90, or a single line number")
				}
			}
		}
		f, err := c.ReadRemote(ctxFrom(cmd), args[0], from, to)
		if err != nil {
			return err
		}
		if flagJSON {
			tui.New(os.Stdout, true).EmitJSON(f)
			return nil
		}
		if f.Binary {
			fmt.Fprintf(os.Stderr, "%s is a binary file (%s); not printed.\n", f.Path, humanBytes(f.Size))
			return nil
		}
		// With a range, number the lines: that is what you quote back.
		if catLines != "" {
			for i, line := range strings.Split(f.Contents, "\n") {
				fmt.Printf("%6d  %s\n", f.From+i, line)
			}
			return nil
		}
		fmt.Print(f.Contents)
		if !strings.HasSuffix(f.Contents, "\n") {
			fmt.Println()
		}
		return nil
	},
}

var filesGrepCmd = &cobra.Command{
	Use:   "grep <text> [path]",
	Short: "Search text files under wp-content.",
	Args:  cobra.RangeArgs(1, 2),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, _, err := mustClient()
		if err != nil {
			return err
		}
		path := ""
		if len(args) == 2 {
			path = args[1]
		}
		res, err := c.GrepRemote(ctxFrom(cmd), args[0], path, grepRegex, grepCase, grepExt, grepLimit)
		if err != nil {
			return err
		}
		if flagJSON {
			tui.New(os.Stdout, true).EmitJSON(res)
			return nil
		}
		for _, m := range res.Matches {
			fmt.Printf("%s:%d: %s\n", m.Path, m.Line, m.Text)
		}
		fmt.Fprintf(os.Stderr, "%d match(es) in %d file(s) searched", res.Count, res.Searched)
		if res.Stopped != "" {
			fmt.Fprintf(os.Stderr, " — stopped at the %s; narrow the path", res.Stopped)
		}
		fmt.Fprintln(os.Stderr, ".")
		return nil
	},
}

// db ---------------------------------------------------------------------------

var dbCmd = &cobra.Command{
	Use:   "db",
	Short: "Look into the site's database, read-only and without credentials.",
	Long: `Runs read-only queries on the site's database.

  wpicker db tables
  wpicker db query "SELECT ID, post_title, post_status FROM wp_posts ORDER BY ID DESC LIMIT 10"
  wpicker db query "DESCRIBE wp_postmeta"
  wpicker db query "SELECT option_name, LENGTH(option_value) FROM wp_options WHERE autoload = 'yes' ORDER BY 2 DESC LIMIT 20"

Allowed: SELECT, WITH, SHOW, DESCRIBE, EXPLAIN — one statement, no comments.
The statement runs in a read-only transaction; the database refuses writes.

Credentials are not readable: password and activation-key columns do not
exist for these queries, rows of options and user meta that hold keys, tokens,
salts or sessions are left out, and tables that are credential stores by name
are refused. ` + "`wpicker db tables`" + ` shows which tables are filtered or denied.
Everything else — including personal data — IS readable.
` + inspectHelp,
}

var dbTablesCmd = &cobra.Command{
	Use:   "tables",
	Short: "List the site's tables.",
	Args:  cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		c, _, err := mustClient()
		if err != nil {
			return err
		}
		tables, err := c.DBTables(ctxFrom(cmd))
		if err != nil {
			return err
		}
		if flagJSON {
			tui.New(os.Stdout, true).EmitJSON(map[string]any{"tables": tables})
			return nil
		}
		fmt.Printf("%-40s %10s %10s  %s\n", "TABLE", "ROWS (≈)", "SIZE", "ACCESS")
		for _, t := range tables {
			note := ""
			switch t.Access {
			case "filtered":
				note = "filtered — credentials left out"
			case "denied":
				note = "denied — credential store"
			}
			fmt.Printf("%-40s %10d %10s  %s\n", t.Name, t.ApproxRows, humanBytes(int64(t.KB)*1024), note)
		}
		return nil
	},
}

var dbQueryCmd = &cobra.Command{
	Use:   "query <sql>",
	Short: "Run one read-only statement.",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, _, err := mustClient()
		if err != nil {
			return err
		}
		res, err := c.DBQuery(ctxFrom(cmd), args[0], dbLimit)
		if err != nil {
			return err
		}
		if flagJSON {
			tui.New(os.Stdout, true).EmitJSON(res)
			return nil
		}
		printRows(res.Columns, res.Rows)
		fmt.Fprintf(os.Stderr, "%d row(s) in %d ms", res.Count, res.Ms)
		if res.Truncated {
			fmt.Fprintf(os.Stderr, " — more exist; showing the first %d (raise --limit, max 1000)", res.Limit)
		}
		fmt.Fprintln(os.Stderr, ".")
		return nil
	},
}

// printRows renders a result as an aligned table, one row per line.
func printRows(columns []string, rows [][]*string) {
	const maxWidth = 60
	cell := func(v *string) string {
		if v == nil {
			return "NULL"
		}
		s := strings.NewReplacer("\n", "⏎", "\r", "", "\t", " ").Replace(*v)
		if utf8.RuneCountInString(s) > maxWidth {
			s = string([]rune(s)[:maxWidth-1]) + "…"
		}
		return s
	}
	widths := make([]int, len(columns))
	for i, c := range columns {
		widths[i] = utf8.RuneCountInString(c)
	}
	text := make([][]string, len(rows))
	for r, row := range rows {
		text[r] = make([]string, len(row))
		for i, v := range row {
			text[r][i] = cell(v)
			if i < len(widths) && utf8.RuneCountInString(text[r][i]) > widths[i] {
				widths[i] = utf8.RuneCountInString(text[r][i])
			}
		}
	}
	line := func(cells []string) {
		for i, c := range cells {
			if i > 0 {
				fmt.Print("  ")
			}
			fmt.Print(c + strings.Repeat(" ", widths[i]-utf8.RuneCountInString(c)))
		}
		fmt.Println()
	}
	line(columns)
	rule := make([]string, len(columns))
	for i := range rule {
		rule[i] = strings.Repeat("-", widths[i])
	}
	line(rule)
	for _, row := range text {
		line(row)
	}
}

func init() {
	logsCmd.Flags().StringVar(&logsSince, "since", "", "only entries from the last 30m, 2h, 1d… (default: everything in the logs)")
	logsCmd.Flags().StringVar(&logsLevel, "level", "info", "least severe level to show: fatal, error, warning, notice, deprecated, info")
	logsCmd.Flags().StringVar(&logsGrep, "grep", "", "only entries whose message or file contains this text")
	logsCmd.Flags().StringVar(&logsSource, "source", "all", "which log: all, php, wp (debug.log), diag (WPicker's debug log), wc (all WooCommerce logs) or wc:<name>")
	logsCmd.Flags().IntVar(&logsLimit, "limit", 50, "distinct entries to show")
	logsCmd.Flags().BoolVar(&logsTrace, "trace", false, "include stack traces")

	filesLsCmd.Flags().IntVar(&filesDepth, "depth", 1, "levels of sub-directories to include (max 6)")
	filesCatCmd.Flags().StringVar(&catLines, "lines", "", "only these lines, e.g. 40-90 (printed with line numbers)")
	filesGrepCmd.Flags().BoolVar(&grepRegex, "regex", false, "treat the text as a regular expression")
	filesGrepCmd.Flags().BoolVar(&grepCase, "case", false, "match case exactly")
	filesGrepCmd.Flags().StringVar(&grepExt, "ext", "", "file extensions to search, comma-separated (default: php,js,css,json,html,…)")
	filesGrepCmd.Flags().IntVar(&grepLimit, "limit", 100, "matches to return (max 500)")

	dbQueryCmd.Flags().IntVar(&dbLimit, "limit", 100, "rows to return (max 1000)")

	remoteFilesCmd.AddCommand(filesLsCmd, filesCatCmd, filesGrepCmd)
	dbCmd.AddCommand(dbTablesCmd, dbQueryCmd)
	rootCmd.AddCommand(logsCmd, debugCmd, remoteFilesCmd, dbCmd)
}
