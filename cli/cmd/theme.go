package cmd

import (
	"errors"
	"fmt"
	"os"

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

// theme push -----------------------------------------------------------------

var pushCmd = &cobra.Command{
	Use:   "push",
	Short: "Upload local child-theme changes to the site (with lint + snapshot).",
	Long: `Pushes locally-changed files to the site's child theme. Before applying, the
plugin:
  1. runs ` + "`php -l`" + ` on every incoming .php file (lint gate),
  2. snapshots the current child theme (Deployment Vault),
  3. applies the files, and deletes the ones you deleted locally,
  4. requests the site from itself; if it now answers 5xx (a fatal error the
     lint gate cannot see), the snapshot is restored automatically.

On failure, a structured error is written to ./.wpicker/last-error.json so an
AI agent can inspect, fix, and re-push.`,
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

		rf, err := c.ListFiles(ctxFrom(cmd))
		if err != nil {
			return err
		}
		remote, sizes := remoteMaps(rf)

		st, err := sync.ComputeStatus(root, manifest, remote)
		if err != nil {
			return err
		}
		if err := saveIfDirty(root, manifest, st, remote, sizes); err != nil {
			return err
		}

		if len(st.Conflicts) > 0 {
			fmt.Fprintln(os.Stderr, "⚠ Drift detected — these files changed both locally and on the site:")
			for _, ch := range st.Conflicts {
				fmt.Fprintf(os.Stderr, "    %s\n", ch.Path)
			}
			fmt.Fprintln(os.Stderr, "  Run `wpicker theme pull` to merge them with your changes, then push again.")
			return errors.New("refusing to push drifted files without a pull")
		}
		if pushAll && len(st.Incoming) > 0 {
			fmt.Fprintln(os.Stderr, "⚠ The site has changes you have not pulled; --all would overwrite them:")
			for _, ch := range st.Incoming {
				fmt.Fprintf(os.Stderr, "    %s\n", ch.Path)
			}
			return errors.New("refusing to push --all over unpulled site changes")
		}

		// Collect the files to push. Hashes are of the raw bytes, which is
		// what the server stores and reports back in /theme/files.
		var toPush []client.PushFile
		var unmerged []string
		pushed := map[string]sync.ManifestFile{}
		contents := map[string][]byte{}
		push := func(rel string) error {
			data, err := sync.ReadFile(root, rel)
			if err != nil {
				return err
			}
			// A merge left both versions in the file for someone to settle.
			// Deployed as it is, it would be a syntax error at best.
			if sync.IsText(data) && sync.HasConflictMarkers(data) {
				unmerged = append(unmerged, rel)
			}
			toPush = append(toPush, client.NewPushFile(rel, data))
			pushed[rel] = sync.ManifestFile{SHA256: sync.HashBytes(data), Size: int64(len(data))}
			contents[rel] = data
			return nil
		}

		// Files pulled earlier and deleted locally since are deleted remotely.
		var targets, toDelete []string
		for _, ch := range st.Outgoing {
			if ch.Local == sync.Deleted {
				toDelete = append(toDelete, ch.Path)
			} else {
				targets = append(targets, ch.Path)
			}
		}
		if pushAll {
			// Include every local file (still confined by the server).
			if targets, err = sync.WalkLocal(root); err != nil {
				return err
			}
		}
		for _, rel := range targets {
			if err := push(rel); err != nil {
				return err
			}
		}

		if len(toPush) == 0 && len(toDelete) == 0 {
			tui.New(os.Stdout, flagJSON).Line("No local changes to push.")
			return nil
		}
		if len(unmerged) > 0 {
			fmt.Fprintln(os.Stderr, "⚠ Unresolved merge — these files still contain conflict markers (<<<<<<< … >>>>>>>):")
			for _, rel := range unmerged {
				fmt.Fprintf(os.Stderr, "    %s\n", rel)
			}
			fmt.Fprintln(os.Stderr, "  Settle each block and remove the marker lines, then push again.")
			return errors.New("refusing to push files with unresolved conflict markers")
		}

		if pushDryRun {
			tui.New(os.Stdout, flagJSON).Linef("DRY RUN: would push %d file(s), delete %d:", len(toPush), len(toDelete))
			for _, pf := range toPush {
				fmt.Fprintf(os.Stderr, "  - %s\n", pf.Path)
			}
			for _, rel := range toDelete {
				fmt.Fprintf(os.Stderr, "  x %s\n", rel)
			}
			return nil
		}

		if !flagJSON {
			fmt.Fprintf(os.Stderr, "Pushing %d file(s), deleting %d…\n", len(toPush), len(toDelete))
		}

		res, err := c.Push(ctxFrom(cmd), client.PushRequest{
			Files:  toPush,
			Delete: toDelete,
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
				"deleted": toDelete,
			}
			if re, ok := client.IsRestError(err); ok {
				errBody["server_error"] = map[string]any{
					"code":        re.Details.Code,
					"message":     re.Details.Message,
					"file":        re.Details.File,
					"line":        re.Details.Line,
					"manifest_id": re.Details.ManifestID,
					"rolled_back": re.Details.RolledBack,
				}
			} else {
				errBody["message"] = err.Error()
			}
			_ = sync.WriteError(root, errBody)
			return err
		}

		// Held for review: the site is unchanged, so the local manifest must
		// not claim these files are in sync. Once approved, `theme status`
		// sees both sides agree and settles the manifest by itself.
		if res.Status == "review" {
			sync.ClearError(root)
			manifest.Pending = map[string]sync.ManifestFile{}
			for rel, entry := range pushed {
				manifest.Pending[rel] = entry
				_ = sync.SavePending(root, rel, contents[rel])
			}
			for _, rel := range toDelete {
				manifest.Pending[rel] = sync.ManifestFile{}
			}
			if err := sync.SaveManifest(root, manifest); err != nil {
				fmt.Fprintf(os.Stderr, "warning: could not update local manifest: %v\n", err)
			}
			tui.New(os.Stdout, flagJSON).Push(res)
			return nil
		}

		// Success: clear any stale error and update the local manifest.
		sync.ClearError(root)
		st.Settle(root, manifest, remote, sizes)
		for rel, entry := range pushed {
			manifest.Files[rel] = entry
			_ = sync.SaveBase(root, rel, contents[rel])
		}
		for _, rel := range toDelete {
			delete(manifest.Files, rel)
			sync.RemoveBase(root, rel)
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
	pushCmd.Flags().StringVar(&pullOut, "out", "", "source directory (default: current dir)")
	pushCmd.Flags().BoolVar(&pushDryRun, "dry-run", false, "show what would be pushed without sending")
	pushCmd.Flags().BoolVar(&pushAll, "all", false, "push every local file, not just changes")

	themeCmd.AddCommand(pushCmd, filesCmd)
}
