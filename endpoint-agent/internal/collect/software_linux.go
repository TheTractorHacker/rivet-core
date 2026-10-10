//go:build linux

package collect

import (
	"context"
	"errors"
	"io/fs"
	"log/slog"
	"os/exec"
	"time"
)

// SoftwareSupported reports whether this build can collect the software inventory.
func SoftwareSupported() bool { return true }

func hideProc(*exec.Cmd) {}

type linuxSource struct {
	tool    string
	args    []string
	timeout time.Duration // per tool, so one hung daemon cannot eat the whole budget
	parse   func([]byte) []SoftwareItem
}

// linuxSources: dpkg and rpm read local databases (fast); snap and flatpak talk
// to a daemon / read remotes and get a shorter leash. A box has usually one
// system package manager. The sum of the timeouts stays below the Collector's
// 15 s hard timeout.
var linuxSources = []linuxSource{
	{"dpkg-query", []string{"-W", `-f=${binary:Package}` + "\t" + `${Version}` + "\t" + `${Maintainer}` + "\t" + `${db:Status-Abbrev}` + "\n"}, 4 * time.Second, ParseDpkg},
	{"rpm", []string{"-qa", "--qf", `%{NAME}` + "\t" + `%{VERSION}-%{RELEASE}` + "\t" + `%{VENDOR}` + "\t" + `%{INSTALLTIME}` + "\n"}, 4 * time.Second, ParseRPM},
	{"snap", []string{"list"}, 3 * time.Second, ParseSnap},
	{"flatpak", []string{"list", "--columns=application,version,origin"}, 3 * time.Second, ParseFlatpak},
}

// Software lists the installed packages of every package manager present
// (dpkg, rpm, snap, flatpak). A missing tool is skipped silently, a failing or
// hung one with a log line; when no source worked at all it is an error.
func (p *LinuxPlatform) Software(ctx context.Context) (SoftwareList, error) {
	run := p.Run
	if run == nil {
		run = execRunner
	}
	return linuxSoftware(ctx, run, slog.Default())
}

func linuxSoftware(ctx context.Context, run CmdRunner, log *slog.Logger) (SoftwareList, error) {
	var items []SoftwareItem
	worked := 0
	for _, s := range linuxSources {
		cctx, cancel := context.WithTimeout(ctx, s.timeout)
		out, err := run(cctx, s.tool, s.args...)
		cancel()
		if err != nil {
			if errors.Is(err, exec.ErrNotFound) || errors.Is(err, fs.ErrNotExist) {
				continue
			}
			log.Warn("software inventory: package tool failed; skipping it", "tool", s.tool, "err", err)
			continue
		}
		worked++
		items = append(items, s.parse(out)...)
	}
	if worked == 0 {
		return SoftwareList{}, errors.New("no package source could be read (dpkg, rpm, snap, flatpak)")
	}
	return SoftwareList{Items: items}, nil
}
