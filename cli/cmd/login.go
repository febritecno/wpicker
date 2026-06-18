package cmd

import (
	"fmt"
	"os"

	"github.com/spf13/cobra"

	"wpicker/internal/auth"
	"wpicker/internal/config"
)

var loginCmd = &cobra.Command{
	Use:   "login",
	Short: "Pair this device with a WordPress site via PIN + Application Password.",
	Long: `Authenticate against a WordPress site running the WPicker plugin.

You will be asked for:
  - the site URL,
  - an admin username + password (used ONLY to pair, never stored),
  - a 6-digit PIN that the plugin shows in WP-Admin → WPicker → Devices.

On success, a WordPress Application Password is stored in ~/` + config.DirName + `/` + config.FileName + `.`,
	RunE: func(cmd *cobra.Command, args []string) error {
		cfg, err := loadConfig()
		if err != nil {
			return err
		}
		if err := auth.Login(cmd.Context(), cfg, os.Stdin, os.Stdout); err != nil {
			return err
		}
		return nil
	},
}

var logoutCmd = &cobra.Command{
	Use:   "logout",
	Short: "Remove the stored credentials for this device.",
	RunE: func(cmd *cobra.Command, args []string) error {
		if err := auth.Logout(); err != nil {
			return err
		}
		fmt.Println("Logged out. Credentials removed.")
		return nil
	},
}

var whoamiCmd = &cobra.Command{
	Use:   "whoami",
	Short: "Show the currently paired device and site.",
	RunE: func(cmd *cobra.Command, args []string) error {
		cfg, err := loadConfig()
		if err != nil {
			return err
		}
		if !cfg.IsLoggedIn() {
			return fmt.Errorf("not logged in")
		}
		if flagJSON {
			fmt.Printf(`{"site":%q,"username":%q,"device_id":%q,"device_name":%q}`+"\n",
				cfg.Site, cfg.Username, cfg.DeviceID, cfg.DeviceName)
			return nil
		}
		fmt.Printf("Site:    %s\n", cfg.Site)
		fmt.Printf("User:    %s\n", cfg.Username)
		fmt.Printf("Device:  %s (%s)\n", cfg.DeviceName, cfg.DeviceID)
		return nil
	},
}
