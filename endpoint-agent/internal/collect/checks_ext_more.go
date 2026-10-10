package collect

import (
	"context"
	"crypto/tls"
	"encoding/json"
	"fmt"
	"net"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"time"

	"rivetit-agent/internal/api"
)

// ---------------------------------------------------------------- cert

func (c *Collector) checkCert(ctx context.Context, key string, raw json.RawMessage) api.CheckResult {
	p := struct {
		Paths    []string `json:"paths"`
		Store    string   `json:"store"`
		Host     string   `json:"host"`
		Port     int      `json:"port"`
		WarnDays *int     `json:"warn_days"`
		FailDays *int     `json:"fail_days"`
	}{Port: 443}
	if err := unmarshalParams(raw, &p); err != nil {
		return res(key, Unknown, "invalid cert check parameters")
	}
	warn, fail := 30, 7
	if p.WarnDays != nil {
		warn = *p.WarnDays
	}
	if p.FailDays != nil {
		fail = *p.FailDays
	}
	x := c.sys()
	now := x.now()
	type found struct {
		name string
		at   time.Time
	}
	var certs []found
	var problems []string
	switch {
	case len(p.Paths) > 0:
		if len(p.Paths) > 10 {
			return res(key, Unknown, "invalid cert check parameters")
		}
		for _, path := range p.Paths {
			if !safeCertPath(path) {
				return res(key, Unknown, "invalid cert check parameters")
			}
			files := []string{path}
			if st, err := os.Stat(path); err == nil && st.IsDir() {
				ents, err := x.ReadDir(path)
				if err != nil {
					problems = append(problems, path+": cannot read directory")
					continue
				}
				files = nil
				for _, e := range ents {
					ext := strings.ToLower(filepath.Ext(e.Name()))
					if !e.IsDir() && (ext == ".pem" || ext == ".crt" || ext == ".cer") && len(files) < 50 {
						files = append(files, filepath.Join(path, e.Name()))
					}
				}
			}
			for _, f := range files {
				b, err := x.ReadFile(f)
				if err != nil {
					problems = append(problems, f+": cannot read")
					continue
				}
				cert, err := certFromBytes(b)
				if err != nil {
					problems = append(problems, f+": no certificate")
					continue
				}
				certs = append(certs, found{f, cert.NotAfter})
			}
		}
	case p.Store != "":
		if x.GOOS != "windows" {
			return res(key, Unknown, "certificate stores exist on Windows only")
		}
		scope, store, ok := parseCertStoreName(p.Store)
		if !ok {
			return res(key, Unknown, "invalid cert check parameters")
		}
		// scope and store passed the allow-list (letters, digits, space, . _ -), so neither can carry a quote or an operator into the script
		script := "Get-ChildItem -LiteralPath 'Cert:\\" + scope + "\\" + store + "' | ForEach-Object { $_.NotAfter.ToUniversalTime().ToString('o') + '|' + $_.Subject }"
		out, _, err := x.Exec(ctx, 20*time.Second, "powershell.exe", "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-Command", script)
		if err != nil {
			return res(key, Unknown, "cannot read the certificate store: "+firstLine(err.Error()))
		}
		names, ats := parseCertStore(out)
		for i := range names {
			certs = append(certs, found{names[i], ats[i]})
		}
	case p.Host != "":
		if !safeHost(p.Host) || p.Port < 1 || p.Port > 65535 {
			return res(key, Unknown, "invalid cert check parameters")
		}
		dctx, cancel := context.WithTimeout(ctx, 10*time.Second)
		defer cancel()
		raw, err := x.Dial(dctx, "tcp", net.JoinHostPort(p.Host, strconv.Itoa(p.Port)))
		if err != nil {
			return res(key, Fail, fmt.Sprintf("%s:%d unreachable: %s", p.Host, p.Port, netReason(err)))
		}
		defer raw.Close()
		_ = raw.SetDeadline(time.Now().Add(10 * time.Second))
		tc := tls.Client(raw, &tls.Config{ServerName: p.Host, InsecureSkipVerify: true, MinVersion: tls.VersionTLS12}) //nolint:gosec // only the expiry date is read
		if err := tc.HandshakeContext(dctx); err != nil {
			return res(key, Fail, fmt.Sprintf("%s:%d: TLS handshake failed", p.Host, p.Port))
		}
		pc := tc.ConnectionState().PeerCertificates
		if len(pc) == 0 {
			return res(key, Fail, fmt.Sprintf("%s:%d sent no certificate", p.Host, p.Port))
		}
		certs = append(certs, found{fmt.Sprintf("%s:%d", p.Host, p.Port), pc[0].NotAfter})
	default:
		return res(key, Unknown, "invalid cert check parameters")
	}
	if len(certs) == 0 {
		if len(problems) > 0 {
			return res(key, Unknown, strings.Join(problems, "; "))
		}
		return res(key, Unknown, "no certificates found")
	}
	sort.Slice(certs, func(i, j int) bool { return certs[i].at.Before(certs[j].at) })
	soon := certs[0]
	days := daysLeft(soon.at, now)
	st := OK
	switch {
	case days <= fail:
		st = Fail
	case days <= warn:
		st = Warn
	}
	detail := fmt.Sprintf("%s expires in %d days (%d certificate%s checked)", soon.name, days, len(certs), map[bool]string{true: "", false: "s"}[len(certs) == 1])
	if days < 0 {
		detail = fmt.Sprintf("%s expired %d days ago", soon.name, -days)
	}
	if len(problems) > 0 {
		detail += "; " + strings.Join(problems, "; ")
	}
	return resv(key, st, detail, float64(days))
}

