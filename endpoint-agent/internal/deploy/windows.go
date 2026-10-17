package deploy

import (
	"context"
	"errors"
	"regexp"
	"strings"
	"time"
)

// Windows side, behind injected interfaces (runner, registry, HTTP client, environment) so the whole flow is unit-tested on Linux with GOOS set
// to "windows". The agent never reboots: exit codes 3010 and 1641 are reported as reboot_required only.

var sysRootRe = regexp.MustCompile(`^[A-Za-z]:\\[^\x00-\x1f"<>|*?]*$`)

const (
	exitRebootRequired = 3010
	exitRebootStarted  = 1641 // msiexec says a restart was initiated by the installer itself; we passed /norestart, so this is only reported
	exitBusy           = 1618
)

func (pr *probe) loadRegistry() {
	if pr.regLoaded {
		return
	}
	pr.regLoaded = true
	r := pr.e.Reg
	if r == nil {
		r = defaultRegistry()
	}
	okViews := 0
	for _, v := range []View{View64, View32} {
		l, err := r.Uninstall(v)
		if err != nil {
			continue
		}
		okViews++
		pr.regList = append(pr.regList, l...)
	}
	if okViews == 0 {
		pr.regErr = DetailRegistry
	}
}

func (pr *probe) checkRegistry(it Item) probeRes {
	pr.loadRegistry()
	if pr.regErr != "" {
		return probeRes{fatal: pr.regErr}
	}
	var res probeRes
	for _, en := range pr.regList {
		var match bool
		switch it.Kind {
		case KindProductCode:
			match = strings.EqualFold(en.KeyName, it.Value)
		case KindUninstallNam:
			match = en.DisplayName != "" && strings.EqualFold(en.DisplayName, it.Value)
		}
		if !match {
			continue
		}
		if !res.installed || CompareVersions(en.DisplayVersion, res.version) > 0 {
			res.version = en.DisplayVersion
		}
		res.installed = true
	}
	return res
}

func (e Engine) msiexec() (string, bool) {
	root := e.getenv("SystemRoot")
	if root == "" {
		root = e.getenv("windir")
	}
	if !sysRootRe.MatchString(root) {
		return "", false
	}
	return strings.TrimRight(root, `\`) + `\System32\msiexec.exe`, true
}

func (e Engine) installWindows(ctx context.Context, p InstallParams) outcome {
	before := e.newProbe(ctx, "").check(p.Detect)
	if before.fatal != "" {
		return fail(before.fatal, "detection failed")
	}
	isSat := satisfied(before.installed, before.version, p.Detect.Min)
	if p.Action == "install" && isSat {
		return outcome{result: ResAlready, version: before.version}
	}
	if p.Action == "uninstall" && !before.installed {
		return outcome{result: ResNotInstalled}
	}
	if p.Mode == "dry_run" {
		if p.Action == "install" {
			return outcome{result: ResWouldInstall, detail: "not_downloaded"}
		}
		return outcome{result: ResWouldUninstall, version: before.version}
	}

	var prog string
	var args []string
	switch {
	case p.Fmt == FmtMsi:
		m, ok := e.msiexec()
		if !ok {
			return fail("system_root_invalid", "SystemRoot is not an absolute path with a drive letter")
		}
		prog = m
	}
	if p.Action == "install" {
		dir, file, res, detail := e.download(ctx, p)
		if dir != "" {
			defer removeStage(dir)
		}
		if res != "" {
			return outcome{result: res, code: res, detail: detail}
		}
		// Re-verify immediately before executing (the file sits on disk between the download and now).
		if e.beforeEx != nil {
			e.beforeEx(file)
		}
		if ok, err := fileMatches(file, p.SHA256, p.Size); err != nil || !ok {
			return outcome{result: ResHashMismatch, code: ResHashMismatch, detail: "the staged file changed before it was run"}
		}
		if p.Fmt == FmtMsi {
			args = []string{"/i", file, "/qn", "/norestart"}
		} else {
			prog, args = file, p.Args
		}
		return e.execAndVerify(ctx, p, prog, args)
	}
	args = []string{"/x", p.ProductCode, "/qn", "/norestart"}
	return e.execAndVerify(ctx, p, prog, args)
}

func (e Engine) execAndVerify(ctx context.Context, p InstallParams, prog string, args []string) outcome {
	r, err := e.runner().Run(ctx, prog, args, 64<<10, time.Duration(p.MaxMinutes)*time.Minute)
	if err != nil {
		if errors.Is(err, context.DeadlineExceeded) {
			return fail(DetailTimeout, "the installer did not finish in time")
		}
		if errors.Is(err, context.Canceled) {
			return fail("cancelled", "the job was cancelled")
		}
		return fail("cannot_run", "the program could not be started")
	}
	reboot := false
	switch r.ExitCode {
	case 0:
	case exitRebootRequired:
		reboot = true
	case exitRebootStarted:
		reboot = p.Fmt == FmtMsi
		if !reboot {
			return fail("exit_1641", "")
		}
	case exitBusy:
		if p.Fmt == FmtMsi {
			return outcome{result: ResLocked, code: "exit_1618", detail: "another installation is already in progress"}
		}
		return fail("exit_1618", "")
	default:
		return fail("exit_"+itoa(r.ExitCode), "the installer reported a failure")
	}
	after := e.newProbe(ctx, "").check(p.Detect)
	if after.fatal != "" {
		return fail(after.fatal, "verification could not run")
	}
	if p.Action == "install" {
		if !satisfied(after.installed, after.version, p.Detect.Min) {
			return outcome{result: ResVerifyFailed, code: "not_detected", detail: "the software is not detected after the run", version: after.version, reboot: reboot}
		}
		if reboot {
			return outcome{result: ResNeedsReboot, version: after.version, reboot: true}
		}
		return outcome{result: ResInstalled, version: after.version}
	}
	if after.installed {
		return outcome{result: ResVerifyFailed, code: "still_installed", detail: "the software is still installed after the run", version: after.version, reboot: reboot}
	}
	if reboot {
		return outcome{result: ResNeedsReboot, reboot: true}
	}
	return outcome{result: ResUninstalled}
}
