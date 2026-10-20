package logs

import (
	"testing"
	"time"
)

func TestShiftTSMovesAnEventByTheClockCorrection(t *testing.T) {
	m := &Manager{o: Options{Offset: func() time.Duration { return -20 * time.Minute }}}
	if got := m.shiftTS("2026-10-20T10:00:00Z"); got != "2026-10-20T09:40:00Z" {
		t.Fatal(got)
	}
	if got := m.shiftTS("garbage"); got != "garbage" {
		t.Fatal(got)
	}
	m.o.Offset = nil
	if got := m.shiftTS("2026-10-20T10:00:00Z"); got != "2026-10-20T10:00:00Z" {
		t.Fatal(got)
	}
}
