package sync

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestWriteAndReadFileAtomic(t *testing.T) {
	tmp := t.TempDir()
	if err := WriteFileAtomic(tmp, "sub/dir/hello.txt", []byte("world")); err != nil {
		t.Fatalf("WriteFileAtomic: %v", err)
	}
	got, err := ReadFile(tmp, "sub/dir/hello.txt")
	if err != nil {
		t.Fatalf("ReadFile: %v", err)
	}
	if string(got) != "world" {
		t.Errorf("ReadFile = %q, want %q", got, "world")
	}
}

func TestHashString(t *testing.T) {
	// sha256 of empty string.
	if got := HashString(""); got != "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855" {
		t.Errorf("HashString(\"\") = %q", got)
	}
	if got := HashString("hello"); got != "2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824" {
		t.Errorf("HashString(\"hello\") = %q", got)
	}
}

func TestHashFile(t *testing.T) {
	tmp := t.TempDir()
	p := filepath.Join(tmp, "test.bin")
	if err := os.WriteFile(p, []byte("abc"), 0o644); err != nil {
		t.Fatal(err)
	}
	got, err := HashFile(p)
	if err != nil {
		t.Fatal(err)
	}
	expected := HashString("abc")
	if got != expected {
		t.Errorf("HashFile = %q, want %q", got, expected)
	}
}

func TestManifestLoadSaveRoundTrip(t *testing.T) {
	tmp := t.TempDir()
	m := &Manifest{
		Stylesheet: "twentytwentyfour-child",
		Files: map[string]ManifestFile{
			"style.css":    {SHA256: "aaa", Size: 100},
			"functions.php": {SHA256: "bbb", Size: 200},
		},
	}

	if err := SaveManifest(tmp, m); err != nil {
		t.Fatalf("SaveManifest: %v", err)
	}

	loaded, err := LoadManifest(tmp)
	if err != nil {
		t.Fatalf("LoadManifest: %v", err)
	}
	if loaded.Stylesheet != m.Stylesheet {
		t.Errorf("Stylesheet = %q, want %q", loaded.Stylesheet, m.Stylesheet)
	}
	if len(loaded.Files) != len(m.Files) {
		t.Fatalf("Files count = %d, want %d", len(loaded.Files), len(m.Files))
	}
	for k, v := range m.Files {
		got := loaded.Files[k]
		if got.SHA256 != v.SHA256 || got.Size != v.Size {
			t.Errorf("Files[%q] = %+v, want %+v", k, got, v)
		}
	}
}

func TestLoadManifestEmpty(t *testing.T) {
	tmp := t.TempDir()
	m, err := LoadManifest(tmp)
	if err != nil {
		t.Fatalf("LoadManifest empty: %v", err)
	}
	if m.Stylesheet != "" || len(m.Files) != 0 {
		t.Errorf("empty manifest should be zero, got %+v", m)
	}
}

func TestComputeChanges(t *testing.T) {
	tmp := t.TempDir()
	// Create a local file tree.
	os.WriteFile(filepath.Join(tmp, "style.css"), []byte("body{}"), 0o644)
	os.WriteFile(filepath.Join(tmp, "functions.php"), []byte("<?php // test"), 0o644)
	os.MkdirAll(filepath.Join(tmp, "inc"), 0o755)
	os.WriteFile(filepath.Join(tmp, "inc/helpers.php"), []byte("<?php // helper"), 0o644)

	manifest := &Manifest{
		Stylesheet: "test-child",
		Files: map[string]ManifestFile{
			"style.css":    {SHA256: HashString("body{}"), Size: 6},
			"functions.php": {SHA256: HashString("old"), Size: 3}, // unchanged hash below
		},
	}

	remote := map[string]string{
		"style.css":    HashString("body{}"),
		"functions.php": HashString("old"),
	}

	c, err := ComputeChanges(tmp, manifest, remote)
	if err != nil {
		t.Fatal(err)
	}

	// style.css: hash matches manifest → unchanged (not in Modified).
	for _, p := range c.Modified {
		if p == "style.css" {
			t.Error("style.css should not be Modified (hash matches)")
		}
	}

	// functions.php: local hash != manifest hash → Modified.
	found := false
	for _, p := range c.Modified {
		if p == "functions.php" {
			found = true
		}
	}
	if !found {
		t.Error("functions.php should be Modified (local differs from manifest)")
	}

	// inc/helpers.php: not in manifest → Added.
	found = false
	for _, p := range c.Added {
		if p == "inc/helpers.php" {
			found = true
		}
	}
	if !found {
		t.Error("inc/helpers.php should be Added (not in manifest)")
	}
}

func TestWriteErrorClearError(t *testing.T) {
	tmp := t.TempDir()
	errBody := map[string]any{"code": "test", "message": "boom"}
	if err := WriteError(tmp, errBody); err != nil {
		t.Fatal(err)
	}
	data, err := os.ReadFile(filepath.Join(tmp, LocalDirName, LastErrorName))
	if err != nil {
		t.Fatalf("read error file: %v", err)
	}
	if len(data) == 0 {
		t.Error("error file should not be empty")
	}

	ClearError(tmp)
	if _, err := os.Stat(filepath.Join(tmp, LocalDirName, LastErrorName)); !os.IsNotExist(err) {
		t.Error("error file should be deleted after ClearError")
	}
}

func TestComputeChanges_DeletedLocally(t *testing.T) {
	tmp := t.TempDir()
	if err := WriteFileAtomic(tmp, "keep.php", []byte("keep")); err != nil {
		t.Fatal(err)
	}
	manifest := &Manifest{Files: map[string]ManifestFile{
		"keep.php":    {SHA256: HashString("keep")},
		"gone.php":    {SHA256: HashString("gone")},
		"drifted.php": {SHA256: HashString("old")},
	}}
	remote := map[string]string{
		"keep.php":    HashString("keep"),
		"gone.php":    HashString("gone"),
		"drifted.php": HashString("edited on server"),
	}

	c, err := ComputeChanges(tmp, manifest, remote)
	if err != nil {
		t.Fatal(err)
	}
	if got := strings.Join(c.Removed, ","); got != "drifted.php,gone.php" {
		t.Errorf("Removed = %q", got)
	}
	// A file deleted locally whose server copy changed since the pull must
	// block the push rather than silently destroy the server-side edit.
	if got := strings.Join(c.Drifted, ","); got != "drifted.php" {
		t.Errorf("Drifted = %q", got)
	}
}

func TestRemoveFile_prunesEmptyParents(t *testing.T) {
	tmp := t.TempDir()
	for _, rel := range []string{"a/b/one.php", "a/two.php"} {
		if err := WriteFileAtomic(tmp, rel, []byte("x")); err != nil {
			t.Fatal(err)
		}
	}
	if err := RemoveFile(tmp, "a/b/one.php"); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(tmp, "a", "b")); !os.IsNotExist(err) {
		t.Errorf("empty dir a/b should be removed")
	}
	if _, err := os.Stat(filepath.Join(tmp, "a", "two.php")); err != nil {
		t.Errorf("sibling file must survive: %v", err)
	}
	if err := RemoveFile(tmp, "missing.php"); err != nil {
		t.Errorf("removing a missing file should be a no-op, got %v", err)
	}
}
