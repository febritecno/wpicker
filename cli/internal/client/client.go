// Package client is the thin REST client for the WPicker plugin (wpicker/v1).
//
// It wraps net/http with: Basic auth (Application Password), JSON encode/decode,
// the uniform { ok, data } / { ok:false, error:{...} } response contract, and
// surfacing of structured errors so the self-healing loop works end-to-end.
package client

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"strings"
	"time"
)

// Namespace is the REST namespace exposed by the plugin.
const Namespace = "wpicker/v1"

// DefaultTimeout for requests that don't override it.
const DefaultTimeout = 60 * time.Second

// Client talks to a single WPicker site.
type Client struct {
	Site        string // e.g. https://example.com (no trailing slash)
	Username    string
	AppPassword string
	HTTP        *http.Client
	UserAgent   string
}

// New builds a Client. If httpClient is nil, a default one is used.
func New(site, username, appPassword string, httpClient *http.Client) *Client {
	if httpClient == nil {
		httpClient = &http.Client{Timeout: DefaultTimeout}
	}
	site = strings.TrimRight(site, "/")
	return &Client{
		Site:        site,
		Username:    username,
		AppPassword: appPassword,
		HTTP:        httpClient,
		UserAgent:   "WPicker-CLI/1.1.0",
	}
}

// ErrorDetail is the structured error body returned by the plugin on failure.
// It carries enough context (file, line, manifest_id) for an AI agent to
// self-heal without a human.
type ErrorDetail struct {
	Code       string `json:"code"`
	Message    string `json:"message"`
	File       string `json:"file,omitempty"`
	Line       int    `json:"line,omitempty"`
	ManifestID string `json:"manifest_id,omitempty"`
	Checked    int    `json:"checked,omitempty"`
}

// RESTError wraps the server's error shape and satisfies the error interface.
type RESTError struct {
	Ok      bool        `json:"ok"`
	Details ErrorDetail `json:"error"`
}

// Error implements the error interface.
func (e *RESTError) Error() string {
	if e == nil || e.Ok {
		return "<no error>"
	}
	d := e.Details
	msg := fmt.Sprintf("%s: %s", d.Code, d.Message)
	if d.File != "" {
		msg += fmt.Sprintf(" (file=%s", d.File)
		if d.Line > 0 {
			msg += fmt.Sprintf(":%d", d.Line)
		}
		if d.ManifestID != "" {
			msg += fmt.Sprintf(", manifest=%s", d.ManifestID)
		}
		msg += ")"
	} else if d.ManifestID != "" {
		msg += fmt.Sprintf(" (manifest=%s)", d.ManifestID)
	}
	return msg
}

// IsRestError reports whether err is a *RESTError from the server.
func IsRestError(err error) (*RESTError, bool) {
	var re *RESTError
	if errors.As(err, &re) {
		return re, true
	}
	return nil, false
}

// envelope mirrors the plugin's { ok, data } / { ok:false, error } shape.
type envelope struct {
	Ok    bool            `json:"ok"`
	Data  json.RawMessage `json:"data,omitempty"`
	Error json.RawMessage `json:"error,omitempty"`
}

// Do performs a request and unmarshals the data field into out (if non-nil).
//
// On a non-2xx status, or an envelope with ok=false, it returns a *Error.
func (c *Client) Do(ctx context.Context, method, path string, body any, out any) error {
	if c.Site == "" {
		return errors.New("client: site URL is empty")
	}
	url := c.Site + "/wp-json/" + Namespace + "/" + strings.TrimLeft(path, "/")

	var reader io.Reader
	if body != nil {
		buf, err := json.Marshal(body)
		if err != nil {
			return fmt.Errorf("encode body: %w", err)
		}
		reader = bytes.NewReader(buf)
	}

	req, err := http.NewRequestWithContext(ctx, method, url, reader)
	if err != nil {
		return err
	}
	req.SetBasicAuth(c.Username, c.AppPassword)
	req.Header.Set("Accept", "application/json")
	req.Header.Set("User-Agent", c.UserAgent)
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}

	resp, err := c.HTTP.Do(req)
	if err != nil {
		return fmt.Errorf("request %s %s: %w", method, path, err)
	}
	defer resp.Body.Close()

	raw, err := io.ReadAll(io.LimitReader(resp.Body, 50<<20)) // 50 MB safety cap
	if err != nil {
		return fmt.Errorf("read response: %w", err)
	}

	// First, try the envelope shape.
	var env envelope
	if err := json.Unmarshal(raw, &env); err == nil {
		if !env.Ok {
				ee := &RESTError{}
				if len(env.Error) > 0 {
					_ = json.Unmarshal(env.Error, &ee.Details)
				}
				ee.Ok = false
				// Status-code based code if the plugin didn't supply one.
				if ee.Details.Code == "" {
					ee.Details.Code = fmt.Sprintf("http_%d", resp.StatusCode)
				}
				return ee
		}
		if out != nil && len(env.Data) > 0 {
			if err := json.Unmarshal(env.Data, out); err != nil {
				return fmt.Errorf("decode data: %w", err)
			}
		}
		return nil
	}

	// Fall back to raw passthrough into out (e.g. WP core error pages).
	if out != nil {
		_ = json.Unmarshal(raw, out)
	}
	if resp.StatusCode >= 400 {
		return fmt.Errorf("http %d: %s", resp.StatusCode, truncate(string(raw), 300))
	}
	return nil
}

// DoRaw performs a request and returns the raw response body (used by login,
// where the Basic auth identity differs from the stored app password).
func (c *Client) DoRaw(ctx context.Context, method, path string, body any, username, password string) ([]byte, int, error) {
	url := c.Site + "/wp-json/" + Namespace + "/" + strings.TrimLeft(path, "/")

	var reader io.Reader
	if body != nil {
		buf, err := json.Marshal(body)
		if err != nil {
			return nil, 0, fmt.Errorf("encode body: %w", err)
		}
		reader = bytes.NewReader(buf)
	}

	req, err := http.NewRequestWithContext(ctx, method, url, reader)
	if err != nil {
		return nil, 0, err
	}
	req.SetBasicAuth(username, password)
	req.Header.Set("Accept", "application/json")
	req.Header.Set("User-Agent", c.UserAgent)
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}

	resp, err := c.HTTP.Do(req)
	if err != nil {
		return nil, 0, fmt.Errorf("request: %w", err)
	}
	defer resp.Body.Close()

	raw, err := io.ReadAll(io.LimitReader(resp.Body, 10<<20))
	if err != nil {
		return nil, resp.StatusCode, err
	}
	return raw, resp.StatusCode, nil
}

func truncate(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return s[:n] + "…"
}
