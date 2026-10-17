package deploy

import (
	"context"
	"errors"
	"regexp"
	"strconv"
	"strings"
	"time"
)

// Linux side. No build tag: the package manager is driven through the injected remote.Runner and FS, so the whole flow is tested on any machine.
// Programs are found at FIXED absolute paths (never through PATH), started from an argv list under /usr/bin/env with a fixed environment, and every
// package name is preceded by "--". Only the repositories the machine already has configured are used: no URL, file path, .deb or .rpm and none
// of the options that switch signature checks off are ever passed (a unit test asserts this over every command the engine builds).

var binDirs = []string{"/usr/bin", "/bin", "/usr/sbin"}

const (
	cmdMax       = 1 << 20
	quickTimeout = 60 * time.Second
	simTimeout   = 10 * time.Minute
	fixedPath    = "PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
)

var fixedEnv = []string{fixedPath, "LC_ALL=C", "DEBIAN_FRONTEND=noninteractive", "APT_LISTCHANGES_FRONTEND=none", "NEEDRESTART_MODE=l"}

type cmd struct {
	out, errs string
	code      int
	err       error // could not start, or the context ended
}

func (c cmd) text() string { return c.out + "\n" + c.errs }

type lx struct {
	e   Engine
	ctx context.Context
}

func (e Engine) findBin(name string) string {
	for _, d := range binDirs {
		if p := d + "/" + name; e.fs().Exists(p) {
			return p
		}
	}
	return ""
}

// run starts prog (an absolute path found by findBin) under env with the fixed environment.
func (x *lx) run(prog string, args []string, timeout time.Duration) cmd {
	env := x.e.findBin("env")
	if env == "" {
		return cmd{err: errors.New("no env")}
	}
	if dl, ok := x.ctx.Deadline(); ok {
		if left := time.Until(dl); left < timeout {
			timeout = left
		}
	}
	argv := append(append(append([]string{}, fixedEnv...), prog), args...)
	r, err := x.e.runner().Run(x.ctx, env, argv, cmdMax, timeout)
	return cmd{out: string(r.Stdout), errs: string(r.Stderr), code: r.ExitCode, err: err}
}

var lockMarks = []string{
	"could not get lock", "unable to acquire the dpkg frontend lock", "unable to lock directory", "unable to lock the administration directory",
	"is another process using it", "waiting for process with pid", "another app is currently holding the yum lock",
	"system management is locked by the application", "/var/lib/dpkg/lock", "/var/lib/apt/lists/lock", "cannot lock",
	"waiting for process with pid", "rpm database is locked", "cannot get exclusive lock",
}

func isLocked(c cmd) bool {
	t := strings.ToLower(c.text())
	for _, m := range lockMarks {
		if strings.Contains(t, m) {
			return true
		}
	}
	return false
}

// ended turns a context end into a failure code ("" = the command ran).
func ended(c cmd) string {
	if c.err == nil {
		return ""
	}
	if errors.Is(c.err, context.DeadlineExceeded) {
		return DetailTimeout
	}
	if errors.Is(c.err, context.Canceled) {
		return "cancelled"
	}
	return "cannot_run"
}

func lastLine(c cmd) string {
	for _, s := range []string{c.errs, c.out} {
		ls := strings.Split(strings.TrimSpace(s), "\n")
		if l := strings.TrimSpace(ls[len(ls)-1]); l != "" {
			return clip(l, maxDetail)
		}
	}
	return ""
}

// ---- detection ----

// manager picks dpkg or rpm for the queries (by what the probe was told, else by what is present).
func (pr *probe) manager() (string, string) {
	e := pr.e
	pm := pr.pm
	if pm == "" {
		if e.findBin("dpkg-query") != "" {
			pm = "dpkg"
		} else {
			pm = "rpm"
		}
	}
	bin := "dpkg-query"
	if pm == "rpm" {
		bin = "rpm"
	}
	if p := e.findBin(bin); p != "" && e.findBin("env") != "" {
		return pm, p
	}
	return pm, ""
}

func (pr *probe) checkPackage(name string) probeRes {
	pm, bin := pr.manager()
	if bin == "" {
		return probeRes{fatal: DetailNoManager}
	}
	x := &lx{e: pr.e, ctx: pr.ctx}
	if pm == "dpkg" {
		c := x.run(bin, []string{"-W", "-f=${db:Status-Abbrev}|${Version}\\n", "--", name}, quickTimeout)
		if d := ended(c); d != "" {
			return probeRes{fatal: d}
		}
		if c.code != 0 && c.code != 1 {
			return probeRes{detail: "query_failed"}
		}
		return parseDpkg(c.out)
	}
	c := x.run(bin, []string{"-q", "--qf", "%{VERSION}-%{RELEASE}\\n", "--", name}, quickTimeout)
	if d := ended(c); d != "" {
		return probeRes{fatal: d}
	}
	switch c.code {
	case 0:
		return parseRpm(c.out)
	case 1:
		return probeRes{}
	}
	return probeRes{detail: "query_failed"}
}

