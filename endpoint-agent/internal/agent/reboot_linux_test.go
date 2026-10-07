//go:build linux

package agent

import (
	"errors"
	"io"
	"log/slog"
	"strings"
	"testing"
	"time"
)

type rec struct {
	calls []string
	fail  map[string]bool
}

func (r *rec) run(name string, args ...string) ([]byte, error) {
	r.calls = append(r.calls, name+" "+strings.Join(args, " "))
	base := name[strings.LastIndex(name, "/")+1:]
	if r.fail[base] {
		return []byte("boom"), errors.New("exit status 1")
	}
	return nil, nil
}

func rebooter(r *rec, have ...string) linuxRebooter {
	return linuxRebooter{log: slog.New(slog.NewTextHandler(io.Discard, nil)), run: r.run,
		lookPath: func(n string) (string, error) {
			for _, h := range have {
				if h == n {
					return "/usr/bin/" + n, nil
				}
			}
			return "", errors.New("not found")
		}}
}

func TestLinuxRebootPrefersSystemdTimer(t *testing.T) {
	r := &rec{}
	if err := rebooter(r, "systemd-run", "shutdown").Schedule(90 * time.Second); err != nil {
		t.Fatal(err)
	}
	want := []string{"systemctl stop rivetit-agent-reboot.timer", "/usr/bin/systemd-run --quiet --unit=rivetit-agent-reboot --on-active=90s systemctl reboot"}
	if strings.Join(r.calls, "|") != strings.Join(want, "|") {
		t.Fatalf("%v", r.calls)
	}
}

func TestLinuxRebootMinimumDelayAndFallback(t *testing.T) {
	r := &rec{fail: map[string]bool{"systemd-run": true}}
	if err := rebooter(r, "systemd-run", "shutdown").Schedule(time.Second); err != nil {
		t.Fatal(err)
	}
	last := r.calls[len(r.calls)-1]
	if last != "/usr/bin/shutdown -r +1 RivetIT maintenance reboot" {
		t.Fatalf("%v", r.calls)
	}
	if !strings.Contains(strings.Join(r.calls, "|"), "--on-active=5s") {
		t.Fatalf("minimum delay not applied: %v", r.calls)
	}
	r = &rec{}
	if err := rebooter(r, "shutdown").Schedule(150 * time.Second); err != nil {
		t.Fatal(err)
	}
	if r.calls[0] != "/usr/bin/shutdown -r +3 RivetIT maintenance reboot" {
		t.Fatalf("minutes must round up: %v", r.calls)
	}
}

func TestLinuxRebootErrors(t *testing.T) {
	if err := rebooter(&rec{}).Schedule(time.Minute); err == nil || !strings.Contains(err.Error(), "neither") {
		t.Fatalf("%v", err)
	}
	r := &rec{fail: map[string]bool{"systemd-run": true, "shutdown": true}}
	err := rebooter(r, "systemd-run", "shutdown").Schedule(time.Minute)
	if err == nil || !strings.Contains(err.Error(), "systemd-run") || !strings.Contains(err.Error(), "shutdown") {
		t.Fatalf("both failures must be reported: %v", err)
	}
}
