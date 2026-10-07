// Command rmm-sim is the load simulator of the RMM module (scaling item S1, design 13.7). It enrolls N fake devices with one multi-use
// enrollment token and checks them in at a configured interval with realistic protocol payloads, then reports request rate, latency
// percentiles and status codes. It speaks the real device protocol (docs/rmm/PROTOCOL.md) and nothing else: no private endpoints.
//
//	rmm-sim -url http://127.0.0.1:8700/api/v1/ -token rvte1.... -devices 500 -interval 300s -duration 10m -concurrency 64 -insecure
//
// SAFETY. Run it only against a scratch server. Plain http (and TLS without certificate verification) is accepted ONLY when -insecure is
// given AND the target host is a loopback address; against any other host the simulator refuses to start. The enrollment token must allow
// at least -devices uses. It creates real devices and check-in rows on the server it points at.
package main

import (
	"context"
	"flag"
	"fmt"
	"net/netip"
	"net/url"
	"os"
	"os/signal"
	"strings"
	"time"
)

type config struct {
	URL            string
	Token          string
	Devices        int
	Interval       time.Duration
	Duration       time.Duration
	Concurrency    int
	Insecure       bool
	Herd           bool
	Batches        int
	Report         time.Duration
	Seed           uint64
	Timeout        time.Duration
	RetryScale     float64
	IPHeader       string
	JSON           bool
	EnrollOnly     bool
	DisabledFloor  time.Duration
	TokenUses      int
	FollowInterval bool
}

func parseFlags(args []string, out *flag.FlagSet) (config, error) {
	var c config
	out.StringVar(&c.URL, "url", "", "base URL of the device API, e.g. http://127.0.0.1:8700/api/v1/ (endpoints agent_enroll and agent_checkin are appended)")
	out.StringVar(&c.Token, "token", "", "multi-use enrollment token (max_uses >= devices); several comma-separated tokens for more than 5,000 devices; also read from RMM_SIM_TOKEN")
	out.IntVar(&c.Devices, "devices", 100, "number of simulated devices")
	out.DurationVar(&c.Interval, "interval", 300*time.Second, "check-in interval per device (shorten it to accelerate a run)")
	out.DurationVar(&c.Duration, "duration", 2*time.Minute, "how long to run after enrollment")
	out.IntVar(&c.Concurrency, "concurrency", 32, "maximum requests in flight")
	out.BoolVar(&c.Insecure, "insecure", false, "allow plain http and unverified TLS, ONLY for loopback test servers (refused for any other host)")
	out.BoolVar(&c.Herd, "herd", false, "thundering herd: every device's first check-in falls in the first 10 s instead of spread over one interval")
	out.IntVar(&c.Batches, "batches", 4, "buffered sample batches per check-in (the current sample is one more: the default 4 gives the 5 of the defaults profile)")
	out.DurationVar(&c.Report, "report", 10*time.Second, "progress line period")
	out.Uint64Var(&c.Seed, "seed", 0, "random seed (0 = from the clock)")
	out.DurationVar(&c.Timeout, "timeout", 30*time.Second, "per-request timeout")
	out.Float64Var(&c.RetryScale, "retry-scale", 1, "multiply server Retry-After and the retry backoff by this (use < 1 when the interval is accelerated)")
	out.StringVar(&c.IPHeader, "client-ip-header", "", "send a distinct fake client address in this header per device (only for a test server that honours it; the enrollment limiter counts per IP)")
	out.BoolVar(&c.JSON, "json", false, "print the final summary as one JSON line")
	out.BoolVar(&c.EnrollOnly, "enroll-only", false, "enroll the devices and exit")
	out.DurationVar(&c.DisabledFloor, "disabled-floor", 15*time.Minute, "minimum wait after module_disabled (scaled by -retry-scale)")
	out.IntVar(&c.TokenUses, "token-uses", 5000, "devices enrolled per token when -token lists several comma-separated tokens (the server caps a token at 5,000 uses)")
	out.BoolVar(&c.FollowInterval, "follow-server-interval", false, "obey next_check_in_s of the server instead of -interval (the real agent does)")
	if err := out.Parse(args); err != nil {
		return c, err
	}
	if c.Token == "" {
		c.Token = os.Getenv("RMM_SIM_TOKEN")
	}
	return c, c.validate()
}

