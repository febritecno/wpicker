package migrate

import (
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"strconv"
	"strings"
)

// DBConfig is how to reach the local site's MySQL database.
type DBConfig struct {
	Host   string
	Port   int
	Socket string
	Name   string
	User   string
	Pass   string
	Prefix string
}

// DSN builds the go-sql-driver connection string.
func (c DBConfig) DSN() string {
	addr := fmt.Sprintf("tcp(%s:%d)", c.Host, c.Port)
	if c.Socket != "" {
		addr = "unix(" + c.Socket + ")"
	}
	// maxAllowedPacket=0 takes the server's limit instead of the driver's
	// 64 MB default; a dump line is one whole INSERT statement.
	return fmt.Sprintf("%s:%s@%s/%s?charset=utf8mb4&maxAllowedPacket=0", c.User, c.Pass, addr, c.Name)
}

// Where describes the connection without the password, for messages.
func (c DBConfig) Where() string {
	if c.Socket != "" {
		return fmt.Sprintf("%s (user %s, socket %s)", c.Name, c.User, c.Socket)
	}
	return fmt.Sprintf("%s (user %s, %s:%d)", c.Name, c.User, c.Host, c.Port)
}

var (
	defineRe = regexp.MustCompile(`define\(\s*['"](DB_NAME|DB_USER|DB_PASSWORD|DB_HOST)['"]\s*,\s*(?:'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)")\s*\)`)
	prefixRe = regexp.MustCompile(`\$table_prefix\s*=\s*(?:'([^']*)'|"([^"]*)")\s*;`)
)

// ReadWPConfig reads the database settings from wpRoot/wp-config.php.
func ReadWPConfig(wpRoot string) (DBConfig, error) {
	cfg := DBConfig{Host: "127.0.0.1", Port: 3306, Prefix: "wp_"}
	data, err := os.ReadFile(filepath.Join(wpRoot, "wp-config.php"))
	if err != nil {
		return cfg, err
	}
	src := string(data)

	for _, m := range defineRe.FindAllStringSubmatch(src, -1) {
		value := m[2]
		if value == "" {
			value = m[3]
		}
		value = strings.NewReplacer(`\'`, `'`, `\"`, `"`, `\\`, `\`).Replace(value)
		switch m[1] {
		case "DB_NAME":
			cfg.Name = value
		case "DB_USER":
			cfg.User = value
		case "DB_PASSWORD":
			cfg.Pass = value
		case "DB_HOST":
			applyHost(&cfg, value)
		}
	}
	if m := prefixRe.FindStringSubmatch(src); m != nil {
		cfg.Prefix = m[1] + m[2]
	}
	if cfg.Name == "" || cfg.User == "" {
		return cfg, errors.New("could not read DB_NAME / DB_USER from wp-config.php")
	}
	return cfg, nil
}

// applyHost understands WordPress's DB_HOST forms: host, host:port,
// host:/path/to/socket.
func applyHost(cfg *DBConfig, value string) {
	host, rest, found := strings.Cut(value, ":")
	if host != "" && host != "localhost" {
		cfg.Host = host
	}
	if !found {
		return
	}
	if strings.HasPrefix(rest, "/") {
		cfg.Socket = rest
	} else if port, err := strconv.Atoi(rest); err == nil {
		cfg.Port = port
	}
}

// SetTablePrefix rewrites $table_prefix in wpRoot/wp-config.php.
func SetTablePrefix(wpRoot, prefix string) error {
	p := filepath.Join(wpRoot, "wp-config.php")
	data, err := os.ReadFile(p)
	if err != nil {
		return err
	}
	if !prefixRe.Match(data) {
		return errors.New("no $table_prefix line found in wp-config.php")
	}
	out := prefixRe.ReplaceAllLiteral(data, []byte("$table_prefix = '"+prefix+"';"))
	return os.WriteFile(p, out, 0o644)
}

// LocalAppSocket finds the MySQL endpoint of a site managed by the "Local"
// app (localwp.com). Its wp-config.php says DB_HOST=localhost, which only
// works for the PHP that Local itself starts; every site has its own MySQL
// with its own socket and port, recorded in Local's sites.json.
func LocalAppSocket(wpRoot string) (socket string, port int, ok bool) {
	home, err := os.UserHomeDir()
	if err != nil {
		return "", 0, false
	}
	var base string
	switch runtime.GOOS {
	case "darwin":
		base = filepath.Join(home, "Library", "Application Support", "Local")
	case "windows":
		base = filepath.Join(os.Getenv("APPDATA"), "Local")
	default:
		base = filepath.Join(home, ".config", "Local")
	}
	data, err := os.ReadFile(filepath.Join(base, "sites.json"))
	if err != nil {
		return "", 0, false
	}
	var sites map[string]struct {
		Path     string `json:"path"`
		Services map[string]struct {
			Ports map[string][]int `json:"ports"`
		} `json:"services"`
	}
	if json.Unmarshal(data, &sites) != nil {
		return "", 0, false
	}

	abs, err := filepath.Abs(wpRoot)
	if err != nil {
		return "", 0, false
	}
	for id, site := range sites {
		sitePath := site.Path
		if strings.HasPrefix(sitePath, "~") {
			sitePath = filepath.Join(home, sitePath[1:])
		}
		if abs != sitePath && !strings.HasPrefix(abs, sitePath+string(filepath.Separator)) {
			continue
		}
		if ports := site.Services["mysql"].Ports["MYSQL"]; len(ports) > 0 {
			port = ports[0]
		}
		sock := filepath.Join(base, "run", id, "mysql", "mysqld.sock")
		if _, err := os.Stat(sock); err == nil {
			socket = sock
		}
		return socket, port, socket != "" || port != 0
	}
	return "", 0, false
}
