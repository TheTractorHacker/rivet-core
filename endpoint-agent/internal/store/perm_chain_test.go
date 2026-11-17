package store

import "testing"

func TestStateChainOnlyVetsTheContentsOfTheAgentDirectory(t *testing.T) {
	ch := stateChain("/pd/RivetIT/Agent")
	if len(ch) != 2 || ch[0].Dir != "/pd/RivetIT" || ch[0].CheckAll || ch[1].Dir != "/pd/RivetIT/Agent" || !ch[1].CheckAll {
		t.Fatalf("%+v", ch)
	}
	if ch := stateChain("/custom/state"); len(ch) != 1 || !ch[0].CheckAll {
		t.Fatalf("custom dir: %+v", ch)
	}
	if ch := stateChain("/pd/rivetit/Agent"); len(ch) != 2 || ch[0].CheckAll {
		t.Fatalf("case-insensitive parent name: %+v", ch)
	}
}
