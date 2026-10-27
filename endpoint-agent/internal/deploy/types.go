// Package deploy implements the software_detect and software_install jobs (RivetCore RMM Phase 5).
//
// Wire contract: the server asks whether named software is installed (read only) and later for the installation or removal of one package. The
// agent NEVER reboots and never passes a reboot flag; it only reports reboot_required. Everything the server sends is re-validated here (the
// server is not trusted for syntax): an unknown parameter, a wrong type, a duplicate key, an out-of-range number or a bad syntax refuses the whole
// job before any command is built or any byte is downloaded. Programs are started from an argv list, never through a shell string.
//
// Linux drives apt or dnf/yum from the machine's own configured repositories only (no URL, file or unauthenticated option ever reaches the package
// manager; every package name follows "--"). Windows installs an MSI or an EXE that the agent downloads itself over HTTPS, pinned by sha256 and
// size, into a private staging directory that is removed afterwards.
package deploy

// Job type wire names (the same strings as jobs.TypeSoftwareDetect and jobs.TypeSoftwareInstall; a test keeps them equal).
const (
	JobDetect  = "software_detect"
	JobInstall = "software_install"
)

// Capability strings announced in the check-in.
const (
	CapDetect  = "software:detect"
	CapInstall = "software:install"
)

// Limits of the wire contract.
const (
	MaxItems          = 25
	MaxRulesBytes     = 1000
	MaxURLLen         = 600
	MaxSize           = 1 << 30
	MaxArgsChars      = 200
	MaxArgTokens      = 12
	MaxArgToken       = 80
	MaxNameChars      = 120
	DefaultMaxMinutes = 30
	MaxMaxMinutes     = 120
	maxDetail         = 200
)

// Detection kinds.
const (
	KindPackage      = "package"
	KindProductCode  = "product_code"
	KindUninstallNam = "uninstall_name"
)

// Package formats.
const (
	FmtApt = "apt"
	FmtDnf = "dnf"
	FmtMsi = "msi"
	FmtExe = "exe"
)

// Failure detail codes of a whole job.
const (
	DetailInvalidParams = "invalid_params"
	DetailUnsupported   = "unsupported_platform"
	DetailNoManager     = "no_package_manager"
	DetailRegistry      = "registry_unavailable"
	DetailTimeout       = "timeout"
	DetailBusy          = "busy"
)

// Install results of the wire contract.
const (
	ResWouldInstall   = "would_install"
	ResWouldUninstall = "would_uninstall"
	ResInstalled      = "installed"
	ResUninstalled    = "uninstalled"
	ResAlready        = "already_installed"
	ResNotInstalled   = "not_installed"
	ResFailed         = "failed"
	ResHashMismatch   = "hash_mismatch"
	ResDownloadFailed = "download_failed"
	ResNotFound       = "not_found"
	ResLocked         = "locked"
	ResNeedsReboot    = "needs_reboot"
	ResVerifyFailed   = "verify_failed"
)

// goodResults are the results that count as success (ok=true).
var goodResults = map[string]bool{
	ResWouldInstall: true, ResWouldUninstall: true, ResInstalled: true, ResUninstalled: true,
	ResAlready: true, ResNotInstalled: true, ResNeedsReboot: true,
}

// ItemOut is the outcome for one detection item.
type ItemOut struct {
	Key       string `json:"key"`
	Installed bool   `json:"installed"`
	Version   string `json:"version"`
	Satisfied bool   `json:"satisfied"`
	Detail    string `json:"detail"`
}

// DetectOut is the output of software_detect.
type DetectOut struct {
	V     int       `json:"v"`
	OK    bool      `json:"ok"`
	Items []ItemOut `json:"items"`
}

// InstallOut is the output of software_install.
type InstallOut struct {
	V              int    `json:"v"`
	OK             bool   `json:"ok"`
	Action         string `json:"action"`
	Mode           string `json:"mode"`
	Fmt            string `json:"fmt"`
	Result         string `json:"result"`
	Code           string `json:"code"`
	Detail         string `json:"detail"`
	Version        string `json:"version"`
	RebootRequired bool   `json:"reboot_required"`
}
