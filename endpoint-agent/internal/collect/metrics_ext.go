package collect

import (
	"context"
	"math"

	"rivetit-agent/internal/api"
)

// maxIOPS and maxNetErrors keep a garbage counter from becoming a garbage sample.
const (
	maxIOPS      = 1e7
	maxNetErrors = 1e12
)

// extendedMetrics adds the rc.25 optional fields. Every part is independent: a collector that errors leaves its own fields out and
// never affects the others or the check-in.
func (c *Collector) extendedMetrics(ctx context.Context, m *api.Metrics) {
	if u, err := call(c, ctx, "uptime", 0, c.P.UptimeS); err == nil {
		m.UptimeS = &u
	}
	if b, err := call(c, ctx, "pendingreboot", 0, func(ctx context.Context) (bool, error) {
		b, _, err := c.P.PendingReboot(ctx)
		return b, err
	}); err == nil {
		v := 0
		if b {
			v = 1
		}
		m.PendingReboot = &v
	}
	x, ok := c.P.(ExtraPlatform)
	if !ok {
		return
	}
	now := c.now()
	c.mu.Lock()
	prev := c.extPrev
	c.mu.Unlock()
	cur := &extSample{at: now}

	if cores, err := call(c, ctx, "cores", 0, x.CoreTimes); err == nil {
		if len(cores) > api.MaxCores {
			cores = cores[:api.MaxCores]
		}
		cur.cores = cores
	}
	if io, err := call(c, ctx, "diskio", 0, x.DiskIO); err == nil {
		cur.reads, cur.writes, cur.haveIO = io.Reads, io.Writes, true
		if io.Queue >= 0 && io.Queue < 1e6 && !math.IsNaN(io.Queue) {
			q := math.Round(io.Queue*100) / 100
			m.DiskQueue = &q
		}
	}
	if e, err := call(c, ctx, "neterrors", 0, x.NetErrors); err == nil {
		cur.errs, cur.haveErrs = e, true
	}
	if b, err := call(c, ctx, "battery", 0, x.Battery); err == nil && b.Pct >= 0 && b.Pct <= 100 {
		m.BatteryPct = pct(b.Pct)
		v := 0
		if b.Charging {
			v = 1
		}
		m.BatteryCharging = &v
	}

	c.mu.Lock()
	c.extPrev = cur
	c.mu.Unlock()
	if prev == nil {
		return // rates and per-core utilization need two samples
	}
	if len(prev.cores) == len(cur.cores) && len(cur.cores) > 0 {
		var out []float64
		for i, k := range cur.cores {
			p := prev.cores[i]
			if k.Total <= p.Total || k.Idle < p.Idle || k.Idle-p.Idle > k.Total-p.Total {
				out = nil
				break // a reset or a weird core: leave the whole list out rather than shift cores
			}
			out = append(out, *pct(100 * (1 - float64(k.Idle-p.Idle)/float64(k.Total-p.Total))))
		}
		m.CPUCorePct = out
	}
	el := now.Sub(prev.at).Seconds()
	if el < 1 {
		return
	}
	if cur.haveIO && prev.haveIO && cur.reads >= prev.reads && cur.writes >= prev.writes {
		r, w := float64(cur.reads-prev.reads)/el, float64(cur.writes-prev.writes)/el
		if r <= maxIOPS && w <= maxIOPS {
			r, w = math.Round(r*10)/10, math.Round(w*10)/10
			m.DiskReadIOPS, m.DiskWriteIOPS = &r, &w
		}
	}
	if cur.haveErrs && prev.haveErrs && cur.errs >= prev.errs && float64(cur.errs-prev.errs) < maxNetErrors {
		d := cur.errs - prev.errs
		m.NetErrors = &d
	}
}
