package auth

import (
	"bufio"
	"context"
	"errors"
	"fmt"
	"io"
	"net/url"
	"regexp"
	"strings"
	"time"
	"unicode"

	"wpicker/internal/client"
	"wpicker/internal/config"
)

var errNotOpenable = errors.New("that link is not one the browser may be asked to open")

// DeviceOptions is one pairing through the browser.
type DeviceOptions struct {
	Site       string // address; asked for when empty
	Fixed      bool   // the address is known (re-pairing a listed site): not asked
	Device     string // name for this computer; defaults to its host name
	CLIVersion string

	// OpenBrowser, when set, is called with the verification link. Left nil, the
	// link is only printed.
	OpenBrowser func(link string) error
	// Wait sleeps between polls; nil uses the real clock. Tests replace it.
	Wait func(ctx context.Context, d time.Duration) error
}

// cleanText makes a string from a server safe to print on a terminal: control
// characters (an ESC sequence can clear the screen or rewrite what is shown),
// invisible and bidirectional-override characters (they make text read as
// something else) are removed, runs of space collapse, and it is shortened.
func cleanText(s string, max int) string {
	var b strings.Builder
	space := false
	for _, r := range s {
		switch {
		case unicode.IsSpace(r):
			space = b.Len() > 0
			continue
		case unicode.IsControl(r), unicode.Is(unicode.Cf, r), unicode.Is(unicode.Co, r), r == unicode.ReplacementChar:
			continue
		}
		if space {
			b.WriteRune(' ')
			space = false
		}
		b.WriteRune(r)
	}
	out := b.String()
	if r := []rune(out); len(r) > max {
		out = string(r[:max]) + "…"
	}
	return out
}

// maxInterval bounds how far a slow_down or a busy site can stretch the wait.
const maxInterval = 30 * time.Second

var userCodeRe = regexp.MustCompile(`^[A-Z0-9]{4}-[A-Z0-9]{4}$`)

func realWait(ctx context.Context, d time.Duration) error {
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-t.C:
		return nil
	}
}

// DeviceLogin pairs this computer with a site the way `gh auth login` does:
// it asks the site to open a request, prints a link, and waits while a person
// logs in to WordPress in a browser and approves. No password is typed here.
// The only thing it may ask for is the site address.
func DeviceLogin(ctx context.Context, o DeviceOptions, in io.Reader, out io.Writer) (*Result, error) {
	site := o.Site
	if o.Fixed {
		fmt.Fprintf(out, "WordPress site: %s\n", site)
	} else if strings.TrimSpace(site) == "" {
		site = prompt(out, bufio.NewReader(in), "WordPress site address", "")
	}
	norm, err := config.NormalizeURL(strings.TrimSpace(site))
	if err != nil {
		return nil, fmt.Errorf("the site address is needed, e.g. https://shop.example.com (%v)", err)
	}
	site = norm

	device := strings.TrimSpace(o.Device)
	if device == "" {
		device = defaultDeviceName()
	}
	wait := o.Wait
	if wait == nil {
		wait = realWait
	}

	c := client.New(site, "", "", nil)
	start, _, err := c.DeviceStart(ctx, device)
	if err != nil {
		return nil, explainStartError(err, site)
	}
	if err := checkLink(start.VerificationURL, site); err != nil {
		return nil, err
	}
	// ASCII controls are already rejected by the URL parser; invisible and direction-changing
	// characters are not. A link that does not print as itself is not one to send a person to.
	if cleanText(start.VerificationURL, len(start.VerificationURL)+1) != start.VerificationURL {
		return nil, errors.New("the site returned a link with unusual characters in it; refusing to continue")
	}
	if !userCodeRe.MatchString(start.UserCode) {
		return nil, errors.New("the site returned a confirmation code in an unexpected format; refusing to continue (update the WPicker plugin)")
	}

	fmt.Fprintf(out, "\nTo connect %q to %s, open this link in your browser and approve the request:\n\n    %s\n\n", cleanText(device, 80), site, start.VerificationURL)
	fmt.Fprintf(out, "Check that the code shown there is  %s\n", start.UserCode)
	if o.OpenBrowser != nil {
		if err := o.OpenBrowser(start.VerificationURL); err == nil {
			fmt.Fprintln(out, "(opened in your browser)")
		}
	}
	fmt.Fprintln(out, "Waiting for approval… (Ctrl-C to cancel)")

	interval := time.Duration(max(start.Interval, 2)) * time.Second
	deadline := time.Now().Add(time.Duration(start.ExpiresIn) * time.Second)
	failures := 0
	for {
		if err := wait(ctx, interval); err != nil {
			return nil, errors.New("cancelled: nothing was paired")
		}
		if start.ExpiresIn > 0 && time.Now().After(deadline.Add(15*time.Second)) {
			return nil, errors.New("the request expired before it was approved. Run the command again")
		}
		res, _, err := c.DevicePoll(ctx, start.DeviceCode)
		if err == nil {
			return finish(res, site, o, out)
		}
		var rest *client.RESTError
		if errors.As(err, &rest) && !transient(rest.Details.Code) {
			failures = 0
			switch rest.Details.Code {
			case client.CodePending:
				continue
			case client.CodeSlow:
				interval = min(interval+5*time.Second, maxInterval)
				continue
			case client.CodeBusy:
				interval = min(interval*2, maxInterval)
				continue
			case client.CodeDenied:
				return nil, errors.New("the request was denied (or the approving user may no longer pair devices). Nothing was paired")
			case client.CodeExpired:
				return nil, errors.New("the request expired before it was approved. Run the command again")
			case client.CodeInvalid:
				return nil, errors.New("the site no longer knows this request (it expired or was already used). Run the command again")
			}
			return nil, fmt.Errorf("the site refused: %s (%s)", cleanText(rest.Details.Message, 200), cleanText(rest.Details.Code, 60))
		}
		// A network blip while waiting should not throw away a pairing that is about to succeed.
		if failures++; failures >= 6 {
			return nil, fmt.Errorf("lost contact with %s while waiting: %v", site, err)
		}
	}
}

