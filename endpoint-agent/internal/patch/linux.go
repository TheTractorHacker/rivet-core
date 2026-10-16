package patch

import (
	"context"
	"errors"
	"regexp"
	"strconv"
	"strings"
	"time"

	"rivetit-agent/internal/remote"
)

// Linux side. No build tag: the package manager is driven through the injected remote.Runner and FS, so the whole flow is tested on any machine with
// captured command output. Programs are found at FIXED absolute paths (never through PATH), started from an argv list, and every package name is
// preceded by "--".

var binDirs = []string{"/usr/bin", "/bin", "/usr/sbin"}

const (
	cmdMax       = 4 << 20 // bytes kept of one command's output
	listTimeout  = 90 * time.Second
	quickTimeout = 30 * time.Second
	fixedPath    = "PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
	logTail      = 512 << 10
)

// cmd is the outcome of one command.
type cmd struct {
	out, errs string
	code      int
	trunc     bool
	err       error // could not start, or the context ended
}

func (c cmd) text() string { return c.out + "\n" + c.errs }

type lx struct {
	e    Engine
	ctx  context.Context
	mgr  string
	bin  map[string]string
	locs *time.Location
}

func (e Engine) findBin(name string) string {
	for _, d := range binDirs {
		if p := d + "/" + name; e.fs().Exists(p) {
			return p
		}
	}
	return ""
}

// detect picks the package manager by the binaries present, in the order apt, dnf, yum, zypper.
func (e Engine) detect(ctx context.Context) (*lx, bool) {
	x := &lx{e: e, ctx: ctx, bin: map[string]string{}, locs: time.Local}
	need := func(names ...string) bool {
		for _, n := range names {
			p := e.findBin(n)
			if p == "" {
				return false
			}
			x.bin[n] = p
		}
		return true
	}
	switch {
	case need("apt-get", "dpkg-query", "apt", "apt-cache", "env"):
		x.mgr = ManagerApt
		x.bin["apt-mark"] = e.findBin("apt-mark")
	case need("dnf", "rpm", "env"):
		x.mgr = ManagerDnf
	case need("yum", "rpm", "env"):
		x.mgr = ManagerYum
	case need("zypper", "rpm", "env"):
		x.mgr = ManagerZypper
	default:
		return nil, false
	}
	x.bin["needs-restarting"] = e.findBin("needs-restarting")
	return x, true
}

func (x *lx) run(bin string, args []string, timeout time.Duration) cmd {
	if dl, ok := x.ctx.Deadline(); ok {
		if left := time.Until(dl); left < timeout {
			timeout = left
		}
	}
	r, err := x.e.runner().Run(x.ctx, bin, args, cmdMax, timeout)
	return cmd{out: string(r.Stdout), errs: string(r.Stderr), code: r.ExitCode, trunc: r.Truncated, err: err}
}

// runEnv runs bin under /usr/bin/env with the fixed, non-interactive environment (still an argv list).
func (x *lx) runEnv(extra []string, bin string, args []string, timeout time.Duration) cmd {
	a := append([]string{fixedPath, "LC_ALL=C"}, extra...)
	a = append(a, bin)
	a = append(a, args...)
	return x.run(x.bin["env"], a, timeout)
}

var lockMarks = []string{
	"could not get lock", "unable to acquire the dpkg frontend lock", "unable to lock directory", "unable to lock the administration directory",
	"is another process using it", "waiting for process with pid", "another app is currently holding the yum lock",
	"system management is locked by the application", "/var/lib/dpkg/lock", "/var/lib/apt/lists/lock", "cannot lock",
}

// isLocked reports whether the package manager said another process holds its lock.
func isLocked(c cmd) bool {
	t := strings.ToLower(c.text())
	for _, m := range lockMarks {
		if strings.Contains(t, m) {
			return true
		}
	}
	return false
}

