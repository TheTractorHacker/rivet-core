package collect

import (
	"bufio"
	"bytes"
	"crypto/x509"
	"encoding/binary"
	"encoding/csv"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"math"
	"regexp"
	"strconv"
	"strings"
	"time"
)

// Parsers of command and file output used by the extended checks. They are pure so every format has a fixture test on any platform.

// parseSwap reads SwapTotal and SwapFree (kB) from /proc/meminfo.
func parseSwap(s string) (total, free uint64, err error) {
	var haveT, haveF bool
	for _, line := range strings.Split(s, "\n") {
		f := strings.Fields(line)
		if len(f) < 2 {
			continue
		}
		v, e := strconv.ParseUint(f[1], 10, 64)
		if e != nil {
			continue
		}
		switch f[0] {
		case "SwapTotal:":
			total, haveT = v, true
		case "SwapFree:":
			free, haveF = v, true
		}
	}
	if !haveT || !haveF {
		return 0, 0, errors.New("swap figures missing from /proc/meminfo")
	}
	return total, free, nil
}

var psiRE = regexp.MustCompile(`(?m)^some\s+avg10=([0-9.]+)`)

// parsePSI reads "some avg10" (percent of the last 10 s some task stalled on memory) from /proc/pressure/memory.
func parsePSI(s string) (float64, error) {
	m := psiRE.FindStringSubmatch(s)
	if m == nil {
		return 0, errors.New("unexpected /proc/pressure/memory format")
	}
	return strconv.ParseFloat(m[1], 64)
}

// parseTasklist returns the image names of `tasklist /FO CSV /NH` output.
func parseTasklist(out string) []string {
	r := csv.NewReader(strings.NewReader(out))
	r.FieldsPerRecord = -1
	r.LazyQuotes = true
	var names []string
	for {
		rec, err := r.Read()
		if err != nil {
			break
		}
		if len(rec) > 0 && strings.TrimSpace(rec[0]) != "" {
			names = append(names, strings.TrimSpace(rec[0]))
		}
	}
	return names
}

func isPID(s string) bool {
	if s == "" || len(s) > 9 {
		return false
	}
	for _, r := range s {
		if r < '0' || r > '9' {
			return false
		}
	}
	return true
}

// countProcesses counts the names that match the wanted process. A Windows image name matches with or without ".exe"; a Linux comm
// name is cut to 15 characters by the kernel, so a longer wanted name is compared on its first 15.
func countProcesses(names []string, want string) int {
	w := strings.ToLower(strings.TrimSpace(want))
	wExe := strings.TrimSuffix(w, ".exe")
	n := 0
	for _, name := range names {
		l := strings.ToLower(name)
		if l == w || strings.TrimSuffix(l, ".exe") == wExe || (len(w) > 15 && l == w[:15]) {
			n++
		}
	}
	return n
}

// ---------------------------------------------------------------- ping

type pingResult struct {
	Sent, Received int
	LossPct        float64
	AvgMS          float64
}

var (
	pingLinuxStats = regexp.MustCompile(`(\d+) packets transmitted, (\d+) (?:packets )?received.*?([0-9.]+)% packet loss`)
	pingLinuxRTT   = regexp.MustCompile(`(?:rtt|round-trip) min/avg/max(?:/mdev|/stddev)? = [0-9.]+/([0-9.]+)/`)
	pingWinStats   = regexp.MustCompile(`=\s*(\d+),\s*[^=]*?=\s*(\d+),\s*[^=]*?=\s*(\d+)\s*\((\d+)%`)
	pingWinRTT     = regexp.MustCompile(`=\s*(\d+)\s*ms\D+=\s*(\d+)\s*ms\D+=\s*(\d+)\s*ms`)
)

// parsePing reads the summary of iputils/busybox ping (Linux, `LC_ALL=C`) or Windows ping.
func parsePing(out string) (pingResult, error) {
	var r pingResult
	if m := pingLinuxStats.FindStringSubmatch(out); m != nil {
		r.Sent, _ = strconv.Atoi(m[1])
		r.Received, _ = strconv.Atoi(m[2])
		r.LossPct, _ = strconv.ParseFloat(m[3], 64)
		if t := pingLinuxRTT.FindStringSubmatch(out); t != nil {
			r.AvgMS, _ = strconv.ParseFloat(t[1], 64)
		} else if r.Received > 0 {
			return r, errors.New("ping printed no round-trip summary")
		}
		return r, nil
	}
	if m := pingWinStats.FindStringSubmatch(out); m != nil {
		r.Sent, _ = strconv.Atoi(m[1])
		r.Received, _ = strconv.Atoi(m[2])
		r.LossPct, _ = strconv.ParseFloat(m[4], 64)
		if t := pingWinRTT.FindStringSubmatch(out); t != nil {
			r.AvgMS, _ = strconv.ParseFloat(t[3], 64)
		} else if r.Received > 0 {
			return r, errors.New("ping printed no round-trip summary")
		}
		return r, nil
	}
	return r, errors.New("could not read the ping result")
}

