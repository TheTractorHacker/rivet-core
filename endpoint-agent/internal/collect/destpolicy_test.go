package collect

import (
	"context"
	"errors"
	"net"
	"net/http"
	"net/http/httptest"
	"net/netip"
	"strings"
	"testing"
	"time"

	"rivetit-agent/internal/store"
)

// CORE-AGENT-2 / Chain G: link-local and cloud metadata addresses are always refused; loopback and private ones only with checks_block_private.
func TestDestinationPolicyAddresses(t *testing.T) {
	for addr, want := range map[string][2]bool{ // [blocked by default, blocked strict]
		"169.254.169.254":          {true, true},
		"169.254.0.1":              {true, true},
		"::ffff:169.254.169.254":   {true, true},
		"64:ff9b::a9fe:a9fe":       {true, true},
		"fe80::1":                  {true, true},
		"fd00:ec2::254":            {true, true},
		"fd20:ce::254":             {true, true},
		"168.63.129.16":            {true, true},
		"100.100.100.200":          {true, true},
		"127.0.0.1":                {false, true},
		"::1":                      {false, true},
		"10.0.0.5":                 {false, true},
		"172.16.4.4":               {false, true},
		"192.168.1.10":             {false, true},
		"100.64.0.1":               {false, true},
		"fd12:3456::1":             {false, true},
		"0.0.0.0":                  {false, true},
		"8.8.8.8":                  {false, false},
		"2606:4700:4700::1111":     {false, false},
		"169.255.0.1":              {false, false},
		"::ffff:8.8.8.8":           {false, false},
		"64:ff9b::808:808":         {false, false},
		"fd00:ec2::253":            {false, true},
		"100.100.100.201":          {false, true},
		"168.63.129.17":            {false, false},
		"2001:db8::a9fe:a9fe":      {false, false},
		"::ffff:10.1.1.1":          {false, true},
		"169.254.169.123":          {true, true},
		"fe80::a9fe:a9fe%eth0":     {true, true},
		"127.255.255.254":          {false, true},
		"::":                       {false, true},
		"fc00::1":                  {false, true},
		"febf:ffff::1":             {true, true},
		"fec0::1":                  {false, false},
		"192.0.2.1":                {false, false},
		"198.51.100.7":             {false, false},
		"203.0.113.9":              {false, false},
		"172.32.0.1":               {false, false},
		"11.0.0.1":                 {false, false},
		"100.128.0.1":              {false, false},
		"169.254.255.255":          {true, true},
		"::ffff:a9fe:a9fe":         {true, true},
		"0:0:0:0:0:ffff:a9fe:fea9": {true, true},
	} {
		ip, err := netip.ParseAddr(addr)
		if err != nil {
			t.Fatalf("%s: %v", addr, err)
		}
		if got := blockedAddr(ip, false) != ""; got != want[0] {
			t.Errorf("%s default: blocked=%v, want %v", addr, got, want[0])
		}
		if got := blockedAddr(ip, true) != ""; got != want[1] {
			t.Errorf("%s strict: blocked=%v, want %v", addr, got, want[1])
		}
	}
}

// The dialer hook decides on the RESOLVED address of every connection (DNS names, rebinding, redirect hops); ntp's UDP is not filtered.
func TestDestinationControlHook(t *testing.T) {
	ctl := destControl(false)
	if err := ctl("tcp4", "169.254.169.254:80", nil); !errors.Is(err, ErrDestinationBlocked) {
		t.Fatalf("metadata: %v", err)
	}
	if err := ctl("tcp6", "[fd00:ec2::254]:80", nil); !errors.Is(err, ErrDestinationBlocked) {
		t.Fatalf("metadata v6: %v", err)
	}
	if err := ctl("tcp4", "127.0.0.1:8080", nil); err != nil {
		t.Fatalf("loopback refused by default: %v", err)
	}
	if err := destControl(true)("tcp4", "127.0.0.1:8080", nil); !errors.Is(err, ErrDestinationBlocked) {
		t.Fatalf("loopback with checks_block_private: %v", err)
	}
	if err := ctl("udp4", "169.254.169.123:123", nil); err != nil {
		t.Fatalf("ntp to Amazon Time Sync must keep working: %v", err)
	}
	// a real dial: refused before any packet leaves (Control runs before connect)
	d := guardedDialer(false)
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()
	if _, err := d.DialContext(ctx, "tcp", "169.254.169.254:80"); !errors.Is(err, ErrDestinationBlocked) {
		t.Fatalf("dial: %v", err)
	}
}

