package mesh

import (
	"context"
	"testing"
	"time"
)

type retryRig struct {
	now    time.Time
	exists bool
	id     string
	runs   int
	flags  []string
	rp     *Reporter
}

func newRetryRig(t *testing.T) *retryRig {
	r := &retryRig{now: time.Date(2026, 10, 20, 9, 23, 32, 0, time.UTC)}
	r.rp = &Reporter{StateDir: t.TempDir(), Now: func() time.Time { return r.now },
		Probe: Probe{GOOS: "linux", Paths: []string{"/usr/local/mesh_services/meshagent/meshagent"},
			Exists:   func(string) bool { return r.exists },
			ReadFile: func(string) ([]byte, error) { return nil, context.Canceled },
			Run: func(_ context.Context, _ string, _ string, args []string) (int, string, error) {
				r.runs++
				r.flags = append(r.flags, args[0])
				if r.id == "" {
					return 1, "", nil
				}
				return 0, "MeshCentral banner\n" + r.id + "\n", nil
			}}}
	return r
}

const idA = "AbCdEfGhIjKlMnOpQrStUvWxYz0123456789@$AbCdEfGhIjKlMnOpQrStUvWxYz0123"

func (r *retryRig) step(d time.Duration) *Report {
	r.now = r.now.Add(d)
	return r.rp.Current(context.Background())
}

func TestRetryMeshDirAppearsLater(t *testing.T) {
	r := newRetryRig(t)
	if got := r.rp.Current(context.Background()); got != nil {
		t.Fatalf("no mesh yet: %v", got)
	}
	for i := 0; i < 5; i++ { // 5 minutes, nothing there: still nothing, no program run
		if r.step(60*time.Second) != nil {
			t.Fatal("unexpected report")
		}
	}
	if r.runs != 0 {
		t.Fatalf("ran a program without an agent: %d", r.runs)
	}
	r.exists = true // installer finished; id not readable yet (agent not enrolled)
	got := r.step(10 * time.Second)
	if got == nil || !got.Installed || got.NodeID != "" || got.State != StateNoNodeID {
		t.Fatalf("want installed, no id: %+v", got)
	}
	if r.rp.Retry() != fastEvery {
		t.Fatalf("retry %v", r.rp.Retry())
	}
	runs := r.runs
	r.step(30 * time.Second) // inside the 60 s gap: cached
	if r.runs != runs {
		t.Fatal("looked again too soon")
	}
	r.id = idA
	got = r.step(40 * time.Second)
	if got == nil || got.NodeID != "node//"+idA || got.State != StateReady {
		t.Fatalf("want id: %+v", got)
	}
	if r.rp.Retry() != 0 {
		t.Fatal("still retrying after found")
	}
	runs = r.runs
	r.step(5 * time.Minute)
	r.step(2 * time.Hour)
	if r.runs != runs {
		t.Fatalf("kept running after found: %d", r.runs-runs)
	}
	if got = r.step(25 * time.Hour); r.runs == runs || got.NodeID == "" {
		t.Fatal("daily refresh missing")
	}
}

func TestRetrySchedule(t *testing.T) {
	r := newRetryRig(t)
	r.exists = true
	r.rp.Current(context.Background()) // t0: installed, no id
	count := func(span, step time.Duration) int {
		before := r.runs
		for el := time.Duration(0); el < span; el += step {
			r.step(step)
		}
		return r.runs - before
	}
	// each lookup tries -nodeid and --nodeid; count lookups = runs/2
	if n := count(9*time.Minute, 10*time.Second) / 2; n < 8 || n > 9 {
		t.Fatalf("first 10 min: %d lookups", n)
	}
	count(2*time.Minute, 10*time.Second) // cross into the slow window
	if n := count(60*time.Minute, 10*time.Second) / 2; n < 11 || n > 13 {
		t.Fatalf("slow window: %d lookups in 60 min", n)
	}
	if r.rp.Retry() != slowEvery {
		t.Fatalf("retry %v", r.rp.Retry())
	}
	count(70*time.Minute, 10*time.Second) // past the 2 h 10 min mark
	if r.rp.Retry() != 0 {
		t.Fatalf("still polling after the windows: %v", r.rp.Retry())
	}
	if n := count(10*time.Hour, time.Minute); n != 0 {
		t.Fatalf("polled %d times after the windows", n)
	}
}

func TestRetryReReportsOnChangeAndInvalidate(t *testing.T) {
	r := newRetryRig(t)
	r.exists, r.id = true, idA
	a := r.rp.Current(context.Background())
	if a == nil || a.NodeID == "" {
		t.Fatal("no id")
	}
	r.id = "ZyXwVuTsRqPoNmLkJiHgFeDcBa9876543210@$ZyXwVuTsRqPoNmLkJiHgFeDcBa9876"
	if got := r.step(time.Minute); got.NodeID != a.NodeID { // cached until Invalidate or a day
		t.Fatal("changed too early")
	}
	r.rp.Invalidate() // a reinstall job succeeded
	b := r.step(time.Second)
	if b.NodeID == a.NodeID || b.Hash() == a.Hash() {
		t.Fatalf("change not reported: %+v", b)
	}
	if r.rp.Retry() != 0 {
		t.Fatal("retry after id found")
	}
}

func TestProbeFlagVariantAndExtraPath(t *testing.T) {
	var ran []string
	p := Probe{GOOS: "linux", Paths: []string{"/usr/local/mesh_services/meshagent/meshagent"}, ExtraPaths: []string{"/srv/custom/meshagent64", "relative/meshagent", "/bin/sh"},
		Exists: func(s string) bool { return s == "/srv/custom/meshagent64" },
		Run: func(_ context.Context, _ string, exe string, args []string) (int, string, error) {
			ran = append(ran, exe+" "+args[0])
			if args[0] == "--nodeid" {
				return 0, "banner\nnode//" + idA + "\n", nil
			}
			return 2, "usage", nil
		}}
	id, src := p.NodeID(context.Background())
	if id != "node//"+idA || src != "nodeid" {
		t.Fatalf("%q %q", id, src)
	}
	if len(ran) != 2 || ran[0] != "/srv/custom/meshagent64 -nodeid" || ran[1] != "/srv/custom/meshagent64 --nodeid" {
		t.Fatalf("ran %v", ran)
	}
	if ValidExtraPath("/bin/sh") || ValidExtraPath("relative/meshagent") {
		t.Fatal("accepted a path outside the rules")
	}
}
