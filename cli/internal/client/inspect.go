package client

import (
	"context"
	"net/url"
	"strconv"
)

// LogEntry is one distinct error from the site's logs, with how often and
// when it occurred.
type LogEntry struct {
	Level   string   `json:"level"`
	Message string   `json:"message"`
	File    string   `json:"file"`
	Line    int      `json:"line"`
	Count   int      `json:"count"`
	First   string   `json:"first_gmt"`
	Last    string   `json:"last_gmt"`
	Trace   []string `json:"trace"`
	Source  string   `json:"source"`
}

// LogsResponse mirrors GET /inspect/logs.
type LogsResponse struct {
	Entries []LogEntry `json:"entries"`
	Count   int        `json:"count"`
	Total   int        `json:"total"`
	Sources map[string]struct {
		File  string `json:"file"`
		Bytes int64  `json:"bytes"`
	} `json:"sources"`
	DebugLogging bool `json:"debug_logging"`
	WPDebug      bool `json:"wp_debug"`
	WPDebugLog   bool `json:"wp_debug_log"`
}

// Logs fetches parsed, grouped log entries.
func (c *Client) Logs(ctx context.Context, sinceMinutes int, level, grep, source string, limit int) (*LogsResponse, error) {
	q := url.Values{
		"since":  {strconv.Itoa(sinceMinutes)},
		"level":  {level},
		"grep":   {grep},
		"source": {source},
		"limit":  {strconv.Itoa(limit)},
	}
	var out LogsResponse
	if err := c.Do(ctx, "GET", "/inspect/logs?"+q.Encode(), nil, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// InspectStatus mirrors GET /inspect/status.
type InspectStatus struct {
	DebugLogging bool             `json:"debug_logging"`
	WPDebug      bool             `json:"wp_debug"`
	WPDebugLog   bool             `json:"wp_debug_log"`
	LogSources   map[string]int64 `json:"log_sources"`
	PHPVersion   string           `json:"php_version"`
}

// InspectStatus reports what diagnostics are switched on.
func (c *Client) InspectStatus(ctx context.Context) (*InspectStatus, error) {
	var out InspectStatus
	if err := c.Do(ctx, "GET", "/inspect/status", nil, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// SetDebugLogging turns WordPress debug logging on or off on the site.
func (c *Client) SetDebugLogging(ctx context.Context, enabled bool) error {
	return c.Do(ctx, "POST", "/inspect/debug", map[string]bool{"enabled": enabled}, nil)
}

// RemoteEntry is a file or directory under wp-content.
type RemoteEntry struct {
	Path     string `json:"path"`
	Type     string `json:"type"`
	Size     int64  `json:"size"`
	Modified string `json:"modified"`
}

// RemoteListing mirrors GET /inspect/files.
type RemoteListing struct {
	Path    string        `json:"path"`
	Entries []RemoteEntry `json:"entries"`
	Count   int           `json:"count"`
	Capped  bool          `json:"capped"`
}

// ListRemote lists a directory under wp-content.
func (c *Client) ListRemote(ctx context.Context, path string, depth int) (*RemoteListing, error) {
	q := url.Values{"path": {path}, "depth": {strconv.Itoa(depth)}}
	var out RemoteListing
	if err := c.Do(ctx, "GET", "/inspect/files?"+q.Encode(), nil, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// RemoteFile mirrors GET /inspect/file.
type RemoteFile struct {
	Path     string `json:"path"`
	Size     int64  `json:"size"`
	Modified string `json:"modified"`
	Binary   bool   `json:"binary"`
	Lines    int    `json:"lines"`
	From     int    `json:"from"`
	To       int    `json:"to"`
	Contents string `json:"contents"`
}

// ReadRemote reads a text file under wp-content (to = 0: to the end).
func (c *Client) ReadRemote(ctx context.Context, path string, from, to int) (*RemoteFile, error) {
	q := url.Values{"path": {path}, "from": {strconv.Itoa(from)}, "to": {strconv.Itoa(to)}}
	var out RemoteFile
	if err := c.Do(ctx, "GET", "/inspect/file?"+q.Encode(), nil, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// GrepResponse mirrors GET /inspect/grep.
type GrepResponse struct {
	Matches []struct {
		Path string `json:"path"`
		Line int    `json:"line"`
		Text string `json:"text"`
	} `json:"matches"`
	Count    int    `json:"count"`
	Searched int    `json:"searched"`
	Stopped  string `json:"stopped"`
}

// GrepRemote searches text files under wp-content.
func (c *Client) GrepRemote(ctx context.Context, pattern, path string, regex, caseSensitive bool, ext string, limit int) (*GrepResponse, error) {
	q := url.Values{
		"pattern": {pattern},
		"path":    {path},
		"regex":   {strconv.FormatBool(regex)},
		"case":    {strconv.FormatBool(caseSensitive)},
		"limit":   {strconv.Itoa(limit)},
	}
	if ext != "" {
		q.Set("ext", ext)
	}
	var out GrepResponse
	if err := c.Do(ctx, "GET", "/inspect/grep?"+q.Encode(), nil, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// DBTable describes one table and how much of it a diagnostic query sees.
type DBTable struct {
	Name       string `json:"name"`
	ApproxRows int    `json:"approx_rows"`
	KB         int    `json:"kb"`
	// Access is "full", "filtered" (credentials left out) or "denied".
	Access string `json:"access"`
}

// DBTables lists the site's tables.
func (c *Client) DBTables(ctx context.Context) ([]DBTable, error) {
	var out struct {
		Tables []DBTable `json:"tables"`
	}
	if err := c.Do(ctx, "GET", "/inspect/db/tables", nil, &out); err != nil {
		return nil, err
	}
	return out.Tables, nil
}

// QueryResult mirrors POST /inspect/db/query. A NULL cell is a nil pointer.
type QueryResult struct {
	Columns   []string    `json:"columns"`
	Rows      [][]*string `json:"rows"`
	Count     int         `json:"count"`
	Truncated bool        `json:"truncated"`
	Limit     int         `json:"limit"`
	Ms        int         `json:"ms"`
}

// DBQuery runs one read-only statement on the site.
func (c *Client) DBQuery(ctx context.Context, sql string, limit int) (*QueryResult, error) {
	var out QueryResult
	if err := c.Do(ctx, "POST", "/inspect/db/query", map[string]any{"sql": sql, "limit": limit}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}
