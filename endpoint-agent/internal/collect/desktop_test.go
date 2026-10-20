package collect

import "testing"

func TestWindowsHasDesktop(t *testing.T) {
	for in, want := range map[string]bool{"Client": true, "Server": true, "Server Core": false, " server core ": false, "Nano Server": false, "": true, "Embedded": true} {
		if got := windowsHasDesktop(in); got != want {
			t.Errorf("%q: %v", in, got)
		}
	}
}

func TestLinuxHasDesktop(t *testing.T) {
	cases := []struct {
		name   string
		target string
		dm     bool
		socks  []string
		want   bool
	}{
		{"headless server", "/lib/systemd/system/multi-user.target", false, nil, false},
		{"graphical target", "/lib/systemd/system/graphical.target", false, nil, true},
		{"display manager on a multi-user box", "multi-user.target", true, nil, true},
		{"x socket", "", false, []string{"X0"}, true},
		{"wayland", "", false, []string{"wayland-0"}, true},
		{"unrelated socket names", "", false, []string{"Xorg", "X", "mysql.sock"}, false},
	}
	for _, c := range cases {
		if got := linuxHasDesktop(c.target, c.dm, c.socks); got != c.want {
			t.Errorf("%s: %v", c.name, got)
		}
	}
}

func TestMountSource(t *testing.T) {
	for in, want := range map[string]string{"rpool/data/subvol-102-disk-0": "rpool/data/subvol-102-disk-0", "none": "", "": "", "-": "", "/dev/sda2": "/dev/sda2"} {
		if got := mountSource(in); got != want {
			t.Errorf("%q: %q", in, got)
		}
	}
}
