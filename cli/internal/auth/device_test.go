package auth

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"
)

// A pretend WordPress that plays the device flow from a script.
type site struct {
	srv      *httptest.Server
	mu       sync.Mutex
	startReq map[string]string
	polls    []string // device_codes it was polled with
	auth     []string // any Authorization header it ever saw
	script   []string // what each poll answers: pending | slow | denied | expired | invalid | busy | ok | 5xx
	link     string   // verification_url to return ('' = same host)
	startAns int      // status of /device/start (0 = 201)
	startRaw string
}

func newSite(t *testing.T, script ...string) *site {
	s := &site{script: script}
	s.srv = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		s.mu.Lock()
		defer s.mu.Unlock()
		if a := r.Header.Get("Authorization"); a != "" {
			s.auth = append(s.auth, a)
		}
		raw, _ := io.ReadAll(r.Body)
		var body map[string]string
		_ = json.Unmarshal(raw, &body)
		w.Header().Set("Content-Type", "application/json")
		switch r.URL.Path {
		case "/wp-json/wpicker/v1/device/start":
			s.startReq = body
			if s.startRaw != "" {
				w.WriteHeader(s.startAns)
				io.WriteString(w, s.startRaw)
				return
			}
			link := s.link
			if link == "" {
				link = s.srv.URL + "/wp-admin/admin.php?page=wpicker-approve&user_code=K7QF-92XM"
			}
			w.WriteHeader(201)
			io.WriteString(w, `{"ok":true,"data":{"device_code":"`+strings.Repeat("ab", 24)+`","user_code":"K7QF-92XM","verification_url":"`+link+`","expires_in":600,"interval":3,"site_name":"Shop"}}`)
		case "/wp-json/wpicker/v1/device/poll":
			s.polls = append(s.polls, body["device_code"])
			step := "pending"
			if len(s.script) > 0 {
				step, s.script = s.script[0], s.script[1:]
			}
			fail := func(code int, c string) {
				w.WriteHeader(code)
				io.WriteString(w, `{"ok":false,"error":{"code":"`+c+`","message":"`+c+`"}}`)
			}
			switch step {
			case "pending":
				fail(400, "authorization_pending")
			case "slow":
				fail(400, "slow_down")
			case "denied":
				fail(403, "access_denied")
			case "expired":
				fail(400, "expired_token")
			case "invalid":
				fail(401, "wpicker_code_invalid")
			case "busy":
				fail(429, "wpicker_busy")
			case "5xx":
				w.WriteHeader(502)
				io.WriteString(w, "<html>bad gateway</html>")
			case "ok":
				w.WriteHeader(201)
				io.WriteString(w, `{"ok":true,"data":{"user_id":1,"user_login":"root","device_id":"dev-1","app_password":"abcd efgh","device_name":"laptop","plugin_version":"1.3.0","site_name":"Shop"}}`)
			}
		default:
			w.WriteHeader(404)
			io.WriteString(w, `{"code":"rest_no_route"}`)
		}
	}))
	t.Cleanup(s.srv.Close)
	return s
}

// instant clock; records the waits.
type clock struct {
	waits []time.Duration
	after func(n int) // optional hook after the nth wait
}

func (c *clock) wait(ctx context.Context, d time.Duration) error {
	if err := ctx.Err(); err != nil {
		return err
	}
	c.waits = append(c.waits, d)
	if c.after != nil {
		c.after(len(c.waits))
	}
	return nil
}

func opts(s *site, ck *clock) DeviceOptions {
	return DeviceOptions{Site: s.srv.URL, Device: "laptop", CLIVersion: "1.3.0", Wait: ck.wait}
}

func TestWaitsForApprovalThenReturnsTheLogin(t *testing.T) {
	s := newSite(t, "pending", "pending", "ok")
	ck := &clock{}
	var out bytes.Buffer
	res, err := DeviceLogin(context.Background(), opts(s, ck), strings.NewReader(""), &out)
	if err != nil {
		t.Fatal(err)
	}
	if res.Username != "root" || res.AppPassword != "abcd efgh" || res.DeviceID != "dev-1" || res.URL != s.srv.URL {
		t.Fatalf("%+v", res)
	}
	if len(s.polls) != 3 || len(ck.waits) != 3 {
		t.Fatalf("polls=%d waits=%d", len(s.polls), len(ck.waits))
	}
	if strings.Contains(out.String(), "abcd efgh") {
		t.Fatal("the new password was printed")
	}
	for _, want := range []string{"open this link in your browser", "/wp-admin/admin.php?page=wpicker-approve", "K7QF-92XM", "Waiting for approval", "Approved"} {
		if !strings.Contains(out.String(), want) {
			t.Errorf("%q missing from:\n%s", want, out.String())
		}
	}
}

