package main

import (
	"encoding/json"
	"fmt"
	"math/rand/v2"
	"time"
)

// The check-in body of the real agent protocol (docs/rmm/PROTOCOL.md), generated with realistic content and sizes: the profile "defaults" is
// the current sample, four buffered 60 s samples (metrics only: the checks run on their own, longer intervals) and three check results (about 1.5 KB); the first check-in of a device also carries the
// inventory. Field names and shapes are the frozen wire format, nothing here is simulator-specific.

type diskMetric struct {
	Mount   string  `json:"mount"`
	UsedPct float64 `json:"used_pct"`
}

type metrics struct {
	CPUPct   float64      `json:"cpu_pct"`
	MemPct   float64      `json:"mem_pct"`
	Disk     []diskMetric `json:"disk"`
	NetRxBps float64      `json:"net_rx_bps"`
	NetTxBps float64      `json:"net_tx_bps"`
}

type checkResult struct {
	Key    string `json:"key"`
	Status string `json:"status"`
	Detail string `json:"detail"`
}

type buffered struct {
	CollectedAt string        `json:"collected_at"`
	Metrics     *metrics      `json:"metrics"`
	Checks      []checkResult `json:"checks"`
}

type checkinBody struct {
	Seq          uint64        `json:"seq"`
	CollectedAt  string        `json:"collected_at"`
	AgentVersion string        `json:"agent_version"`
	Inventory    any           `json:"inventory"`
	Metrics      *metrics      `json:"metrics"`
	Checks       []checkResult `json:"checks"`
	Buffered     []buffered    `json:"buffered"`
}

func rfc3339(t time.Time) string { return t.UTC().Format("2006-01-02T15:04:05Z") }

func sampleMetrics(r *rand.Rand) *metrics {
	return &metrics{
		CPUPct: round2(5 + r.Float64()*60), MemPct: round2(30 + r.Float64()*40),
		Disk:     []diskMetric{{Mount: "C:", UsedPct: round2(40 + r.Float64()*40)}, {Mount: "D:", UsedPct: round2(10 + r.Float64()*30)}},
		NetRxBps: round2(r.Float64() * 5e6), NetTxBps: round2(r.Float64() * 1e6),
	}
}

func round2(v float64) float64 { return float64(int(v*100)) / 100 }

func sampleChecks(r *rand.Rand) []checkResult {
	disk := "ok"
	detail := "C: 61% used"
	if r.IntN(50) == 0 {
		disk, detail = "warn", "C: 88% used"
	}
	return []checkResult{
		{Key: "disk_c", Status: disk, Detail: detail},
		{Key: "pending_reboot", Status: "ok", Detail: ""},
		{Key: "svc_eventlog", Status: "ok", Detail: "EventLog running"},
	}
}

// inventory is the object of the first check-in (and daily): hardware, disks, network.
func inventory(r *rand.Rand, host string, serial string) map[string]any {
	return map[string]any{
		"hostname": host, "os": "windows", "os_version": "Windows 11 23H2 (22631.4317)", "manufacturer": "Dell Inc.", "model": "Latitude 7440", "serial": serial,
		"cpu":                map[string]any{"model": "13th Gen Intel(R) Core(TM) i7-1355U", "cores": 10},
		"memory_total_bytes": uint64(17179869184),
		"disks":              []any{map[string]any{"mount": "C:", "total_bytes": uint64(511101108224), "free_bytes": uint64(150000000000 + r.Uint64N(100000000000)), "fs": "NTFS"}},
		"network":            []any{map[string]any{"name": "Ethernet", "mac": fmt.Sprintf("%02X-%02X-%02X-%02X-%02X-%02X", 0xAA, r.IntN(256), r.IntN(256), r.IntN(256), r.IntN(256), r.IntN(256)), "ips": []string{fmt.Sprintf("10.%d.%d.%d", r.IntN(250), r.IntN(250), 1+r.IntN(250))}}},
		"uptime_s":           uint64(3600 + r.Uint64N(900000)), "logged_in_user": "user" + fmt.Sprint(r.IntN(900)), "pending_reboot": r.IntN(10) == 0,
	}
}

// buildCheckin makes one check-in body. batches is the number of buffered samples before the current one (4 in the defaults profile).
func buildCheckin(r *rand.Rand, seq uint64, now time.Time, version string, batches int, withInventory bool, host, serial string) ([]byte, error) {
	b := checkinBody{Seq: seq, CollectedAt: rfc3339(now), AgentVersion: version, Metrics: sampleMetrics(r), Checks: sampleChecks(r)}
	if withInventory {
		b.Inventory = inventory(r, host, serial)
	}
	for i := batches; i >= 1; i-- {
		b.Buffered = append(b.Buffered, buffered{CollectedAt: rfc3339(now.Add(-time.Duration(i) * 60 * time.Second)), Metrics: sampleMetrics(r), Checks: []checkResult{}})
	}
	return json.Marshal(b)
}

type enrollBody struct {
	EnrollmentToken string         `json:"enrollment_token"`
	Device          map[string]any `json:"device"`
}

func buildEnroll(r *rand.Rand, token string, n int, runID string) ([]byte, string, error) {
	serial := fmt.Sprintf("SIM%s%06d", runID, n)
	host := fmt.Sprintf("SIM-%s-%05d", runID, n)
	b := enrollBody{EnrollmentToken: token, Device: map[string]any{
		"install_id": uuid(r), "machine_guid": fmt.Sprintf("%016x", r.Uint64()), "hostname": host, "os": "windows", "os_version": "Windows 11 23H2", "arch": "amd64",
		"serial": serial, "manufacturer": "Dell Inc.", "model": "Latitude 7440", "mac_addresses": []string{}, "agent_version": "0.0.0-sim",
	}}
	out, err := json.Marshal(b)
	return out, host + "|" + serial, err
}

func uuid(r *rand.Rand) string {
	var b [16]byte
	for i := range b {
		b[i] = byte(r.Uint32())
	}
	b[6] = b[6]&0x0f | 0x40
	b[8] = b[8]&0x3f | 0x80
	return fmt.Sprintf("%08x-%04x-%04x-%04x-%012x", b[0:4], b[4:6], b[6:8], b[8:10], b[10:16])
}