// failDetail turns a command that failed to start or ended by the context into a stable detail code ("" = it ran).
func failDetail(c cmd) string {
	if c.err == nil {
		return ""
	}
	if errors.Is(c.err, context.DeadlineExceeded) || errors.Is(c.err, context.Canceled) {
		if isLocked(c) {
			return DetailLockHeld
		}
		return DetailTimeout
	}
	return DetailScanFailed
}

// lastLine is the last non-empty line of the error text (else of the output), the thing a human wants to read.
func lastLine(c cmd) string {
	for _, s := range []string{c.errs, c.out} {
		ls := strings.Split(strings.TrimSpace(s), "\n")
		if l := strings.TrimSpace(ls[len(ls)-1]); l != "" {
			return clip(l, maxDetail)
		}
	}
	return ""
}

var rebootNameRe = regexp.MustCompile(`^(linux-image-.*|linux-generic.*|linux-(aws|azure|gcp|oracle|kvm|lowlatency)|kernel(-core)?|kernel-default|libc6|glibc|systemd|dbus|dbus-broker|linux-firmware|intel-microcode|amd64-microcode|microcode_ctl)$`)

func baseName(id string) string {
	if i := strings.IndexAny(id, ":"); i > 0 {
		id = id[:i]
	}
	if n, _, ok := splitNameArch(id); ok {
		return n
	}
	return id
}

func wantKind(cats []string, kind string) bool {
	if len(cats) == 0 {
		return true
	}
	for _, c := range cats {
		if c == kind {
			return true
		}
	}
	return false
}

// scanned is a complete Linux scan: the output plus, per id, what the installer needs (the candidate version, or zypper's real patch name).
type scanned struct {
	out    ScanOut
	target map[string]string
}

func (e Engine) scanLinux(ctx context.Context, p ScanParams, maxOut int) remote.Result {
	x, ok := e.detect(ctx)
	if !ok {
		return remote.Fail(DetailNoManager, nil)
	}
	sc, detail, msg := x.scan(p.Categories)
	if detail != "" {
		return failWith(detail, msg)
	}
	return finishScan(sc.out, p.MaxItems, maxOut)
}

func failWith(detail, msg string) remote.Result {
	if msg == "" {
		return remote.Fail(detail, nil)
	}
	return remote.Fail(detail, map[string]any{"message": clip(msg, maxDetail)})
}

// scan runs the full scan of the detected manager. detail is a failure code ("" = success).
func (x *lx) scan(cats []string) (sc scanned, detail, msg string) {
	sc.out = ScanOut{V: 1, OK: true, Manager: x.mgr, ScannedAt: rfc3339(x.e.now()), ScanState: "ok", WUState: "n/a", Updates: []Update{}}
	sc.target = map[string]string{}
	switch x.mgr {
	case ManagerApt:
		detail, msg = x.scanApt(&sc, cats)
	case ManagerDnf, ManagerYum:
		detail, msg = x.scanRpm(&sc, cats)
	case ManagerZypper:
		detail, msg = x.scanZypper(&sc, cats)
	}
	if detail != "" {
		return sc, detail, msg
	}
	sc.out.Total = len(sc.out.Updates)
	sc.out.RebootPending = x.rebootPending()
	sc.out.LastInstallAt = x.lastInstall()
	return sc, "", ""
}

func (x *lx) rebootPending() bool {
	for _, f := range []string{"/var/run/reboot-required", "/run/reboot-required", "/var/run/reboot-needed", "/run/reboot-needed"} {
		if x.e.fs().Exists(f) {
			return true
		}
	}
	if (x.mgr == ManagerDnf || x.mgr == ManagerYum) && x.bin["needs-restarting"] != "" {
		c := x.run(x.bin["needs-restarting"], []string{"-r"}, quickTimeout)
		return c.err == nil && c.code == 1 // exit 1 = a reboot is needed
	}
	return false
}

