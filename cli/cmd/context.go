package cmd

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"

	"github.com/spf13/cobra"

	"wpicker/internal/sync"
	"wpicker/internal/tui"
)

var (
	contextSave bool
)

var contextCmd = &cobra.Command{
	Use:   "context",
	Short: "Fetch AI-friendly site context (WP/PHP/plugin/theme versions + theme_mods).",
	Long: `Fetches the Global Context payload from the site, to ground an AI agent and
reduce hallucination. Output is a human summary by default, or full JSON with --json.`,
	RunE: func(cmd *cobra.Command, args []string) error {
		c, _, err := mustClient()
		if err != nil {
			return err
		}
		ctx, err := c.GetContext(ctxFrom(cmd))
		if err != nil {
			return err
		}
		p := tui.New(os.Stdout, flagJSON)
		p.Context(ctx)

		if contextSave {
			dir, err := sync.LocalDir(".")
			if err != nil {
				return err
			}
			data, err := json.MarshalIndent(ctx, "", "  ")
			if err != nil {
				return err
			}
			if err := os.WriteFile(filepath.Join(dir, "context.json"), data, 0o644); err != nil {
				return err
			}
			fmt.Fprintf(os.Stderr, "Saved context to %s/context.json\n", dir)
		}
		return nil
	},
}

func init() {
	contextCmd.Flags().BoolVar(&contextSave, "save", false, "also save the context JSON to ./.wpicker/context.json")
}
