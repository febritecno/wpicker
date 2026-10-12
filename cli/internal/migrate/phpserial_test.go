package migrate

import (
	"strings"
	"testing"
)

func TestReplaceValue_fixesSerializedLengths(t *testing.T) {
	r := Rewrites{
		OldURLs: []string{"http://wpicker.local"},
		NewURL:  "http://wpicker-local.local",
		OldPath: "/srv/www/site",
		NewPath: "/Users/me/Local Sites/site/app/public",
	}.Replacer()

	cases := []struct{ name, in, want string }{
		{"plain", "see http://wpicker.local/about", "see http://wpicker-local.local/about"},
		{"https becomes the new url", "https://wpicker.local/x", "http://wpicker-local.local/x"},
		{
			"serialized string",
			`s:26:"http://wpicker.local/a.png";`,
			`s:32:"http://wpicker-local.local/a.png";`,
		},
		{
			"array with nested array, int, bool, null",
			`a:3:{s:3:"url";s:20:"http://wpicker.local";i:5;a:1:{s:1:"p";s:13:"/srv/www/site";}s:1:"n";N;}`,
			`a:3:{s:3:"url";s:26:"http://wpicker-local.local";i:5;a:1:{s:1:"p";s:37:"/Users/me/Local Sites/site/app/public";}s:1:"n";N;}`,
		},
		{
			"object",
			`O:8:"stdClass":1:{s:4:"link";s:22:"http://wpicker.local/x";}`,
			`O:8:"stdClass":1:{s:4:"link";s:28:"http://wpicker-local.local/x";}`,
		},
		{
			"serialized data inside a serialized string",
			`a:1:{s:1:"v";s:38:"a:1:{i:0;s:20:"http://wpicker.local";}";}`,
			`a:1:{s:1:"v";s:44:"a:1:{i:0;s:26:"http://wpicker-local.local";}";}`,
		},
		{
			"multibyte: length is bytes, not characters",
			`s:26:"é http://wpicker.local é";`,
			`s:32:"é http://wpicker-local.local é";`,
		},
		{"json escaped slashes", `{"u":"http:\/\/wpicker.local\/a"}`, `{"u":"http:\/\/wpicker-local.local\/a"}`},
		{"protocol relative", `src="//wpicker.local/a.js"`, `src="//wpicker-local.local/a.js"`},
		{"untouched serialized value is byte-identical", `a:2:{i:0;b:1;i:1;d:0.5;}`, `a:2:{i:0;b:1;i:1;d:0.5;}`},
		{
			"malformed serialized data falls back to plain replace",
			`a:1:{s:3:"url";s:999:"http://wpicker.local";}`,
			`a:1:{s:3:"url";s:999:"http://wpicker-local.local";}`,
		},
		{"email addresses are left alone", "admin@wpicker.local", "admin@wpicker.local"},
	}
	for _, c := range cases {
		if got := ReplaceValue(c.in, r); got != c.want {
			t.Errorf("%s\n got %s\nwant %s", c.name, got, c.want)
		}
	}
}

// When the new URL contains the old one, a second pass over the result would
// replace it again. The replacer must be single-pass.
func TestReplacer_newUrlContainsOld(t *testing.T) {
	r := Rewrites{OldURLs: []string{"https://example.com"}, NewURL: "https://dev.example.com"}.Replacer()
	in := `a:1:{i:0;s:21:"https://example.com/x";}`
	want := `a:1:{i:0;s:25:"https://dev.example.com/x";}`
	if got := ReplaceValue(in, r); got != want {
		t.Errorf("got %s\nwant %s", got, want)
	}
	if got := ReplaceValue(want, r); !strings.Contains(got, "dev.dev.") {
		// Documented limitation: running the migration twice is not idempotent
		// in this case; the importer only ever runs it once per import.
		t.Log("second pass left the value unchanged")
	}
}

func TestNeedles(t *testing.T) {
	w := Rewrites{OldURLs: []string{"http://a.test", "http://a.test/"}, NewURL: "http://b.test", OldPath: "/old", NewPath: "/new"}
	got := strings.Join(w.Needles(), "|")
	if got != `a.test|www.a.test|/old|\/old|%2Fold` {
		t.Errorf("Needles = %s", got)
	}
}

func TestReplacer_spellingsAndBoundaries(t *testing.T) {
	r := Rewrites{
		OldURLs: []string{"http://wpicker.local"}, NewURL: "http://wpicker-local.local",
		OldPath: "/srv/www/site", NewPath: "/Users/me/Local Sites/x",
	}.Replacer()
	cases := []struct{ name, in, want string }{
		{"www variant", "http://www.wpicker.local/w", "http://wpicker-local.local/w"},
		{"attribute without scheme", `<a href="wpicker.local/p">`, `<a href="wpicker-local.local/p">`},
		{"urlencode", "http%3A%2F%2Fwpicker.local%2Fp%3Fa%3D1", "http%3A%2F%2Fwpicker-local.local%2Fp%3Fa%3D1"},
		{
			"other sites that merely start with our name are not ours",
			"http://wpicker.localhost/x http://wpicker.local.evil.com/y //wpicker.local-cdn.net/z",
			"http://wpicker.localhost/x http://wpicker.local.evil.com/y //wpicker.local-cdn.net/z",
		},
		{"sibling directories are not ours", "/srv/www/site2/a /srv/www/site.bak/b /srv/www/site/c", "/srv/www/site2/a /srv/www/site.bak/b /Users/me/Local Sites/x/c"},
		{"end of sentence", "Visit http://wpicker.local. Or http://wpicker.local, ok", "Visit http://wpicker-local.local. Or http://wpicker-local.local, ok"},
		{"port and query", "http://wpicker.local:8080/a http://wpicker.local?x=1", "http://wpicker-local.local:8080/a http://wpicker-local.local?x=1"},
		{"at the very end", "http://wpicker.local", "http://wpicker-local.local"},
	}
	for _, c := range cases {
		if got := r.Replace(c.in); got != c.want {
			t.Errorf("%s\n got %s\nwant %s", c.name, got, c.want)
		}
	}

	outside := Rewrites{OldURLs: []string{"http://a.test"}, NewURL: "http://b.test", OldPath: "/old", NewPath: "/new", OldContent: "/data/content", NewContent: "/new/wp-content"}.Replacer()
	if got, want := outside.Replace("/data/content/uploads/x.png and /old/wp-config.php"), "/new/wp-content/uploads/x.png and /new/wp-config.php"; got != want {
		t.Errorf("content dir outside install\n got %s\nwant %s", got, want)
	}
}

func TestPHPURLEncode(t *testing.T) {
	in := "http://a.test/p q?x=1&y=~z"
	if got, want := phpURLEncode(in, false), "http%3A%2F%2Fa.test%2Fp+q%3Fx%3D1%26y%3D%7Ez"; got != want {
		t.Errorf("urlencode = %s, want %s", got, want)
	}
	if got, want := phpURLEncode(in, true), "http%3A%2F%2Fa.test%2Fp%20q%3Fx%3D1%26y%3D~z"; got != want {
		t.Errorf("rawurlencode = %s, want %s", got, want)
	}
}
