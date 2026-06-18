package cmd

import (
	"fmt"
	"os"
	"strings"

	"github.com/spf13/cobra"
)

var scanCmd = &cobra.Command{
	Use:     "scan",
	Aliases: []string{"vuln"},
	Short:   "Scan installed plugins for vulnerabilities",
	Long:    `Fetches all installed plugins on the live site and checks their versions against the WPVulnerability.net database.`,
	RunE: func(cmd *cobra.Command, args []string) error {
		c, _, err := mustClient()
		if err != nil {
			return err
		}

		fmt.Printf("Scanning plugins on the remote site...\n")
		
		res, err := c.ScanVuln(ctxFrom(cmd))
		if err != nil {
			return fmt.Errorf("Scan failed: %w", err)
		}

		if len(res.Plugins) == 0 {
			fmt.Println("✓ No known vulnerabilities found in any plugins.")
			return nil
		}

		fmt.Printf("✗ Found vulnerabilities in %d plugin(s)!\n", len(res.Plugins))

		for _, p := range res.Plugins {
			fmt.Printf("\n--- %s (%s) ---\n", p.Name, p.InstalledVersion)
			for _, v := range p.Vulnerabilities {
				severity := strings.ToUpper(v.Severity)
				scoreStr := ""
				if v.Score != nil {
					scoreStr = fmt.Sprintf(" (%v)", v.Score)
				}
				fmt.Printf("  [%s%s] %s\n", severity, scoreStr, v.Name)
			}
		}
		fmt.Println()
		os.Exit(1) // Exit code 1 if vulns found
		return nil
	},
}

func init() {
	rootCmd.AddCommand(scanCmd)
}