func (x *lx) tail(path string) string {
	b, err := x.e.fs().ReadTail(path, logTail)
	if err != nil {
		return ""
	}
	return string(b)
}

func (x *lx) lastInstall() *string {
	var t time.Time
	switch x.mgr {
	case ManagerApt:
		if t = aptHistoryAt(x.tail("/var/log/apt/history.log"), x.locs); t.IsZero() {
			t = dpkgLogAt(x.tail("/var/log/dpkg.log"), x.locs)
		}
	case ManagerDnf, ManagerYum:
		t = dnfLogAt(x.tail("/var/log/dnf.rpm.log"))
	case ManagerZypper:
		t = zyppHistoryAt(x.tail("/var/log/zypp/history"), x.locs)
	}
	if t.IsZero() {
		return nil
	}
	s := rfc3339(t)
	return &s
}

// ---- apt

func (x *lx) scanApt(sc *scanned, cats []string) (string, string) {
	c := x.run(x.bin["apt"], []string{"list", "--upgradable"}, listTimeout)
	if d := failDetail(c); d != "" {
		return d, lastLine(c)
	}
	if c.code != 0 {
		if isLocked(c) {
			return DetailLockHeld, lastLine(c)
		}
		return DetailScanFailed, lastLine(c)
	}
	if c.trunc {
		sc.out.ScanState = "partial"
	}
	pkgs := parseAptList(c.out)

	holds := map[string]bool{}
	if x.bin["apt-mark"] != "" {
		h := x.run(x.bin["apt-mark"], []string{"showhold"}, quickTimeout)
		if h.err != nil || h.code != 0 {
			sc.out.ScanState = "partial"
		} else {
			holds = parseHolds(h.out)
		}
	} else {
		sc.out.ScanState = "partial"
	}

	// A package that exists for several architectures is told apart by name:arch for all of them.
	count := map[string]int{}
	for _, p := range pkgs {
		count[p.Name]++
	}
	idOf := func(p aptPkg) string {
		if count[p.Name] > 1 {
			return p.Name + ":" + p.Arch
		}
		return p.Name
	}
	var ids []string
	var live []aptPkg
	for _, p := range pkgs {
		id := idOf(p)
		if !ValidLinuxID(id) || holds[p.Name] || holds[id] {
			continue
		}
		live = append(live, p)
		ids = append(ids, id)
	}

	policy := map[string]aptPolicy{}
	failed := false
	for i := 0; i < len(ids); i += 50 {
		j := i + 50
		if j > len(ids) {
			j = len(ids)
		}
		pc := x.run(x.bin["apt-cache"], append([]string{"policy", "--"}, ids[i:j]...), listTimeout)
		if pc.err != nil || pc.code != 0 {
			failed = true
			continue
		}
		for k, v := range parseAptPolicy(pc.out) {
			policy[k] = v
		}
	}
	if failed {
		sc.out.ScanState = "partial"
	}

	seen := map[string]bool{}
	for i, p := range live {
		id := ids[i]
		if seen[id] {
			continue
		}
		seen[id] = true
		pol, has := policy[id]
		if !has {
			sc.out.ScanState = "partial" // origin of this package unknown
		}
		// The policy candidate must be the version the list offers; otherwise its origin lines say nothing about it.
		sec := has && pol.Security && pol.Candidate == p.New
		for _, s := range p.Suites {
			if securityOrigin(s) {
				sec = true
			}
		}
		u := Update{ID: id, Title: clip(p.Name, maxTitle), Kind: "optional", Severity: "unspecified", EulaAccepted: true, Categories: []string{"Software Updates"},
			Current: clip(p.Old, 100), Version: clip(p.New, 100), RebootRequired: rebootNameRe.MatchString(p.Name)}
		if sec {
			u.Kind, u.Severity, u.Categories = "security", "important", []string{"Security Updates"}
		}
		if wantKind(cats, u.Kind) {
			sc.out.Updates = append(sc.out.Updates, u)
			sc.target[id] = p.New
		}
	}
	return "", ""
}

