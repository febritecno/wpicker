// Package sync implements the local file operations for pull/push:
//
//   - walking a directory tree,
//   - computing sha256 of files (to diff against the server's manifest),
//   - writing files atomically (temp + rename),
//   - recording a last-pull hash manifest to detect drift before pushing.
package sync

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
)

// Manifest is the local record of what we last pulled, keyed by relative path.
// Stored at .wpicker/last-pull.json in the working directory.
type Manifest struct {
	Stylesheet string                  `json:"stylesheet"`
	Files      map[string]ManifestFile `json:"files"`
}

// ManifestFile captures the local hash + size of one pulled file.
type ManifestFile struct {
	SHA256 string `json:"sha256"`
	Size   int64  `json:"size"`
}

// LocalDirName and LocalManifestName are the working-dir artifacts.
const (
	LocalDirName      = ".wpicker"
	LocalManifestName = "last-pull.json"
	LastErrorName     = "last-error.json"
)

// LocalDir returns the .wpicker path inside root, creating it if missing.
func LocalDir(root string) (string, error) {
	d := filepath.Join(root, LocalDirName)
	if err := os.MkdirAll(d, 0o755); err != nil {
		return "", err
	}
	return d, nil
}

// ManifestPath returns the absolute path to the local pull manifest.
func ManifestPath(root string) (string, error) {
	d, err := LocalDir(root)
	if err != nil {
		return "", err
	}
	return filepath.Join(d, LocalManifestName), nil
}

// LoadManifest reads the local pull manifest, or returns an empty one.
func LoadManifest(root string) (*Manifest, error) {
	p, err := ManifestPath(root)
	if err != nil {
		return nil, err
	}
	data, err := os.ReadFile(p)
	if err != nil {
		if errors.Is(err, os.ErrNotExist) {
			return &Manifest{Files: map[string]ManifestFile{}}, nil
		}
		return nil, err
	}
	var m Manifest
	if err := json.Unmarshal(data, &m); err != nil {
		return nil, err
	}
	if m.Files == nil {
		m.Files = map[string]ManifestFile{}
	}
	return &m, nil
}

// SaveManifest writes the local pull manifest.
func SaveManifest(root string, m *Manifest) error {
	p, err := ManifestPath(root)
	if err != nil {
		return err
	}
	data, err := json.MarshalIndent(m, "", "  ")
	if err != nil {
		return err
	}
	return writeFileAtomic(p, data, 0o644)
}

// WriteError saves the last structured error from a failed push, so an AI
// agent can read it after a non-zero exit and decide how to self-heal.
func WriteError(root string, errBody map[string]any) error {
	d, err := LocalDir(root)
	if err != nil {
		return err
	}
	p := filepath.Join(d, LastErrorName)
	data, _ := json.MarshalIndent(errBody, "", "  ")
	return writeFileAtomic(p, data, 0o644)
}

// ClearError removes a previously-recorded error (e.g. after a successful push).
func ClearError(root string) {
	d, err := LocalDir(root)
	if err != nil {
		return
	}
	_ = os.Remove(filepath.Join(d, LastErrorName))
}

// WalkLocal returns the relative paths of all files under root, excluding
// .wpicker/, vendor/, node_modules/, and dotfiles.
func WalkLocal(root string) ([]string, error) {
	var out []string
	err := filepath.WalkDir(root, func(path string, d os.DirEntry, walkErr error) error {
		if walkErr != nil {
			return walkErr
		}
		rel, _ := filepath.Rel(root, path)
		rel = filepath.ToSlash(rel)
		base := d.Name()

		// Skip our own working dir and common noise.
		if d.IsDir() {
			if rel == "." {
				return nil
			}
			if isIgnoredDir(base) {
				return filepath.SkipDir
			}
			return nil
		}
		// Skip dotfiles and ignored files.
		if strings.HasPrefix(base, ".") {
			return nil
		}
		if isIgnoredFile(base) {
			return nil
		}
		out = append(out, rel)
		return nil
	})
	return out, err
}

// HashFile computes the sha256 hex digest of a file.
func HashFile(abs string) (string, error) {
	f, err := os.Open(abs)
	if err != nil {
		return "", err
	}
	defer f.Close()
	h := sha256.New()
	if _, err := io.Copy(h, f); err != nil {
		return "", err
	}
	return hex.EncodeToString(h.Sum(nil)), nil
}

// HashString computes the sha256 hex digest of a string.
func HashString(s string) string {
	h := sha256.Sum256([]byte(s))
	return hex.EncodeToString(h[:])
}

// ReadFile reads a file relative to root.
func ReadFile(root, rel string) (string, error) {
	data, err := os.ReadFile(filepath.Join(root, rel))
	if err != nil {
		return "", err
	}
	return string(data), nil
}

// WriteFileAtomic writes contents to root/rel via a temp file + rename.
// Creates parent directories as needed.
func WriteFileAtomic(root, rel, contents string) error {
	abs := filepath.Join(root, filepath.FromSlash(rel))
	dir := filepath.Dir(abs)
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return err
	}
	return writeFileAtomic(abs, []byte(contents), 0o644)
}

// writeFileAtomic is the low-level temp+rename writer.
func writeFileAtomic(abs string, data []byte, mode os.FileMode) error {
	tmp := abs + ".wpicker-tmp"
	if err := os.WriteFile(tmp, data, mode); err != nil {
		return err
	}
	if err := os.Rename(tmp, abs); err != nil {
		_ = os.Remove(tmp)
		return err
	}
	return nil
}

// DiffChanges classifies local files against the last-pull manifest:
//
//   - added:   in local tree, not in manifest (or remote),
//   - modified: hash differs from manifest,
//   - removed: in manifest, not in local tree.
//
// remote is the server's current file map (path → sha256). It is used to
// detect drift (local diverged from BOTH manifest and remote).
type Changes struct {
	Added    []string
	Modified []string
	Removed  []string
	Drifted  []string // changed locally AND differently on the server
}

// ComputeChanges walks the local tree and compares against manifest + remote.
func ComputeChanges(root string, manifest *Manifest, remote map[string]string) (*Changes, error) {
	localFiles, err := WalkLocal(root)
	if err != nil {
		return nil, err
	}

	c := &Changes{}
	seen := map[string]bool{}

	for _, rel := range localFiles {
		seen[rel] = true
		abs := filepath.Join(root, filepath.FromSlash(rel))
		hash, err := HashFile(abs)
		if err != nil {
			return nil, fmt.Errorf("hash %s: %w", rel, err)
		}
		prev, hadPrev := manifest.Files[rel]
		if !hadPrev {
			c.Added = append(c.Added, rel)
			continue
		}
		if hash != prev.SHA256 {
			c.Modified = append(c.Modified, rel)
			// Drift: changed locally AND the server already has something different.
			if rh, ok := remote[rel]; ok && rh != prev.SHA256 && rh != hash {
				c.Drifted = append(c.Drifted, rel)
			}
		}
	}

	for path := range manifest.Files {
		if !seen[path] {
			c.Removed = append(c.Removed, path)
		}
	}

	return c, nil
}

func isIgnoredDir(base string) bool {
	switch base {
	case ".wpicker", ".git", "vendor", "node_modules", ".sass-cache", "dist", "build":
		return true
	}
	return false
}

func isIgnoredFile(base string) bool {
	switch strings.ToLower(base) {
	case ".ds_store", "thumbs.db":
		return true
	}
	return false
}