// ---------------------------------------------------------------- smart

func (c *Collector) checkSmart(ctx context.Context, key string, raw json.RawMessage) api.CheckResult {
	p := struct {
		Devices []string `json:"devices"`
	}{}
	if err := unmarshalParams(raw, &p); err != nil || len(p.Devices) > 16 {
		return res(key, Unknown, "invalid smart check parameters")
	}
	for _, d := range p.Devices {
		if !smartDeviceRE.MatchString(d) {
			return res(key, Unknown, "invalid smart check parameters")
		}
	}
	x := c.sys()
	if x.GOOS == "windows" {
		out, _, err := x.Exec(ctx, 30*time.Second, "powershell.exe", "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-Command",
			"Get-PhysicalDisk | Select-Object FriendlyName,HealthStatus,OperationalStatus | ConvertTo-Json -Compress")
		if err != nil {
			return res(key, Unknown, "cannot read disk health: "+firstLine(err.Error()))
		}
		disks, err := parsePhysicalDisks(out)
		if err != nil {
			return res(key, Unknown, err.Error())
		}
		st, bad := OK, []string{}
		for _, d := range disks {
			switch strings.ToLower(d.Health) {
			case "healthy":
			case "warning":
				st = worse(st, Warn)
				bad = append(bad, d.Name+" warning")
			default:
				st = worse(st, Fail)
				bad = append(bad, d.Name+" "+strings.ToLower(orDefault(d.Health, "unknown")))
			}
		}
		if len(bad) > 0 {
			return res(key, st, "disk health: "+strings.Join(bad, ", "))
		}
		return res(key, OK, fmt.Sprintf("%d disk(s) healthy", len(disks)))
	}
	devs := p.Devices
	if len(devs) == 0 {
		out, _, err := x.Exec(ctx, 20*time.Second, "smartctl", "--scan", "-j")
		if err != nil {
			return res(key, Unknown, "smartctl is not available: "+firstLine(err.Error()))
		}
		devs = parseSmartScan(out)
		if len(devs) == 0 {
			return res(key, Unknown, "smartctl found no disks")
		}
	}
	st, failed, unread := OK, []string{}, []string{}
	passed := 0
	for _, d := range devs {
		out, _, err := x.Exec(ctx, 30*time.Second, "smartctl", "-H", "-j", d)
		if err != nil {
			return res(key, Unknown, "smartctl is not available: "+firstLine(err.Error()))
		}
		sd, err := parseSmartctl(d, out)
		if err != nil || sd.Passed == nil {
			unread = append(unread, d)
			continue
		}
		if *sd.Passed {
			passed++
		} else {
			st = Fail
			failed = append(failed, d)
		}
	}
	switch {
	case len(failed) > 0:
		return res(key, Fail, "SMART health FAILED on "+strings.Join(failed, ", "))
	case passed == 0:
		return res(key, Unknown, "no SMART health status could be read ("+strings.Join(unread, ", ")+")")
	case len(unread) > 0:
		return res(key, Warn, fmt.Sprintf("SMART passed on %d disk(s), unreadable: %s", passed, strings.Join(unread, ", ")))
	}
	return res(key, st, fmt.Sprintf("SMART health passed on %d disk(s)", passed))
}

func orDefault(s, d string) string {
	if s == "" {
		return d
	}
	return s
}

// ---------------------------------------------------------------- av (Windows)

