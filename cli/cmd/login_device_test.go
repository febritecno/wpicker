package cmd

import (
	"context"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"wpicker/internal/config"
)

func resetLogin() {
	loginName, loginDevice, loginPassword, loginNoBrowser = "", "", false, false
}

// stdin replaces standard input for one test with the given content.
func stdin(t *testing.T, content string) {
	t.Helper()
	r, w, _ := os.Pipe()
	w.WriteString(content)
	w.Close()
	old := os.Stdin
	os.Stdin = r
	t.Cleanup(func() { os.Stdin = old; r.Close() })
}

// A pretend WordPress for the device flow: pending a few times, then the answer asked for.
func deviceSite(t *testing.T, final string) (*httptest.Server, *int32) {
	t.Helper()
	var polls int32
	var srv *httptest.Server
	srv = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		switch {
		case strings.HasSuffix(r.URL.Path, "/device/start"):
			w.WriteHeader(201)
			io.WriteString(w, `{"ok":true,"data":{"device_code":"`+strings.Repeat("cd", 24)+`","user_code":"K7QF-92XM","verification_url":"`+srv.URL+`/wp-admin/admin.php?page=wpicker-approve&user_code=K7QF-92XM","expires_in":600,"interval":3,"site_name":"Shop"}}`)
		case strings.HasSuffix(r.URL.Path, "/device/poll"):
			n := atomic.AddInt32(&polls, 1)
			if n < 3 {
				w.WriteHeader(400)
				io.WriteString(w, `{"ok":false,"error":{"code":"authorization_pending","message":"wait"}}`)
				return
			}
			if final == "denied" {
				w.WriteHeader(403)
				io.WriteString(w, `{"ok":false,"error":{"code":"access_denied","message":"no"}}`)
				return
			}
			w.WriteHeader(201)
			io.WriteString(w, `{"ok":true,"data":{"user_id":1,"user_login":"root","device_id":"dev-new","app_password":"NEW-SECRET","device_name":"laptop","plugin_version":"`+Version+`","site_name":"Shop"}}`)
		default:
			w.WriteHeader(404)
			io.WriteString(w, `{"code":"rest_no_route"}`)
		}
	}))
	t.Cleanup(srv.Close)
	return srv, &polls
}

func quick(t *testing.T) {
	t.Helper()
	deviceWait = func(ctx context.Context, d time.Duration) error { return ctx.Err() }
	t.Cleanup(func() { deviceWait = nil })
}

func TestLoginPairsAfterApprovalAndSavesTheSite(t *testing.T) {
	fresh(t)
	resetLogin()
	quick(t)
	stdin(t, "") // nothing to read: any prompt would end in an error
	srv, polls := deviceSite(t, "ok")

	out := capture(t, func() {
		if err := loginCmd.RunE(loginCmd, []string{srv.URL}); err != nil {
			t.Fatal(err)
		}
	})
	if atomic.LoadInt32(polls) != 3 {
		t.Fatalf("polls=%d", *polls)
	}
	st, _ := config.LoadStore()
	if len(st.Sites) != 1 || st.Sites[0].Username != "root" || st.Sites[0].AppPassword != "NEW-SECRET" || st.Sites[0].DeviceID != "dev-new" {
		t.Fatalf("%+v", st.Sites)
	}
	for _, want := range []string{"open this link in your browser", "K7QF-92XM", "Approved"} {
		if !strings.Contains(out, want) {
			t.Errorf("%q missing:\n%s", want, out)
		}
	}
	if strings.Contains(out, "NEW-SECRET") {
		t.Fatal("the password was printed")
	}
	if strings.Contains(strings.ToLower(out), "password:") || strings.Contains(out, "PIN") {
		t.Fatalf("it asked for something it should not:\n%s", out)
	}
}

func TestADeniedRequestSavesNothing(t *testing.T) {
	fresh(t)
	resetLogin()
	quick(t)
	stdin(t, "")
	srv, _ := deviceSite(t, "denied")
	err := loginCmd.RunE(loginCmd, []string{srv.URL})
	if err == nil || !strings.Contains(err.Error(), "denied") {
		t.Fatalf("%v", err)
	}
	if st, _ := config.LoadStore(); len(st.Sites) != 0 {
		t.Fatal("a denied request left a site behind")
	}
}

