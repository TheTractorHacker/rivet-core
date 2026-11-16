package store

import (
	"path/filepath"
	"strings"
)

// stateLevel is one directory of the state directory chain and whether everything inside it must be vetted too.
type stateLevel struct {
	Dir      string
	CheckAll bool
}

// stateChain lists the directories ensureDir verifies. For the default layout (<ProgramData>\RivetIT\Agent) the RivetIT parent is verified itself
// (owner, not a link, tightened) but its CONTENTS are not: older agents left it with the inherited ProgramData ACL, so a standard user can have put
// an entry there, and the agent never reads anything in it except Agent\. Refusing to start because of such an entry would let any user take the
// device offline for good (CORE-DS-55). Only the Agent directory, which holds config.json and the credential, is vetted entry by entry.
func stateChain(dir string) []stateLevel {
	dir = filepath.Clean(dir)
	if parent := filepath.Dir(dir); strings.EqualFold(filepath.Base(parent), "RivetIT") {
		return []stateLevel{{parent, false}, {dir, true}}
	}
	return []stateLevel{{dir, true}}
}
