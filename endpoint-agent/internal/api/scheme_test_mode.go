//go:build agenttest

package api

import (
	"errors"
	"net/url"
)

// Test builds only: plain http to a loopback scratch server is allowed.
func checkScheme(u *url.URL) error {
	if u.Scheme == "https" {
		return nil
	}
	h := u.Hostname()
	if u.Scheme == "http" && (h == "127.0.0.1" || h == "localhost" || h == "::1") {
		return nil
	}
	return errors.New("server URL must use https (agenttest build allows http only on loopback)")
}
