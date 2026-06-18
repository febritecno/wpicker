package cmd

import (
	"fmt"
	"os"

	"github.com/spf13/cobra"

	"wpicker/internal/tui"
)

var historyLimit int

var historyCmd = &cobra.Command{
	Use:   "history",
	Short: "Show the deployment history (pushes & rollbacks).",
	Long: `Lists recent Vault manifests: manifest id, time, acting device, kind, file
count, and status. Use --limit to change how many entries are shown (default 30).

Pass a manifest id as an argument to inspect a single manifest (file list,
error log, snapshot existence).`,
	Args: cobra.MaximumNArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, _, err := mustClient()
		if err != nil {
			return err
		}
		p := tui.New(os.Stdout, flagJSON)

		if len(args) == 1 {
			// Inspect a single manifest.
			m, err := c.GetManifest(ctxFrom(cmd), args[0])
			if err != nil {
				return err
			}
			if flagJSON {
				p.EmitJSON(m)
				return nil
			}
			fmt.Printf("Manifest:    %s\n", m.ID)
			fmt.Printf("Kind:        %s\n", m.Kind)
			fmt.Printf("Created:     %s (GMT)\n", m.CreatedAt)
			fmt.Printf("Device:      %s\n", m.DeviceName)
			fmt.Printf("Status:      %s   (restored %d times)\n", m.Status, m.RestoreCount)
			fmt.Printf("Files (%d):\n", m.Count)
			for _, f := range m.Files {
				fmt.Printf("  - %s\n", f)
			}
			if len(m.Error) > 0 {
				fmt.Println("Error log:")
				for k, v := range m.Error {
					fmt.Printf("  %s: %v\n", k, v)
				}
			}
			fmt.Printf("Snapshot exists: %v\n", m.SnapshotExists)
			return nil
		}

		h, err := c.History(ctxFrom(cmd), historyLimit)
		if err != nil {
			return err
		}
		p.History(h)
		return nil
	},
}

func init() {
	historyCmd.Flags().IntVar(&historyLimit, "limit", 30, "number of entries to show")
}