func TestNothingBeyondTheDeviceNameIsEverSentAndNoCredentialsAtAll(t *testing.T) {
	s := newSite(t, "ok")
	if _, err := DeviceLogin(context.Background(), opts(s, &clock{}), strings.NewReader(""), io.Discard); err != nil {
		t.Fatal(err)
	}
	if len(s.startReq) != 1 || s.startReq["device_name"] != "laptop" {
		t.Fatalf("start sent %v", s.startReq)
	}
	if len(s.auth) != 0 {
		t.Fatalf("an Authorization header was sent: %v", s.auth)
	}
	if len(s.polls) != 1 || s.polls[0] != strings.Repeat("ab", 24) {
		t.Fatalf("the poll must carry the secret device_code: %v", s.polls)
	}
}

func TestTheHumanCodeIsNeverSentBack(t *testing.T) {
	s := newSite(t, "pending", "ok")
	DeviceLogin(context.Background(), opts(s, &clock{}), strings.NewReader(""), io.Discard)
	for _, p := range s.polls {
		if strings.Contains(p, "K7QF") {
			t.Fatalf("the user code was sent to the poll: %q", p)
		}
	}
}

func TestAnEagerClientBacksOff(t *testing.T) {
	s := newSite(t, "slow", "slow", "ok")
	ck := &clock{}
	if _, err := DeviceLogin(context.Background(), opts(s, ck), strings.NewReader(""), io.Discard); err != nil {
		t.Fatal(err)
	}
	if !(ck.waits[1] > ck.waits[0] && ck.waits[2] > ck.waits[1]) {
		t.Fatalf("each slow_down must lengthen the wait: %v", ck.waits)
	}
}

func TestEveryOutcomeSaysWhatHappened(t *testing.T) {
	cases := map[string]string{
		"denied":  "denied",
		"expired": "expired",
		"invalid": "no longer knows this request",
	}
	for step, want := range cases {
		s := newSite(t, "pending", step)
		_, err := DeviceLogin(context.Background(), opts(s, &clock{}), strings.NewReader(""), io.Discard)
		if err == nil || !strings.Contains(err.Error(), want) {
			t.Errorf("%s: %v", step, err)
		}
		if len(s.polls) != 2 {
			t.Errorf("%s: it kept polling after a final answer (%d)", step, len(s.polls))
		}
	}
}

func TestABusySiteIsWaitedOutNotGivenUpOn(t *testing.T) {
	s := newSite(t, "busy", "ok")
	ck := &clock{}
	if _, err := DeviceLogin(context.Background(), opts(s, ck), strings.NewReader(""), io.Discard); err != nil {
		t.Fatal(err)
	}
	if ck.waits[1] <= ck.waits[0] {
		t.Fatalf("%v", ck.waits)
	}
}

func TestAFewNetworkHiccupsDoNotThrowAwayAPairing(t *testing.T) {
	s := newSite(t, "5xx", "5xx", "5xx", "ok")
	if _, err := DeviceLogin(context.Background(), opts(s, &clock{}), strings.NewReader(""), io.Discard); err != nil {
		t.Fatalf("%v", err)
	}
	s = newSite(t, "5xx", "5xx", "5xx", "5xx", "5xx", "5xx", "ok")
	if _, err := DeviceLogin(context.Background(), opts(s, &clock{}), strings.NewReader(""), io.Discard); err == nil || !strings.Contains(err.Error(), "lost contact") {
		t.Fatalf("a site that stays down must end the wait: %v", err)
	}
}

