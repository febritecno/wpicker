// Package migrate turns a WPicker site export (one tar file) into a working
// local WordPress: it unpacks wp-content, loads the database dump, and
// rewrites the source site's URLs and paths to the local ones.
package migrate

import (
	"strconv"
	"strings"
)

// ReplaceValue applies r to one database value.
//
// WordPress stores many options and meta values as PHP-serialized data, where
// every string carries its byte length: s:20:"http://example.com/";. A plain
// search-and-replace that changes the length corrupts the value and PHP then
// refuses to unserialize it. So if the value is serialized, it is walked and
// re-emitted with corrected lengths; otherwise it is replaced as plain text.
func ReplaceValue(value string, r *Replacer) string {
	if looksSerialized(value) {
		p := &phpParser{in: value, r: r}
		if p.value() && p.pos == len(value) {
			return p.out.String()
		}
	}
	return r.Replace(value)
}

func looksSerialized(s string) bool {
	if s == "N;" {
		return true
	}
	return len(s) >= 4 && s[1] == ':' && strings.IndexByte("aOsbidCE", s[0]) >= 0
}

// phpParser copies a serialized value from in to out, replacing inside strings.
type phpParser struct {
	in  string
	pos int
	out strings.Builder
	r   *Replacer
}

// value consumes one serialized value. It reports false on malformed input,
// in which case the caller falls back to a plain replace.
func (p *phpParser) value() bool {
	if p.pos >= len(p.in) {
		return false
	}
	switch p.in[p.pos] {
	case 'N':
		return p.literal("N;")
	case 'b', 'i', 'd', 'r', 'R':
		// scalar or reference: copy through the terminating ';'
		end := strings.IndexByte(p.in[p.pos:], ';')
		if end < 0 || p.pos+1 >= len(p.in) || p.in[p.pos+1] != ':' {
			return false
		}
		p.out.WriteString(p.in[p.pos : p.pos+end+1])
		p.pos += end + 1
		return true
	case 's':
		p.pos++
		raw, ok := p.counted()
		if !ok || !p.skip(";") {
			return false
		}
		// A string may itself hold serialized data (WordPress does this).
		writeString(&p.out, 's', ReplaceValue(raw, p.r))
		p.out.WriteByte(';')
		return true
	case 'E':
		p.pos++
		raw, ok := p.counted()
		if !ok || !p.skip(";") {
			return false
		}
		writeString(&p.out, 'E', raw)
		p.out.WriteByte(';')
		return true
	case 'a':
		p.pos++
		return p.skip(":") && p.members("a:")
	case 'O':
		p.pos++
		class, ok := p.counted()
		if !ok || !p.skip(":") {
			return false
		}
		var head strings.Builder
		writeString(&head, 'O', class)
		head.WriteByte(':')
		return p.members(head.String())
	case 'C':
		// Custom-serialized object: its payload has a format of its own, so it
		// is copied untouched.
		p.pos++
		class, ok := p.counted()
		if !ok || !p.skip(":") {
			return false
		}
		n, ok := p.number(':')
		if !ok || !p.skip("{") || p.pos+n > len(p.in) {
			return false
		}
		payload := p.in[p.pos : p.pos+n]
		p.pos += n
		if !p.skip("}") {
			return false
		}
		writeString(&p.out, 'C', class)
		p.out.WriteString(":" + strconv.Itoa(n) + ":{" + payload + "}")
		return true
	}
	return false
}

// members consumes COUNT:{ key value ... } after head has been decided.
func (p *phpParser) members(head string) bool {
	n, ok := p.number(':')
	if !ok || !p.skip("{") {
		return false
	}
	p.out.WriteString(head + strconv.Itoa(n) + ":{")
	for i := 0; i < n*2; i++ {
		if !p.value() {
			return false
		}
	}
	if !p.skip("}") {
		return false
	}
	p.out.WriteByte('}')
	return true
}

// counted consumes :LEN:"BYTES" and returns BYTES.
func (p *phpParser) counted() (string, bool) {
	if !p.skip(":") {
		return "", false
	}
	n, ok := p.number(':')
	if !ok || !p.skip(`"`) || p.pos+n > len(p.in) {
		return "", false
	}
	raw := p.in[p.pos : p.pos+n]
	p.pos += n
	return raw, p.skip(`"`)
}

