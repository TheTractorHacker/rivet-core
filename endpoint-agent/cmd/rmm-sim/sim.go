package main

import (
	"bytes"
	"context"
	"crypto/tls"
	"encoding/json"
	"fmt"
	"io"
	"math/rand/v2"
	"net"
	"net/http"
	"sort"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"
)

type device struct {
	n      int
	id     int64
	token  string
	host   string
	serial string
	rng    *rand.Rand
	seq    uint64
	body   []byte // the body of the request in flight, kept for a retry with the same seq
	first  bool
	ip     string
}

type sim struct {
	c        config
	out      io.Writer
	client   *http.Client
	sem      chan struct{}
	runID    string
	hist     hist
	started  time.Time
	reqs     atomic.Uint64
	bytesOut atomic.Uint64
	inflight atomic.Int64
	mu       sync.Mutex
	codes    map[string]uint64 // "200", "503:unavailable", "transport", ...
	enrolled atomic.Uint64
	enrollEr atomic.Uint64
	sleep    func(context.Context, time.Duration) bool // a seam for tests: reports whether the full wait elapsed
}

func newSim(c config, out io.Writer) *sim {
	if c.Seed == 0 {
		c.Seed = uint64(time.Now().UnixNano())
	}
	tr := &http.Transport{
		MaxIdleConns: c.Concurrency * 2, MaxIdleConnsPerHost: c.Concurrency * 2, IdleConnTimeout: 60 * time.Second,
		DialContext:     (&net.Dialer{Timeout: 10 * time.Second, KeepAlive: 30 * time.Second}).DialContext,
		TLSClientConfig: &tls.Config{MinVersion: tls.VersionTLS12},
	}
	if c.Insecure { // checkTarget already limited this to loopback hosts
		tr.TLSClientConfig.InsecureSkipVerify = true
	}
	return &sim{
		c: c, out: out, client: &http.Client{Transport: tr, Timeout: c.Timeout, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }},
		sem: make(chan struct{}, c.Concurrency), runID: fmt.Sprintf("%05d", c.Seed%100000), codes: map[string]uint64{}, sleep: sleepCtx,
	}
}

func sleepCtx(ctx context.Context, d time.Duration) bool {
	if d <= 0 {
		return ctx.Err() == nil
	}
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
		return false
	case <-t.C:
		return true
	}
}

func (s *sim) endpoint(name string) string {
	return strings.TrimRight(s.c.URL, "/") + "/" + name
}

func (s *sim) count(key string) {
	s.mu.Lock()
	s.codes[key]++
	s.mu.Unlock()
}

// reply is what one request came back with.
type reply struct {
	status     int
	code       string // the "code" field of an error body
	retryAfter time.Duration
	body       []byte
	err        error
	latency    time.Duration
}

// do sends one POST, bounded by the concurrency semaphore, and records its latency and status.
func (s *sim) do(ctx context.Context, endpoint string, body []byte, token, ip string) reply {
	select {
	case s.sem <- struct{}{}:
	case <-ctx.Done():
		return reply{err: ctx.Err()}
	}
	defer func() { <-s.sem }()
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, s.endpoint(endpoint), bytes.NewReader(body))
	if err != nil {
		return reply{err: err}
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("User-Agent", "rmm-sim/1")
	if token != "" {
		req.Header.Set("Authorization", "Bearer "+token)
	}
	if s.c.IPHeader != "" && ip != "" {
		req.Header.Set(s.c.IPHeader, ip)
	}
	s.inflight.Add(1)
	t0 := time.Now()
	resp, err := s.client.Do(req)
	r := reply{latency: time.Since(t0)}
	s.inflight.Add(-1)
	s.reqs.Add(1)
	s.bytesOut.Add(uint64(len(body)))
	if err != nil {
		if ctx.Err() != nil {
			r.err = ctx.Err()
			return r // the run ended: not an error of the server
		}
		r.err = err
		s.hist.add(r.latency)
		s.count("transport")
		return r
	}
	defer resp.Body.Close()
	b, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	r.status, r.body = resp.StatusCode, b
	if ra := resp.Header.Get("Retry-After"); ra != "" {
		if n, err := strconv.Atoi(strings.TrimSpace(ra)); err == nil && n > 0 {
			r.retryAfter = time.Duration(n) * time.Second
		}
	}
	var e struct {
		Code string `json:"code"`
	}
	if r.status >= 400 && json.Unmarshal(b, &e) == nil {
		r.code = e.Code
	}
	s.hist.add(r.latency)
	key := strconv.Itoa(r.status)
	if r.code != "" {
		key += ":" + r.code
	}
	s.count(key)
	return r
}

