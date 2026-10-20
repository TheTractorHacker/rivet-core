package collect

import (
	"context"
	"strings"
)

// DesktopSessioner is implemented by a Platform that can say whether the machine has a desktop (additive inventory field
// `desktop_session`). A Platform without it simply leaves the field out and the server falls back to its OS rules.
type DesktopSessioner interface {
	DesktopSession(ctx context.Context) (bool, error)
}

// windowsHasDesktop reports whether a Windows InstallationType ("Client", "Server", "Server Core", "Nano Server", "Embedded") has a desktop shell.
func windowsHasDesktop(installationType string) bool {
	t := strings.ToLower(strings.TrimSpace(installationType))
	return t != "server core" && t != "nano server"
}

// mountSource cleans the device column of /proc/mounts for the inventory: "none", "-" and an empty value carry no information.
func mountSource(dev string) string {
	d := strings.TrimSpace(dev)
	if d == "" || d == "none" || d == "-" || len(d) > 200 {
		return ""
	}
	return d
}

// linuxHasDesktop decides from three facts: the default systemd target (the target of default.target), whether a display-manager unit is enabled, and the names found
// under /tmp/.X11-unix and /run/user/*/ (an X socket "X0" or a Wayland socket "wayland-0").
func linuxHasDesktop(defaultTarget string, displayManager bool, sockets []string) bool {
	if strings.Contains(defaultTarget, "graphical.target") || displayManager {
		return true
	}
	for _, s := range sockets {
		if (strings.HasPrefix(s, "X") && len(s) > 1 && s[1] >= '0' && s[1] <= '9') || strings.HasPrefix(s, "wayland-") {
			return true
		}
	}
	return false
}
