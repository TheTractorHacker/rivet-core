//go:build !windows

package mesh

import (
	"context"
	"strings"
	"testing"
)

// CORE-DS-56: the third-party Mesh installer must not inherit the agent's secrets (RIVETIT_ENROLL_TOKEN and friends) from the setup environment.
func TestRunProgramDoesNotPassRivetitEnvironment(t *testing.T) {
	t.Setenv("RIVETIT_ENROLL_TOKEN", "rvte1.secretsecretsecret")
	t.Setenv("RIVETIT_SOMETHING_ELSE", "x")
	t.Setenv("KEEP_ME", "yes")
	_, out, err := RunProgram(context.Background(), t.TempDir(), "/usr/bin/env", nil)
	if err != nil {
		t.Skip("env not runnable:", err)
	}
	if strings.Contains(out, "RIVETIT_") || strings.Contains(out, "secretsecret") {
		t.Fatalf("secrets reached the child: %s", out)
	}
	if !strings.Contains(out, "KEEP_ME=yes") {
		t.Fatalf("the rest of the environment must still be there: %s", out)
	}
}