// number consumes digits followed by the terminator.
func (p *phpParser) number(term byte) (int, bool) {
	end := p.pos
	for end < len(p.in) && p.in[end] >= '0' && p.in[end] <= '9' {
		end++
	}
	if end == p.pos || end >= len(p.in) || p.in[end] != term {
		return 0, false
	}
	n, err := strconv.Atoi(p.in[p.pos:end])
	if err != nil {
		return 0, false
	}
	p.pos = end + 1
	return n, true
}

func (p *phpParser) skip(s string) bool {
	if !strings.HasPrefix(p.in[p.pos:], s) {
		return false
	}
	p.pos += len(s)
	return true
}

func (p *phpParser) literal(s string) bool {
	if !p.skip(s) {
		return false
	}
	p.out.WriteString(s)
	return true
}

// writeString emits T:LEN:"S" with LEN in bytes, as PHP counts it.
func writeString(b *strings.Builder, kind byte, s string) {
	b.WriteByte(kind)
	b.WriteByte(':')
	b.WriteString(strconv.Itoa(len(s)))
	b.WriteString(`:"`)
	b.WriteString(s)
	b.WriteByte('"')
}

// Rewrites lists what to replace when moving a site from one home to another.
type Rewrites struct {
	// OldURLs are the source site's addresses (site URL and home URL).
	OldURLs []string
	NewURL  string
	OldPath string // source ABSPATH, no trailing slash
	NewPath string // local WordPress directory, no trailing slash
	// OldContent / NewContent are the wp-content directories; only used when
	// the source's is not simply OldPath/wp-content.
	OldContent string
	NewContent string
}

// oldHosts returns each old address without its scheme, plus its "www."
// counterpart: a site is often linked both ways.
func (w Rewrites) oldHosts() []string {
	seen := map[string]bool{}
	var out []string
	for _, u := range w.OldURLs {
		host := stripScheme(u)
		if host == "" {
			continue
		}
		other := "www." + host
		if strings.HasPrefix(strings.ToLower(host), "www.") {
			other = host[4:]
		}
		for _, h := range []string{host, other} {
			if !seen[h] {
				seen[h] = true
				out = append(out, h)
			}
		}
	}
	return out
}

// paths returns the (old, new) directory pairs to rewrite.
func (w Rewrites) paths() [][2]string {
	var out [][2]string
	if w.OldPath != "" && w.OldPath != w.NewPath {
		out = append(out, [2]string{w.OldPath, w.NewPath})
	}
	if w.OldContent != "" && w.OldContent != w.OldPath+"/wp-content" && w.OldContent != w.NewContent {
		out = append(out, [2]string{w.OldContent, w.NewContent})
	}
	return out
}

func jsonEscape(s string) string { return strings.ReplaceAll(s, "/", `\/`) }

// phpURLEncode matches PHP's urlencode() (spaces become "+") or, with raw,
// rawurlencode(); WordPress data contains both.
func phpURLEncode(s string, raw bool) string {
	var b strings.Builder
	for i := 0; i < len(s); i++ {
		c := s[i]
		switch {
		case c >= 'a' && c <= 'z', c >= 'A' && c <= 'Z', c >= '0' && c <= '9', c == '-', c == '_', c == '.':
			b.WriteByte(c)
		case c == '~' && raw:
			b.WriteByte(c)
		case c == ' ' && !raw:
			b.WriteByte('+')
		default:
			b.WriteString("%" + strings.ToUpper(strconv.FormatInt(int64(c)+256, 16)[1:]))
		}
	}
	return b.String()
}

// Needles returns the substrings whose presence means a value needs rewriting.
func (w Rewrites) Needles() []string {
	seen := map[string]bool{}
	var out []string
	add := func(s string) {
		if s != "" && !seen[s] {
			seen[s] = true
			out = append(out, s)
		}
	}
	for _, host := range w.oldHosts() {
		add(host)
		add(jsonEscape(host))
		add(phpURLEncode(host, false))
	}
	for _, p := range w.paths() {
		add(p[0])
		add(jsonEscape(p[0]))
		add(phpURLEncode(p[0], false))
	}
	return out
}