func (c *config) validate() error {
	if c.URL == "" {
		return fmt.Errorf("-url is required")
	}
	if c.Token == "" {
		return fmt.Errorf("-token is required (or RMM_SIM_TOKEN)")
	}
	if c.Devices < 1 || c.Devices > 200000 {
		return fmt.Errorf("-devices must be 1..200000")
	}
	if c.Interval < 500*time.Millisecond {
		return fmt.Errorf("-interval must be at least 500ms")
	}
	if c.Concurrency < 1 || c.Concurrency > 4096 {
		return fmt.Errorf("-concurrency must be 1..4096")
	}
	if c.TokenUses < 1 {
		return fmt.Errorf("-token-uses must be at least 1")
	}
	if need := (c.Devices + c.TokenUses - 1) / c.TokenUses; len(c.tokens()) < need {
		return fmt.Errorf("%d devices at %d per token need %d comma-separated tokens, got %d", c.Devices, c.TokenUses, need, len(c.tokens()))
	}
	if c.Batches < 0 || c.Batches > 99 {
		return fmt.Errorf("-batches must be 0..99")
	}
	if c.RetryScale <= 0 {
		return fmt.Errorf("-retry-scale must be positive")
	}
	return checkTarget(c.URL, c.Insecure)
}

// tokens is the comma-separated -token list.
func (c *config) tokens() []string {
	var out []string
	for _, t := range strings.Split(c.Token, ",") {
		if t = strings.TrimSpace(t); t != "" {
			out = append(out, t)
		}
	}
	return out
}

// tokenFor is the enrollment token device n (1-based) uses.
func (c *config) tokenFor(n int) string {
	t := c.tokens()
	uses := c.TokenUses
	if uses < 1 {
		uses = 5000
	}
	i := (n - 1) / uses
	if i >= len(t) {
		i = len(t) - 1
	}
	return t[i]
}

// checkTarget enforces the safety rule: an https URL is always fine; plain http is fine only with -insecure and a loopback host.
func checkTarget(raw string, insecure bool) error {
	u, err := url.Parse(raw)
	if err != nil || u.Host == "" {
		return fmt.Errorf("-url %q is not a valid URL", raw)
	}
	switch u.Scheme {
	case "https":
		if insecure && !isLoopback(u.Hostname()) {
			return fmt.Errorf("-insecure is only allowed for loopback hosts, not %q", u.Hostname())
		}
		return nil
	case "http":
		if !insecure {
			return fmt.Errorf("plain http needs -insecure (and a loopback host); use https")
		}
		if !isLoopback(u.Hostname()) {
			return fmt.Errorf("-insecure is only allowed for loopback hosts, not %q: refusing to send plain http there", u.Hostname())
		}
		return nil
	}
	return fmt.Errorf("-url must be http or https")
}

func isLoopback(host string) bool {
	if strings.EqualFold(host, "localhost") {
		return true
	}
	a, err := netip.ParseAddr(host)
	return err == nil && a.IsLoopback()
}

func main() {
	fs := flag.NewFlagSet("rmm-sim", flag.ExitOnError)
	c, err := parseFlags(os.Args[1:], fs)
	if err != nil {
		fmt.Fprintln(os.Stderr, "rmm-sim:", err)
		os.Exit(2)
	}
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt)
	defer stop()
	s := newSim(c, os.Stdout)
	if err := s.run(ctx); err != nil {
		fmt.Fprintln(os.Stderr, "rmm-sim:", err)
		os.Exit(1)
	}
}
