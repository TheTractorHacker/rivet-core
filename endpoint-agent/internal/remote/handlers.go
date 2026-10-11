package remote

// Job type wire names (<= 20 characters).
const (
	JobSvcList    = "svc_list"
	JobSvcControl = "svc_control"
	JobProcList   = "proc_list"
	JobProcKill   = "proc_kill"
	JobFileGet    = "file_get"
	JobFilePut    = "file_put"
)

// Capability strings announced in the check-in.
const (
	CapServices  = "remote:services"
	CapProcesses = "remote:processes"
	CapFiles     = "remote:files"
)

// Deps are the production dependencies of the remote tools.
type Deps struct {
	StateDir string    // the agent's state directory (always denied to file jobs)
	T        Transport // agent_transfer (the API client)
	R        Runner    // nil = ExecRunner
}

// Handlers returns the job handlers by job type.
func (d Deps) Handlers() map[string]Handler {
	r := d.R
	if r == nil {
		r = ExecRunner{}
	}
	svc := Services{B: NewServiceBackend(r)}
	procs := Procs{B: NewProcBackend()}
	files := Files{Policy: NewHostPolicy(d.StateDir), T: d.T}
	return map[string]Handler{
		JobSvcList:    svc.List,
		JobSvcControl: svc.Control,
		JobProcList:   procs.List,
		JobProcKill:   procs.Kill,
		JobFileGet:    files.Get,
		JobFilePut:    files.Put,
	}
}
