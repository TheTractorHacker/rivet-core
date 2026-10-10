package collect

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"net"
	"os"
	"runtime"
	"sort"
	"strings"
	"sync"
	"time"

	"rivetit-agent/internal/api"
	"rivetit-agent/internal/store"
)

// Collector turns Platform data into contract structures. A failed item is
// null/unknown, never a fabricated zero.
type Collector struct {
	P       Platform
	Cfg     func() store.Config
	Timeout time.Duration // hard per-call timeout (default 15s)
	Now     func() time.Time

	mu      sync.Mutex
	cpuPrev *cpuSample
	netPrev *netSample
	hung    map[string]bool

	stateDirV string

	// VerifyCheck verifies a check definition's detached signature against the
	// pinned signing key. Script checks are refused without a valid signature.
	VerifyCheck func(store.CheckSpec) error
}

type cpuSample struct{ idle, total uint64 }
type netSample struct {
	rx, tx uint64
	at     time.Time
}

// New builds a Collector with default timeouts.
func New(p Platform, cfg func() store.Config) *Collector {
	return &Collector{P: p, Cfg: cfg, Timeout: 15 * time.Second, Now: time.Now, hung: map[string]bool{}}
}

func (c *Collector) now() time.Time {
	if c.Now != nil {
		return c.Now()
	}
	return time.Now()
}

func (c *Collector) timeout() time.Duration {
	if c.Timeout > 0 {
		return c.Timeout
	}
	return 15 * time.Second
}

// call runs fn with a hard timeout. If fn ignores cancellation and hangs, the
// goroutine is abandoned and further calls under the same key fail fast until
// it finishes, so hung collectors cannot accumulate.
func call[T any](c *Collector, ctx context.Context, key string, d time.Duration, fn func(context.Context) (T, error)) (T, error) {
	var zero T
	c.mu.Lock()
	if c.hung == nil {
		c.hung = map[string]bool{}
	}
	if c.hung[key] {
		c.mu.Unlock()
		return zero, fmt.Errorf("%s: previous collection still running", key)
	}
	c.hung[key] = true
	c.mu.Unlock()
	if d <= 0 {
		d = c.timeout()
	}
	cctx, cancel := context.WithTimeout(ctx, d)
	defer cancel()
	type res struct {
		v   T
		err error
	}
	ch := make(chan res, 1)
	go func() {
		var out res
		defer func() {
			if r := recover(); r != nil {
				out = res{err: fmt.Errorf("%s: panic: %v", key, r)}
			}
			// release the key BEFORE delivering the result: a caller that gets the
			// result may call again immediately and must not see a stale "running".
			c.mu.Lock()
			delete(c.hung, key)
			c.mu.Unlock()
			ch <- out
		}()
		v, err := fn(cctx)
		out = res{v, err}
	}()
	select {
	case r := <-ch:
		return r.v, r.err
	case <-cctx.Done():
		return zero, fmt.Errorf("%s: timed out after %s", key, d)
	}
}

func pct(v float64) *float64 {
	if v < 0 {
		v = 0
	}
	if v > 100 {
		v = 100
	}
	v = float64(int(v*10+0.5)) / 10
	return &v
}

// Metrics samples CPU/memory/disk/network. CPU and network are rates over
// the time since the previous call, so the first sample leaves them null.
func (c *Collector) Metrics(ctx context.Context) api.Metrics {
	var m api.Metrics

	if idle, total, err := func() (uint64, uint64, error) {
		type t struct{ i, t uint64 }
		r, err := call(c, ctx, "cpu", 0, func(ctx context.Context) (t, error) {
			i, tt, err := c.P.CPUTimes(ctx)
			return t{i, tt}, err
		})
		return r.i, r.t, err
	}(); err == nil {
		c.mu.Lock()
		prev := c.cpuPrev
		c.cpuPrev = &cpuSample{idle, total}
		c.mu.Unlock()
		if prev != nil && total > prev.total && idle >= prev.idle {
			dt, di := float64(total-prev.total), float64(idle-prev.idle)
			if di <= dt {
				m.CPUPct = pct(100 * (1 - di/dt))
			}
		}
	}

	type mem struct{ t, a uint64 }
	if r, err := call(c, ctx, "mem", 0, func(ctx context.Context) (mem, error) {
		t, a, err := c.P.Memory(ctx)
		return mem{t, a}, err
	}); err == nil && r.t > 0 && r.a <= r.t {
		m.MemPct = pct(100 * float64(r.t-r.a) / float64(r.t))
	}

	if ds, err := call(c, ctx, "disks", 0, c.P.Disks); err == nil {
		for _, d := range ds {
			if d.Total == 0 || d.Free > d.Total {
				continue
			}
			m.Disk = append(m.Disk, api.DiskMetric{Mount: d.Mount, UsedPct: *pct(100 * float64(d.Total-d.Free) / float64(d.Total))})
		}
	}

	type nb struct{ rx, tx uint64 }
	if r, err := call(c, ctx, "net", 0, func(ctx context.Context) (nb, error) {
		rx, tx, err := c.P.NetBytes(ctx)
		return nb{rx, tx}, err
	}); err == nil {
		now := c.now()
		c.mu.Lock()
		prev := c.netPrev
		c.netPrev = &netSample{r.rx, r.tx, now}
		c.mu.Unlock()
		if prev != nil {
			el := now.Sub(prev.at).Seconds()
			// counter reset/wrap or implausibly short interval => null, not 0
			if el >= 1 && r.rx >= prev.rx && r.tx >= prev.tx {
				rx, tx := float64(r.rx-prev.rx)*8/el, float64(r.tx-prev.tx)*8/el
				m.NetRxBps, m.NetTxBps = &rx, &tx
			}
		}
	}
	return m
}