// ---------------------------------------------------------------- ntp (RFC 4330 SNTP)

const ntpEpochOffset = 2208988800 // seconds from 1900-01-01 to 1970-01-01

func ntpTimestamp(t time.Time) uint64 {
	secs := uint64(t.Unix()) + ntpEpochOffset
	frac := uint64(t.Nanosecond()) * (1 << 32) / 1e9
	return secs<<32 | frac
}

func fromNTP(ts uint64) time.Time {
	secs := int64(ts>>32) - ntpEpochOffset
	nsec := int64((ts & 0xffffffff) * 1e9 >> 32)
	return time.Unix(secs, nsec).UTC()
}

// buildNTPRequest is a 48-byte client packet (version 4, mode 3) whose transmit timestamp is t0.
func buildNTPRequest(t0 time.Time) []byte {
	b := make([]byte, 48)
	b[0] = 0<<6 | 4<<3 | 3
	binary.BigEndian.PutUint64(b[40:48], ntpTimestamp(t0))
	return b
}

// parseNTPResponse validates a server reply to req and returns the offset of the local clock (server time minus local time), using
// the local receive time t3: ((t1 - t0) + (t2 - t3)) / 2 with t1 the server's receive and t2 its transmit time.
func parseNTPResponse(resp, req []byte, t3 time.Time) (time.Duration, error) {
	if len(resp) < 48 {
		return 0, errors.New("short reply")
	}
	li, vn, mode := resp[0]>>6, (resp[0]>>3)&7, resp[0]&7
	if mode != 4 && mode != 5 {
		return 0, errors.New("not a server reply")
	}
	if vn < 3 {
		return 0, errors.New("unsupported protocol version")
	}
	if li == 3 {
		return 0, errors.New("the server is not synchronised")
	}
	if resp[1] == 0 {
		return 0, errors.New("the server refused the request (stratum 0)")
	}
	if !bytes.Equal(resp[24:32], req[40:48]) {
		return 0, errors.New("reply does not match the request")
	}
	t0 := fromNTP(binary.BigEndian.Uint64(resp[24:32]))
	t1 := fromNTP(binary.BigEndian.Uint64(resp[32:40]))
	t2 := fromNTP(binary.BigEndian.Uint64(resp[40:48]))
	if binary.BigEndian.Uint64(resp[40:48]) == 0 {
		return 0, errors.New("the server sent no time")
	}
	return (t1.Sub(t0) + t2.Sub(t3)) / 2, nil
}

// ---------------------------------------------------------------- certificates

// certsFromBytes returns the first certificate of a PEM bundle (the leaf), or the certificate of a DER file.
func certFromBytes(b []byte) (*x509.Certificate, error) {
	rest := b
	for {
		var blk *pem.Block
		blk, rest = pem.Decode(rest)
		if blk == nil {
			break
		}
		if blk.Type == "CERTIFICATE" {
			return x509.ParseCertificate(blk.Bytes)
		}
	}
	if c, err := x509.ParseCertificate(b); err == nil {
		return c, nil
	}
	return nil, errors.New("no certificate found")
}

// parseCertStore reads "<RFC 3339 NotAfter>|<subject>" lines (the PowerShell listing of a Windows certificate store).
func parseCertStore(out string) (names []string, notAfter []time.Time) {
	sc := bufio.NewScanner(strings.NewReader(out))
	for sc.Scan() {
		line := strings.TrimSpace(sc.Text())
		i := strings.Index(line, "|")
		if i < 1 {
			continue
		}
		t, err := time.Parse(time.RFC3339Nano, line[:i])
		if err != nil {
			continue
		}
		names = append(names, strings.TrimSpace(line[i+1:]))
		notAfter = append(notAfter, t)
	}
	return names, notAfter
}

// ---------------------------------------------------------------- health

type smartDevice struct {
	Name   string
	Passed *bool
	Note   string
}

// parseSmartctl reads `smartctl -H -j` output of one device.
func parseSmartctl(dev string, out string) (smartDevice, error) {
	var j struct {
		SmartStatus *struct {
			Passed *bool `json:"passed"`
		} `json:"smart_status"`
		Smartctl struct {
			ExitStatus int `json:"exit_status"`
			Messages   []struct {
				String string `json:"string"`
			} `json:"messages"`
		} `json:"smartctl"`
	}
	if err := json.Unmarshal([]byte(out), &j); err != nil {
		return smartDevice{Name: dev}, errors.New("unreadable smartctl output")
	}
	d := smartDevice{Name: dev}
	if j.SmartStatus != nil {
		d.Passed = j.SmartStatus.Passed
	}
	if len(j.Smartctl.Messages) > 0 {
		d.Note = j.Smartctl.Messages[0].String
	}
	return d, nil
}