func TestCtrlCStopsTheWaitAtOnce(t *testing.T) {
	s := newSite(t) // pending forever
	ctx, cancel := context.WithCancel(context.Background())
	ck := &clock{after: func(n int) {
		if n == 2 {
			cancel()
		}
	}}
	_, err := DeviceLogin(ctx, opts(s, ck), strings.NewReader(""), io.Discard)
	if err == nil || !strings.Contains(err.Error(), "cancelled") {
		t.Fatalf("%v", err)
	}
	if len(s.polls) > 2 {
		t.Fatalf("kept polling after the cancel: %d", len(s.polls))
	}
}

func TestALinkToAnotherHostIsRefusedAndNeverOpened(t *testing.T) {
	var opened int32
	for _, evil := range []string{"https://evil.example/wp-admin/admin.php?page=wpicker-approve", "http://127.0.0.1:1/x", "javascript:alert(1)", "file:///etc/passwd", "//evil.example/x"} {
		s := newSite(t, "ok")
		s.link = evil
		o := opts(s, &clock{})
		o.OpenBrowser = func(string) error { atomic.AddInt32(&opened, 1); return nil }
		_, err := DeviceLogin(context.Background(), o, strings.NewReader(""), io.Discard)
		if err == nil {
			t.Errorf("%q was accepted", evil)
		}
		if len(s.polls) != 0 {
			t.Errorf("%q: it polled a site whose link it did not trust", evil)
		}
	}
	if atomic.LoadInt32(&opened) != 0 {
		t.Fatal("a link it did not trust was opened in the browser")
	}
}

func TestAnHttpsSiteMustNotSendYouToPlainHttp(t *testing.T) {
	if err := checkLink("http://shop.example.com/wp-admin/x", "https://shop.example.com"); err == nil {
		t.Fatal("a downgrade must be refused")
	}
	if err := checkLink("https://shop.example.com/wp-admin/x", "https://shop.example.com"); err != nil {
		t.Fatal(err)
	}
	if err := checkLink("https://SHOP.example.com/wp-admin/x", "https://shop.example.com"); err != nil {
		t.Fatal("host case is not a difference")
	}
	if err := checkLink("http://wpicker.local/wp-admin/x", "http://wpicker.local"); err != nil {
		t.Fatal("a local http site links to itself over http")
	}
}

func TestTheBrowserIsOpenedOnlyWhenAsked(t *testing.T) {
	s := newSite(t, "ok")
	var got []string
	o := opts(s, &clock{})
	o.OpenBrowser = func(l string) error { got = append(got, l); return nil }
	var out bytes.Buffer
	if _, err := DeviceLogin(context.Background(), o, strings.NewReader(""), &out); err != nil {
		t.Fatal(err)
	}
	if len(got) != 1 || !strings.Contains(got[0], "wpicker-approve") || !strings.Contains(out.String(), "opened in your browser") {
		t.Fatalf("%v\n%s", got, out.String())
	}
	s2 := newSite(t, "ok")
	var out2 bytes.Buffer
	if _, err := DeviceLogin(context.Background(), opts(s2, &clock{}), strings.NewReader(""), &out2); err != nil {
		t.Fatal(err)
	}
	if strings.Contains(out2.String(), "opened in your browser") {
		t.Fatal("no opener was given, yet it claims to have opened the browser")
	}
}

func TestAFailingBrowserOpenerIsNotAnError(t *testing.T) {
	s := newSite(t, "ok")
	o := opts(s, &clock{})
	o.OpenBrowser = func(string) error { return errors.New("no display") }
	var out bytes.Buffer
	if _, err := DeviceLogin(context.Background(), o, strings.NewReader(""), &out); err != nil {
		t.Fatal(err)
	}
	if strings.Contains(out.String(), "opened in your browser") || !strings.Contains(out.String(), "open this link") {
		t.Fatalf("the link must still be printed:\n%s", out.String())
	}
}

