package api

import (
	"encoding/json"
	"os"
	"reflect"
	"testing"
)

// The shared rc.25 vector (tests/Fixtures/rmm/vectors/metrics-extended.json) must round-trip through Metrics unchanged: the
// field names and shapes the agent sends are the ones the server maps.
func TestExtendedMetricsVectorRoundTrips(t *testing.T) {
	b, err := os.ReadFile("../../../tests/Fixtures/rmm/vectors/metrics-extended.json")
	if err != nil {
		t.Skip("vector not found (agent built outside the repository)")
	}
	var v struct {
		Metrics json.RawMessage `json:"metrics"`
	}
	if err := json.Unmarshal(b, &v); err != nil {
		t.Fatal(err)
	}
	var m Metrics
	if err := json.Unmarshal(v.Metrics, &m); err != nil {
		t.Fatal(err)
	}
	out, _ := json.Marshal(m)
	var a, w map[string]any
	json.Unmarshal(out, &a)
	json.Unmarshal(v.Metrics, &w)
	if !reflect.DeepEqual(a, w) {
		t.Fatalf("round trip differs:\n got %s\nwant %s", out, v.Metrics)
	}
}