// ---- dnf / yum

func (x *lx) scanRpm(sc *scanned, cats []string) (string, string) {
	bin := x.bin[x.mgr]
	c := x.run(bin, []string{"-q", "check-update"}, 3*listTimeout)
	if d := failDetail(c); d != "" {
		return d, lastLine(c)
	}
	switch c.code {
	case 0:
		return "", "" // nothing to update
	case 100:
	default:
		if isLocked(c) {
			return DetailLockHeld, lastLine(c)
		}
		return DetailScanFailed, lastLine(c)
	}
	if c.trunc {
		sc.out.ScanState = "partial"
	}
	rows := parseCheckUpdate(c.out)

	args := []string{"-q", "updateinfo", "list", "--security"}
	if x.mgr == ManagerYum {
		args = []string{"-q", "updateinfo", "list", "security"}
	}
	adv := map[string]advisory{}
	if u := x.run(bin, args, 2*listTimeout); u.err != nil || u.code != 0 {
		sc.out.ScanState = "partial" // advisories unavailable: nothing can be called a security update
	} else {
		adv = parseUpdateinfo(u.out)
	}

	cur := x.rpmVersions(idsOfRows(rows))
	seen := map[string]bool{}
	for _, r := range rows {
		id := r.ID()
		if seen[id] {
			continue
		}
		seen[id] = true
		u := Update{ID: id, Title: clip(r.Name, maxTitle), Kind: "optional", Severity: "unspecified", EulaAccepted: true, Categories: []string{"Software Updates"},
			Version: clip(strings.TrimPrefix(r.Version, "0:"), 100), RebootRequired: rebootNameRe.MatchString(r.Name)}
		if vs := cur[id]; len(vs) > 0 {
			u.Current = clip(vs[len(vs)-1], 100)
		}
		if a, ok := adv[id]; ok {
			u.Kind, u.Severity, u.Categories = "security", a.Severity, []string{"Security Updates"}
			if a.Severity == "critical" {
				u.Kind = "critical"
			}
			u.Title = clip(r.Name+" ("+a.ID+")", maxTitle)
		}
		if wantKind(cats, u.Kind) {
			sc.out.Updates = append(sc.out.Updates, u)
			sc.target[id] = u.Version
		}
	}
	return "", ""
}

func idsOfRows(rows []rpmUpdate) []string {
	var ids []string
	for _, r := range rows {
		ids = append(ids, r.ID())
	}
	return ids
}

const rpmQF = `%{NAME}.%{ARCH} %|EPOCH?{%{EPOCH}:}|%{VERSION}-%{RELEASE}\n`

// rpmVersions asks rpm for the installed versions of ids (name.arch). Failures give an empty map.
func (x *lx) rpmVersions(ids []string) map[string][]string {
	res := map[string][]string{}
	for i := 0; i < len(ids); i += 200 {
		j := i + 200
		if j > len(ids) {
			j = len(ids)
		}
		c := x.run(x.bin["rpm"], append([]string{"-q", "--qf", rpmQF, "--"}, ids[i:j]...), quickTimeout)
		if c.err != nil {
			continue
		}
		for k, v := range parseRpmVersions(c.out) {
			res[k] = append(res[k], v...)
		}
	}
	return res
}

// ---- zypper

