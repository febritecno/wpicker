package client

import (
	"context"
	"net/http"
	"net/http/httptest"
	"testing"
)

// TestContract_Context verifies the parsing of the Global Context API response.
func TestContract_Context(t *testing.T) {
	mockJSON := `{
		"ok": true,
		"data": {
			"wpicker": {"version": "1.1.0"},
			"site": {
				"name": "Test Site",
				"description": "Just another site",
				"url": "http://localhost",
				"admin_url": "http://localhost/wp-admin",
				"permalink_structure": "/%postname%/",
				"timezone": "UTC"
			},
			"environment": {
				"wp_version": "6.2",
				"php_version": "7.4.33",
				"is_multisite": false,
				"language": "en-US"
			},
			"theme": {
				"name": "WPicker Child",
				"stylesheet": "wpicker-child",
				"version": "1.0",
				"template": "twentytwentyfour",
				"is_child_theme": true
			},
			"plugins": [
				{
					"name": "WPicker",
					"version": "1.1.0",
					"path": "wpicker/wpicker.php"
				}
			],
			"theme_mods": {
				"background_color": "ffffff"
			}
		}
	}`

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(mockJSON))
	}))
	defer srv.Close()

	c := New(srv.URL, "user", "pass", srv.Client())
	res, err := c.GetContext(context.Background())
	if err != nil {
		t.Fatalf("GetContext failed: %v", err)
	}

	if res.Site.Name != "Test Site" {
		t.Errorf("Site.Name = %q, want 'Test Site'", res.Site.Name)
	}
	if res.Environment.PHPVersion != "7.4.33" {
		t.Errorf("Environment.PHPVersion = %q, want '7.4.33'", res.Environment.PHPVersion)
	}
	if !res.Theme.IsChildTheme {
		t.Error("Theme.IsChildTheme = false, want true")
	}
	if len(res.Plugins) != 1 || res.Plugins[0].Name != "WPicker" {
		t.Errorf("Plugins parsing failed: %+v", res.Plugins)
	}
	if res.ThemeMods["background_color"] != "ffffff" {
		t.Errorf("ThemeMods parsing failed: %+v", res.ThemeMods)
	}
}

// TestContract_PushSuccess verifies parsing of a successful push response.
func TestContract_PushSuccess(t *testing.T) {
	mockJSON := `{
		"ok": true,
		"data": {
			"manifest_id": "20231010-120000-abcdef",
			"applied": ["style.css", "functions.php"],
			"count": 2
		}
	}`

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(mockJSON))
	}))
	defer srv.Close()

	c := New(srv.URL, "user", "pass", srv.Client())
	res, err := c.Push(context.Background(), PushRequest{})
	if err != nil {
		t.Fatalf("Push failed: %v", err)
	}

	if res.ManifestID != "20231010-120000-abcdef" {
		t.Errorf("ManifestID = %q", res.ManifestID)
	}
	if res.Count != 2 || len(res.Applied) != 2 {
		t.Errorf("Count or Applied array parsing failed")
	}
}

// TestContract_Error_LintSyntax verifies parsing of structured self-healing errors.
func TestContract_Error_LintSyntax(t *testing.T) {
	mockJSON := `{
		"ok": false,
		"error": {
			"code": "wpicker_lint_syntax",
			"message": "syntax error, unexpected end of file",
			"file": "functions.php",
			"line": 42
		}
	}`

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(mockJSON))
	}))
	defer srv.Close()

	c := New(srv.URL, "user", "pass", srv.Client())
	_, err := c.Push(context.Background(), PushRequest{})
	
	if err == nil {
		t.Fatal("expected error, got nil")
	}

	restErr, ok := IsRestError(err)
	if !ok {
		t.Fatalf("expected RESTError, got: %v", err)
	}

	if restErr.Details.Code != "wpicker_lint_syntax" {
		t.Errorf("Code = %q", restErr.Details.Code)
	}
	if restErr.Details.File != "functions.php" {
		t.Errorf("File = %q", restErr.Details.File)
	}
	if restErr.Details.Line != 42 {
		t.Errorf("Line = %d", restErr.Details.Line)
	}
}

// TestContract_History verifies parsing of the history listing.
func TestContract_History(t *testing.T) {
	mockJSON := `{
		"ok": true,
		"data": {
			"manifests": [
				{
					"id": "man-1",
					"kind": "push",
					"created_at_gmt": "2023-10-10T12:00:00Z",
					"device_name": "CLI 1",
					"count": 5,
					"status": "applied",
					"restore_count": 0
				}
			],
			"count": 1
		}
	}`

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(mockJSON))
	}))
	defer srv.Close()

	c := New(srv.URL, "user", "pass", srv.Client())
	res, err := c.History(context.Background(), 10)
	if err != nil {
		t.Fatalf("History failed: %v", err)
	}

	if res.Count != 1 || len(res.Manifests) != 1 {
		t.Fatalf("Count parsing failed: %d", res.Count)
	}
	m := res.Manifests[0]
	if m.ID != "man-1" || m.Status != "applied" || m.DeviceName != "CLI 1" {
		t.Errorf("Manifests array parsing failed: %+v", m)
	}
}

// TestContract_DecodeEnvelope verifies the low-level decoding logic directly.
func TestContract_DecodeEnvelope(t *testing.T) {
	raw := []byte(`{
		"ok": true,
		"data": {
			"user_id": 1,
			"pin": "123456",
			"expires_at_gmt": "date",
			"ttl": 600,
			"hint": "message"
		}
	}`)
	
	res, status, err := decodeEnvelope[ChallengeResponse](raw, 200)
	if err != nil {
		t.Fatalf("decodeEnvelope failed: %v", err)
	}
	if status != 200 {
		t.Errorf("status = %d", status)
	}
	if res.PIN != "123456" || res.TTL != 600 {
		t.Errorf("parsed response invalid: %+v", res)
	}
}
