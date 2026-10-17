package cmd

import (
	"fmt"
	"os"
	"sort"
	"strings"

	"github.com/spf13/cobra"

	"wpicker/internal/client"
	"wpicker/internal/tui"
)

var (
	mapAll bool

	findKind   string
	findIn     string
	findLimit  int
	findVendor bool
)

// map --------------------------------------------------------------------------

var mapCmd = &cobra.Command{
	Use:   "map",
	Short: "What is installed on the site: plugins, themes, drop-ins, WooCommerce, email.",
	Long: `The first command to run on an unfamiliar site. One compact answer: versions,
which plugins and themes are active, which come from wordpress.org and which are
the site's own ("custom-or-unknown" — where its bugs usually live), must-use
plugins, cache drop-ins, and the well-known systems present (WooCommerce and its
enabled payment gateways, email plugins, page builders).

  wpicker map            active plugins and themes
  wpicker map --all      include inactive ones
` + inspectHelp,
	Args: cobra.NoArgs,
	RunE: func(cmd *cobra.Command, args []string) error {
		c, _, err := mustClient()
		if err != nil {
			return err
		}
		m, err := c.Map(ctxFrom(cmd))
		if err != nil {
			return err
		}
		if flagJSON {
			tui.New(os.Stdout, true).EmitJSON(m)
			return nil
		}
		printMap(m, mapAll)
		return nil
	},
}

func printMap(m *client.SiteMap, all bool) {
	w := m.WordPress
	cron := "on"
	if !w.WPCron {
		cron = "OFF (DISABLE_WP_CRON)"
	}
	fmt.Printf("WordPress %s · PHP %s · multisite %v · WP_DEBUG %v · wp-cron %s\n", w.Version, w.PHP, w.Multisite, w.Debug, cron)

	fmt.Println("\nThemes")
	for _, t := range m.Themes {
		if !t.Active && !all {
			continue
		}
		mark := " "
		if t.Active {
			mark = "*"
		}
		extra := t.Origin
		if t.Parent != "" {
			extra = "child of " + t.Parent + ", " + t.Origin
		}
		fmt.Printf("  %s %-28s %-10s %s\n", mark, t.Slug, t.Version, extra)
	}

	active, inactive := 0, 0
	for _, p := range m.Plugins {
		if p.Active {
			active++
		} else {
			inactive++
		}
	}
	fmt.Printf("\nPlugins (%d active, %d inactive)\n", active, inactive)
	plugins := append([]client.MapPlugin(nil), m.Plugins...)
	sort.SliceStable(plugins, func(i, j int) bool {
		if plugins[i].Origin != plugins[j].Origin { // the site's own code first
			return plugins[i].Origin != "wordpress.org" && plugins[j].Origin == "wordpress.org"
		}
		return plugins[i].Slug < plugins[j].Slug
	})
	for _, p := range plugins {
		if !p.Active && !all {
			continue
		}
		flags := p.Origin
		if p.Update {
			flags += ", update available"
		}
		if !p.Active {
			flags += ", inactive"
		}
		if p.Linked {
			flags += ", symlink (its code is outside wp-content and cannot be read here)"
		}
		fmt.Printf("  %-32s %-10s %s\n", p.Slug, p.Version, flags)
	}
	if inactive > 0 && !all {
		fmt.Printf("  (%d inactive not shown: --all)\n", inactive)
	}

	if len(m.MUPlugins) > 0 {
		fmt.Println("\nMust-use plugins")
		for _, p := range m.MUPlugins {
			fmt.Printf("  %-32s %s\n", p.File, p.Version)
		}
	}
	if len(m.Dropins) > 0 {
		fmt.Println("\nDrop-ins")
		for _, d := range m.Dropins {
			fmt.Printf("  %-32s %s\n", d.File, d.Is)
		}
	}

	in := m.Integrations
	fmt.Println("\nSystems")
	if in.WooCommerce != nil {
		g := "none enabled"
		if len(in.WooCommerce.Gateways) > 0 {
			g = strings.Join(in.WooCommerce.Gateways, ", ")
		}
		fmt.Printf("  WooCommerce %s · HPOS %v · gateways: %s\n", in.WooCommerce.Version, in.WooCommerce.HPOS, g)
	}
	if len(in.Email) > 0 {
		fmt.Printf("  email: %s\n", strings.Join(in.Email, ", "))
	} else {
		fmt.Println("  email: no SMTP/email plugin active (mail uses the server's default)")
	}
	fmt.Printf("  builders: %s\n", strings.Join(in.Builders, ", "))
	fmt.Println("\nNext: `wpicker find <name>` to locate code, `wpicker logs --since 1h --level error` for failures.")
}