// enrollAll enrolls every device with bounded concurrency and returns the ones that got credentials.
func (s *sim) enrollAll(ctx context.Context) []*device {
	devs := make([]*device, 0, s.c.Devices)
	var mu sync.Mutex
	var wg sync.WaitGroup
	next := make(chan int)
	workers := s.c.Concurrency
	if workers > 32 {
		workers = 32
	}
	for w := 0; w < workers; w++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for n := range next {
				r := rand.New(rand.NewPCG(s.c.Seed, uint64(n)))
				body, ident, err := buildEnroll(r, s.c.tokenFor(n), n, s.runID)
				if err != nil {
					s.enrollEr.Add(1)
					continue
				}
				ip := fmt.Sprintf("10.%d.%d.%d", 100+(n>>16)&0x3f, (n>>8)&0xff, 1+n&0xfe)
				var resp reply
				for attempt := 0; attempt < 5; attempt++ {
					resp = s.do(ctx, "agent_enroll", body, "", ip)
					if resp.status == 201 || resp.err != nil && ctx.Err() != nil {
						break
					}
					if resp.status == 429 || resp.status >= 500 {
						wait := resp.retryAfter
						if wait > 5*time.Second {
							wait = 5 * time.Second
						}
						if !s.sleep(ctx, wait+time.Duration(r.Float64()*float64(time.Second))) {
							break
						}
						continue
					}
					break
				}
				if resp.status != 201 {
					s.enrollEr.Add(1)
					continue
				}
				var er struct {
					DeviceID    int64  `json:"device_id"`
					DeviceToken string `json:"device_token"`
				}
				if json.Unmarshal(resp.body, &er) != nil || er.DeviceToken == "" {
					s.enrollEr.Add(1)
					continue
				}
				parts := strings.SplitN(ident, "|", 2)
				mu.Lock()
				devs = append(devs, &device{n: n, id: er.DeviceID, token: er.DeviceToken, host: parts[0], serial: parts[1], rng: rand.New(rand.NewPCG(s.c.Seed^0x9e3779b97f4a7c15, uint64(n))), first: true, ip: ip})
				mu.Unlock()
				s.enrolled.Add(1)
			}
		}()
	}
	for n := 1; n <= s.c.Devices && ctx.Err() == nil; n++ {
		next <- n
	}
	close(next)
	wg.Wait()
	sort.Slice(devs, func(i, j int) bool { return devs[i].n < devs[j].n })
	return devs
}

// jitter returns d scaled by a random factor in [1-f, 1+f].
func jitter(r *rand.Rand, d time.Duration, f float64) time.Duration {
	return time.Duration(float64(d) * (1 - f + 2*f*r.Float64()))
}

