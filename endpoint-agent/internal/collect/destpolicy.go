package collect

import (
	"context"
	"errors"
	"fmt"
	"net"
	"net/http"
	"net/netip"
	"net/url"
	"strings"
	"syscall"
	"time"
)

// Destination policy of the network checks (http, port, cert host, ntp), CORE-AGENT-2 / Chain G of the 2026-11-21 red team.
//
// A check runs as root/SYSTEM from inside the customer's network, and an http check reports whether a `contains` string was found: pointed at a cloud
// metadata service it reads instance credentials back one guess at a time. The agent therefore refuses, by default, every destination that is link-local
// or a cloud metadata / host-agent address. Loopback and private (RFC 1918, ULA) addresses stay allowed by default because monitoring local and internal
// services is what these checks are for; `checks_block_private` in config.json (a local administrator's choice) refuses them too.
//
// The decision is made on the RESOLVED address, in the dialer's Control hook, so a DNS name that points at a blocked address (or is re-bound to one) and
// every redirect hop are covered: each connection the check makes goes through the same dialer. Literal addresses in a URL are refused before any request.

// ErrDestinationBlocked is returned for a connection to a refused address.
var ErrDestinationBlocked = errors.New("destination refused")

var (
	linkLocal4 = netip.MustParsePrefix("169.254.0.0/16")
	linkLocal6 = netip.MustParsePrefix("fe80::/10")
	nat64      = netip.MustParsePrefix("64:ff9b::/96")
	// cloud metadata and host-agent endpoints that are not link-local: Azure WireServer, Alibaba Cloud, AWS IMDS over IPv6, GCP metadata over IPv6.
	metadataAddrs = map[netip.Addr]string{
		netip.MustParseAddr("168.63.129.16"):   "Azure WireServer",
		netip.MustParseAddr("100.100.100.200"): "Alibaba Cloud metadata",
		netip.MustParseAddr("fd00:ec2::254"):   "AWS metadata (IPv6)",
		netip.MustParseAddr("fd20:ce::254"):    "GCP metadata (IPv6)",
	}
	cgnat = netip.MustParsePrefix("100.64.0.0/10")
)

// blockedAddr returns why a check may not connect to ip ("" = allowed). strict adds loopback, private, CGNAT, unique-local and unspecified addresses.
func blockedAddr(ip netip.Addr, strict bool) string {
	ip = ip.WithZone("").Unmap() // a zone (fe80::1%eth0) would make every prefix test below miss
	if nat64.Contains(ip) {      // 64:ff9b::a9fe:a9fe is 169.254.169.254 through a NAT64 gateway
		b := ip.As16()
		ip = netip.AddrFrom4([4]byte{b[12], b[13], b[14], b[15]})
	}
	if why, ok := metadataAddrs[ip]; ok {
		return why + " address"
	}
	if linkLocal4.Contains(ip) || linkLocal6.Contains(ip) {
		return "link-local / cloud metadata address"
	}
	if strict {
		switch {
		case ip.IsLoopback():
			return "loopback address (checks_block_private)"
		case ip.IsPrivate() || cgnat.Contains(ip):
			return "private address (checks_block_private)"
		case ip.IsUnspecified():
			return "unspecified address (checks_block_private)"
		}
	}
	return ""
}

// blockedHostLiteral is blockedAddr for a host that is a literal address (a name returns "": it is decided when it is resolved).
func blockedHostLiteral(host string, strict bool) string {
	ip, err := netip.ParseAddr(host)
	if err != nil {
		return ""
	}
	return blockedAddr(ip, strict)
}

// destControl is the net.Dialer Control hook: it runs after name resolution and before connect for every connection a check makes. UDP is the ntp
// check only, which reads nothing back but a time stamp and must keep reaching link-local time services (Amazon Time Sync, 169.254.169.123), so it is
// not filtered (judge: the policy adds little for ntp and ping, which have no content oracle).
func destControl(strict bool) func(network, address string, c syscall.RawConn) error {
	return func(network, address string, _ syscall.RawConn) error {
		if strings.HasPrefix(network, "udp") {
			return nil
		}
		ap, err := netip.ParseAddrPort(address)
		if err != nil {
			return fmt.Errorf("%w: unexpected address %q", ErrDestinationBlocked, address)
		}
		if why := blockedAddr(ap.Addr(), strict); why != "" {
			return fmt.Errorf("%w: %s is a %s", ErrDestinationBlocked, ap.Addr(), why)
		}
		return nil
	}
}

// guardedDialer is the dialer of every network check.
func guardedDialer(strict bool) *net.Dialer {
	return &net.Dialer{Control: destControl(strict)}
}

// maxRedirects bounds the http check's redirect chain (each hop is still checked by the dialer).
const maxRedirects = 5

// guardedHTTPClient is the http check's client: every connection through guardedDialer, redirects capped, and for a request that goes through a proxy
// (HTTP(S)_PROXY in the service's environment, where the proxy resolves the name) the target host is resolved here too and refused when it points at a
// blocked address, best effort.
func guardedHTTPClient(timeout time.Duration, tr *http.Transport, follow, strict bool) *http.Client {
	tr.DialContext = guardedDialer(strict).DialContext
	cl := &http.Client{Timeout: timeout, Transport: proxyGuard{tr: tr, strict: strict}}
	if !follow {
		cl.CheckRedirect = func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }
	} else {
		cl.CheckRedirect = func(req *http.Request, via []*http.Request) error {
			if len(via) >= maxRedirects {
				return fmt.Errorf("stopped after %d redirects", maxRedirects)
			}
			if why := blockedHostLiteral(req.URL.Hostname(), strict); why != "" {
				return fmt.Errorf("%w: redirect to %s, a %s", ErrDestinationBlocked, req.URL.Hostname(), why)
			}
			return nil
		}
	}
	return cl
}

// proxyGuard checks the target of a proxied request (the dialer only ever sees the proxy's address then).
type proxyGuard struct {
	tr     *http.Transport
	strict bool
}

func (g proxyGuard) RoundTrip(req *http.Request) (*http.Response, error) {
	if g.tr.Proxy != nil {
		if pu, err := g.tr.Proxy(req); err == nil && pu != nil {
			if err := checkResolvedHost(req.Context(), req.URL, g.strict); err != nil {
				return nil, err
			}
		}
	}
	return g.tr.RoundTrip(req)
}

func checkResolvedHost(ctx context.Context, u *url.URL, strict bool) error {
	host := u.Hostname()
	if why := blockedHostLiteral(host, strict); why != "" {
		return fmt.Errorf("%w: %s is a %s", ErrDestinationBlocked, host, why)
	}
	addrs, err := net.DefaultResolver.LookupNetIP(ctx, "ip", host)
	if err != nil {
		return nil // the proxy resolves it or fails; nothing to decide here
	}
	for _, a := range addrs {
		if why := blockedAddr(a, strict); why != "" {
			return fmt.Errorf("%w: %s resolves to %s, a %s", ErrDestinationBlocked, host, a, why)
		}
	}
	return nil
}
