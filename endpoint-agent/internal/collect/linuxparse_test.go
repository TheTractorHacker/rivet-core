package collect

import (
	"encoding/binary"
	"errors"
	"reflect"
	"testing"
)

// These tests need no Linux: the parsers take plain strings.

func TestParseCPUStat(t *testing.T) {
	for _, c := range []struct {
		name, in    string
		idle, total uint64
		wantErr     bool
	}{
		{"classic 10 fields", "cpu  100 0 50 800 50 0 0 0 0 0\ncpu0 1 2 3\n", 850, 1000, false},
		{"guest columns are not added twice", "cpu 10 1 2 70 5 1 1 1 999 999\n", 75, 91, false},
		{"old kernel, 4 columns", "cpu 1 2 3 4\n", 0, 0, true},
		{"not the aggregate line", "cpu0 1 2 3 4 5\n", 0, 0, true},
		{"garbage number", "cpu 1 2 x 4 5 6\n", 0, 0, true},
		{"empty", "", 0, 0, true},
	} {
		idle, total, err := parseCPUStat(c.in)
		if (err != nil) != c.wantErr || idle != c.idle || total != c.total {
			t.Errorf("%s: got %d %d %v", c.name, idle, total, err)
		}
	}
}

func TestParseMeminfo(t *testing.T) {
	tot, av, err := parseMeminfo("MemTotal:       2048 kB\nMemFree: 1 kB\nMemAvailable:    512 kB\nSwapTotal: 9 kB\n")
	if err != nil || tot != 2048*1024 || av != 512*1024 {
		t.Fatalf("%d %d %v", tot, av, err)
	}
	for _, bad := range []string{"", "MemTotal: 5 kB\n", "MemAvailable: 5 kB\n", "MemTotal: x kB\nMemAvailable: 1 kB\n"} {
		if _, _, err := parseMeminfo(bad); err == nil {
			t.Errorf("expected an error for %q", bad)
		}
	}
}

func TestParseNetDev(t *testing.T) {
	in := "Inter-|   Receive                      |  Transmit\n face |bytes    packets errs drop fifo frame compressed multicast|bytes packets errs drop fifo colls carrier compressed\n" +
		"    lo: 999 0 0 0 0 0 0 0 999 0 0 0 0 0 0 0\n" +
		"  eth0: 1000 1 0 0 0 0 0 0 2000 1 0 0 0 0 0 0\n" +
		"ens3:   500 1 0 0 0 0 0 0 100 1 0 0 0 0 0 0\n" +
		"docker0: 7777 1 0 0 0 0 0 0 7777 1 0 0 0 0 0 0\n" +
		"veth1a2b: 5 1 0 0 0 0 0 0 5 1 0 0 0 0 0 0\n" +
		"br-1234: 5 1 0 0 0 0 0 0 5 1 0 0 0 0 0 0\n" +
		"short: 1 2\n"
	rx, tx, err := parseNetDev(in)
	if err != nil || rx != 1500 || tx != 2100 {
		t.Fatalf("rx=%d tx=%d err=%v (loopback and container interfaces must be excluded)", rx, tx, err)
	}
	if _, _, err := parseNetDev(""); err == nil {
		t.Fatal("empty input must be an error")
	}
	// A host whose only interface is named like a prefix of a virtual family still counts it.
	if rx, _, _ := parseNetDev("a\nb\nlogical0: 5 0 0 0 0 0 0 0 5 0\n"); rx != 5 {
		t.Fatalf("interface 'logical0' wrongly treated as loopback: %d", rx)
	}
}

func TestParseUptime(t *testing.T) {
	if u, err := parseUptime("123.45 99.0\n"); err != nil || u != 123 {
		t.Fatalf("%d %v", u, err)
	}
	for _, bad := range []string{"", "x y", "-5 1"} {
		if _, err := parseUptime(bad); err == nil {
			t.Errorf("expected an error for %q", bad)
		}
	}
}

func TestParseOSRelease(t *testing.T) {
	for _, c := range []struct {
		name, in, wantName, wantVer string
		err                         bool
	}{
		{"ubuntu", "NAME=\"Ubuntu\"\nVERSION_ID=\"24.04\"\nPRETTY_NAME=\"Ubuntu 24.04.1 LTS\"\n", "Ubuntu 24.04.1 LTS", "24.04", false},
		{"no pretty name falls back to NAME", "NAME=Alpine Linux\nVERSION_ID=3.20.3\n", "Alpine Linux", "3.20.3", false},
		{"single quotes and escapes", "PRETTY_NAME='Foo \\\"Bar\\\"'\n", "Foo \"Bar\"", "", false},
		{"rolling release has no version", "PRETTY_NAME=\"Arch Linux\"\n", "Arch Linux", "", false},
		{"empty", "", "", "", true},
		{"unrelated keys only", "ID=x\nHOME_URL=y\n", "", "", true},
	} {
		n, v, err := parseOSRelease(c.in)
		if (err != nil) != c.err || n != c.wantName || v != c.wantVer {
			t.Errorf("%s: %q %q %v", c.name, n, v, err)
		}
	}
}

