//go:build windows

package config

// lockFile is a no-op on Windows: releases are built for macOS and Linux only.
func lockFile(string) (func(), error) { return func() {}, nil }
