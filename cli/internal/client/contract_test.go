package client

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
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

// TestContract_BinaryRoundTrip verifies files travel base64-encoded in both
// directions, so bytes that are not valid UTF-8 arrive unchanged.
func TestContract_BinaryRoundTrip(t *testing.T) {
	raw := []byte{0x00, 0xff, 0xfe, 0x89, 'P', 'N', 'G', 0x0a}

	var got PushRequest
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		if r.Method == http.MethodPost {
			_ = json.NewDecoder(r.Body).Decode(&got)
			w.Write([]byte(`{"ok":true,"data":{"manifest_id":"m","applied":["a.png"],"deleted":["old.php"],"count":2,"health":"ok"}}`))
			return
		}
		if r.URL.Query().Get("encoding") != "base64" || r.URL.Query().Get("path") != "img/a b.png" {
			t.Errorf("unexpected query: %s", r.URL.RawQuery)
		}
		body, _ := json.Marshal(map[string]any{"ok": true, "data": map[string]any{
			"path": "img/a b.png", "size": len(raw), "encoding": "base64",
			"contents": base64.StdEncoding.EncodeToString(raw),
		}})
		w.Write(body)
	}))
	defer srv.Close()
	c := New(srv.URL, "user", "pass", srv.Client())

	file, err := c.ReadFile(context.Background(), "img/a b.png")
	if err != nil {
		t.Fatalf("ReadFile: %v", err)
	}
	if !bytes.Equal(file.Contents, raw) {
		t.Errorf("pulled bytes = %v, want %v", file.Contents, raw)
	}

	res, err := c.Push(context.Background(), PushRequest{
		Files:  []PushFile{NewPushFile("a.png", raw)},
		Delete: []string{"old.php"},
	})
	if err != nil {
		t.Fatalf("Push: %v", err)
	}
	sent, _ := base64.StdEncoding.DecodeString(got.Files[0].Contents)
	if got.Files[0].Encoding != "base64" || !bytes.Equal(sent, raw) {
		t.Errorf("pushed file not base64 of the raw bytes: %+v", got.Files[0])
	}
	if len(got.Delete) != 1 || got.Delete[0] != "old.php" {
		t.Errorf("Delete = %v", got.Delete)
	}
	if res.Health != "ok" || len(res.Deleted) != 1 {
		t.Errorf("response not parsed: %+v", res)
	}
}

// TestContract_PairingSendsCredentialsInBody verifies login does not rely on
// Basic auth, which core only accepts for Application Passwords.
func TestContract_PairingSendsCredentialsInBody(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if _, _, ok := r.BasicAuth(); ok {
			t.Errorf("%s must not send Basic auth", r.URL.Path)
		}
		var body map[string]string
		_ = json.NewDecoder(r.Body).Decode(&body)
		if r.Method != http.MethodPost || body["username"] != "admin" || body["password"] != "s3cret" {
			t.Errorf("%s %s body = %v", r.Method, r.URL.Path, body)
		}
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"ok":true,"data":{"user_id":1,"ttl":120,"device_id":"d","app_password":"ap"}}`))
	}))
	defer srv.Close()
	c := New(srv.URL, "", "", srv.Client())

	ch, _, err := c.Challenge(context.Background(), "admin", "s3cret")
	if err != nil || ch.TTL != 120 {
		t.Fatalf("Challenge: %+v, %v", ch, err)
	}
	reg, _, err := c.Register(context.Background(), "admin", "s3cret", RegisterRequest{PIN: "123456"})
	if err != nil || reg.AppPassword != "ap" {
		t.Fatalf("Register: %+v, %v", reg, err)
	}
}

// TestContract_Error_HealthFailed verifies the auto-rollback error carries
// what an agent needs: where the fatal is and that the site was restored.
func TestContract_Error_HealthFailed(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(422)
		w.Write([]byte(`{"ok":false,"error":{"code":"wpicker_health_failed","message":"Site returned HTTP 500","manifest_id":"m1","rolled_back":true,"http_status":500,"file":"functions.php","line":29}}`))
	}))
	defer srv.Close()
	c := New(srv.URL, "user", "pass", srv.Client())

	_, err := c.Push(context.Background(), PushRequest{})
	re, ok := IsRestError(err)
	if !ok {
		t.Fatalf("want RESTError, got %v", err)
	}
	d := re.Details
	if d.Code != "wpicker_health_failed" || !d.RolledBack || d.File != "functions.php" || d.Line != 29 || d.ManifestID != "m1" {
		t.Errorf("details = %+v", d)
	}
}
