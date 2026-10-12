package cmd

import (
	"fmt"
	"testing"
)

func TestParseSelection(t *testing.T) {
	cases := []struct {
		in, want string
	}{
		{"1", "[0]"},
		{"1,3", "[0 2]"},
		{"2-4", "[1 2 3]"},
		{"3 1-2, 3", "[2 0 1]"},
	}
	for _, c := range cases {
		got, err := parseSelection(c.in, 5)
		if err != nil || fmt.Sprint(got) != c.want {
			t.Errorf("parseSelection(%q) = %v, %v; want %s", c.in, got, err, c.want)
		}
	}
	for _, bad := range []string{"0", "6", "4-2", "x", "1-", ""} {
		if got, err := parseSelection(bad, 5); err == nil {
			t.Errorf("parseSelection(%q) = %v, want error", bad, got)
		}
	}
}