func (x *lx) scanZypper(sc *scanned, cats []string) (string, string) {
	// list-patches shows patches only (not the plain package updates): the scan is always reported as partial.
	sc.out.ScanState = "partial"
	c := x.run(x.bin["zypper"], []string{"--non-interactive", "--quiet", "list-patches"}, 2*listTimeout)
	if d := failDetail(c); d != "" {
		return d, lastLine(c)
	}
	if c.code == 7 || isLocked(c) {
		return DetailLockHeld, lastLine(c)
	}
	if c.code != 0 {
		return DetailScanFailed, lastLine(c)
	}
	seen := map[string]bool{}
	for _, zp := range parseZypperPatches(c.out) {
		id := strings.ToLower(zp.Name)
		if !ValidLinuxID(id) || seen[id] {
			continue
		}
		seen[id] = true
		u := Update{ID: id, Title: clip(firstNonEmpty(zp.Summary, zp.Name), maxTitle), Kind: "optional", Severity: "unspecified", EulaAccepted: true,
			Categories: []string{"Software Updates"}, RebootRequired: zp.Interactive == "reboot"}
		if _, ok := sevRank[zp.Severity]; ok && zp.Severity != "unspecified" {
			u.Severity = zp.Severity
		}
		switch zp.Category {
		case "security":
			u.Kind, u.Categories = "security", []string{"Security Updates"}
			if u.Severity == "critical" {
				u.Kind = "critical"
			}
		case "recommended":
			u.Kind = "important"
		case "optional", "feature", "document", "yast":
		default:
			u.Kind = "other"
		}
		if wantKind(cats, u.Kind) {
			sc.out.Updates = append(sc.out.Updates, u)
			sc.target[id] = zp.Name // the real, mixed-case patch name zypper needs
		}
	}
	return "", ""
}

func firstNonEmpty(a, b string) string {
	if strings.TrimSpace(a) != "" {
		return a
	}
	return b
}

// ---- install

func lockedAll(ids []string, title map[string]string) []ResultItem {
	var rs []ResultItem
	for _, id := range ids {
		rs = append(rs, ResultItem{ID: id, Title: title[id], Result: "locked", Code: "lock", Detail: "the package manager is locked by another process"})
	}
	return rs
}

func (e Engine) installLinux(ctx context.Context, p InstallParams, maxOut int) remote.Result {
	x, ok := e.detect(ctx)
	if !ok {
		return remote.Fail(DetailNoManager, nil)
	}
	// The whole job, simulations included, is bound by max_minutes.
	var cancel context.CancelFunc
	x.ctx, cancel = context.WithTimeout(ctx, time.Duration(p.MaxMinutes)*time.Minute)
	defer cancel()

	out := InstallOut{V: 1, Mode: p.Mode, Manager: x.mgr}
	sc, detail, msg := x.scan(nil)
	if detail == DetailLockHeld {
		out.Results = lockedAll(p.IDs, nil)
		return finishInstall(out, maxOut)
	}
	if detail != "" {
		return failWith(detail, msg)
	}
	titles := map[string]string{}
	for _, u := range sc.out.Updates {
		titles[u.ID] = u.Title
	}

	byID := map[string]ResultItem{}
	var applicable []string
	for _, id := range p.IDs {
		if _, has := sc.target[id]; has {
			applicable = append(applicable, id)
			continue
		}
		r := ResultItem{ID: id, Result: "not_applicable", Detail: "not among the available updates"}
		if x.knownMissing(id) {
			r.Result, r.Detail = "not_found", "no such package is installed"
		}
		byID[id] = r
	}

	if len(applicable) > 0 {
		x.process(p, applicable, sc, titles, byID)
	}
	for _, id := range p.IDs {
		r := byID[id]
		if r.Title == "" {
			r.Title = titles[id]
		}
		r.Title, r.Detail, r.Code = clip(r.Title, maxTitle), clip(r.Detail, maxDetail), clip(r.Code, 40)
		out.Results = append(out.Results, r)
	}
	if out.Mode == "install" && x.rebootPending() {
		out.RebootRequired = true
	}
	return finishInstall(out, maxOut)
}

