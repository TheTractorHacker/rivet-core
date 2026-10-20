package collect

import (
	"context"
	"encoding/json"
	"errors"
	"strings"
	"testing"
	"time"
)

type fakeExtra struct {
	*fake
	cores  []CoreTime
	io     DiskIO
	errs   uint64
	bat    *Battery
	failIO bool
}

func (f *fakeExtra) CoreTimes(context.Context) ([]CoreTime, error) {
	if err := f.gate("cores"); err != nil {
		return nil, err
	}
	return append([]CoreTime(nil), f.cores...), nil
}
func (f *fakeExtra) DiskIO(context.Context) (DiskIO, error) {
	if f.failIO {
		return DiskIO{}, errors.New("io broke")
	}
	return f.io, nil
}
func (f *fakeExtra) NetErrors(context.Context) (uint64, error) { return f.errs, nil }
func (f *fakeExtra) Battery(context.Context) (Battery, error) {
	if f.bat == nil {
		return Battery{}, ErrUnsupported
	}
	return *f.bat, nil
}

func TestExtendedMetricsEveryCheckin(t *testing.T) {
	f := &fakeExtra{fake: newFake(), cores: []CoreTime{{0, 0}, {0, 0}}, io: DiskIO{Queue: 1.234}, bat: &Battery{Pct: 81.26, Charging: true}}
	f.disks = []DiskStat{{Mount: "C:", Total: 1000, Free: 400}}
	c := newCol(f.fake)
	c.P = f
	now := time.Unix(1000, 0)
	c.Now = func() time.Time { return now }
	m := c.Metrics(context.Background())
	// no rates on the first sample, but the instantaneous ones are there
	if m.MemTotalBytes == nil || *m.MemTotalBytes != 1000 || *m.MemAvailableBytes != 250 || *m.MemUsedBytes != 750 {
		t.Fatalf("memory %v %v %v", m.MemTotalBytes, m.MemAvailableBytes, m.MemUsedBytes)
	}
	if len(m.Disk) != 1 || *m.Disk[0].FreeBytes != 400 || *m.Disk[0].TotalBytes != 1000 {
		t.Fatalf("disk %+v", m.Disk)
	}
	if m.UptimeS == nil || *m.UptimeS != 5 || m.PendingReboot == nil || *m.PendingReboot != 0 {
		t.Fatal("uptime/pending reboot")
	}
	if m.BatteryPct == nil || *m.BatteryPct != 81.3 || *m.BatteryCharging != 1 || m.DiskQueue == nil || *m.DiskQueue != 1.23 {
		t.Fatalf("battery/queue %v %v", m.BatteryPct, m.DiskQueue)
	}
	if m.CPUCorePct != nil || m.DiskReadIOPS != nil || m.NetErrors != nil {
		t.Fatal("rates must be absent on the first sample")
	}
	now = now.Add(10 * time.Second)
	f.cores = []CoreTime{{50, 100}, {100, 100}}
	f.io = DiskIO{Reads: 100, Writes: 50}
	f.errs = 7
	m = c.Metrics(context.Background())
	if len(m.CPUCorePct) != 2 || m.CPUCorePct[0] != 50 || m.CPUCorePct[1] != 0 {
		t.Fatalf("cores %v", m.CPUCorePct)
	}
	if m.DiskReadIOPS == nil || *m.DiskReadIOPS != 10 || *m.DiskWriteIOPS != 5 || m.NetErrors == nil || *m.NetErrors != 7 {
		t.Fatalf("iops/errors %v %v %v", m.DiskReadIOPS, m.DiskWriteIOPS, m.NetErrors)
	}
	// counter reset: the rate is omitted, not negative or zero
	now = now.Add(10 * time.Second)
	f.io = DiskIO{Reads: 1, Writes: 1}
	f.errs = 0
	m = c.Metrics(context.Background())
	if m.DiskReadIOPS != nil || m.NetErrors != nil {
		t.Fatal("reset must omit")
	}
}

func TestExtendedMetricsFailuresOmitOnlyThatField(t *testing.T) {
	f := &fakeExtra{fake: newFake(), cores: []CoreTime{{0, 0}}, failIO: true}
	f.fail["cores"] = true
	c := newCol(f.fake)
	c.P = f
	b, _ := json.Marshal(c.Metrics(context.Background()))
	s := string(b)
	for _, absent := range []string{"cpu_core_pct", "disk_queue", "disk_read_iops", "battery_pct", "net_errors"} {
		if strings.Contains(s, absent) {
			t.Fatalf("%s present in %s", absent, s)
		}
	}
	if !strings.Contains(s, `"mem_used_bytes":750`) {
		t.Fatalf("basic fields lost: %s", s)
	}
}

func TestExtendedMetricsCoreCap(t *testing.T) {
	f := &fakeExtra{fake: newFake(), cores: make([]CoreTime, 300)}
	c := newCol(f.fake)
	c.P = f
	now := time.Unix(1000, 0)
	c.Now = func() time.Time { return now }
	c.Metrics(context.Background())
	for i := range f.cores {
		f.cores[i] = CoreTime{10, 20}
	}
	now = now.Add(5 * time.Second)
	if m := c.Metrics(context.Background()); len(m.CPUCorePct) != 256 {
		t.Fatalf("cap: %d", len(m.CPUCorePct))
	}
}

func TestOldPlatformStillWorks(t *testing.T) {
	m := newCol(newFake()).Metrics(context.Background()) // plain fake has no ExtraPlatform
	if m.CPUCorePct != nil || m.BatteryPct != nil || m.MemUsedBytes == nil {
		t.Fatal("plain platform")
	}
}
