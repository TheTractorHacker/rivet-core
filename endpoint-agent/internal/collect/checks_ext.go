package collect

import (
	"bytes"
	"context"
	"crypto/tls"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"os"
	"os/exec"
	"regexp"
	"strconv"
	"strings"
	"time"

	"rivetit-agent/internal/api"
	"rivetit-agent/internal/store"
)

// The extended check types (RMM Phase 3). Every one reports a number in api.CheckResult.Value where it measures one (latency in ms,
// percent, days, a count) so the server's threshold tiers can apply to it; the agent still derives its own ok/warn/fail from the
// params, with safe defaults, so a check works without any server-side threshold.
//
//	cpu, memory, process, port, ping, http, cert, ntp, smart       Windows and Linux
//	av                                                             Windows (Microsoft Defender)
//	systemd_failed, inodes                                         Linux

// ExtendedTypes lists the extended check types that exist on a platform (GOOS).
func ExtendedTypes(goos string) []string {
	out := []string{"cert", "cpu", "http", "memory", "ntp", "ping", "port", "process", "smart"}
	switch goos {
	case "windows":
		out = append(out, "av")
	case "linux":
		out = append(out, "inodes", "systemd_failed")
	}
	return out
}

// Sys is the operating-system access of the extended checks. Production code uses DefaultSys; tests replace the fields with fixtures.
type Sys struct {
	GOOS string
	// Exec runs a program with a hard timeout and returns its standard output (capped), its exit code and an error when it could not be
	// started or timed out. A non-zero exit is NOT an error: the caller decides.
	Exec     func(ctx context.Context, timeout time.Duration, name string, args ...string) (stdout string, exit int, err error)
	ReadFile func(path string) ([]byte, error)
	ReadDir  func(path string) ([]os.DirEntry, error)
	Dial     func(ctx context.Context, network, addr string) (net.Conn, error)
	// Statfs returns the inode totals of a mounted filesystem (Linux).
	Statfs func(path string) (inodes, inodesFree uint64, err error)
	// Client builds the HTTP client of the http check.
	Client func(timeout time.Duration, verifyTLS bool, follow bool) *http.Client
	Sleep  func(ctx context.Context, d time.Duration)
	Now    func() time.Time
}

func (s *Sys) now() time.Time {
	if s.Now != nil {
		return s.Now()
	}
	return time.Now()
}

func (s *Sys) sleep(ctx context.Context, d time.Duration) {
	if s.Sleep != nil {
		s.Sleep(ctx, d)
		return
	}
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
	case <-t.C:
	}
}

func (c *Collector) sys() *Sys {
	if c.X != nil {
		return c.X
	}
	return DefaultSys()
}

// DefaultSys is the real operating system.
func DefaultSys() *Sys {
	return &Sys{
		GOOS:     goos(),
		Exec:     execCapped,
		ReadFile: readFileCapped,
		ReadDir:  os.ReadDir,
		Dial: func(ctx context.Context, network, addr string) (net.Conn, error) {
			var d net.Dialer
			return d.DialContext(ctx, network, addr)
		},
		Statfs: statfsInodes,
		Client: defaultHTTPClient,
	}
}

const maxExecOutput = 256 << 10

type capBuf struct {
	b   bytes.Buffer
	max int
}

func (c *capBuf) Write(p []byte) (int, error) {
	if room := c.max - c.b.Len(); room > 0 {
		if len(p) > room {
			c.b.Write(p[:room])
		} else {
			c.b.Write(p)
		}
	}
	return len(p), nil
}

func execCapped(ctx context.Context, timeout time.Duration, name string, args ...string) (string, int, error) {
	if timeout <= 0 {
		timeout = 15 * time.Second
	}
	cctx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()
	cmd := exec.CommandContext(cctx, name, args...)
	cmd.Env = append(minimalEnv(), "LC_ALL=C", "LANG=C")
	hideWindow(cmd)
	var out capBuf
	out.max = maxExecOutput
	cmd.Stdout = &out
	cmd.Stderr = io.Discard
	cmd.WaitDelay = 2 * time.Second
	err := cmd.Run()
	if cctx.Err() != nil {
		return out.b.String(), -1, fmt.Errorf("%s timed out after %s", name, timeout)
	}
	var ee *exec.ExitError
	if errors.As(err, &ee) {
		return out.b.String(), ee.ExitCode(), nil
	}
	if err != nil {
		return "", -1, err
	}
	return out.b.String(), 0, nil
}

