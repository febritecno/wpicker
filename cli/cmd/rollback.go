package cmd

import (
	"context"
	"fmt"
	"os"
	"strings"

	"github.com/spf13/cobra"

	"wpicker/internal/client"
	"wpicker/internal/tui"
)

var rollbackCmd = &cobra.Command{
	Use:   "rollback [manifest-id-or-prefix]",
	Short: "Restore the child theme from a snapshot.",
	Long: `Restores the child theme to the state captured in the given manifest snapshot.

Before overwriting the live theme, the plugin takes a fresh safety snapshot of
the current state, so the rollback itself can be rolled back.

The argument may be a full manifest id (shown by ` + "`wpicker history`" + `) or a
prefix such as a timestamp "20240618-1530"; the most recent matching manifest
is selected.`,
	Args: cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, _, err := mustClient()
		if err != nil {
			return err
		}

		id, err := resolveManifest(c, cmd.Context(), args[0])
		if err != nil {
			return err
		}

		fmt.Fprintf(os.Stderr, "Rolling back to snapshot %s…\n", id)
		res, err := c.Rollback(ctxFrom(cmd), id)
		if err != nil {
			return err
		}
		tui.New(os.Stdout, flagJSON).Rollback(res)
		return nil
	},
}

// resolveManifest accepts either a full manifest id or a prefix (e.g. a
// timestamp). For prefixes, it finds the most recent matching manifest.
func resolveManifest(c *client.Client, ctx context.Context, query string) (string, error) {
	// Heuristic: the plugin's ids contain "-" segments and are at least ~20
	// chars; accept anything containing "-" or >=12 chars as a full id.
	if strings.Contains(query, "-") && len(query) >= 16 {
		return query, nil
	}

	h, err := c.History(ctx, 50)
	if err != nil {
		return "", err
	}
	for _, m := range h.Manifests {
		if strings.HasPrefix(m.ID, query) {
			return m.ID, nil
		}
	}
	// Also try matching by date prefix when the user passes a bare date/time.
	for _, m := range h.Manifests {
		if strings.HasPrefix(m.CreatedAt, query) || strings.Contains(m.ID, query) {
			return m.ID, nil
		}
	}
	return "", fmt.Errorf("no manifest found matching %q; run `wpicker history`", query)
}
