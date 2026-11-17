package strictjson

import (
	"encoding/json"
	"testing"
)

type sample struct {
	Script   string `json:"script"`
	TimeoutS int    `json:"timeout_s"`
	Skip     string `json:"-"`
	NoTag    string
}

func TestExactKeysDecode(t *testing.T) {
	var s sample
	if err := Unmarshal([]byte(`{"script":"a","timeout_s":5,"extra":1}`), &s); err != nil {
		t.Fatal(err)
	}
	if s.Script != "a" || s.TimeoutS != 5 {
		t.Fatalf("%+v", s)
	}
}

func TestCaseVariantOfKnownFieldRefused(t *testing.T) {
	for _, in := range []string{
		`{"script":"A","Script":"B"}`,
		`{"SCRIPT":"B"}`,
		`{"Timeout_S":5}`,
		`{"notag":"x"}`,
	} {
		var s sample
		if err := Unmarshal([]byte(in), &s); err == nil {
			t.Errorf("%s accepted: %+v", in, s)
		}
	}
}

func TestUnicodeFoldVariantsRefused(t *testing.T) {
	var kv struct {
		Key string `json:"key"`
		Mss string `json:"ssid"`
	}
	for _, in := range []string{"{\"\u212Aey\":\"x\"}", "{\"\u017F\u017Fid\":\"x\"}", "{\"s\u017Fid\":\"x\"}"} {
		if err := Unmarshal([]byte(in), &kv); err == nil {
			t.Errorf("%s accepted: %+v", in, kv)
		}
	}
	// control: confirm encoding/json itself binds the Kelvin spelling, so the gate is needed
	var raw struct {
		Key string `json:"key"`
	}
	_ = json.Unmarshal([]byte("{\"\u212Aey\":\"x\"}"), &raw)
	if raw.Key != "x" {
		t.Log("encoding/json no longer folds Kelvin; gate is belt and braces")
	}
}

func TestDuplicateKeysRefused(t *testing.T) {
	var s sample
	if err := Unmarshal([]byte(`{"script":"A","script":"B"}`), &s); err == nil {
		t.Fatalf("duplicate accepted: %+v", s)
	}
}

func TestNotAnObjectRefused(t *testing.T) {
	var s sample
	for _, in := range []string{`[]`, `"x"`, `{"script":"a"} {}`, ``} {
		if err := Unmarshal([]byte(in), &s); err == nil {
			t.Errorf("%q accepted", in)
		}
	}
}

func TestNestedKeysAreNotCaseChecked(t *testing.T) {
	var s struct {
		Params map[string]int `json:"params"`
	}
	if err := Unmarshal([]byte(`{"params":{"A":1,"a":2}}`), &s); err != nil {
		t.Fatal(err)
	}
}