// Replacer builds a single-pass replacer covering every spelling of the old
// address found in WordPress data: http and https, with and without "www.",
// protocol-relative, scheme-less in an attribute, with JSON-escaped slashes,
// and URL-encoded — plus the install and wp-content paths.
func (w Rewrites) Replacer() *Replacer {
	r := &Replacer{}
	newHost := stripScheme(w.NewURL)
	for _, oldHost := range w.oldHosts() {
		for _, scheme := range []string{"https://", "http://"} {
			r.add(scheme+oldHost, w.NewURL)
			r.add(jsonEscape(scheme+oldHost), jsonEscape(w.NewURL))
			r.add(phpURLEncode(scheme+oldHost, false), phpURLEncode(w.NewURL, false))
			r.add(phpURLEncode(scheme+oldHost, true), phpURLEncode(w.NewURL, true))
		}
		r.add("//"+oldHost, "//"+newHost)
		r.add(jsonEscape("//"+oldHost), jsonEscape("//"+newHost))
		// Scheme-less attribute values: href="example.com/page".
		r.add(`="`+oldHost, `="`+newHost)
		r.add(`='`+oldHost, `='`+newHost)
	}
	for _, p := range w.paths() {
		r.add(p[0], p[1])
		r.add(jsonEscape(p[0]), jsonEscape(p[1]))
		r.add(phpURLEncode(p[0], false), phpURLEncode(p[1], false))
	}
	r.finish()
	return r
}

// Replacer replaces a fixed set of strings in a single left-to-right pass,
// preferring the longest match, and only where the match ends at a boundary.
//
// Single pass: a new address that contains the old one (example.com →
// dev.example.com) is not replaced twice.
//
// Boundary: the match must end where the old address ends. Otherwise a site
// at example.com would also rewrite links to example.com.au or
// example.community — other people's sites.
//
// Mirrors plugin/includes/Backup/Rewriter.php — keep the two in step.
type Replacer struct {
	from, to []string
	byFirst  [256][]int // indexes into from, longest first
}

func (r *Replacer) add(from, to string) {
	if from == "" || from == to {
		return
	}
	for _, f := range r.from {
		if f == from {
			return
		}
	}
	r.from = append(r.from, from)
	r.to = append(r.to, to)
}

func (r *Replacer) finish() {
	order := make([]int, len(r.from))
	for i := range order {
		order[i] = i
	}
	// insertion sort by length, descending: the set is a few dozen strings
	for i := 1; i < len(order); i++ {
		for j := i; j > 0 && len(r.from[order[j]]) > len(r.from[order[j-1]]); j-- {
			order[j], order[j-1] = order[j-1], order[j]
		}
	}
	for _, idx := range order {
		c := r.from[idx][0]
		r.byFirst[c] = append(r.byFirst[c], idx)
	}
}

// Replace returns s with every old spelling replaced.
func (r *Replacer) Replace(s string) string {
	var b strings.Builder
	last := 0
	for i := 0; i < len(s); {
		matched := false
		for _, idx := range r.byFirst[s[i]] {
			from := r.from[idx]
			if strings.HasPrefix(s[i:], from) && atBoundary(s, i+len(from)) {
				b.WriteString(s[last:i])
				b.WriteString(r.to[idx])
				i += len(from)
				last = i
				matched = true
				break
			}
		}
		if !matched {
			i++
		}
	}
	if last == 0 {
		return s
	}
	b.WriteString(s[last:])
	return b.String()
}

// atBoundary reports whether position j in s can be the end of an address:
// not followed by a name character, nor by a dot that continues into a
// longer name.
func atBoundary(s string, j int) bool {
	if j >= len(s) {
		return true
	}
	isAlnum := func(c byte) bool {
		return c >= 'a' && c <= 'z' || c >= 'A' && c <= 'Z' || c >= '0' && c <= '9'
	}
	c := s[j]
	if isAlnum(c) || c == '_' || c == '-' {
		return false
	}
	return !(c == '.' && j+1 < len(s) && isAlnum(s[j+1]))
}

func stripScheme(u string) string {
	u = strings.TrimRight(u, "/")
	if i := strings.Index(u, "://"); i >= 0 {
		return u[i+3:]
	}
	return u
}