// process fills byID for the applicable ids: simulation first (always), then the real run in install mode.
func (x *lx) process(p InstallParams, ids []string, sc scanned, titles map[string]string, byID map[string]ResultItem) {
	set := func(result, code, detail string, only []string) {
		for _, id := range only {
			byID[id] = ResultItem{ID: id, Title: titles[id], Result: result, Code: code, Detail: detail}
		}
	}
	args := x.pkgArgs(ids, sc)
	sim := x.simulate(args)
	switch {
	case sim.locked:
		set("locked", "lock", "the package manager is locked by another process", ids)
		return
	case sim.err != "":
		set("failed", sim.code, sim.err, ids)
		return
	case sim.removes:
		set("skipped", "", "would_remove_packages", ids)
		return
	}
	var todo []string
	for _, id := range ids {
		if sim.inst != nil && !sim.has(id) {
			set("skipped", "", "not_upgradable", []string{id}) // held back or not installable without more changes
			continue
		}
		todo = append(todo, id)
	}
	if p.Mode == "dry_run" {
		set("would_install", "", "", todo)
		return
	}
	if len(todo) == 0 {
		return
	}
	run := x.install(x.pkgArgs(todo, sc))
	if run.locked {
		set("locked", "lock", "the package manager is locked by another process", todo)
		return
	}
	// Do not trust the exit code alone: compare the installed versions with the candidates.
	after := x.versionsAfter(todo)
	for _, id := range todo {
		r := ResultItem{ID: id, Title: titles[id], Code: strconv.Itoa(run.exit)}
		if x.isInstalled(id, sc.target[id], after) {
			r.Result, r.Code = "installed", "0"
			r.RebootRequired = rebootNameRe.MatchString(baseName(id)) || run.reboot
		} else {
			r.Result, r.Detail = "failed", run.msg
			if r.Detail == "" {
				r.Detail = "the package was not upgraded"
			}
		}
		byID[id] = r
	}
}

// pkgArgs are the names handed to the manager: the ids, or zypper's real patch names.
func (x *lx) pkgArgs(ids []string, sc scanned) []string {
	if x.mgr != ManagerZypper {
		return ids
	}
	var names []string
	for _, id := range ids {
		names = append(names, sc.target[id])
	}
	return names
}

type simResult struct {
	locked, removes bool
	err, code       string
	inst            map[string]bool // apt only: the packages the simulation would install
}

func (s simResult) has(id string) bool {
	return s.inst[id] || s.inst[strings.SplitN(id, ":", 2)[0]]
}

func aptOpts() []string {
	return []string{"-y", "-o", "Dpkg::Options::=--force-confold", "-o", "Dpkg::Options::=--force-confdef", "-o", "DPkg::Lock::Timeout=0"}
}

func (x *lx) simulate(names []string) simResult {
	var c cmd
	switch x.mgr {
	case ManagerApt:
		args := append([]string{"-s", "install", "--only-upgrade"}, aptOpts()...)
		c = x.runEnv(aptEnv, x.bin["apt-get"], append(append(args, "--"), names...), listTimeout)
	case ManagerDnf, ManagerYum:
		c = x.runEnv(nil, x.bin[x.mgr], append([]string{"-y", "--assumeno", "upgrade", "--"}, names...), 3*listTimeout)
	case ManagerZypper:
		c = x.runEnv(nil, x.bin["zypper"], append([]string{"--non-interactive", "install", "--dry-run", "-t", "patch", "--"}, names...), 3*listTimeout)
	}
	if c.err != nil && isLocked(c) {
		return simResult{locked: true}
	}
	if d := failDetail(c); d != "" {
		return simResult{err: "simulation " + d, code: d}
	}
	if isLocked(c) || (x.mgr == ManagerZypper && c.code == 7) {
		return simResult{locked: true}
	}
	res := simResult{}
	switch x.mgr {
	case ManagerApt:
		s := parseAptSim(c.out)
		res.inst, res.removes = s.Inst, len(s.Remove) > 0
		if c.code != 0 {
			return simResult{err: "simulation failed: " + lastLine(c), code: strconv.Itoa(c.code)}
		}
	case ManagerDnf, ManagerYum:
		res.removes = dnfWouldRemove(c.out)
		// --assumeno ends with exit 1 after printing the summary; anything without a summary is an error.
		if !strings.Contains(c.out, "Transaction Summary") && !strings.Contains(c.out, "Nothing to do") && !strings.Contains(c.out, "Dependencies resolved") && !res.removes {
			return simResult{err: "simulation failed: " + lastLine(c), code: strconv.Itoa(c.code)}
		}
	case ManagerZypper:
		res.removes = zypperWouldRemove(c.text())
		if c.code != 0 && c.code != 102 && c.code != 103 {
			return simResult{err: "simulation failed: " + lastLine(c), code: strconv.Itoa(c.code)}
		}
	}
	return res
}

