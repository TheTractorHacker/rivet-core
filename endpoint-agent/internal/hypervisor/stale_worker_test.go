package hypervisor

import (
	"context"
	"sync"
	"testing"
	"time"

	"rivetit-agent/internal/api"
)

// slowPlatform blocks Full until released, ignoring the context (a collection that takes minutes).
type slowPlatform struct {
	fakePlatform
	release chan struct{}
	started chan struct{}
	once    sync.Once
}

func (s *slowPlatform) Full(ctx context.Context, o Options) (*api.HypervisorReport, error) {
	s.once.Do(func() { close(s.started) })
	<-s.release
	return s.fakePlatform.Full(ctx, o)
}

// CORE-DS-21: a configuration change (or switch-off) while a collection runs must not leave the manager "running" for ever.
func TestCollectionRestartsAfterConfigChangeDuringARun(t *testing.T) {
	for _, mode := range []string{"new-version", "switch-off-then-on"} {
		t.Run(mode, func(t *testing.T) {
			sp := &slowPlatform{fakePlatform: fakePlatform{states: map[string]string{"qemu/100": "running"}}, release: make(chan struct{}), started: make(chan struct{})}
			c := &clock{t: time.Date(2026, 10, 20, 12, 0, 0, 0, time.UTC)}
			m := newMgr(&sp.fakePlatform, c, func(ctx context.Context) (Collector, error) { return sp, nil })
			if err := m.Configure(cfgJSON(1, "aaaaaaaaaaaaaaaa", nil)); err != nil {
				t.Fatal(err)
			}
			m.Build()
			<-sp.started
			switch mode {
			case "new-version":
				if err := m.Configure(cfgJSON(2, "bbbbbbbbbbbbbbbb", nil)); err != nil {
					t.Fatal(err)
				}
			default:
				m.Configure(nil)
				if err := m.Configure(cfgJSON(3, "cccccccccccccccc", nil)); err != nil {
					t.Fatal(err)
				}
			}
			close(sp.release) // the stale worker ends now
			time.Sleep(50 * time.Millisecond)
			if r := waitReport(t, m); r == nil {
				t.Fatal("no report ever again: the manager is stuck in running")
			}
		})
	}
}
