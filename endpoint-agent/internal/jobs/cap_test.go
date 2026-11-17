package jobs

import (
	"strings"
	"testing"
)

// CORE-DS-22: capping output must only repair a rune split at the cut, not discard everything after the first invalid byte earlier in the text.
func TestCapUTF8KeepsTextAfterAnInvalidByte(t *testing.T) {
	in := "line1 caf\xe9\n" + strings.Repeat("A", 300)
	got := capUTF8(in, 200)
	if len(got) != 200 {
		t.Fatalf("kept %d bytes of 200", len(got))
	}
	if !strings.HasPrefix(got, "line1 caf\xe9\n") {
		t.Fatalf("lost the head: %q", got[:20])
	}
}

func TestCapUTF8DoesNotSplitARune(t *testing.T) {
	in := strings.Repeat("a", 198) + "€€" // 198 + 3 + 3 bytes
	got := capUTF8(in, 200)               // cut falls inside the first euro sign
	if got != strings.Repeat("a", 198) {
		t.Fatalf("%q", got)
	}
	if got := capUTF8("short", 200); got != "short" {
		t.Fatal(got)
	}
	if got := capUTF8(strings.Repeat("€", 10), 9); got != "€€€" {
		t.Fatalf("%q", got)
	}
}
