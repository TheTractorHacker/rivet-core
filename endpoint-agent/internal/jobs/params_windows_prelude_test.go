package jobs

import (
	"strings"
	"testing"
)

// CORE-DS-12: on Windows the parameters (secrets included) must reach the script through the process environment only, never inside the
// -EncodedCommand text, which process-creation auditing and script-block logging record.
func TestPowerShellParamsSetupKeepsValuesOutOfTheCommandText(t *testing.T) {
	params := []byte(`{"user":"svc","pw":"Hunter2-secret-value","n":5}`)
	prelude, env, err := PowerShellParamsSetup(params)
	if err != nil {
		t.Fatal(err)
	}
	for _, leak := range []string{"Hunter2-secret-value", "svc", "FromBase64String"} {
		if strings.Contains(prelude, leak) {
			t.Fatalf("prelude contains %q: %s", leak, prelude)
		}
	}
	if !strings.Contains(prelude, "$env:RIVETIT_JOB_PARAMS") {
		t.Errorf("prelude does not read the JSON from the environment: %s", prelude)
	}
	joined := strings.Join(env, "\n")
	if !strings.Contains(joined, `RIVETIT_JOB_PARAMS={"user":"svc","pw":"Hunter2-secret-value","n":5}`) || !strings.Contains(joined, "RIVETIT_PARAM_pw=Hunter2-secret-value") {
		t.Errorf("env missing the values: %v", env)
	}
}

func TestPowerShellParamsSetupWithoutParams(t *testing.T) {
	for _, p := range [][]byte{nil, []byte("null")} {
		prelude, env, err := PowerShellParamsSetup(p)
		if err != nil || prelude != "" || len(env) != 0 {
			t.Errorf("%q: %q %v %v", p, prelude, env, err)
		}
	}
}

func TestPowerShellParamsSetupRefusesParamsTooLargeForAnEnvironmentVariable(t *testing.T) {
	if _, _, err := PowerShellParamsSetup([]byte(`{"a":"` + strings.Repeat("x", 40000) + `"}`)); err == nil {
		t.Fatal("accepted a value over the Windows environment variable limit")
	}
}
