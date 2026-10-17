// Package config persists the CLI's per-device credentials and site settings.
//
// Storage: ~/.wpicker/config.json (mode 0600). The file holds the site URL,
// admin username, and a WordPress Application Password (never the user's main
// password). A small state file (.wpicker/last-pull.hash in the working dir)
// tracks pull/push drift and is separate from this config.
package config

import (
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
)

// File name and directory under the user's home.
const (
	DirName  = ".wpicker"
	FileName = "config.json"
)

// Config is the on-disk CLI configuration.
// Config is the single site a command works with: the resolved view of one
// Site in the store. It is also the shape of the original ~/.wpicker/config.json,
// which is still READ (to import an older login) but never written any more.
type Config struct {
	ID          int    `json:"-"`            // id in sites.json (0 for an unsaved view)
	Name        string `json:"-"`            // short name in sites.json
	Site        string `json:"site"`         // e.g. https://example.com
	Username    string `json:"username"`     // WP admin username
	AppPassword string `json:"app_password"` // WordPress Application Password
	DeviceID    string `json:"device_id"`    // app-password uuid
	DeviceName  string `json:"device_name"`  // human label
}

// Dir returns the absolute path to ~/.wpicker, creating it if missing.
func Dir() (string, error) {
	home, err := os.UserHomeDir()
	if err != nil {
		return "", err
	}
	d := filepath.Join(home, DirName)
	if err := os.MkdirAll(d, 0o700); err != nil {
		return "", err
	}
	return d, nil
}

// Path returns the absolute path to the config file.
func Path() (string, error) {
	d, err := Dir()
	if err != nil {
		return "", err
	}
	return filepath.Join(d, FileName), nil
}

// Load reads the config from disk. Returns a zero Config if the file is absent.
func Load() (*Config, error) {
	p, err := Path()
	if err != nil {
		return nil, err
	}
	data, err := os.ReadFile(p)
	if err != nil {
		if errors.Is(err, os.ErrNotExist) {
			return &Config{}, nil
		}
		return nil, err
	}
	// Restrictive permissions if the file somehow widened.
	_ = os.Chmod(p, 0o600)

	var c Config
	if err := json.Unmarshal(data, &c); err != nil {
		return nil, fmt.Errorf("parse %s: %w", p, err)
	}
	return &c, nil
}

// Save writes the config to disk with mode 0600.
func (c *Config) IsLoggedIn() bool {
	return c.Site != "" && c.Username != "" && c.AppPassword != ""
}

// AssertLoggedIn returns an error if the config is incomplete.
func (c *Config) AssertLoggedIn() error {
	if c.Site == "" {
		return errors.New("not logged in: run `wpicker login` first")
	}
	if c.Username == "" || c.AppPassword == "" {
		return errors.New("config incomplete: run `wpicker login` again")
	}
	return nil
}

// Clear removes the config file (used by `wpicker logout`).