// find -------------------------------------------------------------------------

var findCmd = &cobra.Command{
	Use:   "find [name]",
	Short: "Where is it defined? Functions, classes, hooks, shortcodes, REST routes, options.",
	Long: `Searches an index of what the site's PHP defines and hooks into, so you do not
have to grep the whole tree. Files are tokenised, so text in strings and
comments is never a hit. After the first run the index is kept, and only changed
files are read again.

  wpicker find checkout                         anything named like "checkout"
  wpicker find woocommerce_thankyou --kind hook who listens to / fires a hook
  wpicker find my_shortcode --kind shortcode
  wpicker find /orders --kind rest              REST routes
  wpicker find --kind post_type                 every custom post type
  wpicker find render --in my-plugin            only inside one plugin
  wpicker find init --kind hook --in theme:my-child

--kind takes any of: function, class, method, hook, shortcode, rest, post_type,
taxonomy, option, constant (comma-separate several).
--in is "custom" (default: mu-plugins plus everything not from wordpress.org),
"all", "plugins", "themes", "mu", a plugin slug, or theme:<slug>.

Hooks show what the code does with them: listens, fires or removes. Names built
at run time ('prefix_' . $x) cannot be known and are not indexed; use
` + "`wpicker files grep`" + ` for those.
` + inspectHelp,
	Args: cobra.MaximumNArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		q := ""
		if len(args) == 1 {
			q = args[0]
		}
		if q == "" && findKind == "" {
			return fmt.Errorf("give a name to look for, or --kind to list one kind of thing")
		}
		c, _, err := mustClient()
		if err != nil {
			return err
		}
		res, err := c.Symbols(ctxFrom(cmd), q, findKind, findIn, findLimit, findVendor)
		if err != nil {
			return err
		}
		if flagJSON {
			tui.New(os.Stdout, true).EmitJSON(res)
			return nil
		}
		if len(res.Results) == 0 {
			fmt.Printf("Nothing found in %q (%d files searched, %d paths skipped).\n", res.Scope, res.Files, len(res.Skipped))
			if res.Scope == "custom" {
				fmt.Println("  Only the site's own code was searched. Widen with --in all, or name a plugin with --in <slug>.")
			}
		}
		for _, k := range res.Skipped {
			fmt.Printf("skipped    %s: %s\n", k.Path, k.Reason)
		}
		for _, s := range res.Results {
			detail := ""
			if s.Detail != "" {
				detail = " (" + s.Detail + ")"
			}
			fmt.Printf("%-10s %s%s\n", s.Kind, s.Name, detail)
			fmt.Printf("           %s:%d\n", strings.TrimPrefix(s.File, "wp-content/"), s.Line)
		}
		if res.Total > res.Count {
			fmt.Printf("\n%d more matches not shown (raise --limit, or narrow with --kind / --in).\n", res.Total-res.Count)
		}
		if !res.Complete {
			fmt.Println("\nThe search ran out of time before it covered every file; results are partial. Run it again (files already read are remembered) or narrow with --in.")
		}
		return nil
	},
}

func init() {
	mapCmd.Flags().BoolVar(&mapAll, "all", false, "include inactive plugins and themes")

	findCmd.Flags().StringVar(&findKind, "kind", "", "function, class, method, hook, shortcode, rest, post_type, taxonomy, option, constant (comma-separated)")
	findCmd.Flags().StringVar(&findIn, "in", "custom", "where to look: custom, all, plugins, themes, mu, <plugin-slug> or theme:<slug>")
	findCmd.Flags().IntVar(&findLimit, "limit", 50, "matches to return (max 500)")
	findCmd.Flags().BoolVar(&findVendor, "vendor", false, "also search vendor/, tests/ and assets/ folders")

	rootCmd.AddCommand(mapCmd, findCmd)
}