func TestOnlyTheSiteAddressIsEverAskedFor(t *testing.T) {
	s := newSite(t, "ok")
	o := opts(s, &clock{})
	o.Site = ""
	var out bytes.Buffer
	if _, err := DeviceLogin(context.Background(), o, strings.NewReader(s.srv.URL+"\n"), &out); err != nil {
		t.Fatal(err)
	}
	for _, q := range []string{"password", "Password", "PIN", "username", "code:", "Pairing code"} {
		if strings.Contains(out.String(), q) {
			t.Errorf("it asked for %q:\n%s", q, out.String())
		}
	}
	// With the address known, it asks for nothing at all.
	s2 := newSite(t, "ok")
	var out2 bytes.Buffer
	if _, err := DeviceLogin(context.Background(), opts(s2, &clock{}), strings.NewReader(""), &out2); err != nil {
		t.Fatal(err)
	}
	if strings.Contains(out2.String(), "site address") {
		t.Fatal("asked for an address it had")
	}
	// ...and a re-pair of a listed site never asks either.
	s3 := newSite(t, "ok")
	o3 := opts(s3, &clock{})
	o3.Fixed = true
	var out3 bytes.Buffer
	if _, err := DeviceLogin(context.Background(), o3, strings.NewReader(""), &out3); err != nil {
		t.Fatal(err)
	}
}

func TestNoAddressAndNoTerminalIsAnErrorNotAHang(t *testing.T) {
	_, err := DeviceLogin(context.Background(), DeviceOptions{CLIVersion: "1.3.0"}, strings.NewReader(""), io.Discard)
	if err == nil || !strings.Contains(err.Error(), "site address is needed") {
		t.Fatalf("%v", err)
	}
}

func TestAnOldPluginGetsTheFallbackHint(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(404)
		io.WriteString(w, `{"code":"rest_no_route","message":"No route"}`)
	}))
	defer srv.Close()
	_, err := DeviceLogin(context.Background(), DeviceOptions{Site: srv.URL, CLIVersion: "1.3.0"}, strings.NewReader(""), io.Discard)
	if err == nil || !strings.Contains(err.Error(), "older than 1.3.0") || !strings.Contains(err.Error(), "Update or activate the plugin") {
		t.Fatalf("%v", err)
	}
}

func TestABare404IsNotTakenForAnOldPlugin(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(404)
		io.WriteString(w, `<html>Not Found</html>`)
	}))
	defer srv.Close()
	_, err := DeviceLogin(context.Background(), DeviceOptions{Site: srv.URL, CLIVersion: "1.3.0"}, strings.NewReader(""), io.Discard)
	if err == nil || strings.Contains(err.Error(), "older than") {
		t.Fatalf("a wrong address is not a plugin problem: %v", err)
	}
}

func TestStartFailuresAreExplained(t *testing.T) {
	s := newSite(t)
	s.startAns, s.startRaw = 429, `{"ok":false,"error":{"code":"wpicker_busy","message":"Too many pairing requests from this address."}}`
	if _, err := DeviceLogin(context.Background(), opts(s, &clock{}), strings.NewReader(""), io.Discard); err == nil || !strings.Contains(err.Error(), "Too many pairing requests") {
		t.Fatalf("%v", err)
	}
	s2 := newSite(t)
	s2.startAns, s2.startRaw = 403, `{"ok":false,"error":{"code":"wpicker_insecure_transport","message":"Pairing needs HTTPS. Use the https:// address of this site."}}`
	if _, err := DeviceLogin(context.Background(), opts(s2, &clock{}), strings.NewReader(""), io.Discard); err == nil || !strings.Contains(err.Error(), "needs HTTPS") {
		t.Fatalf("%v", err)
	}
}

func TestARedirectedStartIsExplainedAndNotFollowed(t *testing.T) {
	var landed int32
	target := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { atomic.AddInt32(&landed, 1) }))
	defer target.Close()
	moved := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Redirect(w, r, target.URL+r.URL.Path, http.StatusMovedPermanently)
	}))
	defer moved.Close()
	_, err := DeviceLogin(context.Background(), DeviceOptions{Site: moved.URL, CLIVersion: "1.3.0"}, strings.NewReader(""), io.Discard)
	if err == nil || !strings.Contains(err.Error(), "redirects elsewhere") {
		t.Fatalf("%v", err)
	}
	if atomic.LoadInt32(&landed) != 0 {
		t.Fatal("the request was followed to the redirect target")
	}
}

