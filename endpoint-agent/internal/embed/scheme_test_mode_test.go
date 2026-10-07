//go:build agenttest

package embed

import (
	"errors"
	"testing"
)

func TestHTTPOnlyOnLoopbackInTestBuilds(t *testing.T) {
	if err := rawCase(t, func(p *Payload) { p.ServerURL = "http://127.0.0.1:8080" }); err != nil {
		t.Fatalf("loopback http: %v", err)
	}
	if err := rawCase(t, func(p *Payload) { p.ServerURL = "http://rivet.example.com" }); !errors.Is(err, ErrInvalid) {
		t.Fatalf("remote http: %v", err)
	}
}
