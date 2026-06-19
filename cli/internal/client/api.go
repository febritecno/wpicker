package client

import (
	"context"
	"encoding/json"
	"fmt"
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

// ChallengeResponse mirrors GET /device/challenge.
type ChallengeResponse struct {
	UserID        int    `json:"user_id"`
	PIN           string `json:"pin"`
	ExpiresAt     string `json:"expires_at_gmt"`
	PluginVersion string `json:"plugin_version"`
	TTL           int    `json:"ttl"`
	Hint          string `json:"hint"`
}

// Challenge requests a new pairing PIN.
func (c *Client) Challenge(ctx context.Context, username, password string) (*ChallengeResponse, int, error) {
	raw, status, err := c.DoRaw(ctx, "GET", "/device/challenge", nil, username, password)
	if err != nil {
		return nil, status, err
	}
	return decodeEnvelope[ChallengeResponse](raw, status)
}

// RegisterRequest is the body of POST /device/register.
type RegisterRequest struct {
	PIN        string `json:"pin"`
	DeviceName string `json:"device_name"`
}

// RegisterResponse mirrors POST /device/register.
type RegisterResponse struct {
	UserID      int    `json:"user_id"`
	DeviceID    string `json:"device_id"`
	AppPassword string `json:"app_password"`
	DeviceName  string `json:"device_name"`
	Note        string `json:"note"`
}

// Register validates a PIN and mints an Application Password.
func (c *Client) Register(ctx context.Context, username, password string, body RegisterRequest) (*RegisterResponse, int, error) {
	raw, status, err := c.DoRaw(ctx, "POST", "/device/register", body, username, password)
	if err != nil {
		return nil, status, err
	}
	return decodeEnvelope[RegisterResponse](raw, status)
}

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

// FileResponse mirrors GET /theme/file.
type FileResponse struct {
	Path     string `json:"path"`
	Size     int    `json:"size"`
	SHA256   string `json:"sha256"`
	Contents string `json:"contents"`
}

// ReadFile fetches a single child-theme file by relative path.
func (c *Client) ReadFile(ctx context.Context, rel string) (*FileResponse, error) {
	var out FileResponse
	if err := c.Do(ctx, "GET", "/theme/file?path="+rel, nil, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// PushFile is one file in a push payload.
type PushFile struct {
	Path     string `json:"path"`
	Contents string `json:"contents"`
}

// PushRequest is the body of POST /theme/push.
type PushRequest struct {
	Files  []PushFile       `json:"files"`
	Device map[string]string `json:"device,omitempty"`
}

// PushResponse mirrors POST /theme/push success.
type PushResponse struct {
	ManifestID string   `json:"manifest_id"`
	Applied    []string `json:"applied"`
	Count      int      `json:"count"`
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