// parseDpkg reads "ii |1.2.3" lines; a package that is only configured, removed or half installed is not installed.
func parseDpkg(out string) probeRes {
	var res probeRes
	for _, l := range strings.Split(out, "\n") {
		st, ver, ok := strings.Cut(l, "|")
		if !ok || len(st) < 2 || st[1] != 'i' {
			continue
		}
		ver = strings.TrimSpace(ver)
		if !res.installed || CompareVersions(ver, res.version) > 0 {
			res.installed, res.version = true, ver
		}
	}
	return res
}

func parseRpm(out string) probeRes {
	var res probeRes
	for _, l := range strings.Split(out, "\n") {
		v := strings.TrimSpace(l)
		if v == "" || strings.HasPrefix(v, "package ") {
			continue
		}
		if !res.installed || CompareVersions(v, res.version) > 0 {
			res.installed, res.version = true, v
		}
	}
	return res
}

// ---- install / uninstall ----

var (
	aptRemvRe = regexp.MustCompile(`^Remv (\S+)`)
	aptInstRe = regexp.MustCompile(`^Inst (\S+) (?:\[[^\]]*\] )?\(([^ )]+)`)
	dnfRemRe  = regexp.MustCompile(`^\s*Remove\s+(\d+)\s+Packages?`)
)

func stripArch(n string) string {
	if i := strings.IndexByte(n, ':'); i > 0 {
		return n[:i]
	}
	return n
}

func (e Engine) installLinux(ctx context.Context, p InstallParams) outcome {
	x := &lx{e: e, ctx: ctx}
	pm := "dpkg"
	var mgr string
	if p.Fmt == FmtApt {
		if e.findBin("apt-get") == "" || e.findBin("dpkg-query") == "" || e.findBin("env") == "" {
			return outcome{cannot: DetailUnsupported}
		}
		mgr = e.findBin("apt-get")
	} else {
		pm = "rpm"
		if mgr = e.findBin("dnf"); mgr == "" {
			mgr = e.findBin("yum")
		}
		if mgr == "" || e.findBin("rpm") == "" || e.findBin("env") == "" {
			return outcome{cannot: DetailUnsupported} // zypper and the rest are not supported
		}
	}
	before := e.newProbe(ctx, pm).check(p.Detect)
	if before.fatal != "" {
		return fail(before.fatal, "detection failed")
	}
	if before.detail != "" {
		return fail(before.detail, "detection failed")
	}
	isSat := satisfied(before.installed, before.version, p.Detect.Min)

	if p.Action == "install" {
		if isSat && (p.Version == "" || before.version == p.Version) {
			return outcome{result: ResAlready, version: before.version}
		}
	} else if !before.installed {
		return outcome{result: ResNotInstalled}
	}

	target := p.Pkg
	if p.Version != "" {
		if p.Fmt == FmtApt {
			target += "=" + p.Version
		} else {
			target += "-" + p.Version
		}
	}
	var simArgs, runArgs []string
	switch {
	case p.Fmt == FmtApt && p.Action == "install":
		base := []string{"--no-install-recommends", "-o", "Dpkg::Options::=--force-confold", "install", "--", target}
		simArgs = append([]string{"-s", "-y"}, base...)
		runArgs = append([]string{"-y"}, base...)
	case p.Fmt == FmtApt:
		simArgs = []string{"-s", "-y", "remove", "--", p.Pkg}
		runArgs = []string{"-y", "remove", "--", p.Pkg}
	case p.Action == "install":
		simArgs = []string{"-y", "--assumeno", "install", "--", target}
		runArgs = []string{"-y", "install", "--", target}
	default:
		simArgs = []string{"-y", "--assumeno", "remove", "--", p.Pkg}
		runArgs = []string{"-y", "remove", "--", p.Pkg}
	}

	// Dependents of an rpm to be removed: refuse before even simulating.
	if p.Fmt == FmtDnf && p.Action == "uninstall" {
		c := x.run(e.findBin("rpm"), []string{"-q", "--whatrequires", "--qf", "%{NAME}\\n", "--", p.Pkg}, quickTimeout)
		if d := ended(c); d != "" {
			return fail(d, "dependency check failed")
		}
		if c.code == 0 {
			var others []string
			for _, l := range strings.Split(c.out, "\n") {
				if l = strings.TrimSpace(l); l != "" && l != p.Pkg && !strings.HasPrefix(l, "no package") {
					others = append(others, l)
				}
			}
			if len(others) > 0 {
				return fail("would_remove_other", "other installed packages require it: "+strings.Join(others, " "))
			}
		}
	}

	// Always simulate first.
	sim := x.run(mgr, simArgs, simTimeout)
	if d := ended(sim); d != "" {
		return fail(d, "simulation did not finish")
	}
	if isLocked(sim) {
		return outcome{result: ResLocked, code: "lock", detail: "the package manager is locked by another process"}
	}
	simVersion, o, stop := x.judgeSim(p, sim)
	if stop {
		return o
	}
	if p.Mode == "dry_run" {
		if p.Action == "install" {
			return outcome{result: ResWouldInstall, version: simVersion}
		}
		return outcome{result: ResWouldUninstall, version: before.version}
	}

	run := x.run(mgr, runArgs, time.Duration(p.MaxMinutes)*time.Minute)
	if d := ended(run); d != "" {
		return fail(d, "the package manager did not finish")
	}
	if isLocked(run) {
		return outcome{result: ResLocked, code: "lock", detail: "the package manager is locked by another process"}
	}
	if run.code != 0 {
		return mapFailure(run)
	}
	after := e.newProbe(ctx, pm).check(p.Detect)
	if after.fatal != "" {
		return fail(after.fatal, "verification could not run")
	}
	reboot := e.fs().Exists("/var/run/reboot-required") || e.fs().Exists("/run/reboot-required")
	if p.Action == "install" {
		if !satisfied(after.installed, after.version, p.Detect.Min) {
			return outcome{result: ResVerifyFailed, code: "not_detected", detail: "the package is not installed at the required version after the run", version: after.version, reboot: reboot}
		}
		return outcome{result: ResInstalled, version: after.version, reboot: reboot}
	}
	if after.installed {
		return outcome{result: ResVerifyFailed, code: "still_installed", detail: "the package is still installed after the run", version: after.version, reboot: reboot}
	}
	return outcome{result: ResUninstalled, reboot: reboot}
}