var aptEnv = []string{"DEBIAN_FRONTEND=noninteractive", "NEEDRESTART_MODE=l", "APT_LISTCHANGES_FRONTEND=none"}

type installRun struct {
	exit   int
	msg    string
	locked bool
	reboot bool
}

func (x *lx) install(names []string) installRun {
	var c cmd
	timeout := 4 * time.Hour
	switch x.mgr {
	case ManagerApt:
		args := append([]string{"install", "--only-upgrade"}, aptOpts()...)
		c = x.runEnv(aptEnv, x.bin["apt-get"], append(append(args, "--"), names...), timeout)
	case ManagerDnf, ManagerYum:
		c = x.runEnv(nil, x.bin[x.mgr], append([]string{"-y", "upgrade", "--"}, names...), timeout)
	case ManagerZypper:
		c = x.runEnv(nil, x.bin["zypper"], append([]string{"--non-interactive", "install", "-t", "patch", "--"}, names...), timeout)
	}
	r := installRun{exit: c.code, locked: isLocked(c) || (x.mgr == ManagerZypper && c.code == 7)}
	if c.err != nil {
		r.exit, r.msg = -1, failDetail(c)
		return r
	}
	if c.code != 0 {
		r.msg = lastLine(c)
	}
	r.reboot = x.mgr == ManagerZypper && c.code == 102
	return r
}

// versionsAfter reads the installed versions of ids after the run.
func (x *lx) versionsAfter(ids []string) map[string][]string {
	if x.mgr == ManagerApt {
		c := x.run(x.bin["dpkg-query"], append([]string{"-W", "-f=${Package}:${Architecture} ${Version}\\n", "--"}, ids...), quickTimeout)
		return parseDpkgVersions(c.out)
	}
	if x.mgr == ManagerZypper {
		return nil
	}
	return x.rpmVersions(ids)
}

func (x *lx) isInstalled(id, target string, after map[string][]string) bool {
	if x.mgr == ManagerZypper {
		return x.patchApplied(target)
	}
	for _, v := range after[id] {
		if v == strings.TrimPrefix(target, "0:") {
			return true
		}
	}
	return false
}

// patchApplied re-reads the needed patches: an applied patch is no longer listed.
func (x *lx) patchApplied(name string) bool {
	c := x.run(x.bin["zypper"], []string{"--non-interactive", "--quiet", "list-patches"}, 2*listTimeout)
	if c.err != nil || c.code != 0 {
		return false
	}
	for _, zp := range parseZypperPatches(c.out) {
		if zp.Name == name {
			return false
		}
	}
	return true
}

// knownMissing is true only on positive evidence that the package is not installed at all (then it is not_found rather than not_applicable).
func (x *lx) knownMissing(id string) bool {
	switch x.mgr {
	case ManagerApt:
		c := x.run(x.bin["dpkg-query"], []string{"-W", "-f=${db:Status-Abbrev}\\n", "--", id}, quickTimeout)
		return c.err == nil && c.code == 1 && !strings.Contains(c.out, "ii")
	case ManagerDnf, ManagerYum:
		c := x.run(x.bin["rpm"], []string{"-q", "--qf", "%{NAME}\\n", "--", id}, quickTimeout)
		return c.err == nil && c.code == 1
	}
	return false
}