func TestRealSiteAddressesThatLookLikeCodesAreNotRefused(t *testing.T) {
	fresh(t)
	resetLogin()
	quick(t)
	stdin(t, "")
	// Ten characters, no dots: the shape of the codes an older version used. They are addresses now.
	for _, name := range []string{"mysite1234", "wordpress1", "wp-staging1"} {
		err := loginCmd.RunE(loginCmd, []string{"http://" + name + ".invalid"})
		if err != nil && strings.Contains(err.Error(), "not used any more") {
			t.Errorf("%s was refused as an old code", name)
		}
		err = loginCmd.RunE(loginCmd, []string{name})
		if err != nil && strings.Contains(err.Error(), "not used any more") {
			t.Errorf("bare %s was refused as an old code", name)
		}
	}
	if err := loginCmd.Args(loginCmd, []string{"https://a.example", "https://b.example"}); err == nil {
		t.Fatal("two arguments are still too many")
	}
}

func TestSitesLoginPairsTheSameSiteAgainAndKeepsItsIdentity(t *testing.T) {
	_, work := fresh(t)
	resetLogin()
	quick(t)
	stdin(t, "")
	srv, _ := deviceSite(t, "ok")
	old := addSite(t, srv.URL, "shop")
	if err := linkFolder(work, old); err != nil {
		t.Fatal(err)
	}
	// the old login was revoked on the site; pair again (no address asked)
	if err := sitesLoginCmd.RunE(sitesLoginCmd, []string{"shop"}); err != nil {
		t.Fatal(err)
	}
	st, _ := config.LoadStore()
	if len(st.Sites) != 1 {
		t.Fatalf("a second site appeared: %+v", st.Sites)
	}
	s := st.Sites[0]
	if s.ID != old.ID || s.Name != "shop" || s.AppPassword != "NEW-SECRET" || s.DeviceID != "dev-new" || len(s.Folders) != 1 {
		t.Fatalf("identity or folders lost: %+v", s)
	}
}

func TestFlagsForNameAndDeviceAreUsed(t *testing.T) {
	fresh(t)
	resetLogin()
	quick(t)
	stdin(t, "")
	srv, _ := deviceSite(t, "ok")
	loginName = "shop"
	defer resetLogin()
	if err := loginCmd.RunE(loginCmd, []string{srv.URL}); err != nil {
		t.Fatal(err)
	}
	if st, _ := config.LoadStore(); st.Sites[0].Name != "shop" {
		t.Fatalf("%+v", st.Sites[0])
	}
}

func TestAStopCtrlCLeavesNothingBehind(t *testing.T) {
	fresh(t)
	resetLogin()
	stdin(t, "")
	srv, _ := deviceSite(t, "ok")
	ctx, cancel := context.WithCancel(context.Background())
	deviceWait = func(c context.Context, d time.Duration) error { cancel(); return c.Err() }
	t.Cleanup(func() { deviceWait = nil })
	loginCmd.SetContext(ctx)
	defer loginCmd.SetContext(nil)
	if err := loginCmd.RunE(loginCmd, []string{srv.URL}); err == nil || !strings.Contains(err.Error(), "cancelled") {
		t.Fatalf("%v", err)
	}
	if st, _ := config.LoadStore(); len(st.Sites) != 0 {
		t.Fatal("a cancelled pairing left a site behind")
	}
}

func TestLoginStillOffersToLinkTheFolderWhenThereAreSeveralSites(t *testing.T) {
	fresh(t)
	resetLogin()
	quick(t)
	addSite(t, "https://other.example", "other")
	srv, _ := deviceSite(t, "ok")
	stdin(t, "")
	out := capture(t, func() { loginCmd.RunE(loginCmd, []string{srv.URL}) })
	if !strings.Contains(out, "wpicker sites link") {
		t.Fatalf("without a terminal it should say how to link:\n%s", out)
	}
}

func TestTheBrowserIsNeverOpenedForAScript(t *testing.T) {
	// Under `go test` stdin/stdout are not terminals, exactly like a script or an agent.
	if isTerminal(os.Stdout) {
		t.Skip("running attached to a terminal")
	}
	fresh(t)
	resetLogin()
	quick(t)
	stdin(t, "")
	srv, _ := deviceSite(t, "ok")
	out := capture(t, func() { loginCmd.RunE(loginCmd, []string{srv.URL}) })
	if strings.Contains(out, "opened in your browser") {
		t.Fatal("the browser was opened for a non-interactive run")
	}
}