func minimalEnv() []string {
	var env []string
	for _, k := range []string{"PATH", "SystemRoot", "windir", "ComSpec", "PATHEXT", "TEMP", "TMP"} {
		if v, ok := os.LookupEnv(k); ok {
			env = append(env, k+"="+v)
		}
	}
	return env
}

func readFileCapped(path string) ([]byte, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	return io.ReadAll(io.LimitReader(f, 1<<20))
}

func defaultHTTPClient(timeout time.Duration, verifyTLS, follow bool) *http.Client {
	tr := &http.Transport{
		Proxy:             http.ProxyFromEnvironment,
		TLSClientConfig:   &tls.Config{InsecureSkipVerify: !verifyTLS, MinVersion: tls.VersionTLS12}, //nolint:gosec // opt-out is an explicit, signed check parameter
		DisableKeepAlives: true,
	}
	cl := &http.Client{Timeout: timeout, Transport: tr}
	if !follow {
		cl.CheckRedirect = func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }
	} else {
		cl.CheckRedirect = func(_ *http.Request, via []*http.Request) error {
			if len(via) >= 5 {
				return errors.New("stopped after 5 redirects")
			}
			return nil
		}
	}
	return cl
}

// runExtended evaluates a check of an extended type. ok is false when the type is not an extended one.
func (c *Collector) runExtended(ctx context.Context, spec store.CheckSpec) (api.CheckResult, bool) {
	key := spec.Key
	x := c.sys()
	if !supportedHere(spec.Type, x.GOOS) {
		for _, t := range allExtended {
			if t == spec.Type {
				return res(key, Unknown, spec.Type+" checks are not available on "+x.GOOS), true
			}
		}
		return api.CheckResult{}, false
	}
	switch spec.Type {
	case "cpu":
		return c.checkCPU(ctx, key, spec.Params), true
	case "memory":
		return c.checkMemory(ctx, key, spec.Params), true
	case "process":
		return c.checkProcess(ctx, key, spec.Params), true
	case "port":
		return c.checkPort(ctx, key, spec.Params), true
	case "ping":
		return c.checkPing(ctx, key, spec.Params), true
	case "http":
		return c.checkHTTP(ctx, key, spec.Params), true
	case "cert":
		return c.checkCert(ctx, key, spec.Params), true
	case "ntp":
		return c.checkNTP(ctx, key, spec.Params), true
	case "smart":
		return c.checkSmart(ctx, key, spec.Params), true
	case "av":
		return c.checkAV(ctx, key, spec.Params), true
	case "systemd_failed":
		return c.checkSystemdFailed(ctx, key, spec.Params), true
	case "inodes":
		return c.checkInodes(ctx, key, spec.Params), true
	}
	return api.CheckResult{}, false
}

var allExtended = []string{"av", "cert", "cpu", "http", "inodes", "memory", "ntp", "ping", "port", "process", "smart", "systemd_failed"}

func supportedHere(t, goos string) bool {
	for _, e := range ExtendedTypes(goos) {
		if e == t {
			return true
		}
	}
	return false
}

// ---------------------------------------------------------------- shared helpers

// level maps a measured value to a status with warn/fail limits where a higher value is worse. A limit of 0 or less is off.
func levelHigh(v, warn, fail float64) string {
	switch {
	case fail > 0 && v >= fail:
		return Fail
	case warn > 0 && v >= warn:
		return Warn
	}
	return OK
}

func worse(a, b string) string {
	rank := map[string]int{OK: 0, Warn: 1, Fail: 2, Unknown: 0}
	if rank[b] > rank[a] {
		return b
	}
	return a
}

