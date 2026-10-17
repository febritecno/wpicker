package cmd

import (
	"fmt"

	"github.com/spf13/cobra"
)

var guideCmd = &cobra.Command{
	Use:   "guide",
	Short: "Print the full instructions for working on this site (for AI agents).",
	Long: `Prints the complete WPicker guide: how to diagnose the site (logs, code, database),
how to change it safely, and what is off limits. AI agents run this when a task is
about the WordPress site; ` + "`wpicker connect`" + ` points them here.`,
	Args: cobra.NoArgs,
	Run: func(cmd *cobra.Command, args []string) {
		fmt.Print(agentGuide(siteHintFor(mustCwd())))
	},
}

func init() { rootCmd.AddCommand(guideCmd) }
