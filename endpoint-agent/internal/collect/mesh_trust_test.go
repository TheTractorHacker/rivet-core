//go:build !windows

package collect

import (
	"context"
	"os"
	"path/filepath"
	"testing"
)

type meshDirFake struct {
	*fake
	dir string
}

func (m meshDirFake) MeshAgentDir() string { return m.dir }

// CORE-DS-19: inventory must not run a Mesh agent binary the CORE-16/F10 trust checks refuse (here: owned by the test user, not root).
func TestMeshNodeIDDoesNotRunAnUntrustedBinary(t *testing.T) {
	if os.Getuid() == 0 {
		t.Skip("the planted file would be root-owned and therefore trusted")
	}
	dir := t.TempDir()
	marker := filepath.Join(dir, "ran")
	script := "#!/bin/sh\ntouch " + marker + "\necho " + "node//" + "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA" + "\n"
	if err := os.WriteFile(filepath.Join(dir, "meshagent"), []byte(script), 0o755); err != nil {
		t.Fatal(err)
	}
	c := newCol(newFake())
	c.P = meshDirFake{newFake(), dir}
	if got := c.MeshNodeID(context.Background()); got != "" {
		t.Fatalf("an untrusted binary supplied a node id: %q", got)
	}
	if _, err := os.Stat(marker); err == nil {
		t.Fatal("the untrusted binary was executed")
	}
}
