package migrate

import (
	"bufio"
	"context"
	"database/sql"
	"errors"
	"fmt"
	"io"
	"os"
	"regexp"
	"strings"

	_ "github.com/go-sql-driver/mysql" // registers the "mysql" driver
)

// DB is a connection to the local site's database, pinned to one session so
// that settings from the dump (SET NAMES, SQL_MODE, FOREIGN_KEY_CHECKS) stay
// in effect for the statements that follow them.
type DB struct {
	pool *sql.DB
	conn *sql.Conn
	cfg  DBConfig
}

// Open connects and verifies the connection.
func Open(ctx context.Context, cfg DBConfig) (*DB, error) {
	pool, err := sql.Open("mysql", cfg.DSN())
	if err != nil {
		return nil, err
	}
	conn, err := pool.Conn(ctx)
	if err == nil {
		err = conn.PingContext(ctx)
	}
	if err != nil {
		pool.Close()
		return nil, fmt.Errorf("connect to database %s: %w", cfg.Where(), err)
	}
	return &DB{pool: pool, conn: conn, cfg: cfg}, nil
}

// Close releases the connection.
func (d *DB) Close() {
	d.conn.Close()
	d.pool.Close()
}

// Tables lists the tables whose name starts with prefix.
func (d *DB) Tables(ctx context.Context, prefix string) ([]string, error) {
	// SHOW statements cannot take placeholders, so the pattern is a literal.
	rows, err := d.conn.QueryContext(ctx, "SHOW TABLES LIKE '"+sqlEscape(likeEscape(prefix))+"%'")
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []string
	for rows.Next() {
		var name string
		if err := rows.Scan(&name); err != nil {
			return nil, err
		}
		out = append(out, name)
	}
	return out, rows.Err()
}

// SiteURL returns the installed site's URL, or "" if there is no WordPress
// in this database yet.
func (d *DB) SiteURL(ctx context.Context, prefix string) string {
	var url string
	err := d.conn.QueryRowContext(ctx, "SELECT option_value FROM "+quoteName(prefix+"options")+" WHERE option_name = 'siteurl'").Scan(&url)
	if err != nil {
		return ""
	}
	return strings.TrimRight(url, "/")
}

// Dump writes the tables with the given prefix to path, in the same
// one-statement-per-line format the plugin produces, so the file can be
// loaded back with Import. It is the safety copy taken before a restore
// overwrites the local database.
func (d *DB) Dump(ctx context.Context, prefix, path string) (tables int, err error) {
	names, err := d.Tables(ctx, prefix)
	if err != nil {
		return 0, err
	}
	f, err := os.OpenFile(path, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o600)
	if err != nil {
		return 0, err
	}
	w := bufio.NewWriterSize(f, 1<<20)
	defer func() {
		if ferr := w.Flush(); err == nil {
			err = ferr
		}
		if cerr := f.Close(); err == nil {
			err = cerr
		}
	}()

	w.WriteString("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n")
	for _, name := range names {
		if err := d.dumpTable(ctx, w, name); err != nil {
			return tables, fmt.Errorf("dump %s: %w", name, err)
		}
		tables++
	}
	return tables, nil
}

func (d *DB) dumpTable(ctx context.Context, w *bufio.Writer, name string) error {
	var ignored, create string
	if err := d.conn.QueryRowContext(ctx, "SHOW CREATE TABLE "+quoteName(name)).Scan(&ignored, &create); err != nil {
		return err
	}
	fmt.Fprintf(w, "DROP TABLE IF EXISTS %s;\n%s;\n", quoteName(name), strings.Join(strings.Fields(create), " "))

	rows, err := d.conn.QueryContext(ctx, "SELECT * FROM "+quoteName(name))
	if err != nil {
		return err
	}
	defer rows.Close()
	cols, err := rows.Columns()
	if err != nil {
		return err
	}
	values := make([]sql.RawBytes, len(cols))
	dest := make([]any, len(cols))
	for i := range values {
		dest[i] = &values[i]
	}

	const maxStatement = 256 << 10
	size := 0
	for rows.Next() {
		if err := rows.Scan(dest...); err != nil {
			return err
		}
		var tuple strings.Builder
		tuple.WriteByte('(')
		for i, v := range values {
			if i > 0 {
				tuple.WriteByte(',')
			}
			if v == nil {
				tuple.WriteString("NULL")
			} else {
				tuple.WriteByte('\'')
				tuple.WriteString(sqlEscape(string(v)))
				tuple.WriteByte('\'')
			}
		}
		tuple.WriteByte(')')

		switch {
		case size == 0:
			fmt.Fprintf(w, "INSERT INTO %s VALUES ", quoteName(name))
		case size+tuple.Len() > maxStatement:
			fmt.Fprintf(w, ";\nINSERT INTO %s VALUES ", quoteName(name))
			size = 0
		default:
			w.WriteByte(',')
		}
		w.WriteString(tuple.String())
		size += tuple.Len()
	}
	if size > 0 {
		w.WriteString(";\n")
	}
	return rows.Err()
}

