package api

// FeatureHypervisor is the capability string (announced by the agent) and the
// feature string (offered by the server) of hypervisor visibility
// (docs/rmm/PROTOCOL.md 3.2.10, docs/rmm/HYPERVISORS.md). The agent reports a
// "hypervisor" block only after a response offered the feature AND carried a
// signed config.hypervisor object that verified.
const FeatureHypervisor = "hypervisor"

// HypervisorReport is the optional "hypervisor" block of a check-in. Mode "full"
// is the whole inventory of the host (VMs, storage, cluster); mode "summary" is
// the compact state-change report sent between full ones (Changes only).
// Error, when set, means the collection failed or was partial: the server then
// never treats a VM missing from the list as gone.
type HypervisorReport struct {
	Mode        string             `json:"mode"`     // full | summary
	Platform    string             `json:"platform"` // proxmox | hyperv
	Version     string             `json:"version,omitempty"`
	CollectedAt string             `json:"collected_at"`
	Cluster     *HypervisorCluster `json:"cluster,omitempty"`
	VMs         []HypervisorVM     `json:"vms,omitempty"`
	Storage     []HypervisorStore  `json:"storage,omitempty"`
	Changes     []HypervisorChange `json:"changes,omitempty"` // summary mode only
	VMCount     int                `json:"vm_count,omitempty"`
	Truncated   bool               `json:"truncated,omitempty"`
	Error       string             `json:"error,omitempty"`
	CfgVersion  string             `json:"cfg_version,omitempty"`
}

type HypervisorCluster struct {
	Name         string           `json:"name,omitempty"`
	Quorate      *bool            `json:"quorate,omitempty"`
	Nodes        []HypervisorNode `json:"nodes,omitempty"`
	FailedGroups int              `json:"failed_groups,omitempty"`
}

type HypervisorNode struct {
	Name   string `json:"name"`
	Online bool   `json:"online"`
}

type HypervisorSnapshots struct {
	Count    int    `json:"count"`
	OldestAt string `json:"oldest_at,omitempty"`
}

type HypervisorBackup struct {
	At     string `json:"at"`
	Result string `json:"result"` // ok | warning | failed
}

type HypervisorVM struct {
	ID           string               `json:"id"`   // proxmox "qemu/100", hyperv: the VM GUID
	Name         string               `json:"name"`
	Type         string               `json:"type"` // qemu | lxc | hyperv
	State        string               `json:"state"`
	Node         string               `json:"node,omitempty"`
	CPUs         int                  `json:"cpus,omitempty"`
	CPUPct       *float64             `json:"cpu_pct,omitempty"`
	MemBytes     *int64               `json:"mem_bytes,omitempty"`
	MemMaxBytes  *int64               `json:"mem_max_bytes,omitempty"`
	DiskBytes    *int64               `json:"disk_bytes,omitempty"`      // provisioned
	DiskUsed     *int64               `json:"disk_used_bytes,omitempty"` // allocated on the host, when known
	UptimeS      *int64               `json:"uptime_s,omitempty"`
	Template     bool                 `json:"template,omitempty"`
	Tags         []string             `json:"tags,omitempty"`
	Snapshots    *HypervisorSnapshots `json:"snapshots,omitempty"`
	LastBackup   *HypervisorBackup    `json:"last_backup,omitempty"`
	BackupJob    *bool                `json:"backup_covered,omitempty"` // a backup job includes this guest (proxmox)
	GuestAgent   *bool                `json:"guest_agent,omitempty"`
	Replication  string               `json:"replication,omitempty"` // normal | warning | critical (hyperv)
	Generation   int                  `json:"generation,omitempty"`
	ConfigVer    string               `json:"config_version,omitempty"`
}

type HypervisorStore struct {
	Name       string `json:"name"`
	Type       string `json:"type"`
	TotalBytes int64  `json:"total_bytes,omitempty"`
	UsedBytes  int64  `json:"used_bytes,omitempty"`
	Health     string `json:"health"` // ok | warn | error | unknown
	Shared     bool   `json:"shared,omitempty"`
}

type HypervisorChange struct {
	ID    string `json:"id"`
	State string `json:"state"`
}
