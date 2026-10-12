package agent

import (
	"context"
	"os"
	"path/filepath"
	"slices"
	"testing"
	"time"

	"rivetit-agent/internal/api"
	"rivetit-agent/internal/mesh"
)

const meshTestNode = "node//AbCdEfGhIjKlMnOpQrStUvWxYz0123456789@$AbCdEfGhIjKlMnOpQrStUvWxYz01"

func meshRig(t *testing.T, exists *bool, nodeOut *string) *rig {
	r := newRig(t)
	r.enroll()
	r.a.meshRep = &mesh.Reporter{StateDir: r.a.o.Store.Dir, Now: r.a.o.Now,
		Probe: mesh.Probe{GOOS: "linux", Paths: []string{"/x/meshagent"}, Exists: func(string) bool { return *exists },
			Run: func(context.Context, string, string, []string) (int, string, error) { return 0, *nodeOut + "\n", nil }}}
	return r
}

func checkinMesh(t *testing.T, body []byte) map[string]any {
	m := decodeCheckin(t, body)
	if v, ok := m["mesh_agent"]; ok {
		return v.(map[string]any)
	}
	return nil
}

func TestNoMeshAgentNoMeshMember(t *testing.T) {
	exists, node := false, ""
	r := meshRig(t, &exists, &node)
	if out := r.a.checkIn(context.Background(), 0); !out.ok {
		t.Fatal("check-in failed")
	}
	if _, ok := decodeCheckin(t, r.s.checkinBodies()[0])["mesh_agent"]; ok {
		t.Fatal("a device without a Mesh agent must not send a mesh_agent member (byte-identical to before)")
	}
}

func TestCapabilityIsAnnounced(t *testing.T) {
	if !slices.Contains(Capabilities(), api.CapMeshNodeID) || api.CapMeshNodeID != "mesh:nodeid" {
		t.Fatal("mesh:nodeid must be announced")
	}
}

func TestMeshReportOnceThenDailyThenOnChange(t *testing.T) {
	exists, node := true, meshTestNode
	r := meshRig(t, &exists, &node)
	now := time.Date(2026, 10, 11, 12, 0, 0, 0, time.UTC)
	r.a.o.Now = func() time.Time { return now }
	r.a.meshRep.Now = r.a.o.Now
	ctx := context.Background()
	send := func() map[string]any {
		before := len(r.s.checkinBodies())
		if out := r.a.checkIn(ctx, 0); !out.ok {
			t.Fatal("check-in failed")
		}
		return checkinMesh(t, r.s.checkinBodies()[before])
	}
	m := send()
	if m == nil || m["installed"] != true || m["node_id"] != meshTestNode {
		t.Fatalf("first check-in: %v", m)
	}
	if send() != nil {
		t.Fatal("unchanged state is not repeated")
	}
	now = now.Add(23 * time.Hour)
	if send() != nil {
		t.Fatal("not yet a day")
	}
	now = now.Add(2 * time.Hour)
	if send() == nil {
		t.Fatal("repeated after a day")
	}
	// the node id changes (the machine got a new Mesh identity): reported at the next refresh
	node = "node//ZZZZZZZZZZZZZZZZZZZZZZZZ"
	now = now.Add(25 * time.Hour)
	if m := send(); m == nil || m["node_id"] != "node//ZZZZZZZZZZZZZZZZZZZZZZZZ" {
		t.Fatalf("%v", m)
	}
}

func TestFailedMeshInstallIsReportedWithItsErrorAndNothingElse(t *testing.T) {
	exists, node := false, ""
	r := meshRig(t, &exists, &node)
	if err := mesh.SaveResult(r.a.o.Store.Dir, mesh.Result{Error: "the Mesh server answered HTTP 404", AttemptedAt: "2026-10-11T12:00:00Z"}); err != nil {
		t.Fatal(err)
	}
	if out := r.a.checkIn(context.Background(), 0); !out.ok {
		t.Fatal("check-in failed")
	}
	m := checkinMesh(t, r.s.checkinBodies()[0])
	if m == nil || m["installed"] != false || m["error"] != "the Mesh server answered HTTP 404" {
		t.Fatalf("%v", m)
	}
	if _, has := m["node_id"]; has {
		t.Fatal("no node id without an agent")
	}
}

func TestDisableRemoteHidesMesh(t *testing.T) {
	exists, node := true, meshTestNode
	r := meshRig(t, &exists, &node)
	r.a.mu.Lock()
	r.a.cfg.DisableRemote = true
	r.a.mu.Unlock()
	if slices.Contains(r.a.capabilities(), api.CapMeshNodeID) {
		t.Fatal("disable_remote must withdraw mesh:nodeid")
	}
	r.a.checkIn(context.Background(), 0)
	if checkinMesh(t, r.s.checkinBodies()[0]) != nil {
		t.Fatal("disable_remote must not send the Mesh block")
	}
}

func TestOperatorOverrideFileIsUsedWhenTheAgentCannotBeAsked(t *testing.T) {
	exists, node := true, "not a node id"
	r := meshRig(t, &exists, &node)
	r.a.meshRep.Override = r.a.meshNodeOverride
	if err := os.WriteFile(filepath.Join(r.a.o.Store.Dir, "mesh_node_id.txt"), []byte(meshTestNode+"\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	r.a.checkIn(context.Background(), 0)
	if m := checkinMesh(t, r.s.checkinBodies()[0]); m == nil || m["node_id"] != meshTestNode {
		t.Fatalf("%v", m)
	}
}

func TestMeshJobCapabilitiesAreAnnouncedAndWithdrawnWithDisableRemote(t *testing.T) {
	for _, c := range []string{"job:mesh_install", "job:mesh_uninstall"} {
		if !slices.Contains(Capabilities(), c) {
			t.Fatalf("%s must be announced by an agent that can run it", c)
		}
	}
	if mesh.JobInstall != "mesh_install" || mesh.JobUninstall != "mesh_uninstall" {
		t.Fatal("the wire names are part of the protocol")
	}
	exists, node := true, meshTestNode
	r := meshRig(t, &exists, &node)
	r.a.mu.Lock()
	r.a.cfg.DisableRemote = true
	r.a.mu.Unlock()
	for _, c := range []string{"job:mesh_install", "job:mesh_uninstall"} {
		if slices.Contains(r.a.capabilities(), c) {
			t.Fatalf("disable_remote must withdraw %s", c)
		}
	}
}