// mapFailure turns a failed real run into a result.
func mapFailure(c cmd) outcome {
	t := strings.ToLower(c.text())
	if notFoundText(t) {
		return outcome{result: ResNotFound, code: "not_found", detail: lastLine(c)}
	}
	return outcome{result: ResFailed, code: "exit_" + itoa(c.code), detail: lastLine(c)}
}

func notFoundText(t string) bool {
	for _, m := range []string{"unable to locate package", "has no installation candidate", "no match for argument", "unable to find a match", "was not found", "no package ", "couldn't find package"} {
		if strings.Contains(t, m) {
			return true
		}
	}
	return false
}

func itoa(n int) string { return strconv.Itoa(n) }

// judgeSim reads the simulation. stop=true means the returned outcome ends the job.
func (x *lx) judgeSim(p InstallParams, sim cmd) (version string, o outcome, stop bool) {
	if p.Fmt == FmtApt {
		if sim.code != 0 {
			return "", mapFailure(sim), true
		}
		var others []string
		for _, l := range strings.Split(sim.out, "\n") {
			if m := aptRemvRe.FindStringSubmatch(l); m != nil {
				if stripArch(m[1]) != p.Pkg {
					others = append(others, stripArch(m[1]))
				}
			} else if m := aptInstRe.FindStringSubmatch(l); m != nil && stripArch(m[1]) == p.Pkg {
				version = m[2]
			}
		}
		if len(others) > 0 {
			return "", fail("would_remove_other", "would also remove: "+strings.Join(others, " ")), true
		}
		if p.Action == "install" && version == "" {
			return "", fail("nothing_to_install", "the repositories offer no new version to install"), true
		}
		return version, outcome{}, false
	}
	// dnf / yum: --assumeno ends with exit 1 after printing the summary.
	t := sim.out
	if notFoundText(strings.ToLower(sim.text())) {
		return "", mapFailure(sim), true
	}
	hasSummary := strings.Contains(t, "Transaction Summary") || strings.Contains(t, "Dependencies resolved")
	if !hasSummary {
		return "", fail("exit_"+itoa(sim.code), "simulation failed: "+lastLine(sim)), true
	}
	if strings.Contains(t, "Nothing to do") {
		return "", fail("nothing_to_do", "the repositories offer nothing to "+p.Action), true
	}
	removing := strings.Contains(t, "Removing dependent packages") || strings.Contains(t, "Removing unused dependencies")
	for _, l := range strings.Split(t, "\n") {
		if m := dnfRemRe.FindStringSubmatch(l); m != nil && (p.Action == "install" || m[1] != "1") {
			removing = true
		}
		if p.Action == "install" && strings.HasPrefix(strings.TrimSpace(l), "Removing:") {
			removing = true
		}
	}
	if removing {
		return "", fail("would_remove_other", "the transaction would remove other packages"), true
	}
	return "", outcome{}, false
}
