package mesh

import (
	"os"
	"testing"
)

// The tests put fake Mesh agents in a temporary directory that is not root-owned: the ownership check of the real file system is relaxed here and tested directly
// in trust_unix_test.go.
func TestMain(m *testing.M) {
	trustCheck = func(string) bool { return true }
	os.Exit(m.Run())
}