func fnum(v float64) string {
	if v == float64(int64(v)) {
		return strconv.FormatInt(int64(v), 10)
	}
	return strconv.FormatFloat(v, 'f', 1, 64)
}

// safeHost accepts a host name or IP literal, never an option-looking string (it is passed to ping and dialled).
var hostRE = regexp.MustCompile(`^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$|^[0-9A-Fa-f:]{2,45}$`)

func safeHost(h string) bool { return hostRE.MatchString(h) && !strings.HasPrefix(h, "-") }

// redactURL is the URL without credentials, query or fragment: details are shown to technicians and stored in alert text.
func redactURL(u *url.URL) string {
	c := *u
	c.User = nil
	c.RawQuery = ""
	c.Fragment = ""
	return c.String()
}

func unmarshalParams(raw json.RawMessage, v any) error {
	if len(raw) == 0 {
		return nil
	}
	return json.Unmarshal(raw, v)
}

func (c *Collector) hardTimeout(d time.Duration) time.Duration {
	if d <= 0 {
		return 15 * time.Second
	}
	return d + 5*time.Second
}

// ---------------------------------------------------------------- cpu

func (c *Collector) checkCPU(ctx context.Context, key string, raw json.RawMessage) api.CheckResult {
	p := struct {
		WindowS int `json:"window_s"`
		WarnPct int `json:"warn_pct"`
		FailPct int `json:"fail_pct"`
	}{3, 90, 98}
	if err := unmarshalParams(raw, &p); err != nil {
		return res(key, Unknown, "invalid cpu check parameters")
	}
	if p.WindowS < 1 || p.WindowS > 30 {
		p.WindowS = 3
	}
	x := c.sys()
	type t struct{ idle, total uint64 }
	read := func() (t, error) {
		return call(c, ctx, "cpucheck", 0, func(ctx context.Context) (t, error) {
			i, tt, err := c.P.CPUTimes(ctx)
			return t{i, tt}, err
		})
	}
	a, err := read()
	if err != nil {
		return res(key, Unknown, err.Error())
	}
	x.sleep(ctx, time.Duration(p.WindowS)*time.Second)
	b, err := read()
	if err != nil {
		return res(key, Unknown, err.Error())
	}
	if b.total <= a.total {
		return res(key, Unknown, "cpu counters did not advance")
	}
	busy := 100 * (1 - float64(b.idle-a.idle)/float64(b.total-a.total))
	if busy < 0 {
		busy = 0
	}
	if busy > 100 {
		busy = 100
	}
	return resv(key, levelHigh(busy, float64(p.WarnPct), float64(p.FailPct)), fmt.Sprintf("cpu %s%% busy over %ds", fnum(busy), p.WindowS), busy)
}

// ---------------------------------------------------------------- memory

func (c *Collector) checkMemory(ctx context.Context, key string, raw json.RawMessage) api.CheckResult {
	p := struct {
		Metric  string `json:"metric"`
		WarnPct int    `json:"warn_pct"`
		FailPct int    `json:"fail_pct"`
	}{"mem_used_pct", 85, 95}
	if err := unmarshalParams(raw, &p); err != nil {
		return res(key, Unknown, "invalid memory check parameters")
	}
	x := c.sys()
	switch p.Metric {
	case "", "mem_used_pct":
		total, avail, err := call2(c, ctx, "memcheck", c.P.Memory)
		if err != nil {
			return res(key, Unknown, err.Error())
		}
		if total == 0 {
			return res(key, Unknown, "total memory unknown")
		}
		used := 100 * (1 - float64(avail)/float64(total))
		return resv(key, levelHigh(used, float64(p.WarnPct), float64(p.FailPct)), fmt.Sprintf("memory %s%% used", fnum(used)), used)
	case "swap_used_pct":
		if x.GOOS != "linux" {
			return res(key, Unknown, "swap usage is only available on Linux")
		}
		b, err := x.ReadFile("/proc/meminfo")
		if err != nil {
			return res(key, Unknown, "cannot read /proc/meminfo")
		}
		st, sf, err := parseSwap(string(b))
		if err != nil {
			return res(key, Unknown, err.Error())
		}
		if st == 0 {
			return resv(key, OK, "no swap configured", 0)
		}
		used := 100 * (1 - float64(sf)/float64(st))
		return resv(key, levelHigh(used, float64(p.WarnPct), float64(p.FailPct)), fmt.Sprintf("swap %s%% used", fnum(used)), used)
	case "psi_some_avg10":
		if x.GOOS != "linux" {
			return res(key, Unknown, "memory pressure (PSI) is only available on Linux")
		}
		b, err := x.ReadFile("/proc/pressure/memory")
		if err != nil {
			return res(key, Unknown, "memory pressure (PSI) is not enabled in this kernel")
		}
		v, err := parsePSI(string(b))
		if err != nil {
			return res(key, Unknown, err.Error())
		}
		return resv(key, levelHigh(v, float64(p.WarnPct), float64(p.FailPct)), fmt.Sprintf("memory stalls %s%% of the last 10 s", fnum(v)), v)
	}
	return res(key, Unknown, "unknown memory metric")
}

