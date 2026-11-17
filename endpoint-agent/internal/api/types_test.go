package api

import (
	"encoding/json"
	"testing"
)

func TestCheckSpecRefusesCaseVariantMembers(t *testing.T) {
	var c CheckSpec
	if err := json.Unmarshal([]byte(`{"key":"a","Key":"LOG.RULE_7","type":"disk"}`), &c); err == nil {
		t.Fatalf("case-variant key accepted: %+v", c)
	}
	if err := json.Unmarshal([]byte(`{"key":"a","type":"disk","params":{"x":1}}`), &c); err != nil || c.Key != "a" {
		t.Fatalf("plain spec: %v %+v", err, c)
	}
}
