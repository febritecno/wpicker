package cmd

import (
	"errors"
	"fmt"
	"os"
	"path/filepath"

	"github.com/spf13/cobra"

	"wpicker/internal/client"
	"wpicker/internal/sync"
	"wpicker/internal/tui"
)

var (
	pullOut    string // pull --out
	pushDryRun bool   // push --dry-run
	pushAll    bool   // push --all (include unchanged)
	filesCmd   = &cobra.Command{
		Use:   "files",
		Short: "List the child-theme files on the site.",
		RunE: func(cmd *cobra.Command, args []string) error {
			c, _, err := mustClient()
			if err != nil {
				return err
			}
			f, err := c.ListFiles(ctxFrom(cmd))
			if err != nil {
				return err
			}
			tui.New(os.Stdout, flagJSON).Files(f)
			return nil
		},
	}
)

var themeCmd = &cobra.Command{
	Use:   "theme",
	Short: "Sync the active child theme between local and site.",
}

// theme pull -----------------------------------------------------------------

var pullCmd = &cobra.Command{
	Use:   "pull",
	Short: "Download the latest child-theme files from the site.",
	Long: `Pulls every file in the site's active child theme into the current directory
(overwriting local copies) and records a hash manifest at ./.wpicker/last-pull.json
so that subsequent ` + "`wpicker theme push`" + ` can detect local changes.`,
	RunE: func(cmd *cobra.Command, args []string) error {
		c, _, err := mustClient()
		if err != nil {
			return err
		}
		root := pullOut
		if root == "" {
			root = "."
		}
		abs, _ := filepath.Abs(root)

		files, err := c.ListFiles(ctxFrom(cmd))
		if err != nil {
			return err
		}
		if !flagJSON {
			fmt.Fprintf(os.Stderr, "Pulling %d file(s) from stylesheet %q into %s…\n", files.Count, files.Stylesheet, abs)
		}

		manifest := &sync.Manifest{
			Stylesheet: files.Stylesheet,
			Files:      map[string]sync.ManifestFile{},
		}
		for _, entry := range files.Files {
			file, err := c.ReadFile(ctxFrom(cmd), entry.Path)
			if err != nil {
				return fmt.Errorf("read %s: %w", entry.Path, err)
			}
			if err := sync.WriteFileAtomic(root, entry.Path, file.Contents); err != nil {
				return fmt.Errorf("write %s: %w", entry.Path, err)
			}
			manifest.Files[entry.Path] = sync.ManifestFile{
				SHA256: file.SHA256,
				Size:   int64(file.Size),
			}
			if !flagJSON {
				fmt.Fprintf(os.Stderr, "  - %s\n", entry.Path)
			}
		}
		if err := sync.SaveManifest(root, manifest); err != nil {
			return err
		}
		tui.New(os.Stdout, flagJSON).Linef("✓ Pulled %d file(s).", files.Count)
		return nil
	},
}

// theme push -----------------------------------------------------------------