func call2(c *Collector, ctx context.Context, key string, fn func(context.Context) (uint64, uint64, error)) (uint64, uint64, error) {
	type r struct{ a, b uint64 }
	v, err := call(c, ctx, key, 0, func(ctx context.Context) (r, error) {
		a, b, err := fn(ctx)
		return r{a, b}, err
	})
	return v.a, v.b, err
}

// ---------------------------------------------------------------- process

func (c *Collector) checkProcess(ctx context.Context, key string, raw json.RawMessage) api.CheckResult {
	p := struct {
		Name     string `json:"name"`
		MinCount int    `json:"min_count"`
		MaxCount int    `json:"max_count"`
	}{"", 1, 0}
	if err := unmarshalParams(raw, &p); err != nil || strings.TrimSpace(p.Name) == "" || len(p.Name) > 128 {
		return res(key, Unknown, "invalid process check parameters")
	}
	x := c.sys()
	var names []string
	if x.GOOS == "windows" {
		out, _, err := x.Exec(ctx, 15*time.Second, "tasklist", "/FO", "CSV", "/NH")
		if err != nil {
			return res(key, Unknown, "cannot list processes: "+err.Error())
		}
		names = parseTasklist(out)
	} else {
		ents, err := x.ReadDir("/proc")
		if err != nil {
			return res(key, Unknown, "cannot read /proc")
		}
		for _, e := range ents {
			if !isPID(e.Name()) {
				continue
			}
			b, err := x.ReadFile("/proc/" + e.Name() + "/comm")
			if err != nil {
				continue // the process ended, or it is not ours to read
			}
			names = append(names, strings.TrimSpace(string(b)))
		}
	}
	n := countProcesses(names, p.Name)
	switch {
	case n < p.MinCount:
		return resv(key, Fail, fmt.Sprintf("%d %s running (expected at least %d)", n, p.Name, p.MinCount), float64(n))
	case p.MaxCount > 0 && n > p.MaxCount:
		return resv(key, Fail, fmt.Sprintf("%d %s running (expected at most %d)", n, p.Name, p.MaxCount), float64(n))
	}
	return resv(key, OK, fmt.Sprintf("%d %s running", n, p.Name), float64(n))
}

// ---------------------------------------------------------------- port

func (c *Collector) checkPort(ctx context.Context, key string, raw json.RawMessage) api.CheckResult {
	p := struct {
		Host      string `json:"host"`
		Port      int    `json:"port"`
		TimeoutMS int    `json:"timeout_ms"`
		WarnMS    int    `json:"warn_ms"`
	}{"", 0, 3000, 0}
	if err := unmarshalParams(raw, &p); err != nil || !safeHost(p.Host) || p.Port < 1 || p.Port > 65535 {
		return res(key, Unknown, "invalid port check parameters")
	}
	if p.TimeoutMS < 100 || p.TimeoutMS > 30000 {
		p.TimeoutMS = 3000
	}
	x := c.sys()
	dctx, cancel := context.WithTimeout(ctx, time.Duration(p.TimeoutMS)*time.Millisecond)
	defer cancel()
	start := x.now()
	conn, err := x.Dial(dctx, "tcp", net.JoinHostPort(p.Host, strconv.Itoa(p.Port)))
	ms := float64(x.now().Sub(start).Microseconds()) / 1000
	if err != nil {
		return res(key, Fail, fmt.Sprintf("%s:%d unreachable: %s", p.Host, p.Port, netReason(err)))
	}
	_ = conn.Close()
	st := OK
	if p.WarnMS > 0 && ms > float64(p.WarnMS) {
		st = Warn
	}
	return resv(key, st, fmt.Sprintf("%s:%d open, connected in %s ms", p.Host, p.Port, fnum(ms)), ms)
}

