// Package tui renders CLI output: human-friendly tables and machine JSON.
//
// All printers take a `json bool` so commands can emit either form for humans
// or for AI agents. JSON output is the raw envelope data, unpadded by framing.
package tui

import (
	"encoding/json"
	"fmt"
	"io"
	"strings"

	"wpicker/internal/client"
)

// Printer wraps an output writer and the json flag.
type Printer struct {
	Out   io.Writer
	JSON  bool
	Quiet bool
}

// New returns a Printer writing to out.
func New(out io.Writer, asJSON bool) *Printer {
	return &Printer{Out: out, JSON: asJSON}
}

// EmitJSON writes a value as JSON when --json is set; otherwise no-op.
func (p *Printer) EmitJSON(v any) {
	if !p.JSON {
		return
	}
	enc := json.NewEncoder(p.Out)
	enc.SetIndent("", "  ")
	_ = enc.Encode(v)
}

// Line writes a plain line (suppressed under --json unless forced).
func (p *Printer) Line(s string) {
	if p.JSON {
		return
	}
	fmt.Fprintln(p.Out, s)
}

// Linef is a formatted Line.
func (p *Printer) Linef(format string, args ...any) {
	p.Line(fmt.Sprintf(format, args...))
}

// Context renders the site context payload.
func (p *Printer) Context(c *client.ContextResponse) {
	if p.JSON {
		p.EmitJSON(c)
		return
	}
	w := p.Out
	fmt.Fprintf(w, "Site:        %s (%s)\n", c.Site.Name, c.Site.URL)
	fmt.Fprintf(w, "WP:          %s   PHP: %s\n", c.Environment.WPVersion, c.Environment.PHPVersion)
	fmt.Fprintf(w, "Theme:       %s v%s", c.Theme.Name, c.Theme.Version)
	if c.Theme.IsChildTheme {
		fmt.Fprintf(w, "  (child of %s)", c.Theme.Template)
	}
	fmt.Fprintln(w)
	fmt.Fprintf(w, "Plugins (%d):\n", len(c.Plugins))
	for _, pl := range c.Plugins {
		fmt.Fprintf(w, "  - %-30s %s\n", pl.Name, pl.Version)
	}
	if len(c.ThemeMods) > 0 {
		fmt.Fprintf(w, "Theme mods:  %d keys\n", len(c.ThemeMods))
	}
}

// Files renders a child-theme file list.
func (p *Printer) Files(f *client.FilesResponse) {
	if p.JSON {
		p.EmitJSON(f)
		return
	}
	fmt.Fprintf(p.Out, "Stylesheet: %s   (%d files)\n", f.Stylesheet, f.Count)
	rows := tableRows()
	rows.header("PATH", "SIZE", "SHA256")
	for _, file := range f.Files {
		rows.add(file.Path, humanSize(file.Size), shortHash(file.SHA256))
	}
	fmt.Fprint(p.Out, rows.render())
}

// History renders the manifest list.
func (p *Printer) History(h *client.HistoryResponse) {
	if p.JSON {
		p.EmitJSON(h)
		return
	}
	if h.Count == 0 {
		fmt.Fprintln(p.Out, "No deployments recorded yet.")
		return
	}
	rows := tableRows()
	rows.header("MANIFEST", "TIME (GMT)", "DEVICE", "KIND", "FILES", "STATUS")
	for _, m := range h.Manifests {
		rows.add(m.ID, m.CreatedAt, m.DeviceName, m.Kind, fmt.Sprintf("%d", m.Count), m.Status)
	}
	fmt.Fprint(p.Out, rows.render())
}

// Push renders a push result.
func (p *Printer) Push(r *client.PushResponse) {
	if p.JSON {
		p.EmitJSON(r)
		return
	}
	fmt.Fprintf(p.Out, "✓ Pushed %d file(s). Manifest: %s\n", r.Count, r.ManifestID)
	for _, f := range r.Applied {
		fmt.Fprintf(p.Out, "  - %s\n", f)
	}
}

// Rollback renders a rollback result.
func (p *Printer) Rollback(r *client.RollbackResponse) {
	if p.JSON {
		p.EmitJSON(r)
		return
	}
	fmt.Fprintf(p.Out, "✓ Restored snapshot %s (%d files).\n", r.Restored, len(r.Files))
	fmt.Fprintf(p.Out, "  Safety snapshot: %s\n", r.SafetyManifestID)
}

// Devices renders the device list.
func (p *Printer) Devices(ds []client.DeviceRecord) {
	if p.JSON {
		p.EmitJSON(map[string]any{"devices": ds})
		return
	}
	if len(ds) == 0 {
		fmt.Fprintln(p.Out, "No devices registered.")
		return
	}
	rows := tableRows()
	rows.header("NAME", "DEVICE ID", "CREATED", "LAST SEEN")
	for _, d := range ds {
		last := d.LastSeen
		if last == "" {
			last = "never"
		}
		rows.add(d.Name, d.ID, d.CreatedAt, last)
	}
	fmt.Fprint(p.Out, rows.render())
}

// RestError prints a structured REST error (for the self-healing contract).
func (p *Printer) RestError(e *client.RESTError) {
	if p.JSON {
		p.EmitJSON(map[string]any{"ok": false, "error": e.Details})
		return
	}
	fmt.Fprintf(p.Out, "✗ %s\n", e.Error())
}

func humanSize(n int64) string {
	const unit = 1024
	if n < unit {
		return fmt.Sprintf("%dB", n)
	}
	div, exp := int64(unit), 0
	for x := n / unit; x >= unit; x /= unit {
		div *= unit
		exp++
	}
	return fmt.Sprintf("%.1f%cB", float64(n)/float64(div), "KMGTPE"[exp])
}

func shortHash(h string) string {
	if len(h) <= 12 {
		return h
	}
	return h[:12] + "…"
}

// --- minimal aligned-table renderer ----------------------------------------

type table struct {
	cols   []string
	rows   [][]string
	widths []int
}

func tableRows() *table { return &table{} }

func (t *table) header(cols ...string) {
	t.cols = cols
	t.widths = make([]int, len(cols))
	for i, c := range cols {
		t.widths[i] = len(c)
	}
}

func (t *table) add(vals ...string) {
	for len(vals) < len(t.cols) {
		vals = append(vals, "")
	}
	t.rows = append(t.rows, vals)
	for i, v := range vals {
		if len(v) > t.widths[i] {
			t.widths[i] = len(v)
		}
	}
}

func (t *table) render() string {
	var b strings.Builder
	// header
	for i, c := range t.cols {
		b.WriteString(padRight(c, t.widths[i]))
		if i < len(t.cols)-1 {
			b.WriteString("  ")
		}
	}
	b.WriteByte('\n')
	// separator
	for i := range t.cols {
		b.WriteString(strings.Repeat("-", t.widths[i]))
		if i < len(t.cols)-1 {
			b.WriteString("  ")
		}
	}
	b.WriteByte('\n')
	// rows
	for _, row := range t.rows {
		for i, v := range row {
			b.WriteString(padRight(v, t.widths[i]))
			if i < len(t.cols)-1 {
				b.WriteString("  ")
			}
		}
		b.WriteByte('\n')
	}
	return b.String()
}

func padRight(s string, n int) string {
	if len(s) >= n {
		return s
	}
	return s + strings.Repeat(" ", n-len(s))
}