// runDevice is one device's check-in loop. A failed check-in keeps its body (same seq) and retries after the server's Retry-After (a floor)
// or an exponential full-jitter backoff; a module_disabled answer waits at least the disabled floor, like the real agent.
func (s *sim) runDevice(ctx context.Context, d *device) {
	start := s.c.Interval
	if s.c.Herd {
		start = 10 * time.Second
	}
	if !s.sleep(ctx, time.Duration(d.rng.Float64()*float64(start))) {
		return
	}
	interval := s.c.Interval
	attempt := 0
	for ctx.Err() == nil {
		if d.body == nil {
			d.seq++
			body, err := buildCheckin(d.rng, d.seq, time.Now(), "0.0.0-sim", s.c.Batches, d.first, d.host, d.serial)
			if err != nil {
				return
			}
			d.body = body
		}
		r := s.do(ctx, "agent_checkin", d.body, d.token, d.ip)
		if r.err != nil && ctx.Err() != nil {
			return
		}
		var wait time.Duration
		if r.status == 200 {
			d.body, d.first, attempt = nil, false, 0
			if s.c.FollowInterval {
				var ok struct {
					Next int `json:"next_check_in_s"`
				}
				if json.Unmarshal(r.body, &ok) == nil && ok.Next > 0 {
					interval = time.Duration(ok.Next) * time.Second
				}
			}
			wait = jitter(d.rng, interval, 0.10)
		} else if r.status == 401 || r.status == 403 || r.status == 404 || r.status == 422 || r.status == 413 {
			d.body = nil // the server will not take this body: drop it, the next interval builds a new one
			wait = jitter(d.rng, interval, 0.10)
		} else {
			// transport error, 429, 5xx (including a shed 503 and module_disabled): keep the body and back off
			back := time.Duration(float64(5*time.Second) * float64(uint(1)<<uint(min(attempt, 8))) * s.c.RetryScale)
			wait = time.Duration(d.rng.Float64() * float64(back))
			floor := time.Duration(float64(r.retryAfter) * s.c.RetryScale)
			if r.code == "module_disabled" || r.code == "feature_disabled" {
				if f := time.Duration(float64(s.c.DisabledFloor) * s.c.RetryScale); floor < f {
					floor = f
				}
				attempt = 0
			} else {
				attempt++
			}
			if wait < floor {
				wait = floor
			}
			wait = jitter(d.rng, wait, 0.10)
		}
		if !s.sleep(ctx, wait) {
			return
		}
	}
}

func (s *sim) run(ctx context.Context) error {
	fmt.Fprintf(s.out, "rmm-sim: enrolling %d devices at %s (concurrency %d)\n", s.c.Devices, s.c.URL, s.c.Concurrency)
	t0 := time.Now()
	devs := s.enrollAll(ctx)
	fmt.Fprintf(s.out, "rmm-sim: enrolled %d/%d in %v (%d failed)\n", len(devs), s.c.Devices, time.Since(t0).Round(time.Millisecond), s.enrollEr.Load())
	if len(devs) == 0 {
		return fmt.Errorf("no device could be enrolled (token usable? limit reached? server reachable?)")
	}
	if s.c.EnrollOnly {
		return nil
	}
	runCtx, cancel := context.WithTimeout(ctx, s.c.Duration)
	defer cancel()
	// the enrollment requests are not part of the check-in measurement
	s.hist = hist{}
	s.mu.Lock()
	s.codes = map[string]uint64{}
	s.mu.Unlock()
	s.reqs.Store(0)
	s.bytesOut.Store(0)
	s.started = time.Now()
	fmt.Fprintln(s.out, "rmm-sim: measurement start")
	var wg sync.WaitGroup
	for _, d := range devs {
		wg.Add(1)
		go func(d *device) {
			defer wg.Done()
			s.runDevice(runCtx, d)
		}(d)
	}
	done := make(chan struct{})
	go func() { wg.Wait(); close(done) }()
	s.reportLoop(runCtx, done)
	cancel()
	<-done
	fmt.Fprintln(s.out, "rmm-sim: measurement end")
	s.summary(len(devs))
	return nil
}

func (s *sim) reportLoop(ctx context.Context, done <-chan struct{}) {
	tick := time.NewTicker(s.c.Report)
	defer tick.Stop()
	prev := s.hist.snapshot()
	prevReqs := s.reqs.Load()
	prevT := time.Now()
	for {
		select {
		case <-ctx.Done():
			return
		case <-done:
			return
		case now := <-tick.C:
			cur := s.hist.snapshot()
			d := cur.sub(prev)
			reqs := s.reqs.Load()
			rate := float64(reqs-prevReqs) / now.Sub(prevT).Seconds()
			fmt.Fprintf(s.out, "t=%-5s %7.1f req/s  p50=%-9v p95=%-9v p99=%-9v max=%-9v inflight=%-3d %s\n", now.Sub(s.started).Round(time.Second), rate,
				d.percentile(50).Round(10*time.Microsecond), d.percentile(95).Round(10*time.Microsecond), d.percentile(99).Round(10*time.Microsecond),
				time.Duration(d.maxNs).Round(10*time.Microsecond), s.inflight.Load(), s.codeLine())
			prev, prevReqs, prevT = cur, reqs, now
		}
	}
}