func strp(s string) *string {
	if strings.TrimSpace(s) == "" {
		return nil
	}
	return &s
}

// Networks lists non-loopback interfaces that have a MAC address.
func Networks() []api.NetInfo {
	ifs, err := net.Interfaces()
	if err != nil {
		return nil
	}
	var out []api.NetInfo
	for _, i := range ifs {
		if i.Flags&net.FlagLoopback != 0 || len(i.HardwareAddr) == 0 {
			continue
		}
		n := api.NetInfo{Name: i.Name, MAC: strings.ToLower(i.HardwareAddr.String()), IPs: []string{}}
		if as, err := i.Addrs(); err == nil {
			for _, a := range as {
				if ipn, ok := a.(*net.IPNet); ok {
					n.IPs = append(n.IPs, ipn.IP.String())
				}
			}
		}
		out = append(out, n)
	}
	sort.Slice(out, func(a, b int) bool { return out[a].Name < out[b].Name })
	return out
}

// MACs returns the sorted unique MAC addresses.
func MACs() []string {
	seen := map[string]bool{}
	var out []string
	for _, n := range Networks() {
		if n.MAC == "" || n.MAC == "00:00:00:00:00:00" || seen[n.MAC] {
			continue
		}
		seen[n.MAC] = true
		out = append(out, n.MAC)
	}
	if out == nil {
		out = []string{}
	}
	return out
}

// Device builds the enrollment identity block.
func (c *Collector) Device(ctx context.Context, installID, version string) api.Device {
	host, _ := os.Hostname()
	d := api.Device{InstallID: installID, Hostname: host, OS: "windows", Arch: runtime.GOARCH, AgentVersion: version, MACAddresses: MACs()}
	if runtime.GOOS != "windows" {
		d.OS = runtime.GOOS // Linux test mode reports honestly
	}
	if id, err := call(c, ctx, "identity", 30*time.Second, c.P.Identity); err == nil {
		d.MachineGUID, d.Serial, d.Manufacturer, d.Model = id.MachineGUID, id.Serial, id.Manufacturer, id.Model
	}
	type osi struct{ n, v string }
	if r, err := call(c, ctx, "osinfo", 0, func(ctx context.Context) (osi, error) {
		n, v, err := c.P.OSInfo(ctx)
		return osi{n, v}, err
	}); err == nil {
		d.OSVersion = r.v
	}
	return d
}

// Inventory collects everything; unavailable fields stay null.
func (c *Collector) Inventory(ctx context.Context) api.Inventory {
	host, _ := os.Hostname()
	inv := api.Inventory{Hostname: host, OS: runtime.GOOS, Network: Networks(), Disks: []api.DiskInfo{}}
	if runtime.GOOS == "windows" {
		inv.OS = "windows"
	}
	if id, err := call(c, ctx, "identity", 30*time.Second, c.P.Identity); err == nil {
		inv.Manufacturer, inv.Model, inv.Serial = id.Manufacturer, id.Model, id.Serial
	}
	type osi struct{ n, v string }
	if r, err := call(c, ctx, "osinfo", 0, func(ctx context.Context) (osi, error) {
		n, v, err := c.P.OSInfo(ctx)
		return osi{n, v}, err
	}); err == nil {
		inv.OSVersion = r.v
	}
	if model, err := call(c, ctx, "cpumodel", 0, c.P.CPUModel); err == nil {
		inv.CPU = &api.CPUInfo{Model: model, Cores: runtime.NumCPU()} // logical processors
	}
	type mem struct{ t, a uint64 }
	if r, err := call(c, ctx, "mem", 0, func(ctx context.Context) (mem, error) {
		t, a, err := c.P.Memory(ctx)
		return mem{t, a}, err
	}); err == nil && r.t > 0 {
		t := r.t
		inv.MemoryTotalBytes = &t
	}
	if ds, err := call(c, ctx, "disks", 0, c.P.Disks); err == nil {
		for _, d := range ds {
			inv.Disks = append(inv.Disks, api.DiskInfo{Mount: d.Mount, TotalBytes: d.Total, FreeBytes: d.Free, FS: d.FS})
		}
	}
	if u, err := call(c, ctx, "uptime", 0, c.P.UptimeS); err == nil {
		inv.UptimeS = &u
	}
	if u, err := call(c, ctx, "user", 0, c.P.LoggedInUser); err == nil {
		inv.LoggedInUser = strp(u)
	}
	type pr struct{ b bool }
	if r, err := call(c, ctx, "pendingreboot", 0, func(ctx context.Context) (pr, error) {
		b, _, err := c.P.PendingReboot(ctx)
		return pr{b}, err
	}); err == nil {
		b := r.b
		inv.PendingReboot = &b
	}
	if id := c.MeshNodeID(ctx); id != "" {
		inv.MeshNodeID = &id
	}
	return inv
}

// InventoryHash is a change detector that ignores fast-moving values (uptime,
// free space) so routine churn does not force an inventory upload.
func InventoryHash(inv api.Inventory) string {
	c := inv
	c.UptimeS = nil
	c.Disks = append([]api.DiskInfo(nil), inv.Disks...)
	for i := range c.Disks {
		c.Disks[i].FreeBytes = 0
	}
	b, _ := json.Marshal(c)
	s := sha256.Sum256(b)
	return hex.EncodeToString(s[:8])
}
