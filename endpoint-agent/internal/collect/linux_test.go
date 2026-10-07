//go:build linux

package collect

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func fakeRoot(t *testing.T) *LinuxPlatform {
	root := t.TempDir()
	w := func(p, s string) {
		full := filepath.Join(root, p)
		os.MkdirAll(filepath.Dir(full), 0o755)
		if err := os.WriteFile(full, []byte(s), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	w("proc/stat", "cpu  100 0 50 800 50 0 0 0 0 0\ncpu0 1 2 3\n")
	w("proc/meminfo", "MemTotal:       2048 kB\nMemFree: 1 kB\nMemAvailable:    512 kB\n")
	w("proc/net/dev", "Inter-|   Receive\n face |bytes\n  lo: 999 0 0 0 0 0 0 0 999 0\neth0: 1000 1 0 0 0 0 0 0 2000 1\n")
	w("proc/uptime", "123.45 99.0\n")
	w("etc/machine-id", "abc123\n")
	return &LinuxPlatform{Root: root + "/"}
}

func TestLinuxParsers(t *testing.T) {
	root := fakeRoot(t)
	p := &LinuxPlatform{Root: root.Root[:len(root.Root)-1]}
	ctx := context.Background()
	idle, total, err := p.CPUTimes(ctx)
	if err != nil || idle != 850 || total != 1000 {
		t.Fatalf("cpu %d %d %v", idle, total, err)
	}
	mt, ma, err := p.Memory(ctx)
	if err != nil || mt != 2048*1024 || ma != 512*1024 {
		t.Fatalf("mem %d %d %v", mt, ma, err)
	}
	rx, tx, err := p.NetBytes(ctx)
	if err != nil || rx != 1000 || tx != 2000 {
		t.Fatalf("net %d %d %v (loopback must be excluded)", rx, tx, err)
	}
	if u, err := p.UptimeS(ctx); err != nil || u != 123 {
		t.Fatalf("uptime %d %v", u, err)
	}
	id, _ := p.Identity(ctx)
	if id.MachineGUID == nil || *id.MachineGUID != "abc123" || id.Serial != nil {
		t.Fatalf("identity %+v", id)
	}
	if _, _, err := (&LinuxPlatform{Root: t.TempDir()}).CPUTimes(ctx); err == nil {
		t.Fatal("missing /proc must be an error, not zeros")
	}
}

func TestRealLinuxPlatformSmoke(t *testing.T) {
	p := NewPlatform()
	if _, tot, err := p.CPUTimes(context.Background()); err != nil || tot == 0 {
		t.Fatalf("%v", err)
	}
	if ds, err := p.Disks(context.Background()); err != nil || len(ds) == 0 {
		t.Fatalf("disks %v %v", ds, err)
	}
}

func writeTree(t *testing.T, root string, files map[string]string) {
	t.Helper()
	for p, s := range files {
		full := filepath.Join(root, p)
		os.MkdirAll(filepath.Dir(full), 0o755)
		if err := os.WriteFile(full, []byte(s), 0o644); err != nil {
			t.Fatal(err)
		}
	}
}

func TestLinuxInventoryFromFakeSysTree(t *testing.T) {
	root := t.TempDir()
	writeTree(t, root, map[string]string{
		"etc/machine-id":                  "0123456789abcdef0123456789abcdef\n",
		"etc/os-release":                  "PRETTY_NAME=\"Ubuntu 24.04.1 LTS\"\nVERSION_ID=\"24.04\"\n",
		"sys/class/dmi/id/product_serial": "To Be Filled By O.E.M.\n",
		"sys/class/dmi/id/board_serial":   "BSN-77\n",
		"sys/class/dmi/id/sys_vendor":     "Dell Inc.\n",
		"sys/class/dmi/id/product_name":   "OptiPlex 7090\n",
		"proc/cpuinfo":                    "processor : 0\nmodel name : AMD EPYC 7B13\n",
		"proc/mounts":                     "/dev/vda1 / ext4 rw 0 0\n",
		"var/run/reboot-required":         "*** System restart required ***\n",
		"var/run/reboot-required.pkgs":    "linux-image-6.8.0-45\nlibc6\n",
	})
	p := &LinuxPlatform{Root: root}
	ctx := context.Background()
	id, _ := p.Identity(ctx)
	if id.MachineGUID == nil || *id.MachineGUID != "0123456789abcdef0123456789abcdef" {
		t.Fatalf("%+v", id)
	}
	if id.Serial == nil || *id.Serial != "BSN-77" {
		t.Fatalf("serial must fall back from the junk product_serial to board_serial: %v", id.Serial)
	}
	if id.Manufacturer == nil || *id.Manufacturer != "Dell Inc." || id.Model == nil || *id.Model != "OptiPlex 7090" {
		t.Fatalf("%+v", id)
	}
	if osn, ver, err := p.OSInfo(ctx); err != nil || osn != "linux" || ver != "Ubuntu 24.04.1 LTS" {
		t.Fatalf("%q %q %v (the VERSION_ID is already in PRETTY_NAME and must not be repeated)", osn, ver, err)
	}
	if m, err := p.CPUModel(ctx); err != nil || m != "AMD EPYC 7B13" {
		t.Fatalf("%q %v", m, err)
	}
	pending, why, _ := p.PendingReboot(ctx)
	if !pending || len(why) != 2 || !strings.Contains(why[1], "linux-image-6.8.0-45 libc6") {
		t.Fatalf("%v %v", pending, why)
	}
	// the mount table is the fake one, the sizes are a real statfs of <root>/
	if ds, err := p.Disks(ctx); err != nil || len(ds) != 1 || ds[0].Mount != "/" || ds[0].FS != "ext4" || ds[0].Total == 0 {
		t.Fatalf("%v %v", ds, err)
	}
}

func TestLinuxIdentityDeviceTreeFallbackAndNothing(t *testing.T) {
	root := t.TempDir()
	writeTree(t, root, map[string]string{
		"sys/firmware/devicetree/base/serial-number": "10000000deadbeef\x00",
		"sys/firmware/devicetree/base/model":         "Raspberry Pi 4 Model B Rev 1.4\x00",
		"var/lib/dbus/machine-id":                    "feedface\n", // /etc/machine-id missing
	})
	id, _ := (&LinuxPlatform{Root: root}).Identity(context.Background())
	if id.Serial == nil || *id.Serial != "10000000deadbeef" || id.Model == nil || *id.Model != "Raspberry Pi 4 Model B Rev 1.4" || id.MachineGUID == nil || *id.MachineGUID != "feedface" {
		t.Fatalf("%+v", id)
	}
	if id.Manufacturer != nil {
		t.Fatalf("manufacturer must be null when unknown, not a guess: %v", *id.Manufacturer)
	}
	empty, _ := (&LinuxPlatform{Root: t.TempDir()}).Identity(context.Background())
	if empty.Serial != nil || empty.MachineGUID != nil || empty.Model != nil || empty.Manufacturer != nil {
		t.Fatalf("an empty tree must give all-null identity: %+v", empty)
	}
	if _, _, err := (&LinuxPlatform{Root: t.TempDir()}).OSInfo(context.Background()); err == nil {
		t.Fatal("missing os-release must be an error")
	}
	if pending, why, err := (&LinuxPlatform{Root: t.TempDir(), NeedsRestarting: "/nonexistent"}).PendingReboot(context.Background()); pending || len(why) != 0 || err != nil {
		t.Fatalf("%v %v %v", pending, why, err)
	}
}

func shimBin(t *testing.T, body string) string {
	p := filepath.Join(t.TempDir(), "shim")
	if err := os.WriteFile(p, []byte("#!/bin/sh\n"+body+"\n"), 0o755); err != nil {
		t.Fatal(err)
	}
	return p
}

func TestLinuxPendingRebootNeedsRestarting(t *testing.T) {
	ctx := context.Background()
	yes := &LinuxPlatform{Root: t.TempDir(), NeedsRestarting: shimBin(t, "test \"$1\" = -r && exit 1")}
	if b, why, _ := yes.PendingReboot(ctx); !b || len(why) != 1 || why[0] != "needs-restarting -r" {
		t.Fatalf("%v %v", b, why)
	}
	no := &LinuxPlatform{Root: t.TempDir(), NeedsRestarting: shimBin(t, "exit 0")}
	if b, _, _ := no.PendingReboot(ctx); b {
		t.Fatal("exit 0 means no reboot needed")
	}
	broken := &LinuxPlatform{Root: t.TempDir(), NeedsRestarting: shimBin(t, "exit 2")}
	if b, _, _ := broken.PendingReboot(ctx); b {
		t.Fatal("a tool error must not be reported as a pending reboot")
	}
}

func TestLinuxServiceStateViaSystemctlShim(t *testing.T) {
	ctx := context.Background()
	sc := shimBin(t, `case "$*" in
*ssh.service) printf 'ActiveState=active\nLoadState=loaded\nUnitFileState=enabled\n';;
*gone.service) printf 'ActiveState=inactive\nLoadState=not-found\nUnitFileState=\n';;
*) echo "unexpected: $*" >&2; exit 1;;
esac`)
	p := &LinuxPlatform{Systemctl: sc}
	if info, err := p.ServiceState(ctx, "ssh.service"); err != nil || info.State != "running" || info.Startup != "automatic" {
		t.Fatalf("%+v %v", info, err)
	}
	if _, err := p.ServiceState(ctx, "gone.service"); !errors.Is(err, ErrNotFound) {
		t.Fatalf("%v", err)
	}
	if _, err := p.ServiceState(ctx, "other.service"); err == nil || !strings.Contains(err.Error(), "unavailable") {
		t.Fatalf("a failing systemctl must be an error (check result unknown), got %v", err)
	}
	if _, err := p.ServiceState(ctx, "--now"); err == nil {
		t.Fatal("option-like service name reached systemctl")
	}
	if _, err := (&LinuxPlatform{Systemctl: "/nonexistent/systemctl"}).ServiceState(ctx, "ssh"); err == nil {
		t.Fatal("missing systemctl must be an error")
	}
}

func TestLinuxLoggedInUserFromUtmp(t *testing.T) {
	root := t.TempDir()
	var b []byte
	b = append(b, utmpRec(7, "alice")...)
	b = append(b, utmpRec(7, "bob")...)
	writeTree(t, root, map[string]string{"var/run/utmp": string(b)})
	p := &LinuxPlatform{Root: root}
	if u, err := p.LoggedInUser(context.Background()); err != nil || u != "alice,bob" {
		t.Fatalf("%q %v", u, err)
	}
	if _, err := (&LinuxPlatform{Root: t.TempDir()}).LoggedInUser(context.Background()); err == nil {
		t.Fatal("no utmp must be an error (null in the inventory), not an empty user")
	}
}

func TestLinuxDisksRealStatfsOnRoot(t *testing.T) {
	ds, err := NewPlatform().Disks(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	for _, d := range ds {
		if d.Total == 0 || d.Free > d.Total || d.FS == "" || d.Mount == "" {
			t.Fatalf("implausible disk %+v", d)
		}
	}
}
