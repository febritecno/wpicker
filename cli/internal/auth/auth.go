// Package auth pairs this computer with a WordPress site.
//
// The pairing is approved in a browser (see DeviceLogin), so this program never
// handles the administrator's password: a person logs in to WordPress as usual,
// with whatever two-factor plugin the site uses, and approves the request there.
package auth

import (
	"bufio"
	"fmt"
	"io"
	"os"
	"strings"
)

// Result is a completed pairing. DeviceLogin does not save it: the caller stores
// it (under a lock, next to the other sites) so a pairing can never overwrite a
// login to a different site.
type Result struct {
	URL           string
	Username      string
	AppPassword   string
	DeviceID      string
	DeviceName    string
	PluginVersion string
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

// defaultDeviceName returns a friendly machine label.
func defaultDeviceName() string {
	host, err := os.Hostname()
	if err != nil || host == "" {
		return "WPicker CLI"
	}
	return strings.TrimSuffix(host, ".local")
}
