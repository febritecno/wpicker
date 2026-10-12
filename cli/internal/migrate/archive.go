package migrate

import (
	"archive/tar"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path"
	"path/filepath"
	"strings"
)

// Names of the fixed entries in a backup archive.
const (
	ManifestName = "wpicker-backup.json"
	DumpName     = "database.sql"
	contentRoot  = "wp-content/"
)

// Manifest describes the site a backup was taken from.
type Manifest struct {
	Format        int             `json:"format"`
	CreatedAt     string          `json:"created_gmt"`
	SiteURL       string          `json:"site_url"`
	HomeURL       string          `json:"home_url"`
	ABSPath       string          `json:"abspath"`
	ContentDir    string          `json:"content_dir"`
	TablePrefix   string          `json:"table_prefix"`
	WPVersion     string          `json:"wp_version"`
	PHPVersion    string          `json:"php_version"`
	Stylesheet    string          `json:"stylesheet"`
	Template      string          `json:"template"`
	ActivePlugins []string        `json:"active_plugins"`
	Include       map[string]bool `json:"include"`
}

// ReadManifest returns the manifest, which is the first entry of the archive.
func ReadManifest(archive string) (*Manifest, error) {
	f, err := os.Open(archive)
	if err != nil {
		return nil, err
	}
	defer f.Close()

	tr := tar.NewReader(f)
	hdr, err := tr.Next()
	if err != nil {
		return nil, fmt.Errorf("not a WPicker backup: %w", err)
	}
	if hdr.Name != ManifestName {
		return nil, errors.New("not a WPicker backup: the archive does not start with " + ManifestName)
	}
	var m Manifest
	if err := json.NewDecoder(io.LimitReader(tr, 1<<20)).Decode(&m); err != nil {
		return nil, fmt.Errorf("read backup manifest: %w", err)
	}
	if m.Format != 1 {
		return nil, fmt.Errorf("backup format %d is not supported by this version of wpicker", m.Format)
	}
	return &m, nil
}

// ContentParts are the wp-content sub-directories each backup part fills.
var ContentParts = map[string][]string{
	"plugins": {"plugins", "mu-plugins"},
	"themes":  {"themes"},
	"uploads": {"uploads"},
}

// Extracted reports what Extract wrote.
type Extracted struct {
	Files    int
	Bytes    int64
	DumpPath string // empty if the archive has no database dump
}

// Extract unpacks a backup: wp-content/* goes under wpRoot, and the database
// dump is written to dumpPath. Only those entries are accepted, and every
// path is checked to stay inside wpRoot — an archive is input from the
// network and must not be able to write elsewhere.
func Extract(archive, wpRoot, dumpPath string, progress func(files int, bytes int64)) (*Extracted, error) {
	f, err := os.Open(archive)
	if err != nil {
		return nil, err
	}
	defer f.Close()

	root, err := filepath.Abs(wpRoot)
	if err != nil {
		return nil, err
	}
	out := &Extracted{}
	tr := tar.NewReader(f)
	for {
		hdr, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return nil, fmt.Errorf("read archive: %w", err)
		}
		if hdr.Typeflag != tar.TypeReg {
			continue // only regular files are ever written by the plugin
		}

		var target string
		switch {
		case hdr.Name == ManifestName:
			continue
		case hdr.Name == DumpName:
			target = dumpPath
			out.DumpPath = dumpPath
		case strings.HasPrefix(hdr.Name, contentRoot):
			clean := path.Clean(hdr.Name)
			if clean != hdr.Name || strings.Contains(clean, "../") || !strings.HasPrefix(clean, contentRoot) {
				return nil, fmt.Errorf("archive entry with an unsafe path: %q", hdr.Name)
			}
			target = filepath.Join(root, filepath.FromSlash(clean))
			if !strings.HasPrefix(target, root+string(filepath.Separator)) {
				return nil, fmt.Errorf("archive entry escapes the site directory: %q", hdr.Name)
			}
		default:
			continue // unknown top-level entry: ignore rather than place it somewhere
		}

		if err := os.MkdirAll(filepath.Dir(target), 0o755); err != nil {
			return nil, err
		}
		w, err := os.OpenFile(target, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o644)
		if err != nil {
			return nil, err
		}
		n, err := io.Copy(w, tr)
		w.Close()
		if err != nil {
			return nil, fmt.Errorf("extract %s: %w", hdr.Name, err)
		}
		if target != dumpPath {
			// Keep the original modification time: themes and plugins commonly
			// use it as the ?ver= of their assets.
			if !hdr.ModTime.IsZero() {
				_ = os.Chtimes(target, hdr.ModTime, hdr.ModTime)
			}
			out.Files++
			out.Bytes += n
			if progress != nil {
				progress(out.Files, out.Bytes)
			}
		}
	}
	return out, nil
}
