//go:build agenttest

package embed

import "net/url"

// httpAllowed: test builds accept plain http to a loopback scratch server only.
func httpAllowed(u *url.URL) bool {
	h := u.Hostname()
	return h == "127.0.0.1" || h == "localhost" || h == "::1"
}