func netReason(err error) string {
	var ne net.Error
	switch {
	case errors.Is(err, context.DeadlineExceeded) || (errors.As(err, &ne) && ne.Timeout()):
		return "timed out"
	case strings.Contains(err.Error(), "refused"):
		return "connection refused"
	case strings.Contains(err.Error(), "no such host"):
		return "name not found"
	}
	var oe *net.OpError
	if errors.As(err, &oe) && oe.Err != nil {
		return oe.Err.Error()
	}
	return "connection failed"
}

// ---------------------------------------------------------------- ping

func (c *Collector) checkPing(ctx context.Context, key string, raw json.RawMessage) api.CheckResult {
	p := struct {
		Host        string `json:"host"`
		Count       int    `json:"count"`
		TimeoutMS   int    `json:"timeout_ms"`
		WarnMS      int    `json:"warn_ms"`
		FailMS      int    `json:"fail_ms"`
		WarnLossPct int    `json:"warn_loss_pct"`
		FailLossPct int    `json:"fail_loss_pct"`
	}{"", 3, 2000, 0, 0, 1, 100}
	if err := unmarshalParams(raw, &p); err != nil || !safeHost(p.Host) {
		return res(key, Unknown, "invalid ping check parameters")
	}
	if p.Count < 1 || p.Count > 10 {
		p.Count = 3
	}
	if p.TimeoutMS < 200 || p.TimeoutMS > 10000 {
		p.TimeoutMS = 2000
	}
	x := c.sys()
	var args []string
	if x.GOOS == "windows" {
		args = []string{"-n", strconv.Itoa(p.Count), "-w", strconv.Itoa(p.TimeoutMS), p.Host}
	} else {
		secs := (p.TimeoutMS + 999) / 1000
		args = []string{"-n", "-c", strconv.Itoa(p.Count), "-W", strconv.Itoa(secs), p.Host}
	}
	total := time.Duration(p.Count)*(time.Duration(p.TimeoutMS)*time.Millisecond+time.Second) + 5*time.Second
	out, _, err := x.Exec(ctx, total, "ping", args...)
	if err != nil {
		return res(key, Unknown, "ping could not run: "+firstLine(err.Error()))
	}
	r, err := parsePing(out)
	if err != nil {
		return res(key, Unknown, err.Error())
	}
	st := OK
	if r.LossPct >= float64(p.FailLossPct) {
		st = Fail
	} else if r.LossPct >= float64(p.WarnLossPct) {
		st = Warn
	}
	if r.Received == 0 {
		return res(key, Fail, fmt.Sprintf("%s: no reply to %d pings", p.Host, r.Sent))
	}
	st = worse(st, levelHigh(r.AvgMS, float64(p.WarnMS), float64(p.FailMS)))
	return resv(key, st, fmt.Sprintf("%s: %s ms average, %s%% loss (%d of %d)", p.Host, fnum(r.AvgMS), fnum(r.LossPct), r.Received, r.Sent), r.AvgMS)
}

// ---------------------------------------------------------------- http

