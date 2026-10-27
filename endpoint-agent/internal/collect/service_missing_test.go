package collect

import (
	"context"
	"encoding/json"
	"testing"

	"rivetit-agent/internal/store"
)

// A service that does not exist: fail by default, warn or ignore when the
// check says so (params.missing). The same code serves Windows and Linux.
func TestServiceMissingParam(t *testing.T) {
	c := newCol(newFake())
	run := func(params string) string {
		r := c.RunCheck(context.Background(), store.CheckSpec{Key: "svc", Type: "service", Params: json.RawMessage(params)})
		return r.Status
	}
	for params, want := range map[string]string{
		`{"name":"gone"}`:                      Fail,
		`{"name":"gone","missing":"fail"}`:     Fail,
		`{"name":"gone","missing":"warn"}`:     Warn,
		`{"name":"gone","missing":"WARN"}`:     Warn,
		`{"name":"gone","missing":"ignore"}`:   OK,
		`{"name":"gone","missing":"nonsense"}`: Fail,
	} {
		if got := run(params); got != want {
			t.Errorf("%s: status %s, want %s", params, got, want)
		}
	}
}
