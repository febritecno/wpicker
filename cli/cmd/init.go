package cmd

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"

	"github.com/spf13/cobra"

	"wpicker/internal/sync"
)

var initCmd = &cobra.Command{
	Use:   "init",
	Short: "Scaffold AI-agent context files in the current directory.",
	Long: `Creates a .wpicker/ working directory and a .cursorrules file that teaches an
AI agent (Cursor, etc.) how to work with this WPicker project: the repo layout,
the safe commands, and the confinement rules.

If you are logged in, it also fetches the live site context and writes it to
.wpicker/context.json so the agent starts grounded.`,
	RunE: func(cmd *cobra.Command, args []string) error {
		root, _ := os.Getwd()

		// 1. .wpicker/ dir (last-pull manifest skeleton lives there too).
		if _, err := sync.LocalDir(root); err != nil {
			return err
		}

		// 2. context.json if logged in (best-effort, not fatal).
		c, _, err := mustClient()
		if err == nil {
			if ctx, err := c.GetContext(cmd.Context()); err == nil {
				if data, err := json.MarshalIndent(ctx, "", "  "); err == nil {
					_ = os.WriteFile(filepath.Join(root, sync.LocalDirName, "context.json"), data, 0o644)
				}
			} else {
				fmt.Fprintf(os.Stderr, "warning: could not fetch context: %v\n", err)
			}
		} else {
			fmt.Fprintln(os.Stderr, "note: not logged in — skipping live context fetch (run `wpicker login`).")
		}

		// 3. .cursorrules
		rules := cursorRules()
		rulesPath := filepath.Join(root, ".cursorrules")
		if _, statErr := os.Stat(rulesPath); statErr == nil {
			fmt.Fprintln(os.Stderr, "note: .cursorrules already exists — leaving it untouched.")
		} else {
			if err := os.WriteFile(rulesPath, []byte(rules), 0o644); err != nil {
				return err
			}
			fmt.Fprintln(os.Stderr, "Wrote .cursorrules")
		}

		fmt.Println("✓ WPicker project initialized.")
		fmt.Println("  Next: `wpicker theme pull` to fetch the live child theme, then edit and `wpicker theme push`.")
		return nil
	},
}

// cursorRules returns the AI-agent ruleset injected into the project.
//
// Kept here (not a template file) so it travels with the binary and stays in
// sync with the CLI's actual command set.
func cursorRules() string {
	siteHint := ""
	if cfg, _ := loadConfig(); cfg != nil && cfg.Site != "" {
		siteHint = fmt.Sprintf("\nSITE: %s\nUSER: %s\n", cfg.Site, cfg.Username)
	}
	return strings.TrimSpace(fmt.Sprintf(`
# WPicker — AI Agent Rules%s

You are working on a WordPress child theme managed by WPicker. Follow these rules.

## Workflow
1. `+"`wpicker theme pull`"+` — fetch the current live child theme into the working dir.
2. Edit files locally.
3. `+"`wpicker theme push`"+` — upload changes. The plugin snapshots first, then lints
   every .php file with `+"`php -l`"+`, then applies atomically.
4. If a push fails with a structured error:
   - read `+"`.wpicker/last-error.json`"+`,
   - run `+"`wpicker history`"+` to see manifests,
   - run `+"`wpicker rollback <manifest-id>`"+` to restore the last good state,
   - fix the code, then push again.

## Guardrails (HARD)
- You may ONLY modify files inside the active child theme.
- NEVER write to the database via the CLI — `+"`theme_mods`"+` is read-only.
- NEVER edit wp-config.php, the parent theme, core, or plugins via WPicker.
- Treat `+"`php -l`"+` failures as blocking: a push that fails lint is rejected
  before any file changes.
- Always pull before pushing if there is any chance the site changed; the CLI
  refuses to overwrite drifted files.

## Useful commands
- `+"`wpicker context`"+` — site/plugins/theme versions + theme_mods (ground yourself here first).
- `+"`wpicker theme files`"+` — list the child-theme files on the site.
- `+"`wpicker history <id>`"+` — inspect one manifest (file list + error log).
- `+"`wpicker device list`"+` — show paired devices.

## Files
- `+"`.wpicker/last-pull.json`"+` — hash manifest of the last pull (do not hand-edit).
- `+"`.wpicker/last-error.json`"+` — the last push error, when a push failed.
- `+"`.wpicker/context.json`"+` — cached site context.
`, siteHint)) + "\n"
}
