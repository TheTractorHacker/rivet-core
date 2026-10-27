package remote

import (
	"bytes"
	"encoding/json"
	"fmt"
	"regexp"
	"strconv"
	"strings"
)

// Stable detail codes shared by the tools.
const (
	DetailNotFound        = "not_found"
	DetailRefusedSelf     = "refused_self"
	DetailRefusedProtect  = "refused_protected"
	DetailUnsupportedAct  = "unsupported_action"
	DetailInvalidName     = "invalid_name"
	DetailInvalidAction   = "invalid_action"
	DetailInvalidPID      = "invalid_pid"
	DetailNameMismatch    = "name_mismatch"
	DetailKernelThread    = "refused_kernel_thread"
	DetailBadParams       = "bad_params"
	DetailDisabledLocally = "disabled_locally"
	DetailTooLarge        = "too_large"
)

var (
	linuxUnitRE = regexp.MustCompile(`^[A-Za-z0-9:_.@-]{1,200}$`)
	winSvcRE    = regexp.MustCompile(`^[A-Za-z0-9_.@$:-]{1,200}$`)
	procNameRE  = regexp.MustCompile(`^[A-Za-z0-9 _.()+@#~\[\]-]{1,128}$`)
)

// NormalizeUnit validates a Linux unit name and returns it with its ".service"
// suffix. A name that does not match the allow-list, starts with '-' or '.', or
// carries another unit-type suffix is refused.
func NormalizeUnit(name string) (string, bool) {
	if !linuxUnitRE.MatchString(name) || strings.HasPrefix(name, "-") || strings.HasPrefix(name, ".") {
		return "", false
	}
	switch {
	case strings.HasSuffix(name, ".service"):
		if name == ".service" {
			return "", false
		}
		return name, true
	}
	// Any other recognised unit suffix is refused (foo.socket, foo.target ...);
	// a dot inside a service name ("my.app") is allowed and gets ".service".
	if i := strings.LastIndexByte(name, '.'); i >= 0 {
		switch name[i+1:] {
		case "socket", "target", "timer", "mount", "automount", "swap", "path", "slice", "scope", "device", "snapshot":
			return "", false
		}
	}
	return name + ".service", true
}

// ValidWindowsService reports whether name is an acceptable Windows service
// (short) name.
func ValidWindowsService(name string) bool {
	if !winSvcRE.MatchString(name) {
		return false
	}
	c := name[0]
	return c == '_' || (c >= '0' && c <= '9') || (c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z')
}

// ValidProcName reports whether name is an acceptable expected process name.
func ValidProcName(name string) bool { return procNameRE.MatchString(name) }

// Hard bounds of a pid taken from a job.
const (
	MinPID        = 2
	MaxLinuxPID   = 4194304
	MaxWindowsPID = 1<<32 - 1
)

// ParsePID reads a pid from a JSON number token ("12" only: no fraction, no
// exponent, no string). goos picks the legal range.
func ParsePID(raw json.RawMessage, goos string) (int, bool) {
	t := bytes.TrimSpace(raw)
	if len(t) == 0 || len(t) > 12 {
		return 0, false
	}
	for _, c := range t {
		if c < '0' || c > '9' {
			return 0, false
		}
	}
	n, err := strconv.ParseInt(string(t), 10, 64)
	if err != nil {
		return 0, false
	}
	if goos == "windows" {
		if n <= 4 || n > MaxWindowsPID {
			return 0, false
		}
	} else if n < MinPID || n > MaxLinuxPID {
		return 0, false
	}
	return int(n), true
}

// decodeParams reads the flat params object of a job (absent or null = {}).
func decodeParams(raw json.RawMessage, v any) error {
	raw = bytes.TrimSpace(raw)
	if len(raw) == 0 || string(raw) == "null" {
		raw = []byte("{}")
	}
	dec := json.NewDecoder(bytes.NewReader(raw))
	if err := dec.Decode(v); err != nil {
		return fmt.Errorf("params: %w", err)
	}
	return nil
}
