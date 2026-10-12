package sync

import (
	"strings"
	"testing"
)

func paths(list []Change) string {
	var out []string
	for _, c := range list {
		out = append(out, string(c.Local)+"/"+string(c.Remote)+":"+c.Path)
	}
	return strings.Join(out, " ")
}

func TestComputeStatus_threeWay(t *testing.T) {
	tmp := t.TempDir()
	write := func(rel, s string) {
		t.Helper()
		if err := WriteFileAtomic(tmp, rel, []byte(s)); err != nil {
			t.Fatal(err)
		}
	}
	h := HashString

	write("same.css", "v1")
	write("local-edit.css", "mine")
	write("site-edit.css", "v1")
	write("both-edit.css", "mine")
	write("both-same.css", "v2")
	write("local-new.css", "new")
	write("site-gone.css", "v1")
	write("edited-but-gone.css", "mine")
	// local-gone.css and gone-but-edited.css are deleted locally.

	manifest := &Manifest{Files: map[string]ManifestFile{}}
	for _, rel := range []string{
		"same.css", "local-edit.css", "site-edit.css", "both-edit.css", "both-same.css",
		"site-gone.css", "edited-but-gone.css", "local-gone.css", "gone-but-edited.css",
		".wpicker-old", "vendor/lib.php",
	} {
		manifest.Files[rel] = ManifestFile{SHA256: h("v1")}
	}
	remote := map[string]string{
		"same.css":            h("v1"),
		"local-edit.css":      h("v1"),
		"site-edit.css":       h("theirs"),
		"both-edit.css":       h("theirs"),
		"both-same.css":       h("v2"),
		"site-new.css":        h("new"),
		"local-gone.css":      h("v1"),
		"gone-but-edited.css": h("theirs"),
	}

	st, err := ComputeStatus(tmp, manifest, remote)
	if err != nil {
		t.Fatal(err)
	}

	if got, want := paths(st.Outgoing), "modified/:local-edit.css deleted/:local-gone.css added/:local-new.css"; got != want {
		t.Errorf("Outgoing\n got %s\nwant %s", got, want)
	}
	if got, want := paths(st.Incoming), "/modified:site-edit.css /deleted:site-gone.css /added:site-new.css"; got != want {
		t.Errorf("Incoming\n got %s\nwant %s", got, want)
	}
	if got, want := paths(st.Conflicts), "modified/modified:both-edit.css modified/deleted:edited-but-gone.css deleted/modified:gone-but-edited.css"; got != want {
		t.Errorf("Conflicts\n got %s\nwant %s", got, want)
	}

	// The same edit on both sides is not a change; Settle teaches the manifest.
	st.Settle(tmp, manifest, remote, map[string]int64{"both-same.css": 2})
	if manifest.Files["both-same.css"].SHA256 != h("v2") {
		t.Errorf("both-same.css not settled: %+v", manifest.Files["both-same.css"])
	}
}

// Ignored paths that ended up in an old manifest must never be reported as
// "deleted locally" — push would then delete them on the site.
func TestComputeStatus_ignoredPathsNeverScheduled(t *testing.T) {
	tmp := t.TempDir()
	manifest := &Manifest{Files: map[string]ManifestFile{
		".htaccess":        {SHA256: HashString("x")},
		"vendor/lib.php":   {SHA256: HashString("x")},
		"build/app.min.js": {SHA256: HashString("x")},
		"inc/.DS_Store":    {SHA256: HashString("x")},
	}}
	remote := map[string]string{
		".htaccess":        HashString("x"),
		"vendor/lib.php":   HashString("x"),
		"build/app.min.js": HashString("x"),
	}
	st, err := ComputeStatus(tmp, manifest, remote)
	if err != nil {
		t.Fatal(err)
	}
	if !st.Clean() {
		t.Errorf("ignored paths reported: out=%v in=%v conflicts=%v", st.Outgoing, st.Incoming, st.Conflicts)
	}
}

// A push held for review must not look like a conflict once it is approved
// and the user keeps editing the same file.
func TestComputeStatus_approvedReviewPushMovesBase(t *testing.T) {
	tmp := t.TempDir()
	h := HashString
	if err := WriteFileAtomic(tmp, "style.css", []byte("v2 plus a later edit")); err != nil {
		t.Fatal(err)
	}
	manifest := &Manifest{
		Files: map[string]ManifestFile{"style.css": {SHA256: h("v1")}, "old.php": {SHA256: h("v1")}},
		Pending: map[string]ManifestFile{
			"style.css": {SHA256: h("v2")}, // submitted for review
			"old.php":   {},                // submitted deletion
		},
	}

	// Not approved yet: the site still has v1, so the file is just outgoing.
	st, err := ComputeStatus(tmp, manifest, map[string]string{"style.css": h("v1"), "old.php": h("v1")})
	if err != nil {
		t.Fatal(err)
	}
	if got := paths(st.Outgoing); got != "deleted/:old.php modified/:style.css" || len(st.Conflicts) != 0 {
		t.Errorf("before approval: outgoing=%s conflicts=%v", got, st.Conflicts)
	}
	if len(manifest.Pending) != 2 {
		t.Errorf("pending must be kept while waiting: %v", manifest.Pending)
	}

	// Approved: the site now has v2 and old.php is gone.
	st, err = ComputeStatus(tmp, manifest, map[string]string{"style.css": h("v2")})
	if err != nil {
		t.Fatal(err)
	}
	if got := paths(st.Outgoing); got != "modified/:style.css" || len(st.Conflicts) != 0 {
		t.Errorf("after approval: outgoing=%s conflicts=%v", got, st.Conflicts)
	}
	if !st.Dirty() || len(manifest.Pending) != 0 || manifest.Files["style.css"].SHA256 != h("v2") {
		t.Errorf("base not moved: dirty=%v manifest=%+v", st.Dirty(), manifest)
	}
	if _, tracked := manifest.Files["old.php"]; tracked {
		t.Errorf("approved deletion still tracked")
	}
}
