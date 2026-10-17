package client

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"net/url"
)

// --- Context ---------------------------------------------------------------

// ContextResponse mirrors GET /context data.
type ContextResponse struct {
	Wpicker struct {
		Version string `json:"version"`
	} `json:"wpicker"`
	Site struct {
		Name               string `json:"name"`
		Description        string `json:"description"`
		URL                string `json:"url"`
		AdminURL           string `json:"admin_url"`
		PermalinkStructure string `json:"permalink_structure"`
		Timezone           string `json:"timezone"`
	} `json:"site"`
	Environment struct {
		WPVersion   string `json:"wp_version"`
		PHPVersion  string `json:"php_version"`
		IsMultisite bool   `json:"is_multisite"`
		Language    string `json:"language"`
	} `json:"environment"`
	Theme struct {
		Name         string `json:"name"`
		Stylesheet   string `json:"stylesheet"`
		Version      string `json:"version"`
		Template     string `json:"template"`
		IsChildTheme bool   `json:"is_child_theme"`
	} `json:"theme"`
	Plugins []struct {
		Name    string `json:"name"`
		Version string `json:"version"`
		Path    string `json:"path"`
	} `json:"plugins"`
	ThemeMods map[string]any `json:"theme_mods"`
}

// GetContext fetches the Global Context payload.
func (c *Client) GetContext(ctx context.Context) (*ContextResponse, error) {
	var out ContextResponse
	if err := c.Do(ctx, "GET", "/context", nil, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// --- Device ---------------------------------------------------------------

// DeviceRecord is a single registered device.
type DeviceRecord struct {
	ID          string `json:"id"`
	UserID      int    `json:"user_id"`
	Name        string `json:"name"`
	CreatedAt   string `json:"created_at_gmt"`
	LastSeen    string `json:"last_seen_gmt"`
}

// ListDevices returns all devices (admin only).
func (c *Client) ListDevices(ctx context.Context) ([]DeviceRecord, error) {
	var out struct {
		Devices []DeviceRecord `json:"devices"`
	}
	if err := c.Do(ctx, "GET", "/device", nil, &out); err != nil {
		return nil, err
	}
	return out.Devices, nil
}

// RevokeDevice deletes a device by id.
func (c *Client) RevokeDevice(ctx context.Context, id string) error {
	return c.Do(ctx, "DELETE", "/device/"+id, nil, nil)
}

// --- Theme sync ------------------------------------------------------------

// FileEntry is a single child-theme file (from /theme/files).
type FileEntry struct {
	Path   string `json:"path"`
	Size   int64  `json:"size"`
	MTime  string `json:"mtime"`
	SHA256 string `json:"sha256"`
}

// FilesResponse mirrors GET /theme/files.
type FilesResponse struct {
	Stylesheet string      `json:"stylesheet"`
	Count      int         `json:"count"`
	Files      []FileEntry `json:"files"`
}

// ListFiles lists the child-theme files on the site.
func (c *Client) ListFiles(ctx context.Context) (*FilesResponse, error) {
	var out FilesResponse
	if err := c.Do(ctx, "GET", "/theme/files", nil, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// FileResponse is a child-theme file with its raw bytes.
type FileResponse struct {
	Path     string
	Size     int
	SHA256   string
	Contents []byte
}

// ReadFile fetches a single child-theme file by relative path. Contents are
// transferred base64-encoded so binary files (images, fonts) survive intact.
func (c *Client) ReadFile(ctx context.Context, rel string) (*FileResponse, error) {
	var wire struct {
		Path     string `json:"path"`
		Size     int    `json:"size"`
		SHA256   string `json:"sha256"`
		Encoding string `json:"encoding"`
		Contents string `json:"contents"`
	}
	q := url.Values{"path": {rel}, "encoding": {"base64"}}
	if err := c.Do(ctx, "GET", "/theme/file?"+q.Encode(), nil, &wire); err != nil {
		return nil, err
	}
	out := &FileResponse{Path: wire.Path, Size: wire.Size, SHA256: wire.SHA256}
	if wire.Encoding != "base64" {
		// Plugin too old to honour encoding=base64: text only.
		out.Contents = []byte(wire.Contents)
		return out, nil
	}
	data, err := base64.StdEncoding.DecodeString(wire.Contents)
	if err != nil {
		return nil, fmt.Errorf("decode %s: %w", rel, err)
	}
	out.Contents = data
	return out, nil
}

// PushFile is one file in a push payload. Contents is base64 when Encoding
// is "base64" (always, when built with NewPushFile).
type PushFile struct {
	Path     string `json:"path"`
	Contents string `json:"contents"`
	Encoding string `json:"encoding,omitempty"`
}

// NewPushFile wraps raw file bytes for transfer.
func NewPushFile(rel string, data []byte) PushFile {
	return PushFile{
		Path:     rel,
		Contents: base64.StdEncoding.EncodeToString(data),
		Encoding: "base64",
	}
}

// PushRequest is the body of POST /theme/push.
type PushRequest struct {
	Files  []PushFile        `json:"files"`
	Delete []string          `json:"delete,omitempty"`
	Device map[string]string `json:"device,omitempty"`
}

// PushResponse mirrors POST /theme/push success.
type PushResponse struct {
	ManifestID string   `json:"manifest_id"`
	Applied    []string `json:"applied"`
	Deleted    []string `json:"deleted,omitempty"`
	Count      int      `json:"count"`
	// Status is "applied", or "review" when the site holds pushes for an
	// administrator to approve in WP-Admin: nothing has changed there yet.
	Status string `json:"status,omitempty"`
	// Health is the post-deploy check result: "ok", "unreachable" (loopback
	// blocked), "already_failing" (site was down before the push) or "skipped".
	Health string `json:"health,omitempty"`
}

// Push uploads a batch of files (the plugin snapshots + lints + applies).
func (c *Client) Push(ctx context.Context, req PushRequest) (*PushResponse, error) {
	var out PushResponse
	if err := c.Do(ctx, "POST", "/theme/push", req, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// --- Vault -----------------------------------------------------------------

// HistoryEntry is a slim manifest from GET /history.
type HistoryEntry struct {
	ID          string `json:"id"`
	Kind        string `json:"kind"`
	CreatedAt   string `json:"created_at_gmt"`
	DeviceName  string `json:"device_name"`
	Count       int    `json:"count"`
	Status      string `json:"status"`
	RestoreCount int   `json:"restore_count"`
	// Changes lists the files the entry touched (absent on old entries).
	Changes []struct {
		Path   string `json:"path"`
		Action string `json:"action"`
	} `json:"changes,omitempty"`
}

// HistoryResponse mirrors GET /history.
type HistoryResponse struct {
	Manifests []HistoryEntry `json:"manifests"`
	Count     int            `json:"count"`
}

// History returns the most recent manifests.
func (c *Client) History(ctx context.Context, limit int) (*HistoryResponse, error) {
	path := "/history"
	if limit > 0 {
		path += "?limit=" + itoa(limit)
	}
	var out HistoryResponse
	if err := c.Do(ctx, "GET", path, nil, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// ManifestDetail mirrors GET /history/{id}.
type ManifestDetail struct {
	ID            string         `json:"id"`
	Kind          string         `json:"kind"`
	CreatedAt     string         `json:"created_at_gmt"`
	DeviceName    string         `json:"device_name"`
	Files         []string       `json:"files"`
	Count         int            `json:"count"`
	Status        string         `json:"status"`
	RestoreCount  int            `json:"restore_count"`
	Note          string         `json:"note"`
	Error         map[string]any `json:"error"`
	SnapshotDir   string         `json:"snapshot_dir"`
	SnapshotExists bool          `json:"snapshot_exists"`
}

// GetManifest fetches one manifest by id.
func (c *Client) GetManifest(ctx context.Context, id string) (*ManifestDetail, error) {
	var out ManifestDetail
	if err := c.Do(ctx, "GET", "/history/"+id, nil, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// --- Vuln ------------------------------------------------------------------

// VulnItem is a specific vulnerability for a plugin.
type VulnItem struct {
	Name     string `json:"name"`
	Severity string `json:"severity"`
	Score    any    `json:"score"` // sometimes float or string
}

// PluginVuln contains all vulnerabilities for one plugin.
type PluginVuln struct {
	Name             string     `json:"name"`
	Slug             string     `json:"slug"`
	InstalledVersion string     `json:"installed_version"`
	Vulnerabilities  []VulnItem `json:"vulnerabilities"`
}

// VulnResponse mirrors GET /vuln.
type VulnResponse struct {
	Count   int          `json:"count"`
	Plugins []PluginVuln `json:"plugins"`
}

// ScanVuln fetches the vulnerability report.
func (c *Client) ScanVuln(ctx context.Context) (*VulnResponse, error) {
	var out VulnResponse
	if err := c.Do(ctx, "GET", "/vuln", nil, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// --- Page Builder ----------------------------------------------------------

// PageCreateRequest is the body of POST /page.
type PageCreateRequest struct {
	Title   string `json:"title"`
	Builder string `json:"builder"`
	Payload string `json:"payload"`
}

// PageCreateResponse mirrors POST /page success.
type PageCreateResponse struct {
	PostID int    `json:"post_id"`
	URL    string `json:"url"`
}

// CreatePage pushes a page payload remotely to the active builder.
func (c *Client) CreatePage(ctx context.Context, req PageCreateRequest) (*PageCreateResponse, error) {
	var out PageCreateResponse
	if err := c.Do(ctx, "POST", "/page", req, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// UpdatePage updates an existing page via POST /page/{id}.
func (c *Client) UpdatePage(ctx context.Context, id int, req PageCreateRequest) (*PageCreateResponse, error) {
	var out PageCreateResponse
	path := fmt.Sprintf("/page/%d", id)
	if err := c.Do(ctx, "POST", path, req, &out); err != nil {
		return nil, err
	}
	return &out, nil
}


// RollbackRequest is the body of POST /rollback.
type RollbackRequest struct {
	ManifestID string            `json:"manifest_id"`
	Device     map[string]string `json:"device"`
}

// RollbackResponse mirrors POST /rollback.
type RollbackResponse struct {
	Restored        string   `json:"restored"`
	SafetyManifestID string  `json:"safety_manifest_id"`
	Files           []string `json:"files"`
}

// Rollback restores a snapshot by manifest id.
func (c *Client) Rollback(ctx context.Context, manifestID string) (*RollbackResponse, error) {
	req := RollbackRequest{
		ManifestID: manifestID,
		Device:     map[string]string{"name": "wpicker-cli"},
	}
	var out RollbackResponse
	if err := c.Do(ctx, "POST", "/rollback", req, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// --- helpers ---------------------------------------------------------------

// decodeEnvelope reads the uniform { ok, data } / { ok:false, error } shape
// from a raw response and returns either the typed data or a *RESTError.
func decodeEnvelope[T any](raw []byte, status int) (*T, int, error) {
	var env struct {
		Ok    bool            `json:"ok"`
		Data  json.RawMessage `json:"data,omitempty"`
		Error json.RawMessage `json:"error,omitempty"`
	}
	_ = json.Unmarshal(raw, &env)
	if !env.Ok {
		ee := &RESTError{}
		if len(env.Error) > 0 {
			_ = json.Unmarshal(env.Error, &ee.Details)
		}
		if ee.Details.Code == "" {
			ee.Details.Code = "http_" + itoa(status)
		}
		return nil, status, ee
	}
	var out T
	if len(env.Data) > 0 {
		if err := json.Unmarshal(env.Data, &out); err != nil {
			return nil, status, fmt.Errorf("decode data: %w", err)
		}
	}
	return &out, status, nil
}

// tiny itoa avoids strconv import churn here.
func itoa(n int) string {
	if n == 0 {
		return "0"
	}
	neg := n < 0
	if neg {
		n = -n
	}
	var b [20]byte
	i := len(b)
	for n > 0 {
		i--
		b[i] = byte('0' + n%10)
		n /= 10
	}
	if neg {
		i--
		b[i] = '-'
	}
	return string(b[i:])
}