// transient reports codes that mean "the site or something in front of it is
// having a moment" (a proxy's 502, a timeout, a rate limit) rather than an answer
// about the request. Those are waited out, not treated as a refusal.
func transient(code string) bool {
	return strings.HasPrefix(code, "http_5") || code == "http_408" || code == "http_429"
}

func finish(res *client.PairResponse, site string, o DeviceOptions, out io.Writer) (*Result, error) {
	if res.AppPassword == "" || res.UserLogin == "" {
		return nil, errors.New("the site approved the request but did not return a login; update the WPicker plugin and try again")
	}
	warnVersion(out, o.CLIVersion, res.PluginVersion)
	label := cleanText(res.SiteName, 80)
	if label == "" {
		label = site
	}
	fmt.Fprintf(out, "✓ Approved. Paired with %s as %q (user %s).\n", label, cleanText(res.DeviceName, 80), cleanText(res.UserLogin, 60))
	return &Result{
		URL:           site,
		Username:      res.UserLogin,
		AppPassword:   res.AppPassword,
		DeviceID:      res.DeviceID,
		DeviceName:    res.DeviceName,
		PluginVersion: res.PluginVersion,
	}, nil
}

// checkLink makes sure the link the site returned leads to THAT site. The CLI is
// about to hand it to a browser and tell the person to log in there, so a site
// (or something in between) that returns a link elsewhere must not be believed.
func checkLink(link, site string) error {
	l, err := url.Parse(link)
	if err != nil || l.Host == "" {
		return errors.New("the site returned a link that cannot be used")
	}
	s, err := url.Parse(site)
	if err != nil {
		return err
	}
	if !strings.EqualFold(l.Host, s.Host) {
		return fmt.Errorf("refusing to continue: the site at %s returned a link to a different address (%s), where you would be asked to log in.\n"+
			"  If the site's WordPress address differs from the one you typed, run wpicker login with its real address.", s.Host, l.Host)
	}
	if s.Scheme == "https" && l.Scheme != "https" {
		return fmt.Errorf("refusing to continue: the link %s is not https although %s is", link, site)
	}
	return nil
}

// explainStartError turns what the site said into what to do next.
func explainStartError(err error, site string) error {
	var redirect *client.RedirectError
	var rest *client.RESTError
	switch {
	case errors.Is(err, client.ErrNoDeviceFlow):
		return fmt.Errorf("%s cannot pair through the browser: its WPicker plugin is older than 1.3.0, or not active.\n"+
			"  Update or activate the plugin in WP-Admin → Plugins, then run the command again.", site)
	case errors.As(err, &redirect):
		return fmt.Errorf("%s redirects elsewhere (%s). A redirected request loses its data, so use that address directly:\n"+
			"    wpicker login %s", site, cleanText(redirect.Location, 200), cleanText(strings.TrimRight(redirect.Location, "/"), 200))
	case errors.As(err, &rest):
		switch rest.Details.Code {
		case client.CodeBusy, "wpicker_insecure_transport":
			return errors.New(cleanText(rest.Details.Message, 200))
		}
		return fmt.Errorf("the site refused: %s (%s)", cleanText(rest.Details.Message, 200), cleanText(rest.Details.Code, 60))
	}
	return err
}

// warnVersion tells the person when plugin and CLI differ.
func warnVersion(out io.Writer, cli, plugin string) {
	if plugin == "" || plugin == cli {
		return
	}
	fmt.Fprintf(out, "\n⚠️  VERSION MISMATCH: CLI is v%s, but Plugin is v%s.\n", cli, plugin)
	fmt.Fprintln(out, "   We strongly recommend updating so both are the same version.")
	fmt.Fprintln(out, "   - To update CLI: run `wpicker update`")
	fmt.Fprintln(out, "   - To update Plugin: go to WP Admin -> Plugins -> Update WPicker")
	fmt.Fprintln(out)
}