var pushCmd = &cobra.Command{
	Use:   "push",
	Short: "Upload local child-theme changes to the site (with lint + snapshot).",
	Long: `Pushes locally-changed files to the site's child theme. Before applying, the
plugin:
  1. snapshots the current child theme (Deployment Vault),
  2. runs ` + "`php -l`" + ` on every incoming .php file (lint gate),
  3. applies files atomically.

On failure, a structured error is written to ./.wpicker/last-error.json so an
AI agent can inspect, fix, and re-push — or roll back.`,
	RunE: func(cmd *cobra.Command, args []string) error {
		c, cfg, err := mustClient()
		if err != nil {
			return err
		}
		root := pullOut
		if root == "" {
			root = "."
		}

		manifest, err := sync.LoadManifest(root)
		if err != nil {
			return fmt.Errorf("load local manifest: %w (run `wpicker theme pull` first)", err)
		}

		// Build the remote hash map for drift detection.
		remote := map[string]string{}
		if rf, err := c.ListFiles(ctxFrom(cmd)); err == nil {
			for _, f := range rf.Files {
				remote[f.Path] = f.SHA256
			}
		}

		changes, err := sync.ComputeChanges(root, manifest, remote)
		if err != nil {
			return err
		}

		// Collect the files to push.
		var toPush []client.PushFile
		push := func(rel string) error {
			contents, err := sync.ReadFile(root, rel)
			if err != nil {
				return err
			}
			toPush = append(toPush, client.PushFile{Path: rel, Contents: contents})
			return nil
		}

		for _, rel := range changes.Added {
			if err := push(rel); err != nil {
				return err
			}
		}
		for _, rel := range changes.Modified {
			if err := push(rel); err != nil {
				return err
			}
		}

		if len(changes.Drifted) > 0 {
			fmt.Fprintln(os.Stderr, "⚠ Drift detected — these files changed both locally and on the site:")
			for _, rel := range changes.Drifted {
				fmt.Fprintf(os.Stderr, "    %s\n", rel)
			}
			fmt.Fprintln(os.Stderr, "  Run `wpicker theme pull` first to reconcile, then push again.")
			return errors.New("refusing to push drifted files without a pull")
		}

		if !pushAll && len(toPush) == 0 {
			tui.New(os.Stdout, flagJSON).Line("No local changes to push.")
			return nil
		}
		if pushAll {
			// Include every local file (still confined by the server).
			localFiles, err := sync.WalkLocal(root)
			if err != nil {
				return err
			}
			toPush = toPush[:0]
			for _, rel := range localFiles {
				if err := push(rel); err != nil {
					return err
				}
			}
		}

		if pushDryRun {
			tui.New(os.Stdout, flagJSON).Linef("DRY RUN: would push %d file(s):", len(toPush))
			for _, pf := range toPush {
				fmt.Fprintf(os.Stderr, "  - %s\n", pf.Path)
			}
			return nil
		}

		if !flagJSON {
			fmt.Fprintf(os.Stderr, "Pushing %d file(s)…\n", len(toPush))
			for _, pf := range toPush {
				fmt.Fprintf(os.Stderr, "  - %s\n", pf.Path)
			}
		}

		res, err := c.Push(ctxFrom(cmd), client.PushRequest{
			Files: toPush,
			Device: map[string]string{
				"id":   cfg.DeviceID,
				"name": cfg.DeviceName,
			},
		})
		if err != nil {
			// Self-healing: persist the structured error for the AI agent.
			errBody := map[string]any{
				"command": "theme push",
				"files":   pathsOf(toPush),
			}
			if re, ok := client.IsRestError(err); ok {
				errBody["server_error"] = map[string]any{
					"code":    re.Details.Code,
					"message": re.Details.Message,
					"file":    re.Details.File,
					"line":    re.Details.Line,
				}
			} else {
				errBody["message"] = err.Error()
			}
			_ = sync.WriteError(root, errBody)
			return err
		}

		// Success: clear any stale error and update the local manifest.
		sync.ClearError(root)
		for _, pf := range toPush {
			manifest.Files[pf.Path] = sync.ManifestFile{
				SHA256: sync.HashString(pf.Contents),
				Size:   int64(len(pf.Contents)),
			}
		}
		if err := sync.SaveManifest(root, manifest); err != nil {
			fmt.Fprintf(os.Stderr, "warning: could not update local manifest: %v\n", err)
		}

		tui.New(os.Stdout, flagJSON).Push(res)
		return nil
	},
}

func pathsOf(files []client.PushFile) []string {
	out := make([]string, len(files))
	for i, f := range files {
		out[i] = f.Path
	}
	return out
}

func init() {
	pullCmd.Flags().StringVar(&pullOut, "out", "", "destination directory (default: current dir)")
	pushCmd.Flags().StringVar(&pullOut, "out", "", "source directory (default: current dir)")
	pushCmd.Flags().BoolVar(&pushDryRun, "dry-run", false, "show what would be pushed without sending")
	pushCmd.Flags().BoolVar(&pushAll, "all", false, "push every local file, not just changes")

	themeCmd.AddCommand(pullCmd, pushCmd, filesCmd)
}
