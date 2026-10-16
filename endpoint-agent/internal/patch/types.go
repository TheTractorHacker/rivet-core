// Package patch implements the patch_scan and patch_install jobs (RivetCore RMM Phase 4).
//
// Wire contract: the server asks for a read-only scan of the available updates and later for the installation of a list of update ids. The agent
// NEVER reboots and never passes a reboot flag; it only reports reboot_required. Everything the server sends is re-validated here (the server is not
// trusted for syntax): an unknown parameter, an id that does not match the per-manager syntax, a duplicate or a list that is too long is refused
// before any command is built. Programs are started from an argv list, never through a shell string; on Linux every package name is preceded by "--".
//
// Windows runs the two fixed PowerShell scripts in scripts/ (embedded below, no server text is ever written into their text; the filters travel in
// environment variables), Linux drives the package manager found at a fixed absolute path.
package patch

// Job type wire names (the same strings as jobs.TypePatchScan and jobs.TypePatchInstall; a test keeps them equal).
const (
	JobScan    = "patch_scan"
	JobInstall = "patch_install"
)

// Capability strings announced in the check-in.
const (
	CapScan    = "patch:scan"
	CapInstall = "patch:install"
)

// Limits of the wire contract.
const (
	DefaultMaxItems   = 500
	MaxMaxItems       = 1000
	DefaultMaxMinutes = 60
	MaxMaxMinutes     = 240
	MaxIDs            = 40
	MaxIDsChars       = 1000
	maxTitle          = 200
	maxDetail         = 200
)

// Package managers.
const (
	ManagerWUA    = "wua"
	ManagerApt    = "apt"
	ManagerDnf    = "dnf"
	ManagerYum    = "yum"
	ManagerZypper = "zypper"
)

// Failure detail codes.
const (
	DetailInvalidParams = "invalid_params"
	DetailNoManager     = "no_package_manager"
	DetailWUUnavailable = "wu_unavailable"
	DetailTimeout       = "timeout"
	DetailLockHeld      = "lock_held"
	DetailParseError    = "parse_error"
	DetailScanFailed    = "scan_failed"
	DetailBusy          = "busy"
	DetailUnsupported   = "unsupported_platform"
)

// Update is one available update (patch_scan).
type Update struct {
	ID             string   `json:"id"`
	KB             string   `json:"kb"`
	Title          string   `json:"title"`
	Kind           string   `json:"kind"`
	Severity       string   `json:"severity"`
	Size           int64    `json:"size"`
	RebootRequired bool     `json:"reboot_required"`
	ReleasedAt     *string  `json:"released_at"`
	EulaAccepted   bool     `json:"eula_accepted"`
	Categories     []string `json:"categories"`
	Current        string   `json:"current"`
	Version        string   `json:"version"`
}

// ScanOut is the successful output of patch_scan.
type ScanOut struct {
	V             int      `json:"v"`
	OK            bool     `json:"ok"`
	Manager       string   `json:"manager"`
	ScannedAt     string   `json:"scanned_at"`
	ScanState     string   `json:"scan_state"`
	WUState       string   `json:"wu_state"`
	RebootPending bool     `json:"reboot_pending"`
	LastInstallAt *string  `json:"last_install_at"`
	Truncated     bool     `json:"truncated"`
	Total         int      `json:"total"`
	Updates       []Update `json:"updates"`
}

// Kinds, severities and install results of the wire contract.
var (
	validKinds = map[string]bool{"security": true, "critical": true, "important": true, "optional": true, "driver": true, "other": true}
	validSev   = map[string]bool{"critical": true, "important": true, "moderate": true, "low": true, "unspecified": true}
	validWU    = map[string]bool{"running": true, "stopped": true, "disabled": true, "n/a": true}
	// Results that count as success of a requested id.
	goodResults = map[string]bool{"would_install": true, "installed": true, "not_applicable": true, "skipped": true, "needs_reboot": true}
	allResults  = map[string]bool{"would_install": true, "installed": true, "failed": true, "skipped": true, "not_applicable": true, "eula_required": true, "not_found": true, "locked": true, "needs_reboot": true}
)

// ResultItem is the outcome for one requested id (patch_install).
type ResultItem struct {
	ID             string `json:"id"`
	Title          string `json:"title"`
	Result         string `json:"result"`
	Code           string `json:"code"`
	Detail         string `json:"detail"`
	RebootRequired bool   `json:"reboot_required"`
}

// InstallOut is the output of patch_install.
type InstallOut struct {
	V              int          `json:"v"`
	OK             bool         `json:"ok"`
	Mode           string       `json:"mode"`
	Manager        string       `json:"manager"`
	Results        []ResultItem `json:"results"`
	RebootRequired bool         `json:"reboot_required"`
	Truncated      bool         `json:"truncated"`
}
