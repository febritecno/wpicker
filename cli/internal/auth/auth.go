// Package auth implements the interactive `wpicker login` flow.
//
// Flow:
//   1. Prompt for site URL + admin username + main password (used ONCE to
//      authenticate the challenge + register calls; never stored).
//   2. GET /device/challenge (basic auth with username+password) → plugin
//      issues a 6-digit PIN, also shown in WP-Admin.
//   3. Prompt for the PIN.
//   4. POST /device/register with the PIN → plugin mints an Application
//      Password and returns it (one-time).
//   5. Persist {site, username, app_password, device_id} to config.json.
package auth

import (
	"bufio"
	"context"
	"fmt"
	"io"
	"os"
	"strings"

	"wpicker/internal/client"
	"wpicker/internal/config"
)

// Login performs the interactive pairing flow.
//
// The reader/writer are injected so the flow can be tested without a TTY.
// tempBasic is the user's main credentials (username/password) used ONLY for
// the challenge + register calls; it is never persisted.
func Login(ctx context.Context, cfg *config.Config, in io.Reader, out io.Writer) error {
	r := bufio.NewReader(in)

	// 1. Site URL.
	site := prompt(out, r, "WordPress site URL", cfg.Site)
	site = strings.TrimRight(strings.TrimSpace(site), "/")
	if site == "" {
		return fmt.Errorf("site URL is required")
	}
	if !strings.HasPrefix(site, "http://") && !strings.HasPrefix(site, "https://") {
		site = "https://" + site
	}

	// 2. Username.
	username := prompt(out, r, "Admin username", cfg.Username)
	if username == "" {
		return fmt.Errorf("username is required")
	}

	// 3. Main password (used once, never stored).
	password, err := readPassword(out, r, "Admin password (used only to pair, never stored)")
	if err != nil {
		return err
	}
	if password == "" {
		return fmt.Errorf("password is required")
	}

	// Bootstrap a client with the TEMPORARY main credentials.
	c := client.New(site, username, password, nil)

	// 4. Challenge → PIN.
	fmt.Fprintln(out, "→ Requesting pairing PIN…")
	challenge, status, err := c.Challenge(ctx, username, password)
	if err != nil {
		return fmt.Errorf("challenge failed (HTTP %d): %w", status, err)
	}
	fmt.Fprintf(out, "✓ PIN issued. Expires %s.\n", challenge.ExpiresAt)

	// 5. Read the PIN from the user (confirms human-in-the-loop).
	pin, err := readPassword(out, r, "Enter the 6-digit PIN shown in WP-Admin → WPicker → Devices")
	if err != nil {
		return err
	}
	pin = strings.TrimSpace(pin)

	deviceName := prompt(out, r, "Device name (label for this machine)", defaultDeviceName())

	// 6. Register → mint Application Password.
	fmt.Fprintln(out, "→ Registering device…")
	reg, status, err := c.Register(ctx, username, password, client.RegisterRequest{
		PIN:        pin,
		DeviceName: deviceName,
	})
	if err != nil {
		return fmt.Errorf("register failed (HTTP %d): %w", status, err)
	}

	// 7. Persist.
	cfg.Site = site
	cfg.Username = username
	cfg.AppPassword = reg.AppPassword
	cfg.DeviceID = reg.DeviceID
	cfg.DeviceName = reg.DeviceName
	if err := cfg.Save(); err != nil {
		return fmt.Errorf("save config: %w", err)
	}

	fmt.Fprintf(out, "✓ Paired as device %q (%s).\n", reg.DeviceName, reg.DeviceID)
	fmt.Fprintf(out, "  Credentials stored in ~/%s/%s\n", config.DirName, config.FileName)
	fmt.Fprintln(out, "  Your main password was NOT stored.")
	return nil
}

// Logout removes the stored config.
func Logout() error {
	return config.Clear()
}

// prompt prints a prompt and reads a line, returning a default if empty.
func prompt(out io.Writer, r *bufio.Reader, label, def string) string {
	if def != "" {
		fmt.Fprintf(out, "%s [%s]: ", label, def)
	} else {
		fmt.Fprintf(out, "%s: ", label)
	}
	line, _ := r.ReadString('\n')
	line = strings.TrimRight(line, "\r\n")
	if line == "" {
		return def
	}
	return strings.TrimSpace(line)
}

// readPassword reads a secret. Falls back to plain read if no TTY support.
func readPassword(out io.Writer, r *bufio.Reader, label string) (string, error) {
	fmt.Fprintf(out, "%s: ", label)
	line, err := r.ReadString('\n')
	if err != nil && err != io.EOF {
		return "", err
	}
	fmt.Fprintln(out)
	return strings.TrimRight(line, "\r\n"), nil
}

// defaultDeviceName returns a friendly machine label.
func defaultDeviceName() string {
	host, err := os.Hostname()
	if err != nil || host == "" {
		return "WPicker CLI"
	}
	return host
}
