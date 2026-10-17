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
//   5. Return the pairing; the caller stores it next to the other sites.
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

// Prefill is what the pairing prompts start with. With Fixed set, the site
// address is not asked for (re-pairing a site that is already known).
type Prefill struct {
	URL      string
	Username string
	Fixed    bool
}

// Result is a completed pairing. Login does not save it: the caller stores it
// (under a lock, next to the other sites) so a pairing can never overwrite a
// login to a different site.
type Result struct {
	URL           string
	Username      string
	AppPassword   string
	DeviceID      string
	DeviceName    string
	PluginVersion string
}

// Login performs the interactive pairing flow.
//
// The reader/writer are injected so the flow can be tested without a TTY.
// The user's main password is used ONLY for the challenge + register calls;
// it is never persisted.
func Login(ctx context.Context, defaults Prefill, cliVersion string, in io.Reader, out io.Writer) (*Result, error) {
	r := bufio.NewReader(in)

	// 1. Site URL.
	site := defaults.URL
	if defaults.Fixed {
		fmt.Fprintf(out, "WordPress site: %s\n", site)
	} else {
		site = prompt(out, r, "WordPress site URL", defaults.URL)
	}
	site = strings.TrimSpace(site)
	if site == "" {
		return nil, fmt.Errorf("site URL is required")
	}
	norm, err := config.NormalizeURL(site)
	if err != nil {
		return nil, err
	}
	site = norm
	// The password is about to be sent to this address: show exactly where.
	fmt.Fprintf(out, "→ Your admin password will be sent to %s\n", site)

	// 2. Username.
	username := prompt(out, r, "Admin username", defaults.Username)
	if username == "" {
		return nil, fmt.Errorf("username is required")
	}

	// 3. Main password (used once, never stored).
	password, err := readPassword(out, r, "Admin password (used only to pair, never stored)")
	if err != nil {
		return nil, err
	}
	if password == "" {
		return nil, fmt.Errorf("password is required")
	}

	// Bootstrap a client with the TEMPORARY main credentials.
	c := client.New(site, username, password, nil)

	// 4. Challenge → PIN.
	fmt.Fprintln(out, "→ Requesting pairing PIN…")
	challenge, status, err := c.Challenge(ctx, username, password)
	if err != nil {
		return nil, fmt.Errorf("challenge failed (HTTP %d): %w", status, err)
	}

	if challenge.PluginVersion != "" && challenge.PluginVersion != cliVersion {
		fmt.Fprintf(out, "\n⚠️  VERSION MISMATCH: CLI is v%s, but Plugin is v%s.\n", cliVersion, challenge.PluginVersion)
		fmt.Fprintln(out, "   We strongly recommend updating so both are the same version.")
		fmt.Fprintln(out, "   - To update CLI: run `wpicker update`")
		fmt.Fprintln(out, "   - To update Plugin: go to WP Admin -> Plugins -> Update WPicker")
		fmt.Fprintln(out)
	}

	fmt.Fprintf(out, "✓ PIN issued. Open WP-Admin → WPicker to read it (valid %d seconds).\n", challenge.TTL)

	// 5. Read the PIN from the user (confirms human-in-the-loop).
	pin, err := readPassword(out, r, "Enter the 6-digit PIN shown in WP-Admin")
	if err != nil {
		return nil, err
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
		return nil, fmt.Errorf("register failed (HTTP %d): %w", status, err)
	}

	fmt.Fprintf(out, "✓ Paired as device %q (%s).\n", reg.DeviceName, reg.DeviceID)
	fmt.Fprintln(out, "  Your main password was NOT stored.")
	return &Result{
		URL:           site,
		Username:      username,
		AppPassword:   reg.AppPassword,
		DeviceID:      reg.DeviceID,
		DeviceName:    reg.DeviceName,
		PluginVersion: challenge.PluginVersion,
	}, nil
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
