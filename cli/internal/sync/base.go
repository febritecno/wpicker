package sync

import (
	"errors"
	"os"
	"path/filepath"
)

// A three-way merge needs the CONTENT of the common ancestor, not just its
// hash. So next to the manifest, the working directory keeps a copy of every
// file exactly as it was when local and site last agreed:
//
//	.wpicker/base/<path>     the ancestor
//	.wpicker/pending/<path>  what a push held for review will become the
//	                         ancestor once it is approved
//	.wpicker/merge-backup/   the local file as it was before a merge
const (
	baseDirName    = "base"
	pendingDirName = "pending"
	backupDirName  = "merge-backup"
)

func storePath(root, store, rel string) string {
	return filepath.Join(root, LocalDirName, store, filepath.FromSlash(rel))
}

func storeWrite(root, store, rel string, data []byte) error {
	p := storePath(root, store, rel)
	if err := os.MkdirAll(filepath.Dir(p), 0o755); err != nil {
		return err
	}
	return writeFileAtomic(p, data, 0o644)
}

// SaveBase records data as the common ancestor of rel.
func SaveBase(root, rel string, data []byte) error {
	return storeWrite(root, baseDirName, rel, data)
}

// RemoveBase forgets the ancestor of rel (the file is gone on both sides).
func RemoveBase(root, rel string) {
	_ = os.Remove(storePath(root, baseDirName, rel))
}

// LoadBase returns the ancestor of rel, but only if it really is the version
// the manifest says local and site last agreed on. A stale or missing copy
// is reported as absent rather than merged against.
func LoadBase(root, rel string, manifest *Manifest) ([]byte, bool) {
	want, tracked := manifest.Files[rel]
	if !tracked {
		return nil, false
	}
	data, err := os.ReadFile(storePath(root, baseDirName, rel))
	if err != nil || HashBytes(data) != want.SHA256 {
		return nil, false
	}
	return data, true
}

// SavePending records what rel will be once a push held for review is approved.
func SavePending(root, rel string, data []byte) error {
	return storeWrite(root, pendingDirName, rel, data)
}

// promotePendingBase makes the pending copy of rel the ancestor (the held
// push was approved), or drops the ancestor if the push deleted the file.
func promotePendingBase(root, rel string, deleted bool) {
	pending := storePath(root, pendingDirName, rel)
	if deleted {
		RemoveBase(root, rel)
		_ = os.Remove(pending)
		return
	}
	base := storePath(root, baseDirName, rel)
	if err := os.MkdirAll(filepath.Dir(base), 0o755); err == nil {
		_ = os.Rename(pending, base)
	}
}

// BackupBeforeMerge keeps the local file as it was, in case the merge result
// is not what the user wanted. It returns where the copy went.
func BackupBeforeMerge(root, rel string, data []byte) (string, error) {
	p := storePath(root, backupDirName, rel)
	return p, storeWrite(root, backupDirName, rel, data)
}

// baseFromLocal copies the working file to the ancestor store when local and
// site are known to agree on it.
func baseFromLocal(root, rel string) {
	data, err := os.ReadFile(filepath.Join(root, filepath.FromSlash(rel)))
	if err != nil {
		if errors.Is(err, os.ErrNotExist) {
			RemoveBase(root, rel)
		}
		return
	}
	_ = SaveBase(root, rel, data)
}
