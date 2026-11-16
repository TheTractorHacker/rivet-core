package strictjson

import "testing"

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
