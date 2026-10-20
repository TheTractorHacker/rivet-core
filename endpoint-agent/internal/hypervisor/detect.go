package hypervisor

import (
	"context"
	"os"
	"runtime"
)

// SystemDetector is the production Detector: Proxmox VE on Linux, Hyper-V on Windows.
func SystemDetector(r Runner) Detector {
	return func(ctx context.Context) (Collector, error) {
		switch runtime.GOOS {
		case "linux":
			return DetectProxmox(ctx, &Proxmox{R: r, Hostname: func() string { h, _ := os.Hostname(); return h }})
		case "windows":
			return DetectHyperV(ctx, &HyperV{R: r})
		}
		return nil, ErrNotHost
	}
}
