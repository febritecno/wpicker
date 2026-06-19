// Package cmd holds all cobra commands for the wpicker CLI.
//
// Global flags: --site (override config), --json (machine output for AI),
// --verbose. The root command sets up shared state (loaded config, REST client)
// and makes it available to subcommands via package-level globals.
package cmd

import (
	"context"
	"fmt"
	"os"

	"github.com/spf13/cobra"

	"wpicker/internal/client"
	"wpicker/internal/config"
)

// Version is the current CLI version.
const Version = "1.1.0"

// Global flags parsed on the root command.
var (
	flagSite    string
	flagJSON    bool
	flagVerbose bool
)

// Loaded once on Execute; subcommands call mustClient() to obtain a REST client.
var loadedCfg *config.Config

// rootCmd is the entry command.
var rootCmd = &cobra.Command{
	Use:   "wpicker",
	Short: "WPicker — bridge your local AI agent to WordPress.",
	Long: `WPicker pairs a WordPress site (plugin "The Eyes") with this CLI ("The Hands").

Authenticate with ` + "`wpicker login`" + `, then sync your child theme, inspect deployment
history, and roll back instantly. Every push is lint-checked and snapshotted,
so an AI agent can self-heal after a failed deploy.`,
	SilenceUsage: true,
}

// Execute runs the root command.
func Execute() error {
	return rootCmd.ExecuteContext(context.Background())
}

func init() {
	rootCmd.PersistentFlags().StringVar(&flagSite, "site", "", "override the configured site URL")
	rootCmd.PersistentFlags().BoolVar(&flagJSON, "json", false, "emit machine-readable JSON (for AI agents)")
	rootCmd.PersistentFlags().BoolVarP(&flagVerbose, "verbose", "v", false, "verbose output")

	rootCmd.AddCommand(loginCmd)
	rootCmd.AddCommand(logoutCmd)
	rootCmd.AddCommand(contextCmd)
	rootCmd.AddCommand(themeCmd)
	rootCmd.AddCommand(historyCmd)
	rootCmd.AddCommand(rollbackCmd)
	rootCmd.AddCommand(initCmd)
	rootCmd.AddCommand(deviceCmd)
	rootCmd.AddCommand(whoamiCmd)
}

// loadConfig loads the on-disk config once.
func loadConfig() (*config.Config, error) {
	if loadedCfg != nil {
		return loadedCfg, nil
	}
	cfg, err := config.Load()
	if err != nil {
		return nil, err
	}
	loadedCfg = cfg
	return cfg, nil
}

// mustClient loads config and returns a REST client. Errors if not logged in.
func mustClient() (*client.Client, *config.Config, error) {
	cfg, err := loadConfig()
	if err != nil {
		return nil, nil, err
	}
	site := cfg.Site
	if flagSite != "" {
		site = flagSite
	}
	if site == "" || cfg.Username == "" || cfg.AppPassword == "" {
		return nil, cfg, fmt.Errorf("not logged in: run `wpicker login` first")
	}
	return client.New(site, cfg.Username, cfg.AppPassword, nil), cfg, nil
}

// ctxFrom returns a context bound to the cobra command.
func ctxFrom(cmd *cobra.Command) context.Context {
	return cmd.Context()
}

// errOut prints an error to stderr (so stdout stays clean for --json).
func errOut(msg string) {
	fmt.Fprintln(os.Stderr, "Error:", msg)
}