func (s *sim) codeLine() string {
	s.mu.Lock()
	defer s.mu.Unlock()
	keys := make([]string, 0, len(s.codes))
	for k := range s.codes {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	var sb strings.Builder
	for _, k := range keys {
		fmt.Fprintf(&sb, "%s=%d ", k, s.codes[k])
	}
	return strings.TrimSpace(sb.String())
}

type summary struct {
	Devices    int               `json:"devices"`
	Enrolled   int               `json:"enrolled"`
	DurationS  float64           `json:"duration_s"`
	Requests   uint64            `json:"requests"`
	ReqPerS    float64           `json:"req_per_s"`
	P50ms      float64           `json:"p50_ms"`
	P95ms      float64           `json:"p95_ms"`
	P99ms      float64           `json:"p99_ms"`
	MaxMs      float64           `json:"max_ms"`
	MeanMs     float64           `json:"mean_ms"`
	Codes      map[string]uint64 `json:"codes"`
	ErrorPct   float64           `json:"error_pct"`
	BytesSent  uint64            `json:"bytes_sent"`
	EnrollFail uint64            `json:"enroll_failed"`
}

func ms(d time.Duration) float64 { return float64(d.Microseconds()) / 1000 }

func (s *sim) result(enrolled int) summary {
	snap := s.hist.snapshot()
	dur := time.Since(s.started)
	reqs := s.reqs.Load()
	s.mu.Lock()
	codes := make(map[string]uint64, len(s.codes))
	var bad uint64
	for k, v := range s.codes {
		codes[k] = v
		if !strings.HasPrefix(k, "200") {
			bad += v
		}
	}
	s.mu.Unlock()
	out := summary{Devices: s.c.Devices, Enrolled: enrolled, DurationS: dur.Seconds(), Requests: reqs, Codes: codes, BytesSent: s.bytesOut.Load(), EnrollFail: s.enrollEr.Load(),
		P50ms: ms(snap.percentile(50)), P95ms: ms(snap.percentile(95)), P99ms: ms(snap.percentile(99)), MaxMs: ms(time.Duration(snap.maxNs)), MeanMs: ms(snap.mean())}
	if dur > 0 {
		out.ReqPerS = float64(reqs) / dur.Seconds()
	}
	if reqs > 0 {
		out.ErrorPct = 100 * float64(bad) / float64(reqs)
	}
	return out
}

func (s *sim) summary(enrolled int) {
	r := s.result(enrolled)
	if s.c.JSON {
		b, _ := json.Marshal(r)
		fmt.Fprintln(s.out, string(b))
		return
	}
	fmt.Fprintf(s.out, "\n== rmm-sim result ==\n")
	fmt.Fprintf(s.out, "devices %d (enrolled %d), %.1f s, %d requests, %.1f req/s, %.1f KiB sent\n", r.Devices, r.Enrolled, r.DurationS, r.Requests, r.ReqPerS, float64(r.BytesSent)/1024)
	fmt.Fprintf(s.out, "latency  p50=%.1f ms  p95=%.1f ms  p99=%.1f ms  max=%.1f ms  mean=%.1f ms\n", r.P50ms, r.P95ms, r.P99ms, r.MaxMs, r.MeanMs)
	fmt.Fprintf(s.out, "status   %s   (non-200: %.2f%%)\n", s.codeLine(), r.ErrorPct)
	fmt.Fprintf(s.out, "%s", s.hist.snapshot().bars())
}
