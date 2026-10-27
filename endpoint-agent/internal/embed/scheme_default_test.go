//go:build !agenttest

package embed

import (
	"errors"
	"testing"
)

func TestHTTPLoopbackRefusedInReleaseBuilds(t *testing.T) {
	err := rawCase(t, func(p *Payload) { p.ServerURL = "http://127.0.0.1:8080" })
	if !errors.Is(err, ErrInvalid) {
		t.Fatalf("got %v", err)
	}
}