func TestAnApprovalWithoutALoginIsRefused(t *testing.T) {
	s := newSite(t)
	s.script = []string{"ok"}
	// replace the ok answer by a half answer
	half := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		if strings.HasSuffix(r.URL.Path, "/device/start") {
			w.WriteHeader(201)
			io.WriteString(w, `{"ok":true,"data":{"device_code":"`+strings.Repeat("ab", 24)+`","user_code":"K7QF-92XM","verification_url":"http://`+r.Host+`/wp-admin/x","expires_in":600,"interval":3}}`)
			return
		}
		w.WriteHeader(201)
		io.WriteString(w, `{"ok":true,"data":{"device_id":"d"}}`)
	}))
	defer half.Close()
	if _, err := DeviceLogin(context.Background(), DeviceOptions{Site: half.URL, CLIVersion: "1.3.0", Wait: (&clock{}).wait}, strings.NewReader(""), io.Discard); err == nil {
		t.Fatal("a half answer must not be stored as a login")
	}
}

func TestADifferentPluginVersionIsMentioned(t *testing.T) {
	s := newSite(t, "ok")
	o := opts(s, &clock{})
	o.CLIVersion = "1.2.0"
	var out bytes.Buffer
	if _, err := DeviceLogin(context.Background(), o, strings.NewReader(""), &out); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(out.String(), "VERSION MISMATCH") {
		t.Fatal(out.String())
	}
}

func TestTheDeviceNameDefaultsToTheHostWithoutDotLocal(t *testing.T) {
	s := newSite(t, "ok")
	o := opts(s, &clock{})
	o.Device = ""
	if _, err := DeviceLogin(context.Background(), o, strings.NewReader(""), io.Discard); err != nil {
		t.Fatal(err)
	}
	if s.startReq["device_name"] == "" || strings.HasSuffix(s.startReq["device_name"], ".local") {
		t.Fatalf("%q", s.startReq["device_name"])
	}
}

func TestWhichLinksMayBeOpenedInTheBrowser(t *testing.T) {
	for link, want := range map[string]bool{
		"https://shop.example.com/wp-admin/admin.php?page=x": true,
		"http://wpicker.local/wp-admin/x":                    true,
		"http://localhost:8080/wp-admin/x":                   true,
		"http://shop.example.com/wp-admin/x":                 false, // plain http on the public internet
		"https://user:pw@shop.example.com/":                  false,
		"file:///etc/passwd":                                 false,
		"javascript:alert(1)":                                false,
		"ftp://x/y":                                          false,
		"":                                                   false,
		"https://":                                           false,
	} {
		if got := CanOpen(link); got != want {
			t.Errorf("CanOpen(%q) = %v, want %v", link, got, want)
		}
	}
	if err := OpenBrowser("file:///etc/passwd"); err == nil {
		t.Fatal("OpenBrowser must refuse before starting anything")
	}
}

func TestNothingFromTheServerCanRewriteYourTerminal(t *testing.T) {
	evil := "\x1b[2J\x1b[31mYOU HAVE BEEN HACKED\x1b[0m\u202e\u200b\r\n"
	body := `{"ok":true,"data":{"user_id":1,"user_login":"root` + "\\u001b[1mX" + `","device_id":"d","app_password":"abcd","device_name":"` + "\\u001b[2Jlaptop" + `","plugin_version":"1.3.0","site_name":"` + "\\u001b[31mShop\\u202e" + `"}}`
	s := newSite(t, "ok")
	srv := s.srv
	_ = evil
	// Replace the final answer by one full of control characters.
	hostile := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		if strings.HasSuffix(r.URL.Path, "/device/start") {
			w.WriteHeader(201)
			io.WriteString(w, `{"ok":true,"data":{"device_code":"`+strings.Repeat("ab", 24)+`","user_code":"K7QF-92XM","verification_url":"http://`+r.Host+`/wp-admin/x","expires_in":600,"interval":3}}`)
			return
		}
		w.WriteHeader(201)
		io.WriteString(w, body)
	}))
	defer hostile.Close()
	_ = srv
	var out bytes.Buffer
	if _, err := DeviceLogin(context.Background(), DeviceOptions{Site: hostile.URL, Device: "x\x1b[2Jy", CLIVersion: "1.3.0", Wait: (&clock{}).wait}, strings.NewReader(""), &out); err != nil {
		t.Fatal(err)
	}
	if strings.ContainsAny(out.String(), "\x1b\u202e\u200b\r\x07") {
		t.Fatalf("a control or bidi character reached the terminal: %q", out.String())
	}
}

