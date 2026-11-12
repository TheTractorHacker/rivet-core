//go:build !windows

package remote

import (
	"context"
	"os"
	"path/filepath"
	"testing"

	"rivetit-agent/internal/api"
)

// CORE-21: the agent (root) must never chmod or move whatever a user swaps in for the temp file while the download runs.
func TestFilePutDoesNotFollowATempFileSwappedForASymlink(t *testing.T) {
	dir := realDir(t)
	victim := filepath.Join(realDir(t), "victim")
	if err := os.WriteFile(victim, []byte("precious"), 0o600); err != nil {
		t.Fatal(err)
	}
	data := []byte("new content")
	srv := newFakeServer(t)
	srv.content = data
	dest := filepath.Join(dir, "out.bin")
	f := fastFiles(dir, srv)
	inner := f.T
	tmp := filepath.Join(dir, TempName(tid))
	f.T = transportFunc(func(ctx context.Context, tr api.TransferRequest) (*api.TransferResponse, error) {
		os.Remove(tmp)
		if err := os.Symlink(victim, tmp); err != nil {
			t.Error(err)
		}
		return inner.Transfer(ctx, tr)
	})
	r := f.Put(context.Background(), putParams(dest, dir, data, false, map[string]any{"mode": "0666"}), 4096)
	if r.OK {
		t.Fatalf("a swapped temp file was committed: %+v", r)
	}
	if fi, err := os.Stat(victim); err != nil || fi.Mode().Perm() != 0o600 {
		t.Errorf("victim was chmodded or lost: %v %v", fi, err)
	}
	if b, _ := os.ReadFile(victim); string(b) != "precious" {
		t.Errorf("victim content changed: %q", b)
	}
	if _, err := os.Lstat(dest); err == nil {
		t.Error("destination was created from the swapped temp file")
	}
}

func TestFilePutSwappedForAnotherRegularFileIsRefused(t *testing.T) {
	dir := realDir(t)
	data := []byte("new content")
	srv := newFakeServer(t)
	srv.content = data
	dest := filepath.Join(dir, "out.bin")
	f := fastFiles(dir, srv)
	inner := f.T
	tmp := filepath.Join(dir, TempName(tid))
	f.T = transportFunc(func(ctx context.Context, tr api.TransferRequest) (*api.TransferResponse, error) {
		os.Remove(tmp)
		os.WriteFile(tmp, []byte("attacker"), 0o644) // a different inode under the same name
		return inner.Transfer(ctx, tr)
	})
	if r := f.Put(context.Background(), putParams(dest, dir, data, false, nil), 4096); r.OK {
		t.Fatalf("%+v", r)
	}
	if _, err := os.Lstat(dest); err == nil {
		t.Error("destination created")
	}
}

func TestFilePutStaleTempLinkIsRemovedNotFollowed(t *testing.T) {
	dir := realDir(t)
	victim := filepath.Join(realDir(t), "victim")
	os.WriteFile(victim, []byte("precious"), 0o600)
	if err := os.Symlink(victim, filepath.Join(dir, TempName(tid))); err != nil {
		t.Fatal(err)
	}
	data := []byte("fresh")
	srv := newFakeServer(t)
	srv.content = data
	dest := filepath.Join(dir, "out.bin")
	if r := fastFiles(dir, srv).Put(context.Background(), putParams(dest, dir, data, false, nil), 4096); !r.OK {
		t.Fatalf("%+v", r)
	}
	if b, _ := os.ReadFile(victim); string(b) != "precious" {
		t.Errorf("victim written through the stale link: %q", b)
	}
	if b, _ := os.ReadFile(dest); string(b) != "fresh" {
		t.Errorf("dest %q", b)
	}
}

func TestBeginPutRefusesADirectorySymlink(t *testing.T) {
	real := realDir(t)
	link := filepath.Join(realDir(t), "link")
	if err := os.Symlink(real, link); err != nil {
		t.Fatal(err)
	}
	if pt, err := beginPut(link, "x.tmp"); err == nil {
		pt.Discard()
		t.Fatal("a symlinked destination directory was opened")
	}
	if entries, _ := os.ReadDir(real); len(entries) != 0 {
		t.Errorf("a file was created through the link: %v", entries)
	}
}

// The destination directory may sit below a symlinked ANCESTOR (a /tmp -> /private/tmp style link, a symlinked /var/tmp): that must keep working.
func TestFilePutThroughASymlinkedAncestorSucceeds(t *testing.T) {
	base := realDir(t)
	real := filepath.Join(base, "real", "data")
	if err := os.MkdirAll(real, 0o755); err != nil {
		t.Fatal(err)
	}
	alias := filepath.Join(base, "alias")
	if err := os.Symlink(filepath.Join(base, "real"), alias); err != nil {
		t.Fatal(err)
	}
	// beginPut directly with the unresolved path
	pt, err := beginPut(filepath.Join(alias, "data"), "x.tmp")
	if err != nil {
		t.Fatalf("symlinked ancestor refused: %v", err)
	}
	pt.File.WriteString("hi")
	if err := pt.Commit("out.bin", 0o640, true, false); err != nil {
		t.Fatal(err)
	}
	pt.Close()
	if b, err := os.ReadFile(filepath.Join(real, "out.bin")); err != nil || string(b) != "hi" {
		t.Errorf("file not delivered: %q %v", b, err)
	}
}
