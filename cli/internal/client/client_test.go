package client

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestDo_successEnvelope(t *testing.T) {
	data, _ := json.Marshal(map[string]any{"key": "val"})
	body, _ := json.Marshal(map[string]any{"ok": true, "data": json.RawMessage(data)})

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if got := r.Header.Get("Accept"); got != "application/json" {
			t.Errorf("Accept = %q, want application/json", got)
		}
		w.Header().Set("Content-Type", "application/json")
		w.Write(body)
	}))
	defer srv.Close()

	c := New(srv.URL, "user", "pass", srv.Client())
	var out map[string]any
	if err := c.Do(context.Background(), "GET", "/test", nil, &out); err != nil {
		t.Fatalf("Do: %v", err)
	}
	if out["key"] != "val" {
		t.Errorf("data = %v, want key=val", out)
	}
}

func TestDo_errorEnvelope(t *testing.T) {
	body, _ := json.Marshal(map[string]any{
		"ok": false,
		"error": map[string]string{
			"code":    "wpicker_lint_syntax",
			"message": "Parse error in style.css",
			"file":    "style.css",
		},
	})

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write(body)
	}))
	defer srv.Close()

	c := New(srv.URL, "user", "pass", srv.Client())
	err := c.Do(context.Background(), "POST", "/test", nil, nil)
	if err == nil {
		t.Fatal("expected error, got nil")
	}
	re, ok := IsRestError(err)
	if !ok {
		t.Fatalf("expected *RESTError, got %T: %v", err, err)
	}
	if re.Details.Code != "wpicker_lint_syntax" {
		t.Errorf("code = %q", re.Details.Code)
	}
	if re.Details.File != "style.css" {
		t.Errorf("file = %q", re.Details.File)
	}
}

func TestDo_httpError_noEnvelope(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Error(w, "gateway timeout", http.StatusGatewayTimeout)
	}))
	defer srv.Close()

	c := New(srv.URL, "user", "pass", srv.Client())
	err := c.Do(context.Background(), "GET", "/test", nil, nil)
	if err == nil {
		t.Fatal("expected error")
	}
	re, ok := IsRestError(err)
	if ok {
		t.Errorf("should not be RESTError for non-JSON response, got %v", re)
	}
}

func TestRESTError_ErrorMethod(t *testing.T) {
	re := &RESTError{
		Ok: false,
		Details: ErrorDetail{
			Code:    "wpicker_lint_syntax",
			Message: "unexpected end",
			File:    "functions.php",
			Line:    42,
		},
	}
	s := re.Error()
	if s == "" {
		t.Fatal("Error() returned empty")
	}
	t.Logf("Error() = %q", s)
	if s != "wpicker_lint_syntax: unexpected end (file=functions.php:42)" {
		t.Errorf("unexpected message: %s", s)
	}
}

func TestRESTError_nil(t *testing.T) {
	var re *RESTError
	s := re.Error()
	if s != "<no error>" {
		t.Errorf("nil RESTError.Error() = %q", s)
	}
}

func TestRESTError_okTrue(t *testing.T) {
	re := &RESTError{Ok: true}
	s := re.Error()
	if s != "<no error>" {
		t.Errorf("ok=true RESTError.Error() = %q", s)
	}
}

func TestNamespace(t *testing.T) {
	if Namespace != "wpicker/v1" {
		t.Errorf("Namespace = %q, want wpicker/v1", Namespace)
	}
}
