package cmd

import (
	"os"
	"testing"
)

// Every test runs against an empty, throwaway home: none may read or change the
// developer's real ~/.wpicker (their logins), and none may be steered by the
// environment they happen to run in.
func TestMain(m *testing.M) {
	home, err := os.MkdirTemp("", "wpicker-test-home-*")
	if err != nil {
		panic(err)
	}
	os.Setenv("HOME", home)
	os.Unsetenv("WPICKER_SITE")
	code := m.Run()
	os.RemoveAll(home)
	os.Exit(code)
}
