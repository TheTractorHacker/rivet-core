//go:build !agenttest

package embed

import "net/url"

// httpAllowed reports whether a plain-http server URL is acceptable. Release
// builds never allow it.
func httpAllowed(*url.URL) bool { return false }
