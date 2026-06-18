package cmd

import (
	"fmt"
	"os"

	"github.com/spf13/cobra"

	"wpicker/internal/tui"
)

var deviceCmd = &cobra.Command{
	Use:   "device",
	Short: "Manage paired devices.",
}

var deviceListCmd = &cobra.Command{
	Use:   "list",
	Short: "List all paired devices on the site.",
	RunE: func(cmd *cobra.Command, args []string) error {
		c, _, err := mustClient()
		if err != nil {
			return err
		}
		devices, err := c.ListDevices(ctxFrom(cmd))
		if err != nil {
			return err
		}
		tui.New(os.Stdout, flagJSON).Devices(devices)
		return nil
	},
}

var deviceRevokeCmd = &cobra.Command{
	Use:   "revoke [device-id]",
	Short: "Revoke a paired device (immediately loses access).",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		c, cfg, err := mustClient()
		if err != nil {
			return err
		}
		id := args[0]
		if err := c.RevokeDevice(ctxFrom(cmd), id); err != nil {
			return err
		}
		if cfg.DeviceID == id {
			fmt.Fprintln(os.Stderr, "warning: you revoked THIS device. Run `wpicker login` to re-pair.")
		}
		tui.New(os.Stdout, flagJSON).Linef("✓ Revoked device %s.", id)
		return nil
	},
}

func init() {
	deviceCmd.AddCommand(deviceListCmd, deviceRevokeCmd)
}