func (c *Collector) checkHTTP(ctx context.Context, key string, raw json.RawMessage) api.CheckResult {
	verify, follow := true, true
	p := struct {
		URL           string `json:"url"`
		Method        string `json:"method"`
		ExpectMin     int    `json:"expect_min"`
		ExpectMax     int    `json:"expect_max"`
		TimeoutMS     int    `json:"timeout_ms"`
		WarnMS        int    `json:"warn_ms"`
		Contains      string `json:"contains"`
		VerifyTLS     *bool  `json:"verify_tls"`
		CertWarnDays  *int   `json:"cert_warn_days"`
		CertFailDays  *int   `json:"cert_fail_days"`
		FollowRedirec *bool  `json:"follow_redirects"`
	}{ExpectMin: 200, ExpectMax: 399, TimeoutMS: 10000, Method: "GET"}
	if err := unmarshalParams(raw, &p); err != nil || len(p.URL) > 2000 {
		return res(key, Unknown, "invalid http check parameters")
	}
	u, err := url.Parse(p.URL)
	if err != nil || (u.Scheme != "http" && u.Scheme != "https") || u.Host == "" || u.User != nil {
		return res(key, Unknown, "invalid http check parameters")
	}
	if p.Method != "GET" && p.Method != "HEAD" {
		p.Method = "GET"
	}
	if p.TimeoutMS < 500 || p.TimeoutMS > 60000 {
		p.TimeoutMS = 10000
	}
	if p.VerifyTLS != nil {
		verify = *p.VerifyTLS
	}
	if p.FollowRedirec != nil {
		follow = *p.FollowRedirec
	}
	warnDays, failDays := 14, 3
	if p.CertWarnDays != nil {
		warnDays = *p.CertWarnDays
	}
	if p.CertFailDays != nil {
		failDays = *p.CertFailDays
	}
	x := c.sys()
	where := redactURL(u)
	req, err := http.NewRequestWithContext(ctx, p.Method, p.URL, nil)
	if err != nil {
		return res(key, Unknown, "invalid http check parameters")
	}
	req.Header.Set("User-Agent", "rivetit-agent-check")
	start := x.now()
	resp, err := x.Client(time.Duration(p.TimeoutMS)*time.Millisecond, verify, follow).Do(req)
	if err != nil {
		var ue *url.Error
		reason := err.Error()
		if errors.As(err, &ue) {
			reason = ue.Err.Error()
		}
		if ue != nil && ue.Timeout() {
			reason = "timed out"
		}
		return res(key, Fail, fmt.Sprintf("%s: %s", where, firstLine(reason)))
	}
	defer resp.Body.Close()
	var body []byte
	if p.Contains != "" && p.Method == "GET" {
		body, _ = io.ReadAll(io.LimitReader(resp.Body, 64<<10))
	} else {
		_, _ = io.Copy(io.Discard, io.LimitReader(resp.Body, 64<<10))
	}
	ms := float64(x.now().Sub(start).Microseconds()) / 1000
	st := OK
	parts := []string{fmt.Sprintf("%s: %d in %s ms", where, resp.StatusCode, fnum(ms))}
	if resp.StatusCode < p.ExpectMin || resp.StatusCode > p.ExpectMax {
		st = Fail
		parts[0] = fmt.Sprintf("%s: status %d (expected %d to %d)", where, resp.StatusCode, p.ExpectMin, p.ExpectMax)
	}
	if st == OK && p.WarnMS > 0 && ms > float64(p.WarnMS) {
		st = Warn
		parts = append(parts, "slow")
	}
	if p.Contains != "" && p.Method == "GET" && !bytes.Contains(body, []byte(p.Contains)) {
		st = Fail
		parts = append(parts, "expected text not found")
	}
	if resp.TLS != nil && len(resp.TLS.PeerCertificates) > 0 {
		days := daysLeft(resp.TLS.PeerCertificates[0].NotAfter, x.now())
		parts = append(parts, fmt.Sprintf("certificate expires in %d days", days))
		switch {
		case failDays > 0 && days <= failDays:
			st = worse(st, Fail)
		case warnDays > 0 && days <= warnDays:
			st = worse(st, Warn)
		}
	}
	return resv(key, st, strings.Join(parts, "; "), ms)
}