// Import executes a dump produced by the plugin (or by Dump): one statement
// per line. progress, if not nil, receives the number of statements run.
func (d *DB) Import(ctx context.Context, path string, progress func(statements int)) (int, error) {
	f, err := os.Open(path)
	if err != nil {
		return 0, err
	}
	defer f.Close()

	r := bufio.NewReaderSize(f, 1<<20)
	count := 0
	for {
		line, rerr := r.ReadString('\n')
		stmt := strings.TrimSuffix(strings.TrimRight(line, "\r\n"), ";")
		if strings.TrimSpace(stmt) != "" {
			if _, err := d.conn.ExecContext(ctx, stmt); err != nil {
				return count, fmt.Errorf("statement %d (%s…): %w", count+1, truncateStmt(stmt), err)
			}
			count++
			if progress != nil {
				progress(count)
			}
		}
		if errors.Is(rerr, io.EOF) {
			break
		}
		if rerr != nil {
			return count, rerr
		}
	}
	return count, nil
}

// Rewrite replaces the source site's URLs and paths in every text column of
// every table with the prefix, keeping PHP-serialized values valid (see
// ReplaceValue). It returns the number of rows changed.
func (d *DB) Rewrite(ctx context.Context, prefix string, w Rewrites) (int, error) {
	needles := w.Needles()
	replacer := w.Replacer()
	if len(needles) == 0 {
		return 0, nil
	}
	tables, err := d.Tables(ctx, prefix)
	if err != nil {
		return 0, err
	}

	changed := 0
	for _, table := range tables {
		keys, texts, err := d.columns(ctx, table)
		if err != nil {
			return changed, err
		}
		for _, col := range texts {
			var n int
			if len(keys) == 0 {
				n, err = d.rewriteKeyless(ctx, table, col, needles, replacer)
			} else {
				n, err = d.rewriteColumn(ctx, table, keys, col, needles, replacer)
			}
			if err != nil {
				return changed, fmt.Errorf("rewrite %s.%s: %w", table, col, err)
			}
			changed += n
		}
	}
	return changed, nil
}

// columns returns a table's primary-key columns and its text-like columns.
func (d *DB) columns(ctx context.Context, table string) (keys, texts []string, err error) {
	rows, err := d.conn.QueryContext(ctx, "SHOW COLUMNS FROM "+quoteName(table))
	if err != nil {
		return nil, nil, err
	}
	defer rows.Close()
	for rows.Next() {
		var field, typ, null, key string
		var def, extra sql.NullString
		if err := rows.Scan(&field, &typ, &null, &key, &def, &extra); err != nil {
			return nil, nil, err
		}
		if key == "PRI" {
			keys = append(keys, field)
		}
		typ = strings.ToLower(typ)
		for _, t := range []string{"char", "text", "json"} {
			if strings.Contains(typ, t) {
				texts = append(texts, field)
				break
			}
		}
	}
	return keys, texts, rows.Err()
}

func (d *DB) rewriteColumn(ctx context.Context, table string, keys []string, col string, needles []string, replacer *Replacer) (int, error) {
	var where []string
	var args []any
	for _, n := range needles {
		where = append(where, quoteName(col)+" LIKE ?")
		args = append(args, "%"+likeEscape(n)+"%")
	}
	var selectCols []string
	for _, k := range keys {
		selectCols = append(selectCols, quoteName(k))
	}
	selectCols = append(selectCols, quoteName(col))

	rows, err := d.conn.QueryContext(ctx, "SELECT "+strings.Join(selectCols, ",")+" FROM "+quoteName(table)+" WHERE "+strings.Join(where, " OR "), args...)
	if err != nil {
		return 0, err
	}

	// Read everything first: the same connection cannot run the UPDATEs
	// while the SELECT's result set is still open.
	type update struct {
		keys  []any
		value string
	}
	var updates []update
	for rows.Next() {
		keyVals := make([]sql.RawBytes, len(keys))
		var value sql.RawBytes
		dest := make([]any, 0, len(keys)+1)
		for i := range keyVals {
			dest = append(dest, &keyVals[i])
		}
		dest = append(dest, &value)
		if err := rows.Scan(dest...); err != nil {
			rows.Close()
			return 0, err
		}
		old := string(value)
		replaced := ReplaceValue(old, replacer)
		if replaced == old {
			continue
		}
		u := update{value: replaced}
		for _, kv := range keyVals {
			u.keys = append(u.keys, string(kv))
		}
		updates = append(updates, u)
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		return 0, err
	}
	rows.Close()

	var cond []string
	for _, k := range keys {
		cond = append(cond, quoteName(k)+" = ?")
	}
	query := "UPDATE " + quoteName(table) + " SET " + quoteName(col) + " = ? WHERE " + strings.Join(cond, " AND ")
	for _, u := range updates {
		if _, err := d.conn.ExecContext(ctx, query, append([]any{u.value}, u.keys...)...); err != nil {
			return 0, err
		}
	}
	return len(updates), nil
}

