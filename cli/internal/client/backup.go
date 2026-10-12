package client

import (
	"context"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"time"
)

// Backup is one full-site backup on the server, finished or in progress.
type Backup struct {
	ID           string          `json:"id"`
	CreatedAt    string          `json:"created_gmt"`
	Label        string          `json:"label"`
	CreatedBy    string          `json:"created_by"`
	Phase        string          `json:"phase"`
	Done         bool            `json:"done"`
	TablesTotal  int             `json:"tables_total"`
	TablesDone   int             `json:"tables_done"`
	RowsDone     int             `json:"rows_done"`
	FilesTotal   int             `json:"files_total"`
	FilesDone    int             `json:"files_done"`
	FilesSkipped int             `json:"files_skipped"`
	Bytes        int64           `json:"bytes"`
	Include      map[string]bool `json:"include"`
}

// ListBackups returns the server's backups, newest first.
func (c *Client) ListBackups(ctx context.Context) ([]Backup, error) {
	var out struct {
		Backups []Backup `json:"backups"`
	}
	if err := c.Do(ctx, "GET", "/backup", nil, &out); err != nil {
		return nil, err
	}
	return out.Backups, nil
}

// StartBackup begins a new backup. Call StepBackup until Done.
func (c *Client) StartBackup(ctx context.Context, label string, include map[string]bool) (*Backup, error) {
	var out Backup
	body := map[string]any{"label": label, "include": include}
	if err := c.Do(ctx, "POST", "/backup", body, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// StepBackup asks the server to do a few more seconds of work on a backup.
func (c *Client) StepBackup(ctx context.Context, id string) (*Backup, error) {
	var out Backup
	if err := c.Do(ctx, "POST", "/backup/"+id+"/step", nil, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// DeleteBackup removes a backup from the server.
func (c *Client) DeleteBackup(ctx context.Context, id string) error {
	return c.Do(ctx, "DELETE", "/backup/"+id, nil, nil)
}

// DownloadBackup saves a finished backup to dest. If dest already holds the
// first part of the archive (an interrupted download), it continues from
// there. progress, if not nil, is called with the bytes on disk so far.
func (c *Client) DownloadBackup(ctx context.Context, id, dest string, total int64, progress func(done int64)) error {
	var have int64
	if fi, err := os.Stat(dest); err == nil {
		have = fi.Size()
	}
	if total > 0 && have == total {
		return nil
	}
	if total > 0 && have > total {
		have = 0 // not the same archive
	}

	req, err := http.NewRequestWithContext(ctx, "GET", c.Site+"/wp-json/"+Namespace+"/backup/"+id+"/download", nil)
	if err != nil {
		return err
	}
	req.SetBasicAuth(c.Username, c.AppPassword)
	req.Header.Set("User-Agent", c.UserAgent)
	if have > 0 {
		req.Header.Set("Range", fmt.Sprintf("bytes=%d-", have))
	}

	// The archive can be large: no overall timeout, only the context.
	httpClient := &http.Client{Transport: c.HTTP.Transport}
	resp, err := httpClient.Do(req)
	if err != nil {
		return fmt.Errorf("download: %w", err)
	}
	defer resp.Body.Close()

	flags := os.O_CREATE | os.O_WRONLY
	switch resp.StatusCode {
	case http.StatusPartialContent:
		flags |= os.O_APPEND
	case http.StatusOK:
		flags |= os.O_TRUNC
		have = 0
	default:
		body, _ := io.ReadAll(io.LimitReader(resp.Body, 2048))
		return fmt.Errorf("download: http %d: %s", resp.StatusCode, truncate(string(body), 300))
	}

	out, err := os.OpenFile(dest, flags, 0o600)
	if err != nil {
		return err
	}
	defer out.Close()

	buf := make([]byte, 256<<10)
	last := time.Now()
	for {
		n, rerr := resp.Body.Read(buf)
		if n > 0 {
			if _, werr := out.Write(buf[:n]); werr != nil {
				return werr
			}
			have += int64(n)
			if progress != nil && time.Since(last) > 200*time.Millisecond {
				progress(have)
				last = time.Now()
			}
		}
		if errors.Is(rerr, io.EOF) {
			break
		}
		if rerr != nil {
			return fmt.Errorf("download interrupted at %d bytes (run again to resume): %w", have, rerr)
		}
	}
	if progress != nil {
		progress(have)
	}
	if total > 0 && have != total {
		return fmt.Errorf("download incomplete: got %d of %d bytes (run again to resume)", have, total)
	}
	return nil
}
