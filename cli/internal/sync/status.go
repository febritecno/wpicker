package sync

import (
	"fmt"
	"path/filepath"
	"sort"
	"strings"
)

// Kind is what happened to a file on one side since the last pull.
type Kind string

const (
	Added    Kind = "added"
	Modified Kind = "modified"
	Deleted  Kind = "deleted"
)

// Letter is the one-character status used in listings (git-style).
func (k Kind) Letter() string {
	switch k {
	case Added:
		return "A"
	case Deleted:
		return "D"
	}
	return "M"
}

// Change is one file that differs between local, site, and the last pull.
type Change struct {
	Path string `json:"path"`
	// Local is what happened locally since the last pull ("" = nothing).
	Local Kind `json:"local,omitempty"`
	// Remote is what happened on the site since the last pull ("" = nothing).
	Remote Kind `json:"remote,omitempty"`
}

// Conflict reports whether both sides changed the file, differently.
func (c Change) Conflict() bool { return c.Local != "" && c.Remote != "" }

// Status is a three-way comparison of the working directory, the site, and
// the last-pull manifest (the common base), in the manner of `git status`.
type Status struct {
	// Outgoing changed locally only: `push` sends them.
	Outgoing []Change `json:"outgoing"`
	// Incoming changed on the site only: `pull` can apply them safely.
	Incoming []Change `json:"incoming"`
	// Conflicts changed on both sides. Neither push nor a plain pull touches
	// them; the user has to pick a side.
	Conflicts []Change `json:"conflicts"`

	// settled are paths where local and site already agree but the manifest
	// does not know it yet (same edit on both sides, or deleted on both).
	settled []string
	// promoted is set when a push that was held for review turned out to be
	// approved, which moved the manifest's base forward.
	promoted bool
}

// Dirty reports whether computing the status taught the manifest something
// that should be saved (call Settle first).
func (s *Status) Dirty() bool { return s.promoted || len(s.settled) > 0 }

// Clean reports whether local and site are identical.
func (s *Status) Clean() bool {
	return len(s.Outgoing) == 0 && len(s.Incoming) == 0 && len(s.Conflicts) == 0
}

// IsIgnored reports whether a relative path is outside what WPicker syncs:
// dotfiles, OS junk, and tooling directories (see isIgnoredDir). The same rule
// is applied to local files, site files and the manifest, so a file that is
// never uploaded is never downloaded or scheduled for deletion either.
func IsIgnored(rel string) bool {
	parts := strings.Split(filepath.ToSlash(rel), "/")
	for _, dir := range parts[:len(parts)-1] {
		if isIgnoredDir(dir) {
			return true
		}
	}
	base := parts[len(parts)-1]
	return strings.HasPrefix(base, ".") || isIgnoredFile(base)
}

// ComputeStatus compares the local tree, the site's file map (path → sha256)
// and the last-pull manifest.
func ComputeStatus(root string, manifest *Manifest, remote map[string]string) (*Status, error) {
	localFiles, err := WalkLocal(root)
	if err != nil {
		return nil, err
	}
	local := make(map[string]string, len(localFiles))
	for _, rel := range localFiles {
		hash, err := HashFile(filepath.Join(root, filepath.FromSlash(rel)))
		if err != nil {
			return nil, fmt.Errorf("hash %s: %w", rel, err)
		}
		local[rel] = hash
	}

	paths := map[string]bool{}
	for rel := range local {
		paths[rel] = true
	}
	for rel := range remote {
		paths[rel] = true
	}
	for rel := range manifest.Files {
		paths[rel] = true
	}

	st := &Status{promoted: manifest.promotePending(root, remote)}
	for rel := range paths {
		if IsIgnored(rel) {
			continue
		}
		l, hasL := local[rel]
		r, hasR := remote[rel]
		base, hasB := manifest.Files[rel]

		if hasL == hasR && l == r {
			// Both sides agree (same content, or gone from both).
			if !hasB || base.SHA256 != l || !hasL {
				st.settled = append(st.settled, rel)
			} else if _, ok := LoadBase(root, rel, manifest); !ok {
				// A working directory from before ancestors were kept, or
				// one whose copy went missing: the file is in sync, so the
				// local file IS the ancestor.
				baseFromLocal(root, rel)
			}
			continue
		}

		ch := Change{
			Path:   rel,
			Local:  sideKind(hasL, l, hasB, base.SHA256),
			Remote: sideKind(hasR, r, hasB, base.SHA256),
		}
		switch {
		case ch.Conflict():
			st.Conflicts = append(st.Conflicts, ch)
		case ch.Local != "":
			st.Outgoing = append(st.Outgoing, ch)
		default:
			st.Incoming = append(st.Incoming, ch)
		}
	}

	byPath := func(list []Change) {
		sort.Slice(list, func(i, j int) bool { return list[i].Path < list[j].Path })
	}
	byPath(st.Outgoing)
	byPath(st.Incoming)
	byPath(st.Conflicts)
	sort.Strings(st.settled)
	return st, nil
}

// sideKind classifies one side (local or site) against the base.
func sideKind(has bool, hash string, hasBase bool, baseHash string) Kind {
	switch {
	case has && !hasBase:
		return Added
	case !has && hasBase:
		return Deleted
	case has && hash != baseHash:
		return Modified
	}
	return ""
}

// Settle records in the manifest every path where local and site already
// agree, so they stop showing up as changes, and keeps the agreed content as
// the ancestor for later merges. remoteSizes supplies file sizes.
func (s *Status) Settle(root string, manifest *Manifest, remote map[string]string, remoteSizes map[string]int64) {
	for _, rel := range s.settled {
		hash, ok := remote[rel]
		if !ok {
			delete(manifest.Files, rel)
			RemoveBase(root, rel)
			continue
		}
		manifest.Files[rel] = ManifestFile{SHA256: hash, Size: remoteSizes[rel]}
		baseFromLocal(root, rel)
	}
}