func TestErrorMessagesFromTheServerAreCleanedToo(t *testing.T) {
	s := newSite(t)
	s.startAns, s.startRaw = 500, `{"ok":false,"error":{"code":"x\u001b[31m","message":"\u001b[2Jboom\u202e"}}`
	_, err := DeviceLogin(context.Background(), opts(s, &clock{}), strings.NewReader(""), io.Discard)
	if err == nil || strings.ContainsAny(err.Error(), "\x1b\u202e") || !strings.Contains(err.Error(), "boom") {
		t.Fatalf("%q", err)
	}
}

func TestAConfirmationCodeInAnUnexpectedShapeIsRefused(t *testing.T) {
	for _, bad := range []string{"K7QF92XM", "k7qf-92xm", "K7QF-92XM\x1b[2J", "K7QF-92XM-EXTRA", ""} {
		srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			w.Header().Set("Content-Type", "application/json")
			w.WriteHeader(201)
			b, _ := json.Marshal(bad)
			io.WriteString(w, `{"ok":true,"data":{"device_code":"`+strings.Repeat("ab", 24)+`","user_code":`+string(b)+`,"verification_url":"http://`+r.Host+`/wp-admin/x","expires_in":600,"interval":3}}`)
		}))
		_, err := DeviceLogin(context.Background(), DeviceOptions{Site: srv.URL, CLIVersion: "1.3.0", Wait: (&clock{}).wait}, strings.NewReader(""), io.Discard)
		srv.Close()
		if err == nil || !strings.Contains(err.Error(), "unexpected format") {
			t.Errorf("%q: %v", bad, err)
		}
	}
}

func TestCleanText(t *testing.T) {
	for in, want := range map[string]string{
		"  hello\t\n  world  ": "hello world",
		"\x1b[31mred\x1b[0m":   "[31mred[0m",
		"a\u202eb\u200bc":      "abc",
		"":                     "",
	} {
		if got := cleanText(in, 80); got != want {
			t.Errorf("cleanText(%q) = %q, want %q", in, got, want)
		}
	}
	if got := cleanText(strings.Repeat("é", 100), 10); len([]rune(got)) != 11 || !strings.HasSuffix(got, "…") {
		t.Errorf("not shortened: %q", got)
	}
}

func TestALinkWithInvisibleCharactersIsRefused(t *testing.T) {
	for _, odd := range []string{"\u202e", "\u200b", "\u0085", "\u2066"} {
		s := newSite(t, "ok")
		s.link = s.srv.URL + "/wp-admin/admin.php?page=wpicker-approve&user_code=K7QF-92XM" + odd
		_, err := DeviceLogin(context.Background(), opts(s, &clock{}), strings.NewReader(""), io.Discard)
		if err == nil {
			t.Errorf("%q was accepted", odd)
		}
		if len(s.polls) != 0 {
			t.Errorf("%q: it polled after being given a link it did not trust", odd)
		}
	}
}

func TestSlowDownAndBusyCannotStretchTheWaitWithoutLimit(t *testing.T) {
	script := make([]string, 0, 30)
	for i := 0; i < 12; i++ {
		script = append(script, "busy", "slow")
	}
	script = append(script, "ok")
	s := newSite(t, script...)
	ck := &clock{}
	if _, err := DeviceLogin(context.Background(), opts(s, ck), strings.NewReader(""), io.Discard); err != nil {
		t.Fatal(err)
	}
	for _, w := range ck.waits {
		if w > maxInterval {
			t.Fatalf("waited %v, more than the %v ceiling", w, maxInterval)
		}
	}
}

func TestNoMessageSendsAnyoneBackToTheRemovedPasswordPairing(t *testing.T) {
	src, err := os.ReadFile("device.go")
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(string(src), "--password") {
		t.Fatal("device.go still suggests --password, which no longer exists")
	}
	old, _ := os.ReadFile("auth.go")
	for _, gone := range []string{"readPassword", "Challenge", "Register(", "Prefill", "func Login"} {
		if strings.Contains(string(old), gone) {
			t.Errorf("auth.go still holds %q from the password + PIN pairing", gone)
		}
	}
}
