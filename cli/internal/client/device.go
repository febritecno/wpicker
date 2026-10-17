package client

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
)

// ErrNoDeviceFlow: the site answered "no such route" for /device/start. Either
// its WPicker plugin predates browser approval, or it is not active (WordPress
// says the same thing for both).
var ErrNoDeviceFlow = errors.New("this site cannot pair through the browser")

// RedirectError: the site answered with a redirect. A POST that is redirected
// loses its body, so following it would only produce a confusing error; the
// person should use the address it points to.
type RedirectError struct{ Location string }

func (e *RedirectError) Error() string {
	return "the site redirected the request to " + e.Location
}

// Error codes the poll answers with while it is not done (RFC 8628 names).
const (
	CodePending = "authorization_pending"
	CodeSlow    = "slow_down"
	CodeDenied  = "access_denied"
	CodeExpired = "expired_token"
	CodeInvalid = "wpicker_code_invalid"
	CodeBusy    = "wpicker_busy"
)

// DeviceStart mirrors POST /device/start.
type DeviceStart struct {
	DeviceCode      string `json:"device_code"`
	UserCode        string `json:"user_code"`
	VerificationURL string `json:"verification_url"`
	ExpiresIn       int    `json:"expires_in"`
	Interval        int    `json:"interval"`
	SiteName        string `json:"site_name"`
}

// PairResponse mirrors a successful POST /device/poll.
type PairResponse struct {
	UserID        int    `json:"user_id"`
	UserLogin     string `json:"user_login"`
	DeviceID      string `json:"device_id"`
	AppPassword   string `json:"app_password"`
	DeviceName    string `json:"device_name"`
	PluginVersion string `json:"plugin_version"`
	SiteName      string `json:"site_name"`
}

// post sends one JSON request to a pairing route and never follows a redirect.
// These routes are anonymous: no credentials of any kind are sent.
func (c *Client) post(ctx context.Context, path string, body any) ([]byte, int, error) {
	buf, err := json.Marshal(body)
	if err != nil {
		return nil, 0, err
	}
	req, err := http.NewRequestWithContext(ctx, "POST", c.Site+"/wp-json/"+Namespace+path, bytes.NewReader(buf))
	if err != nil {
		return nil, 0, err
	}
	req.Header.Set("Accept", "application/json")
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("User-Agent", c.UserAgent)

	hc := &http.Client{
		Timeout:   c.HTTP.Timeout,
		Transport: c.HTTP.Transport,
		// Never follow: see RedirectError.
		CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
	}
	resp, err := hc.Do(req)
	if err != nil {
		return nil, 0, fmt.Errorf("request: %w", err)
	}
	defer resp.Body.Close()
	raw, err := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if err != nil {
		return nil, resp.StatusCode, err
	}
	if resp.StatusCode >= 300 && resp.StatusCode < 400 {
		where := resp.Header.Get("Location")
		if loc, _ := resp.Location(); loc != nil {
			where = loc.String()
		}
		return nil, resp.StatusCode, &RedirectError{Location: where}
	}
	// WordPress itself answers an unknown route with { code: "rest_no_route" },
	// not with our envelope. A bare 404 is something else (wrong address).
	var core struct {
		Code string `json:"code"`
	}
	if resp.StatusCode == http.StatusNotFound && json.Unmarshal(raw, &core) == nil && core.Code == "rest_no_route" {
		return nil, resp.StatusCode, ErrNoDeviceFlow
	}
	return raw, resp.StatusCode, nil
}

// DeviceStart asks the site to open a pairing request. The answer holds the
// CLI's secret device_code and the link for a person.
func (c *Client) DeviceStart(ctx context.Context, deviceName string) (*DeviceStart, int, error) {
	raw, status, err := c.post(ctx, "/device/start", map[string]string{"device_name": deviceName})
	if err != nil {
		return nil, status, err
	}
	return decodeEnvelope[DeviceStart](raw, status)
}

// DevicePoll asks whether the request has been approved. While it has not, the
// returned error is a *RESTError whose Details.Code is one of the Code* values.
func (c *Client) DevicePoll(ctx context.Context, deviceCode string) (*PairResponse, int, error) {
	raw, status, err := c.post(ctx, "/device/poll", map[string]string{"device_code": deviceCode})
	if err != nil {
		return nil, status, err
	}
	return decodeEnvelope[PairResponse](raw, status)
}
