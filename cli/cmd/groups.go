package cmd

import "github.com/spf13/cobra"

// Help is grouped by what a person is trying to do, not alphabetically.
func init() {
	rootCmd.AddGroup(
		&cobra.Group{ID: "sites", Title: "Your sites:"},
		&cobra.Group{ID: "look", Title: "Look at a site (read-only):"},
		&cobra.Group{ID: "change", Title: "Change a site:"},
		&cobra.Group{ID: "agents", Title: "AI agents:"},
		&cobra.Group{ID: "maint", Title: "Maintenance and advanced:"},
	)
	put := func(id string, cmds ...*cobra.Command) {
		for _, c := range cmds {
			c.GroupID = id
		}
	}
	put("sites", loginCmd, sitesCmd, whoamiCmd)
	put("look", mapCmd, findCmd, logsCmd, debugCmd, remoteFilesCmd, dbCmd, scanCmd)
	put("change", themeCmd, pageCmd, backupCmd, cloneCmd, historyCmd, rollbackCmd)
	put("agents", connectCmd, guideCmd)
	put("maint", doctorCmd, updateCmd, contextCmd, deviceCmd)

	// Shell completion is built in and rarely wanted: still there, but not in the list.
	rootCmd.CompletionOptions.HiddenDefaultCmd = true

	deviceCmd.Short = "Advanced: every computer paired with the site (to audit or revoke one)."
	contextCmd.Short = "Advanced: the site's WP/PHP/plugin/theme versions and theme_mods, for an AI agent."
}
