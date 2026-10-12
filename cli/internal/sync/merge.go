package sync

import (
	"bytes"
	"strings"
	"unicode/utf8"
)

// Conflict markers, the same ones git writes, so editors (VS Code, Cursor,
// JetBrains) offer their "accept current / incoming / both" controls and an
// AI agent recognises them.
const (
	markerOurs   = "<<<<<<< "
	markerSplit  = "======="
	markerTheirs = ">>>>>>> "
)

// MergeResult is the outcome of a three-way merge.
type MergeResult struct {
	Merged    []byte
	Conflicts int // regions both sides changed differently; marked in Merged
}

// Merge3 combines two edited versions of a text file that share a common
// ancestor, line by line — like `git merge-file`.
//
// A region only one side changed is taken from that side. A region both
// sides changed the same way is taken once. A region both changed
// differently is a conflict: both versions are written between markers
// labelled oursLabel / theirsLabel, for a human or an agent to settle.
func Merge3(base, ours, theirs []byte, oursLabel, theirsLabel string) MergeResult {
	b, o, t := splitLines(base), splitLines(ours), splitLines(theirs)
	matchO := matchLines(b, o)
	matchT := matchLines(b, t)

	var out bytes.Buffer
	conflicts := 0
	ib, io, it := 0, 0, 0

	emit := func(lines []string) {
		for _, l := range lines {
			out.WriteString(l)
		}
	}
	// A conflict block must start and end on its own line even when the last
	// line of a version has no newline.
	emitBlock := func(lines []string) {
		emit(lines)
		if n := len(lines); n > 0 && !strings.HasSuffix(lines[n-1], "\n") {
			out.WriteByte('\n')
		}
	}

	for ib < len(b) || io < len(o) || it < len(t) {
		// Stable run: the next base lines are unchanged on both sides.
		run := 0
		for ib+run < len(b) && matchO[ib+run] == io+run && matchT[ib+run] == it+run {
			run++
		}
		if run > 0 {
			emit(o[io : io+run])
			ib, io, it = ib+run, io+run, it+run
			continue
		}

		// Unstable chunk: runs up to the next base line that both sides kept.
		nb, no, nt := len(b), len(o), len(t)
		for j := ib; j < len(b); j++ {
			if matchO[j] >= io && matchT[j] >= it {
				nb, no, nt = j, matchO[j], matchT[j]
				break
			}
		}
		baseChunk, oursChunk, theirsChunk := b[ib:nb], o[io:no], t[it:nt]
		switch {
		case equalLines(oursChunk, theirsChunk):
			emit(oursChunk) // both made the same change
		case equalLines(oursChunk, baseChunk):
			emit(theirsChunk) // only they changed it
		case equalLines(theirsChunk, baseChunk):
			emit(oursChunk) // only we changed it
		default:
			conflicts++
			out.WriteString(markerOurs + oursLabel + "\n")
			emitBlock(oursChunk)
			out.WriteString(markerSplit + "\n")
			emitBlock(theirsChunk)
			out.WriteString(markerTheirs + theirsLabel + "\n")
		}
		ib, io, it = nb, no, nt
	}
	return MergeResult{Merged: out.Bytes(), Conflicts: conflicts}
}

// HasConflictMarkers reports whether data still contains an unresolved
// conflict block written by Merge3 (or by git).
func HasConflictMarkers(data []byte) bool {
	open := false
	for _, line := range bytes.Split(data, []byte("\n")) {
		switch {
		case bytes.HasPrefix(line, []byte(markerOurs)):
			open = true
		case open && bytes.HasPrefix(line, []byte(markerTheirs)):
			return true
		}
	}
	return false
}

// IsText reports whether data can be merged line by line: valid UTF-8
// without NUL bytes.
func IsText(data []byte) bool {
	return utf8.Valid(data) && bytes.IndexByte(data, 0) < 0
}

// splitLines splits after each newline, keeping it, so joining the pieces
// gives back the input exactly — including a missing final newline.
func splitLines(data []byte) []string {
	if len(data) == 0 {
		return nil
	}
	lines := strings.SplitAfter(string(data), "\n")
	if lines[len(lines)-1] == "" {
		lines = lines[:len(lines)-1] // data ended with a newline
	}
	return lines
}

func equalLines(a, b []string) bool {
	if len(a) != len(b) {
		return false
	}
	for i := range a {
		if a[i] != b[i] {
			return false
		}
	}
	return true
}

// maxEditDistance bounds the diff's work and memory. Two versions further
// apart than this are treated as wholly different between their common
// start and end — a merge of such files is a conflict anyway.
const maxEditDistance = 3000

// matchLines returns, for each line of a, the index of the line of b it
// corresponds to in a longest common subsequence, or -1. Myers' algorithm.
func matchLines(a, b []string) []int {
	match := make([]int, len(a))
	for i := range match {
		match[i] = -1
	}

	// Common start and end need no searching.
	start := 0
	for start < len(a) && start < len(b) && a[start] == b[start] {
		match[start] = start
		start++
	}
	endA, endB := len(a), len(b)
	for endA > start && endB > start && a[endA-1] == b[endB-1] {
		endA--
		endB--
		match[endA] = endB
	}
	x, y := a[start:endA], b[start:endB]
	n, m := len(x), len(y)
	if n == 0 || m == 0 {
		return match
	}

	limit := n + m
	if limit > maxEditDistance {
		limit = maxEditDistance
	}
	// trace[d] holds the furthest-reaching x for each diagonal k in [-d, d].
	trace := make([][]int, 0, 64)
	v := []int{0}
	found := -1
	for d := 0; d <= limit && found < 0; d++ {
		next := make([]int, 2*d+1)
		for k := -d; k <= d; k += 2 {
			var px int
			switch {
			case d == 0:
				px = 0
			case k == -d || (k != d && at(v, d-1, k-1) < at(v, d-1, k+1)):
				px = at(v, d-1, k+1) // step down: a line of b inserted
			default:
				px = at(v, d-1, k-1) + 1 // step right: a line of a deleted
			}
			py := px - k
			for px < n && py < m && x[px] == y[py] {
				px++
				py++
			}
			next[k+d] = px
			if px >= n && py >= m {
				found = d
			}
		}
		trace = append(trace, next)
		v = next
	}
	if found < 0 {
		return match // too different: only the common start and end match
	}

	// Walk back from the end, recording the diagonal runs (matched lines).
	px, py := n, m
	for d := found; d > 0; d-- {
		k := px - py
		prev := trace[d-1]
		var pk int
		if k == -d || (k != d && at(prev, d-1, k-1) < at(prev, d-1, k+1)) {
			pk = k + 1
		} else {
			pk = k - 1
		}
		prevX := at(prev, d-1, pk)
		prevY := prevX - pk
		for px > prevX && py > prevY {
			px--
			py--
			match[start+px] = start + py
		}
		px, py = prevX, prevY
	}
	for px > 0 && py > 0 { // the d = 0 run from the origin
		px--
		py--
		match[start+px] = start + py
	}
	return match
}

// at reads diagonal k from a row computed at edit distance d; diagonals
// outside [-d, d] have not been reached.
func at(row []int, d, k int) int {
	if k < -d || k > d {
		return -1
	}
	return row[k+d]
}
