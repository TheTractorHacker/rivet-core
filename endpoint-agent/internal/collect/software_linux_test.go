//go:build linux

package collect

import (
	"context"
	"errors"
	"io"
	"log/slog"
	"os/exec"
	"strings"
	"sync"
	"testing"
	"time"
)

type fakeRun struct {
	mu    sync.Mutex
	calls []string
	out   map[string][]byte
	err   map[string]error
}

func (f *fakeRun) run(ctx context.Context, name string, args ...string) ([]byte, error) {
	f.mu.Lock()
	f.calls = append(f.calls, name+" "+strings.Join(args, " "))
	f.mu.Unlock()
	if e, ok := f.err[name]; ok {
		if e == context.DeadlineExceeded {
			<-ctx.Done()
			return nil, ctx.Err()
		}
		return nil, e
	}
	if o, ok := f.out[name]; ok {
		return o, nil
	}
	return nil, &exec.Error{Name: name, Err: exec.ErrNotFound}
}

var quiet = slog.New(slog.NewTextHandler(io.Discard, nil))

func TestLinuxSoftwareSources(t *testing.T) {
	f := &fakeRun{out: map[string][]byte{
		"dpkg-query": fixture(t, "dpkg.txt"),
		"snap":       fixture(t, "snap.txt"),
	}}
	l, err := linuxSoftware(context.Background(), f.run, quiet)
	if err != nil {
		t.Fatal(err)
	}
	var src = map[string]int{}
	for _, i := range NormalizeSoftware(l.Items).Items {
		src[i.Source]++
	}
	if src[SrcDpkg] != 9 || src[SrcSnap] != 5 || src[SrcRPM] != 0 || src[SrcFlatpak] != 0 {
		t.Errorf("sources %v", src)
	}
	// the exact commands of the spec
	if !strings.HasPrefix(f.calls[0], "dpkg-query -W -f=${binary:Package}\t${Version}\t${Maintainer}\t${db:Status-Abbrev}\n") ||
		!strings.HasPrefix(f.calls[1], "rpm -qa --qf %{NAME}\t%{VERSION}-%{RELEASE}\t%{VENDOR}\t%{INSTALLTIME}\n") ||
		f.calls[2] != "snap list" || f.calls[3] != "flatpak list --columns=application,version,origin" {
		t.Errorf("calls %q", f.calls)
	}
}

func TestLinuxSoftwareFailuresAreSkipped(t *testing.T) {
	f := &fakeRun{
		out: map[string][]byte{"rpm": fixture(t, "rpm.txt"), "flatpak": fixture(t, "flatpak.txt")},
		err: map[string]error{"dpkg-query": errors.New("exit status 2"), "snap": context.DeadlineExceeded},
	}
	start := time.Now()
	l, err := linuxSoftware(context.Background(), f.run, quiet)
	if err != nil {
		t.Fatal(err)
	}
	if time.Since(start) > 10*time.Second {
		t.Error("the hung snap must be cut at its own timeout")
	}
	n := map[string]int{}
	for _, i := range l.Items {
		n[i.Source]++
	}
	if n[SrcRPM] == 0 || n[SrcFlatpak] == 0 || n[SrcDpkg] != 0 || n[SrcSnap] != 0 {
		t.Errorf("%v", n)
	}
}

func TestLinuxSoftwareNoSourceIsAnError(t *testing.T) {
	f := &fakeRun{}
	if _, err := linuxSoftware(context.Background(), f.run, quiet); err == nil {
		t.Fatal("no tool at all must be an error")
	}
	f = &fakeRun{err: map[string]error{"dpkg-query": errors.New("boom"), "rpm": errors.New("boom")}}
	if _, err := linuxSoftware(context.Background(), f.run, quiet); err == nil {
		t.Fatal("only failing tools must be an error")
	}
	// an installed tool that lists nothing is a success with an empty list
	f = &fakeRun{out: map[string][]byte{"dpkg-query": nil}}
	if l, err := linuxSoftware(context.Background(), f.run, quiet); err != nil || len(l.Items) != 0 {
		t.Errorf("%v %v", l, err)
	}
}

func TestLinuxPlatformSoftwareUsesInjectedRunner(t *testing.T) {
	f := &fakeRun{out: map[string][]byte{"dpkg-query": fixture(t, "dpkg.txt")}}
	var p Platform = &LinuxPlatform{Run: f.run}
	l, err := p.(SoftwareLister).Software(context.Background())
	if err != nil || len(l.Items) == 0 {
		t.Fatalf("%v %v", l, err)
	}
	if !SoftwareSupported() {
		t.Error("linux supports the inventory")
	}
}

func TestExecRunnerMissingToolAndOutput(t *testing.T) {
	if _, err := execRunner(context.Background(), "definitely-not-a-real-tool-xyz"); !errors.Is(err, exec.ErrNotFound) {
		t.Errorf("%v", err)
	}
	out, err := execRunner(context.Background(), "sh", "-c", "printf 'a\\tb\\n'; echo err >&2")
	if err != nil || string(out) != "a\tb\n" {
		t.Errorf("%q %v", out, err)
	}
}