// parseSmartScan reads `smartctl --scan -j` into device names.
func parseSmartScan(out string) []string {
	var j struct {
		Devices []struct {
			Name string `json:"name"`
		} `json:"devices"`
	}
	if json.Unmarshal([]byte(out), &j) != nil {
		return nil
	}
	var names []string
	for _, d := range j.Devices {
		if strings.HasPrefix(d.Name, "/dev/") {
			names = append(names, d.Name)
		}
	}
	return names
}

type physDisk struct {
	Name, Health, Operational string
}

// parsePhysicalDisks reads `Get-PhysicalDisk | ConvertTo-Json` (one object or an array).
func parsePhysicalDisks(out string) ([]physDisk, error) {
	out = strings.TrimSpace(out)
	if out == "" {
		return nil, errors.New("no physical disks reported")
	}
	type rec struct {
		FriendlyName      string `json:"FriendlyName"`
		HealthStatus      any    `json:"HealthStatus"`
		OperationalStatus any    `json:"OperationalStatus"`
	}
	var many []rec
	if strings.HasPrefix(out, "[") {
		if err := json.Unmarshal([]byte(out), &many); err != nil {
			return nil, errors.New("unreadable disk health output")
		}
	} else {
		var one rec
		if err := json.Unmarshal([]byte(out), &one); err != nil {
			return nil, errors.New("unreadable disk health output")
		}
		many = []rec{one}
	}
	var res []physDisk
	for _, r := range many {
		res = append(res, physDisk{Name: r.FriendlyName, Health: statusText(r.HealthStatus), Operational: statusText(r.OperationalStatus)})
	}
	return res, nil
}

// statusText accepts PowerShell enums as text ("Healthy"), as numbers (0 Healthy, 1 Warning, 2 Unhealthy) or as arrays of those.
func statusText(v any) string {
	switch t := v.(type) {
	case string:
		return t
	case float64:
		return map[float64]string{0: "Healthy", 1: "Warning", 2: "Unhealthy", 5: "Unknown"}[t]
	case []any:
		if len(t) > 0 {
			return statusText(t[0])
		}
	}
	return ""
}

type defenderStatus struct {
	ServiceEnabled, AVEnabled, RealTime bool
	SignatureAgeDays                    int
}

// parseDefender reads `Get-MpComputerStatus | Select ... | ConvertTo-Json`.
func parseDefender(out string) (defenderStatus, error) {
	var j struct {
		AMServiceEnabled          *bool `json:"AMServiceEnabled"`
		AntivirusEnabled          *bool `json:"AntivirusEnabled"`
		RealTimeProtectionEnabled *bool `json:"RealTimeProtectionEnabled"`
		AntivirusSignatureAge     *int  `json:"AntivirusSignatureAge"`
	}
	if err := json.Unmarshal([]byte(strings.TrimSpace(out)), &j); err != nil {
		return defenderStatus{}, errors.New("Windows Defender status is not available (a third-party antivirus may be in use)")
	}
	if j.AntivirusEnabled == nil || j.AntivirusSignatureAge == nil {
		return defenderStatus{}, errors.New("Windows Defender status is incomplete")
	}
	d := defenderStatus{AVEnabled: *j.AntivirusEnabled, SignatureAgeDays: *j.AntivirusSignatureAge}
	d.ServiceEnabled = j.AMServiceEnabled != nil && *j.AMServiceEnabled
	d.RealTime = j.RealTimeProtectionEnabled != nil && *j.RealTimeProtectionEnabled
	return d, nil
}

// parseFailedUnits reads `systemctl list-units --state=failed --no-legend --plain` into unit names.
func parseFailedUnits(out string) []string {
	var units []string
	for _, line := range strings.Split(out, "\n") {
		f := strings.Fields(strings.TrimLeft(line, "●* "))
		if len(f) > 0 && strings.Contains(f[0], ".") {
			units = append(units, f[0])
		}
	}
	return units
}

// ignored reports whether a unit matches an ignore entry: an exact name, or a prefix ending in "*".
func ignored(unit string, list []string) bool {
	for _, p := range list {
		if p == unit || (strings.HasSuffix(p, "*") && strings.HasPrefix(unit, strings.TrimSuffix(p, "*"))) {
			return true
		}
	}
	return false
}

func roundMS(d time.Duration) float64 { return math.Round(float64(d.Microseconds())/10) / 100 }

var _ = fmt.Sprintf
