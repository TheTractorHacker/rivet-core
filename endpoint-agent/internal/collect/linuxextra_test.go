//go:build linux

package collect

import (
	"context"
	"os"
	"path/filepath"
	"testing"
)

func TestParseCoreStat(t *testing.T) {
	cs, err := parseCoreStat("cpu  10 0 10 80 0 0 0 0\ncpu0 5 0 5 40 0 0 0 0\ncpu1 5 0 5 40 0 0 0 0\nintr 1\n")
	if err != nil || len(cs) != 2 || cs[0].Idle != 40 || cs[0].Total != 50 {
		t.Fatalf("%v %v", cs, err)
	}
	if _, err := parseCoreStat("cpu 1 2 3 4 5\n"); err == nil {
		t.Fatal("aggregate-only must error")
	}
}

func TestParseDiskstats(t *testing.T) {
	s := "   8 0 sda 100 0 0 0 50 0 0 0 2 0 0\n   8 1 sda1 90 0 0 0 40 0 0 0 0 0 0\n   7 0 loop0 5 0 0 0 0 0 0 0 0 0 0\n 259 0 nvme0n1 10 0 0 0 20 0 0 0 1 0 0\n"
	io, err := parseDiskstats(s)
	if err != nil || io.Reads != 110 || io.Writes != 70 || io.Queue != 3 {
		t.Fatalf("%+v %v", io, err)
	}
	if _, err := parseDiskstats("   7 0 loop0 5 0 0 0 0 0 0 0 0 0 0\n"); err == nil {
		t.Fatal("no physical disks must error")
	}
}

func TestParseNetErrors(t *testing.T) {
	s := "h1\nh2\n  eth0: 100 1 3 0 0 0 0 0 200 1 4 0 0 0 0 0\n    lo: 5 1 9 0 0 0 0 0 5 1 9 0 0 0 0 0\n"
	if n, err := parseNetErrors(s); err != nil || n != 7 {
		t.Fatalf("%d %v", n, err)
	}
}

func TestLinuxBattery(t *testing.T) {
	root := t.TempDir()
	p := &LinuxPlatform{Root: root}
	if _, err := p.Battery(context.Background()); err != ErrUnsupported {
		t.Fatalf("no battery: %v", err)
	}
	d := filepath.Join(root, "sys/class/power_supply/BAT0")
	os.MkdirAll(d, 0o755)
	os.WriteFile(filepath.Join(d, "capacity"), []byte("64\n"), 0o644)
	os.WriteFile(filepath.Join(d, "status"), []byte("Discharging\n"), 0o644)
	if b, err := p.Battery(context.Background()); err != nil || b.Pct != 64 || b.Charging {
		t.Fatalf("%+v %v", b, err)
	}
}
