package client

import (
	"context"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
)

// A redirect must never carry the Application Password to another host.
func TestRedirectToAnotherHostIsRefusedAndNothingIsSentThere(t *testing.T) {
	var leaked int32
	other := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&leaked, 1)
		w.WriteHeader(200)
	}))
	defer other.Close()
	site := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Redirect(w, r, other.URL+"/steal", http.StatusTemporaryRedirect)
	}))
	defer site.Close()

	err := New(site.URL, "root", "SECRET", nil).Do(context.Background(), "GET", "/device", nil, nil)
	if err == nil || !strings.Contains(err.Error(), "refusing to follow a redirect") {
		t.Fatalf("expected the redirect to be refused, got %v", err)
	}
	if atomic.LoadInt32(&leaked) != 0 {
		t.Fatal("the other host was contacted")
	}
}

func TestRedirectOnTheSameHostStillWorks(t *testing.T) {
	mux := http.NewServeMux()
	mux.HandleFunc("/wp-json/wpicker/v1/old", func(w http.ResponseWriter, r *http.Request) {
		http.Redirect(w, r, "/wp-json/wpicker/v1/device", http.StatusFound)
	})
	mux.HandleFunc("/wp-json/wpicker/v1/device", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"ok":true,"data":{}}`))
	})
	s := httptest.NewServer(mux)
	defer s.Close()
	if err := New(s.URL, "u", "p", nil).Do(context.Background(), "GET", "/old", nil, nil); err != nil {
		t.Fatalf("a same-host redirect (e.g. trailing slash) must keep working: %v", err)
	}
}

func TestRedirectCheckRules(t *testing.T) {
	mk := func(raw string) *http.Request { r, _ := http.NewRequest("GET", raw, nil); return r }
	if err := refuseUnsafeRedirect(mk("http://a.example/x"), []*http.Request{mk("https://a.example/y")}); err == nil {
		t.Error("an https -> http downgrade must be refused")
	}
	if err := refuseUnsafeRedirect(mk("https://b.example/x"), []*http.Request{mk("https://a.example/y")}); err == nil {
		t.Error("another host must be refused")
	}
	if err := refuseUnsafeRedirect(mk("https://www.a.example/z"), []*http.Request{mk("https://a.example/y")}); err != nil {
		t.Errorf("a.example -> www.a.example is how many sites are set up: %v", err)
	}
	if err := refuseUnsafeRedirect(mk("https://evil-a.example/z"), []*http.Request{mk("https://a.example/y")}); err == nil {
		t.Error("a host that merely ends in the same letters is not a subdomain")
	}
	if err := refuseUnsafeRedirect(mk("https://a.example:8443/z"), []*http.Request{mk("https://a.example/y")}); err == nil {
		t.Error("another port is another service")
	}
	if err := refuseUnsafeRedirect(mk("https://a.example/z"), []*http.Request{mk("http://a.example/y")}); err != nil {
		t.Errorf("http -> https on the default ports is an upgrade: %v", err)
	}
	if err := refuseUnsafeRedirect(mk("https://A.example/z"), []*http.Request{mk("https://a.example/y")}); err != nil {
		t.Errorf("same host, different case: %v", err)
	}
	if err := refuseUnsafeRedirect(mk("http://a.example/z"), []*http.Request{mk("http://a.example/y")}); err != nil {
		t.Errorf("plain http staying plain http is not a downgrade: %v", err)
	}
	via := make([]*http.Request, 10)
	for i := range via {
		via[i] = mk("https://a.example/")
	}
	if err := refuseUnsafeRedirect(mk("https://a.example/"), via); err == nil {
		t.Error("redirect loops must stop")
	}
}