func (c *Collector) checkAV(ctx context.Context, key string, raw json.RawMessage) api.CheckResult {
	p := struct {
		MaxSignatureAgeDays int   `json:"max_signature_age_days"`
		RequireRealtime     *bool `json:"require_realtime"`
	}{MaxSignatureAgeDays: 7}
	if err := unmarshalParams(raw, &p); err != nil {
		return res(key, Unknown, "invalid av check parameters")
	}
	if p.MaxSignatureAgeDays < 1 || p.MaxSignatureAgeDays > 365 {
		p.MaxSignatureAgeDays = 7
	}
	needRT := p.RequireRealtime == nil || *p.RequireRealtime
	out, _, err := c.sys().Exec(ctx, 30*time.Second, "powershell.exe", "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-Command",
		"Get-MpComputerStatus | Select-Object AMServiceEnabled,AntivirusEnabled,RealTimeProtectionEnabled,AntivirusSignatureAge | ConvertTo-Json -Compress")
	if err != nil {
		return res(key, Unknown, "cannot read Defender status: "+firstLine(err.Error()))
	}
	d, err := parseDefender(out)
	if err != nil {
		return res(key, Unknown, err.Error())
	}
	age := float64(d.SignatureAgeDays)
	switch {
	case !d.AVEnabled || !d.ServiceEnabled:
		return resv(key, Fail, "Windows Defender antivirus is turned off", age)
	case needRT && !d.RealTime:
		return resv(key, Fail, "Windows Defender real-time protection is turned off", age)
	case d.SignatureAgeDays > 2*p.MaxSignatureAgeDays:
		return resv(key, Fail, fmt.Sprintf("Defender signatures are %d days old", d.SignatureAgeDays), age)
	case d.SignatureAgeDays > p.MaxSignatureAgeDays:
		return resv(key, Warn, fmt.Sprintf("Defender signatures are %d days old", d.SignatureAgeDays), age)
	}
	return resv(key, OK, fmt.Sprintf("Windows Defender on, signatures %d days old", d.SignatureAgeDays), age)
}

// ---------------------------------------------------------------- systemd_failed (Linux)

func (c *Collector) checkSystemdFailed(ctx context.Context, key string, raw json.RawMessage) api.CheckResult {
	p := struct {
		Ignore    []string `json:"ignore"`
		MaxFailed int      `json:"max_failed"`
	}{}
	if err := unmarshalParams(raw, &p); err != nil || len(p.Ignore) > 20 || p.MaxFailed < 0 {
		return res(key, Unknown, "invalid systemd_failed check parameters")
	}
	out, _, err := c.sys().Exec(ctx, 15*time.Second, "systemctl", "list-units", "--state=failed", "--no-legend", "--plain", "--no-pager")
	if err != nil {
		return res(key, Unknown, "systemctl is not available: "+firstLine(err.Error()))
	}
	var failed []string
	for _, u := range parseFailedUnits(out) {
		if !ignored(u, p.Ignore) {
			failed = append(failed, u)
		}
	}
	n := float64(len(failed))
	if len(failed) > p.MaxFailed {
		show := failed
		if len(show) > 5 {
			show = append(append([]string{}, show[:5]...), fmt.Sprintf("and %d more", len(failed)-5))
		}
		return resv(key, Fail, fmt.Sprintf("%d failed unit(s): %s", len(failed), strings.Join(show, ", ")), n)
	}
	return resv(key, OK, fmt.Sprintf("%d failed unit(s)", len(failed)), n)
}

// ---------------------------------------------------------------- inodes (Linux)

func (c *Collector) checkInodes(ctx context.Context, key string, raw json.RawMessage) api.CheckResult {
	p := struct {
		Mount   string `json:"mount"`
		WarnPct int    `json:"warn_pct"`
		FailPct int    `json:"fail_pct"`
	}{"", 85, 95}
	if err := unmarshalParams(raw, &p); err != nil {
		return res(key, Unknown, "invalid inodes check parameters")
	}
	ds, err := call(c, ctx, "disks", 0, c.P.Disks)
	if err != nil {
		return res(key, Unknown, err.Error())
	}
	x := c.sys()
	worstPct, worstMount, seen := -1.0, "", 0
	for _, d := range ds {
		if p.Mount != "" && strings.TrimRight(d.Mount, "/") != strings.TrimRight(p.Mount, "/") {
			continue
		}
		total, free, err := x.Statfs(d.Mount)
		if err != nil || total == 0 {
			continue // a filesystem without a fixed inode table (btrfs, xfs with dynamic inodes) reports none
		}
		seen++
		used := 100 * (1 - float64(free)/float64(total))
		if used > worstPct {
			worstPct, worstMount = used, d.Mount
		}
	}
	if seen == 0 {
		return res(key, Unknown, "no filesystem with an inode table matched")
	}
	return resv(key, levelHigh(worstPct, float64(p.WarnPct), float64(p.FailPct)), fmt.Sprintf("%s: %s%% of inodes used", worstMount, fnum(worstPct)), worstPct)
}
