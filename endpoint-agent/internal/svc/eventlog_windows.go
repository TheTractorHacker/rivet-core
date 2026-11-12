//go:build windows

package svc

import "golang.org/x/sys/windows/svc/eventlog"

const eventSource = "RivetIT Agent"

// ReportFatal writes msg to the Windows Application event log (source "RivetIT Agent"). The service has no console and, when its state directory was
// refused (CORE-16), no trustworthy place for a log file, so without this a refusal would repeat silently at every restart.
func ReportFatal(msg string) { report(msg, true) }

// ReportInfo writes an informational event (for example a permission repair).
func ReportInfo(msg string) { report(msg, false) }

func report(msg string, isErr bool) {
	// An already registered source is reported as an error; either way it is usable, so the result is ignored.
	_ = eventlog.InstallAsEventCreate(eventSource, eventlog.Error)
	l, err := eventlog.Open(eventSource)
	if err != nil {
		return
	}
	defer l.Close()
	if len(msg) > 30000 {
		msg = msg[:30000]
	}
	if isErr {
		_ = l.Error(1, msg)
	} else {
		_ = l.Info(2, msg)
	}
}