func daysLeft(notAfter, now time.Time) int {
	d := notAfter.Sub(now)
	n := int(d / (24 * time.Hour))
	if d < 0 && d%(24*time.Hour) != 0 {
		n-- // floor, so an expired certificate is negative
	}
	return n
}

// ---------------------------------------------------------------- ntp

func (c *Collector) checkNTP(ctx context.Context, key string, raw json.RawMessage) api.CheckResult {
	p := struct {
		Server    string `json:"server"`
		TimeoutMS int    `json:"timeout_ms"`
		WarnMS    int    `json:"warn_ms"`
		FailMS    int    `json:"fail_ms"`
	}{"pool.ntp.org", 3000, 1000, 5000}
	if err := unmarshalParams(raw, &p); err != nil || !safeHost(p.Server) {
		return res(key, Unknown, "invalid ntp check parameters")
	}
	if p.TimeoutMS < 500 || p.TimeoutMS > 10000 {
		p.TimeoutMS = 3000
	}
	x := c.sys()
	dctx, cancel := context.WithTimeout(ctx, time.Duration(p.TimeoutMS)*time.Millisecond)
	defer cancel()
	conn, err := x.Dial(dctx, "udp", net.JoinHostPort(p.Server, "123"))
	if err != nil {
		return res(key, Unknown, fmt.Sprintf("cannot reach time server %s: %s", p.Server, netReason(err)))
	}
	defer conn.Close()
	_ = conn.SetDeadline(x.now().Add(time.Duration(p.TimeoutMS) * time.Millisecond))
	t0 := x.now()
	pkt := buildNTPRequest(t0)
	if _, err := conn.Write(pkt); err != nil {
		return res(key, Unknown, "cannot send to time server")
	}
	buf := make([]byte, 128)
	n, err := conn.Read(buf)
	t3 := x.now()
	if err != nil {
		return res(key, Unknown, fmt.Sprintf("no answer from time server %s: %s", p.Server, netReason(err)))
	}
	off, err := parseNTPResponse(buf[:n], pkt, t3)
	if err != nil {
		return res(key, Unknown, fmt.Sprintf("time server %s: %s", p.Server, err.Error()))
	}
	ms := float64(off.Microseconds()) / 1000
	abs := ms
	if abs < 0 {
		abs = -abs
	}
	return resv(key, levelHigh(abs, float64(p.WarnMS), float64(p.FailMS)), fmt.Sprintf("clock is %s ms %s the time of %s", fnum(abs), map[bool]string{true: "ahead of", false: "behind"}[ms < 0], p.Server), abs)
}

// Allow-lists for every parameter that reaches a command line or the file system. Parameters come from technicians and policies and
// the agent runs as SYSTEM or root, so nothing is ever spliced into a command string unchecked: programs get an argument list, and what
// is interpolated into a fixed PowerShell script has passed one of these.
var (
	smartDeviceRE = regexp.MustCompile(`^/dev/[A-Za-z0-9]{1,24}$`)
	certStoreRE   = regexp.MustCompile(`^(LocalMachine|CurrentUser)\\([A-Za-z0-9 _.-]{1,64})$`)
)

// parseCertStoreName splits "LocalMachine\\My" after checking it against the allow-list.
func parseCertStoreName(s string) (scope, store string, ok bool) {
	m := certStoreRE.FindStringSubmatch(s)
	if m == nil || strings.Contains(m[2], "..") {
		return "", "", false
	}
	return m[1], m[2], true
}

// safeCertPath accepts an absolute path of at most 300 characters without control characters, "..", UNC prefixes or a leading option.
func safeCertPath(p string) bool {
	if p == "" || len(p) > 300 || strings.HasPrefix(p, `\\`) || strings.HasPrefix(p, "//") || strings.Contains(p, "..") {
		return false
	}
	for _, r := range p {
		if r < 0x20 || r == 0x7f {
			return false
		}
	}
	return strings.HasPrefix(p, "/") || (len(p) > 2 && p[1] == ':' && (p[2] == '\\' || p[2] == '/') && ((p[0] >= 'A' && p[0] <= 'Z') || (p[0] >= 'a' && p[0] <= 'z')))
}