func liveCol(strict bool) *Collector {
	c := New(newFake(), func() store.Config { return store.Config{ChecksBlockPrivate: strict} })
	c.Timeout = 5 * time.Second
	return c
}

// End to end through the real http client: a literal metadata URL, a redirect hop to it, the redirect cap, the private switch; loopback stays allowed.
func TestHTTPCheckDestinationPolicy(t *testing.T) {
	ok := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { _, _ = w.Write([]byte("loopback fixture ok")) }))
	defer ok.Close()
	redir := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Redirect(w, r, "http://169.254.169.254/latest/meta-data/iam/security-credentials/role", http.StatusFound)
	}))
	defer redir.Close()
	var loop *httptest.Server
	loop = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { http.Redirect(w, r, loop.URL+"/again", http.StatusFound) }))
	defer loop.Close()

	c := liveCol(false)
	r := c.RunCheck(context.Background(), xspec("http", `{"url":"`+ok.URL+`/status","contains":"fixture ok"}`))
	if r.Status != OK {
		t.Fatalf("loopback must stay allowed by default: %+v", r)
	}
	for name, url := range map[string]string{
		"literal metadata":    "http://169.254.169.254/latest/meta-data/iam/security-credentials/role",
		"literal ipv6 meta":   "http://[fd00:ec2::254]/latest/meta-data/",
		"mapped metadata":     "http://[::ffff:169.254.169.254]/latest/",
		"redirect to it":      redir.URL + "/go",
		"azure wireserver":    "http://168.63.129.16/machine?comp=goalstate",
		"literal link-local6": "http://[fe80::1]/x",
	} {
		r := c.RunCheck(context.Background(), xspec("http", `{"url":"`+url+`","contains":"SecretAccessKey"}`))
		if r.Status != Unknown || !strings.Contains(r.Detail, "destination refused") {
			t.Errorf("%s: %+v", name, r)
		}
	}
	r = c.RunCheck(context.Background(), xspec("http", `{"url":"`+loop.URL+`/start"}`))
	if r.Status != Fail || !strings.Contains(r.Detail, "stopped after 5 redirects") {
		t.Errorf("redirect cap: %+v", r)
	}
	r = liveCol(true).RunCheck(context.Background(), xspec("http", `{"url":"`+ok.URL+`/status"}`))
	if r.Status != Unknown || !strings.Contains(r.Detail, "destination refused") {
		t.Errorf("checks_block_private: %+v", r)
	}
}

func TestPortAndCertHostDestinationPolicy(t *testing.T) {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Skip(err)
	}
	defer ln.Close()
	_, port, _ := net.SplitHostPort(ln.Addr().String())
	c := liveCol(false)
	if r := c.RunCheck(context.Background(), xspec("port", `{"host":"127.0.0.1","port":`+port+`}`)); r.Status != OK {
		t.Fatalf("loopback port must stay allowed: %+v", r)
	}
	for _, spec := range []string{`{"host":"169.254.169.254","port":80}`, `{"host":"fd00:ec2::254","port":80}`, `{"host":"168.63.129.16","port":32526}`} {
		if r := c.RunCheck(context.Background(), xspec("port", spec)); r.Status != Unknown || !strings.Contains(r.Detail, "destination refused") {
			t.Errorf("port %s: %+v", spec, r)
		}
	}
	if r := c.RunCheck(context.Background(), xspec("cert", `{"host":"169.254.169.254","port":443}`)); r.Status != Unknown || !strings.Contains(r.Detail, "destination refused") {
		t.Errorf("cert host: %+v", r)
	}
	if r := liveCol(true).RunCheck(context.Background(), xspec("port", `{"host":"127.0.0.1","port":`+port+`}`)); r.Status != Unknown {
		t.Errorf("checks_block_private port: %+v", r)
	}
}
