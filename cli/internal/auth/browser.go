package auth

import (
	"net/url"
	"os/exec"
	"runtime"
	"strings"
)

// CanOpen says whether a link may be handed to the system browser: https
// always, plain http only for a local development address. Anything else (a
// file:, a javascript:, a custom scheme a hostile site made up) is never opened.
func CanOpen(raw string) bool {
	u, err := url.Parse(raw)
	if err != nil || u.Host == "" || u.User != nil {
		return false
	}
	switch u.Scheme {
	case "https":
		return true
	case "http":
		h := strings.ToLower(u.Hostname())
		return h == "localhost" || h == "127.0.0.1" || h == "::1" || strings.HasSuffix(h, ".local") || strings.HasSuffix(h, ".test") || strings.HasSuffix(h, ".localhost")
	}
	return false
}

// OpenBrowser opens a link with the system's opener. No shell is involved: the
// link is one argument to a program, so nothing in it is interpreted.
func OpenBrowser(link string) error {
	if !CanOpen(link) {
		return errNotOpenable
	}
	var cmd *exec.Cmd
	switch runtime.GOOS {
	case "darwin":
		cmd = exec.Command("open", link)
	case "windows":
		cmd = exec.Command("rundll32", "url.dll,FileProtocolHandler", link)
	default:
		cmd = exec.Command("xdg-open", link)
	}
	if err := cmd.Start(); err != nil {
		return err
	}
	go func() { _ = cmd.Wait() }()
	return nil
}