// rewriteKeyless handles a table without a primary key: rows cannot be
// addressed one by one, so each distinct value that needs changing is
// replaced wherever it occurs. Every matching value is read before any is
// written, so a replacement that still contains the old address is not
// picked up a second time.
func (d *DB) rewriteKeyless(ctx context.Context, table, col string, needles []string, replacer *Replacer) (int, error) {
	var where []string
	var args []any
	for _, n := range needles {
		where = append(where, quoteName(col)+" LIKE ?")
		args = append(args, "%"+likeEscape(n)+"%")
	}
	rows, err := d.conn.QueryContext(ctx, "SELECT DISTINCT "+quoteName(col)+" FROM "+quoteName(table)+" WHERE "+strings.Join(where, " OR "), args...)
	if err != nil {
		return 0, err
	}
	var values []string
	for rows.Next() {
		var v sql.RawBytes
		if err := rows.Scan(&v); err != nil {
			rows.Close()
			return 0, err
		}
		values = append(values, string(v))
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		return 0, err
	}
	rows.Close()

	changed := 0
	query := "UPDATE " + quoteName(table) + " SET " + quoteName(col) + " = ? WHERE BINARY " + quoteName(col) + " = ?"
	for _, old := range values {
		replaced := ReplaceValue(old, replacer)
		if replaced == old {
			continue
		}
		res, err := d.conn.ExecContext(ctx, query, replaced, old)
		if err != nil {
			return changed, err
		}
		if n, err := res.RowsAffected(); err == nil {
			changed += int(n)
		}
	}
	return changed, nil
}

// forceSSLPlugins redirect all traffic to HTTPS. Restored onto a site without
// HTTPS they redirect every request to an address that does not answer, and
// nobody could get in to switch them off again.
var forceSSLPlugins = map[string]bool{
	"really-simple-ssl/rlrsssl-really-simple-ssl.php": true,
	"wordpress-https/wordpress-https.php":             true,
	"wp-force-ssl/wp-force-ssl.php":                   true,
}

var serializedString = regexp.MustCompile(`s:\d+:"([^"]*)";`)

// Settle makes the freshly loaded database fit its new home, beyond
// addresses: cached values and rewrite rules computed on the other site are
// dropped (WordPress rebuilds both on the next request), and plugins that
// force HTTPS are switched off when the new address is plain HTTP.
// It returns the plugins it deactivated.
func (d *DB) Settle(ctx context.Context, prefix, newURL string) ([]string, error) {
	options := quoteName(prefix + "options")
	if _, err := d.conn.ExecContext(ctx, "DELETE FROM "+options+` WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%' OR option_name = 'rewrite_rules'`); err != nil {
		return nil, err
	}
	if strings.HasPrefix(strings.ToLower(newURL), "https://") {
		return nil, nil
	}

	var value string
	if err := d.conn.QueryRowContext(ctx, "SELECT option_value FROM "+options+" WHERE option_name = 'active_plugins'").Scan(&value); err != nil {
		return nil, nil // no plugin list: nothing to switch off
	}
	// active_plugins is a flat serialized list of plugin paths.
	var keep, dropped []string
	for _, m := range serializedString.FindAllStringSubmatch(value, -1) {
		if forceSSLPlugins[m[1]] {
			dropped = append(dropped, m[1])
		} else {
			keep = append(keep, m[1])
		}
	}
	if len(dropped) == 0 {
		return nil, nil
	}
	var b strings.Builder
	fmt.Fprintf(&b, "a:%d:{", len(keep))
	for i, plugin := range keep {
		fmt.Fprintf(&b, `i:%d;s:%d:"%s";`, i, len(plugin), plugin)
	}
	b.WriteByte('}')
	return dropped, d.SetOption(ctx, prefix, "active_plugins", b.String())
}

// SetOption writes one row of the options table.
func (d *DB) SetOption(ctx context.Context, prefix, name, value string) error {
	_, err := d.conn.ExecContext(ctx, "UPDATE "+quoteName(prefix+"options")+" SET option_value = ? WHERE option_name = ?", value, name)
	return err
}

func quoteName(name string) string {
	return "`" + strings.ReplaceAll(name, "`", "``") + "`"
}

// likeEscape makes s match literally inside a LIKE pattern.
func likeEscape(s string) string {
	return strings.NewReplacer(`\`, `\\`, `%`, `\%`, `_`, `\_`).Replace(s)
}

// sqlEscape escapes a string for a single-quoted MySQL literal, the same way
// mysql_real_escape_string does — including turning newlines into "\n" so a
// statement stays on one line.
var sqlEscaper = strings.NewReplacer(
	"\\", `\\`, "\x00", `\0`, "\n", `\n`, "\r", `\r`, "'", `\'`, `"`, `\"`, "\x1a", `\Z`,
)

func sqlEscape(s string) string { return sqlEscaper.Replace(s) }

func truncateStmt(s string) string {
	if len(s) > 60 {
		return s[:60]
	}
	return s
}
