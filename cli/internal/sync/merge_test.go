package sync

import (
	"strings"
	"testing"
)

func lines(s ...string) []byte { return []byte(strings.Join(s, "\n") + "\n") }

func TestMerge3(t *testing.T) {
	base := lines("a", "b", "c", "d", "e", "f", "g", "h")

	cases := []struct {
		name          string
		ours, theirs  []byte
		want          []byte
		wantConflicts int
	}{
		{
			name:   "edits to different lines are both kept",
			ours:   lines("a", "B-ours", "c", "d", "e", "f", "g", "h"),
			theirs: lines("a", "b", "c", "d", "e", "f", "G-theirs", "h"),
			want:   lines("a", "B-ours", "c", "d", "e", "f", "G-theirs", "h"),
		},
		{
			name:   "one side adds at the top, the other at the bottom",
			ours:   lines("top", "a", "b", "c", "d", "e", "f", "g", "h"),
			theirs: lines("a", "b", "c", "d", "e", "f", "g", "h", "bottom"),
			want:   lines("top", "a", "b", "c", "d", "e", "f", "g", "h", "bottom"),
		},
		{
			name:   "one side deletes, the other edits elsewhere",
			ours:   lines("a", "c", "d", "e", "f", "g", "h"),
			theirs: lines("a", "b", "c", "d", "e", "F2", "g", "h"),
			want:   lines("a", "c", "d", "e", "F2", "g", "h"),
		},
		{
			name:   "the same edit on both sides is not a conflict",
			ours:   lines("a", "b", "SAME", "d", "e", "f", "g", "h"),
			theirs: lines("a", "b", "SAME", "d", "e", "f", "g", "h"),
			want:   lines("a", "b", "SAME", "d", "e", "f", "g", "h"),
		},
		{
			name:   "only they changed",
			ours:   base,
			theirs: lines("a", "b", "c", "X", "e", "f", "g", "h"),
			want:   lines("a", "b", "c", "X", "e", "f", "g", "h"),
		},
		{
			name:   "the same line edited differently is a conflict, the rest still merges",
			ours:   lines("a", "b", "c", "red", "e", "f", "g", "H-ours"),
			theirs: lines("A-theirs", "b", "c", "blue", "e", "f", "g", "h"),
			want: lines("A-theirs", "b", "c",
				"<<<<<<< yours", "red", "=======", "blue", ">>>>>>> site",
				"e", "f", "g", "H-ours"),
			wantConflicts: 1,
		},
		{
			name:   "one side deletes a line the other edited",
			ours:   lines("a", "b", "c", "e", "f", "g", "h"),
			theirs: lines("a", "b", "c", "D2", "e", "f", "g", "h"),
			want: lines("a", "b", "c",
				"<<<<<<< yours", "=======", "D2", ">>>>>>> site",
				"e", "f", "g", "h"),
			wantConflicts: 1,
		},
	}
	for _, c := range cases {
		got := Merge3(base, c.ours, c.theirs, "yours", "site")
		if string(got.Merged) != string(c.want) || got.Conflicts != c.wantConflicts {
			t.Errorf("%s\n got (%d conflicts):\n%s\nwant (%d):\n%s", c.name, got.Conflicts, got.Merged, c.wantConflicts, c.want)
		}
		if HasConflictMarkers(got.Merged) != (c.wantConflicts > 0) {
			t.Errorf("%s: HasConflictMarkers = %v", c.name, HasConflictMarkers(got.Merged))
		}
	}
}

func TestMerge3_edges(t *testing.T) {
	// No common ancestor (both sides created the file): differing content
	// conflicts as a whole, identical content does not.
	got := Merge3(nil, lines("mine"), lines("theirs"), "yours", "site")
	if got.Conflicts != 1 || string(got.Merged) != "<<<<<<< yours\nmine\n=======\ntheirs\n>>>>>>> site\n" {
		t.Errorf("no base: %d conflicts\n%s", got.Conflicts, got.Merged)
	}
	if got := Merge3(nil, lines("x"), lines("x"), "yours", "site"); got.Conflicts != 0 || string(got.Merged) != "x\n" {
		t.Errorf("no base, same content: %q", got.Merged)
	}

	// A missing final newline survives, and never glues a marker to a line.
	base := []byte("a\nb\nc")
	got = Merge3(base, []byte("a\nb\nc-ours"), []byte("a\nb\nc-theirs"), "yours", "site")
	want := "a\nb\n<<<<<<< yours\nc-ours\n=======\nc-theirs\n>>>>>>> site\n"
	if string(got.Merged) != want {
		t.Errorf("no trailing newline:\n got %q\nwant %q", got.Merged, want)
	}
	if got := Merge3(base, []byte("A\nb\nc"), base, "yours", "site"); string(got.Merged) != "A\nb\nc" {
		t.Errorf("clean merge must keep the missing final newline: %q", got.Merged)
	}

	// CRLF files keep their line endings.
	crlf := []byte("a\r\nb\r\nc\r\n")
	if got := Merge3(crlf, []byte("A\r\nb\r\nc\r\n"), []byte("a\r\nb\r\nC\r\n"), "yours", "site"); string(got.Merged) != "A\r\nb\r\nC\r\n" {
		t.Errorf("crlf: %q", got.Merged)
	}

	// Merging is symmetric for clean results and never loses a line.
	ours, theirs := lines("1", "a", "b", "c"), lines("a", "b", "c", "9")
	x := Merge3(lines("a", "b", "c"), ours, theirs, "yours", "site")
	y := Merge3(lines("a", "b", "c"), theirs, ours, "yours", "site")
	if string(x.Merged) != string(y.Merged) || string(x.Merged) != "1\na\nb\nc\n9\n" {
		t.Errorf("asymmetric: %q vs %q", x.Merged, y.Merged)
	}
}

func TestHasConflictMarkersAndIsText(t *testing.T) {
	if HasConflictMarkers([]byte("a\n=======\nb\n")) {
		t.Error("a lone separator (e.g. a Markdown heading underline) is not a conflict")
	}
	if !HasConflictMarkers([]byte("<<<<<<< yours\na\n=======\nb\n>>>>>>> site\n")) {
		t.Error("a full block must be detected")
	}
	if IsText([]byte{0x89, 'P', 'N', 'G', 0, 0xff}) || !IsText([]byte("é ☕\n")) {
		t.Error("IsText")
	}
}

func TestMatchLines_largeAndVeryDifferent(t *testing.T) {
	var a, b []string
	for i := 0; i < 5000; i++ {
		a = append(a, "line "+string(rune('a'+i%26))+"\n")
		b = append(b, "other "+string(rune('a'+i%26))+"\n")
	}
	m := matchLines(a, b) // must return, not exhaust memory
	for _, j := range m {
		if j != -1 {
			t.Fatal("nothing in these files matches")
		}
	}
}