func TestParseCPUModel(t *testing.T) {
	for _, c := range []struct{ name, in, want string }{
		{"x86", "processor : 0\nmodel name\t: Intel(R) Xeon(R) CPU E5-2680 v4 @ 2.40GHz\nmodel name : second\n", "Intel(R) Xeon(R) CPU E5-2680 v4 @ 2.40GHz"},
		{"raspberry pi", "processor : 0\nBogoMIPS : 108.00\nHardware : BCM2835\nModel : Raspberry Pi 4 Model B Rev 1.4\n", "Raspberry Pi 4 Model B Rev 1.4"},
		{"arm64 server without a model name", "processor : 0\nCPU implementer : 0x41\n", ""},
		{"s390 style", "vendor_id : IBM/S390\ncpu : POWER9\n", "POWER9"},
		{"empty value is skipped", "model name :\nHardware : X\n", "X"},
	} {
		if got := parseCPUModel(c.in); got != c.want {
			t.Errorf("%s: got %q want %q", c.name, got, c.want)
		}
	}
}

func TestParseMounts(t *testing.T) {
	in := "proc /proc proc rw 0 0\n" +
		"/dev/sda2 / ext4 rw,relatime 0 0\n" +
		"tmpfs /run tmpfs rw 0 0\n" +
		"/dev/sda2 /var/lib/docker/bind ext4 rw 0 0\n" + // bind mount of the same device
		"/dev/sdb1 /mnt/my\\040disk xfs rw 0 0\n" +
		"/dev/sdc1 /boot/efi vfat rw 0 0\n" +
		"//srv/share /mnt/smb cifs rw 0 0\n" +
		"overlay /var/lib/docker/overlay2/x overlay rw 0 0\n" +
		"short\n"
	got := parseMounts(in)
	want := []MountEntry{{"/dev/sda2", "/", "ext4"}, {"/dev/sdb1", "/mnt/my disk", "xfs"}, {"/dev/sdc1", "/boot/efi", "vfat"}}
	if !reflect.DeepEqual(got, want) {
		t.Fatalf("got %+v", got)
	}
	if len(parseMounts("")) != 0 {
		t.Fatal("empty input must give no mounts")
	}
	if decodeMountField(`a\134b`) != `a\b` || decodeMountField(`x\04`) != `x\04` {
		t.Fatal("octal escape decoding")
	}
}

func TestCleanDMI(t *testing.T) {
	for in, want := range map[string]string{
		"ABC123": "ABC123", "  XYZ \n": "XYZ", "": "", "To Be Filled By O.E.M.": "", "Default string": "", "None": "",
		"System Serial Number": "", "0000000000": "", "FFFFFFFF": "", "0": "", "N/A": "", "VMware-56 4d": "VMware-56 4d",
		"123456789": "", "SN-0001": "SN-0001", "abc": "abc",
	} {
		if got := cleanDMI(in); got != want {
			t.Errorf("cleanDMI(%q)=%q want %q", in, got, want)
		}
	}
	if firstDMI("", "None", "  ", "SER1", "SER2") != "SER1" || firstDMI("", "0000") != "" {
		t.Fatal("firstDMI order")
	}
}

func TestParseSystemctlShow(t *testing.T) {
	for _, c := range []struct {
		name, in string
		want     ServiceInfo
		err      error
		anyErr   bool
	}{
		{"active+enabled", "ActiveState=active\nLoadState=loaded\nUnitFileState=enabled\n", ServiceInfo{"running", "automatic"}, nil, false},
		{"failed+disabled", "ActiveState=failed\nLoadState=loaded\nUnitFileState=disabled\n", ServiceInfo{"stopped", "disabled"}, nil, false},
		{"inactive static", "ActiveState=inactive\nLoadState=loaded\nUnitFileState=static\n", ServiceInfo{"stopped", "manual"}, nil, false},
		{"masked", "ActiveState=inactive\nLoadState=masked\nUnitFileState=masked\n", ServiceInfo{"stopped", "disabled"}, nil, false},
		{"activating", "ActiveState=activating\nLoadState=loaded\nUnitFileState=enabled\n", ServiceInfo{"start_pending", "automatic"}, nil, false},
		{"unknown unit", "ActiveState=inactive\nLoadState=not-found\nUnitFileState=\n", ServiceInfo{}, ErrNotFound, true},
		{"no output", "", ServiceInfo{}, nil, true},
	} {
		got, err := parseSystemctlShow(c.in)
		if (err != nil) != c.anyErr || (c.err != nil && !errors.Is(err, c.err)) || got != c.want {
			t.Errorf("%s: %+v %v", c.name, got, err)
		}
	}
	for _, bad := range []string{"", "-x", "a b", "a/b", "a;b", "a$b", "a\nb"} {
		if validServiceName(bad) == nil {
			t.Errorf("service name %q must be refused", bad)
		}
	}
	if validServiceName("ssh.service") != nil || validServiceName("getty@tty1.service") != nil {
		t.Fatal("ordinary unit names refused")
	}
}

func utmpRec(typ int16, user string) []byte {
	r := make([]byte, 384)
	binary.LittleEndian.PutUint16(r[0:2], uint16(typ))
	copy(r[44:76], user)
	return r
}

func TestParseUtmp(t *testing.T) {
	var b []byte
	b = append(b, utmpRec(2, "reboot")...) // BOOT_TIME
	b = append(b, utmpRec(7, "alice")...)  // USER_PROCESS
	b = append(b, utmpRec(7, "bob")...)
	b = append(b, utmpRec(8, "carol")...) // DEAD_PROCESS
	b = append(b, utmpRec(7, "alice")...) // second session of the same user
	b = append(b, 1, 2, 3)                // trailing partial record is ignored
	if got := parseUtmp(b); !reflect.DeepEqual(got, []string{"alice", "bob"}) {
		t.Fatalf("got %v", got)
	}
	if len(parseUtmp(nil)) != 0 {
		t.Fatal("nil utmp")
	}
}
