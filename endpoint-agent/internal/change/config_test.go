package change

import (
	"encoding/json"
	"strings"
	"testing"
	"time"
)

func TestAConfigurationIsUsedOnlyWhenItsSignatureVerifies(t *testing.T) {
	sg := newSigner(t)
	cfg := baseConfig("linux", "0123456789abcdef", fileWatch("lnx.sudo", map[string]any{"path": "/etc/sudoers"}))
	good := sg.sign(t, cfg)
	opt := ParseOptions{Verify: sg.verify(), Platform: "linux"}
	c, err := Parse(good, opt)
	if err != nil || c.Version != "0123456789abcdef" || len(c.Watches) != 1 || c.Interval != time.Minute || c.RatePerMin != 600 {
		t.Fatalf("%v %+v", err, c)
	}
	// one changed character anywhere
	for _, mut := range []func(m map[string]any){
		func(m map[string]any) { m["interval_s"] = 61 },
		func(m map[string]any) { m["version"] = "0123456789abcdee" },
		func(m map[string]any) {
			m["watches"].([]any)[0].(map[string]any)["paths"].([]any)[0].(map[string]any)["path"] = "/etc/passwd"
		},
		func(m map[string]any) { m["platform"] = "windows" },
		func(m map[string]any) { m["extra"] = true },
	} {
		var m map[string]any
		json.Unmarshal(good, &m)
		mut(m)
		raw, _ := json.Marshal(m)
		if _, err := Parse(raw, opt); err == nil {
			t.Errorf("a tampered configuration was accepted: %s", raw)
		}
	}
	// signed by another key
	other := newSigner(t)
	if _, err := Parse(other.sign(t, baseConfig("linux", "0123456789abcdef", fileWatch("lnx.sudo", map[string]any{"path": "/etc/sudoers"}))), opt); err == nil {
		t.Error("a configuration signed by another key was accepted")
	}
	// unsigned
	var m map[string]any
	json.Unmarshal(good, &m)
	delete(m, "signature")
	raw, _ := json.Marshal(m)
	if _, err := Parse(raw, opt); err == nil {
		t.Error("an unsigned configuration was accepted")
	}
	// domain separation: a job-style signature (no domain line) over the same object is not accepted
	if _, err := Parse(signNoDomain(t, sg, baseConfig("linux", "0123456789abcdef", fileWatch("lnx.sudo", map[string]any{"path": "/etc/sudoers"}))), opt); err == nil {
		t.Error("a signature without the domain line was accepted")
	}
	// a configuration for the other platform
	if _, err := Parse(good, ParseOptions{Verify: sg.verify(), Platform: "windows"}); err == nil {
		t.Error("a Linux configuration on a Windows agent")
	}
	if _, err := Parse(nil, opt); err != ErrNoConfig {
		t.Errorf("empty: %v", err)
	}
	if _, err := Parse(json.RawMessage(`"nope"`), opt); err == nil {
		t.Error("not an object")
	}
	if _, err := Parse(good, ParseOptions{Platform: "linux"}); err == nil {
		t.Error("no verifier means no configuration")
	}
}

func TestEveryLimitOfAValidlySignedConfigurationIsClamped(t *testing.T) {
	sg := newSigner(t)
	cfg := baseConfig("linux", "0123456789abcdef", fileWatch("lnx.sudo", map[string]any{"path": "/etc/sudoers", "depth": 99}))
	cfg["interval_s"] = 1
	cfg["batch_max"] = 100000
	cfg["rate_per_min"] = 10000000
	cfg["max_value_bytes"] = 10
	c, err := Parse(sg.sign(t, cfg), ParseOptions{Verify: sg.verify(), Platform: "linux"})
	if err != nil {
		t.Fatal(err)
	}
	if c.Interval != MinInterval || c.BatchMax != MaxBatch || c.RatePerMin != MaxRatePerMin || c.MaxValueBytes != MinValueBytes || c.Watches[0].Paths[0].Depth != 3 {
		t.Errorf("%+v", c)
	}
	cfg["interval_s"] = 99999999
	cfg["rate_per_min"] = 0
	cfg["max_value_bytes"] = 99999
	c, _ = Parse(sg.sign(t, cfg), ParseOptions{Verify: sg.verify(), Platform: "linux"})
	if c.Interval != MaxInterval || c.RatePerMin != MinRatePerMin || c.MaxValueBytes != MaxValueBytes {
		t.Errorf("%+v", c)
	}
}

func TestThePolicyOfTheAgentRemovesWhatTheServerShouldNotHaveSent(t *testing.T) {
	sg := newSigner(t)
	win := baseConfig("windows", "0123456789abcdef",
		regWatch("win.startup", map[string]any{"key": `HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Run`}, map[string]any{"key": `HKLM\SAM\SAM\Domains`}, map[string]any{"key": `HKLM\SECURITY\Policy\Secrets`}),
		regWatch("custom.evil", map[string]any{"key": `HKLM\SYSTEM\CurrentControlSet\Control\Lsa\JD`}),
		fileWatch("win.hosts", map[string]any{"path": `C:\Windows\System32\drivers\etc\hosts`}, map[string]any{"path": `C:\Windows\System32\config\SAM`}),
		map[string]any{"id": "win.local_admins", "kind": "group", "name": "Administrators"},
		map[string]any{"id": "win.badgroup", "kind": "group", "name": "x; calc"},
		map[string]any{"id": "win.tasks", "kind": "task"},
		map[string]any{"id": "bad id", "kind": "task"},
		map[string]any{"id": "win.weird", "kind": "wmi"},
	)
	c, err := Parse(sg.sign(t, win), ParseOptions{Verify: sg.verify(), Platform: "windows"})
	if err != nil {
		t.Fatal(err)
	}
	var ids []string
	for _, w := range c.Watches {
		ids = append(ids, w.ID)
	}
	if strings.Join(ids, ",") != "win.startup,win.hosts,win.local_admins,win.tasks" {
		t.Errorf("watches kept: %v", ids)
	}
	if n := len(c.Watches[0].Paths); n != 1 {
		t.Errorf("the credential hives are removed from a watch that also names a good key: %d paths", n)
	}
	if n := len(c.Watches[1].Paths); n != 1 || c.Watches[1].Paths[0].Path != `C:\Windows\System32\drivers\etc\hosts` {
		t.Errorf("hosts: %+v", c.Watches[1].Paths)
	}
	if len(c.Refused) < 4 {
		t.Errorf("what was refused is said: %v", c.Refused)
	}
	// a Windows-only kind on Linux
	lin := baseConfig("linux", "0123456789abcdef", regWatch("win.startup", map[string]any{"key": `HKLM\SOFTWARE\Acme\Agent`}), map[string]any{"id": "win.tasks", "kind": "task"},
		fileWatch("lnx.ssh", map[string]any{"path": "/etc/ssh/sshd_config"}, map[string]any{"path": "/etc/shadow"}, map[string]any{"path": "/etc/ssh/sshd_config.d", "depth": 1, "match": "*.conf"}, map[string]any{"path": "/etc/hosts", "match": "$(rm -rf)"}))
	c, err = Parse(sg.sign(t, lin), ParseOptions{Verify: sg.verify(), Platform: "linux"})
	if err != nil || len(c.Watches) != 1 || c.Watches[0].ID != "lnx.ssh" || len(c.Watches[0].Paths) != 2 {
		t.Fatalf("%v %+v", err, c)
	}
	// a local administrator can widen the file roots; nothing else can
	c, _ = Parse(sg.sign(t, baseConfig("linux", "0123456789abcdef", fileWatch("custom.app", map[string]any{"path": "/srv/app/app.conf"}))), ParseOptions{Verify: sg.verify(), Platform: "linux"})
	if len(c.Watches) != 0 {
		t.Error("/srv is not a configuration root")
	}
	c, _ = Parse(sg.sign(t, baseConfig("linux", "0123456789abcdef", fileWatch("custom.app", map[string]any{"path": "/srv/app/app.conf"}))), ParseOptions{Verify: sg.verify(), Platform: "linux", ExtraRoots: []string{"/srv/app"}})
	if len(c.Watches) != 1 {
		t.Error("an extra root from config.json is honoured")
	}
	c, _ = Parse(sg.sign(t, baseConfig("linux", "0123456789abcdef", fileWatch("custom.app", map[string]any{"path": "/srv/app/id_rsa"}))), ParseOptions{Verify: sg.verify(), Platform: "linux", ExtraRoots: []string{"/srv/app"}})
	if len(c.Watches) != 0 {
		t.Error("an extra root never unlocks a credential file")
	}
}

func TestTooManyWatchesAndPathsAreBounded(t *testing.T) {
	sg := newSigner(t)
	var ws []map[string]any
	for i := 0; i < 80; i++ {
		ws = append(ws, fileWatch("custom.w"+strings.Repeat("a", i%30), map[string]any{"path": "/etc/hosts"}))
	}
	c, err := Parse(sg.sign(t, baseConfig("linux", "0123456789abcdef", ws...)), ParseOptions{Verify: sg.verify(), Platform: "linux"})
	if err != nil || len(c.Watches) > MaxWatches {
		t.Fatalf("%v %d", err, len(c.Watches))
	}
	var many []map[string]any
	for i := 0; i < 30; i++ {
		many = append(many, map[string]any{"path": "/etc/hosts"})
	}
	c, _ = Parse(sg.sign(t, baseConfig("linux", "0123456789abcdef", fileWatch("lnx.x", many...))), ParseOptions{Verify: sg.verify(), Platform: "linux"})
	if len(c.Watches) != 0 {
		t.Error("more than 20 paths in a watch is refused")
	}
}

func TestGlobMatchingCannotExplode(t *testing.T) {
	cases := []struct {
		p, s string
		ok   bool
	}{{"*.conf", "a.conf", true}, {"*.conf", "a.confx", false}, {"a?c", "abc", true}, {"a?c", "ac", false}, {"*", "", true}, {"a*b*c", "aXbYc", true}, {"a*b*c", "aXbY", false}, {"", "", true}, {"?", "é", true}}
	for _, c := range cases {
		if got := globMatch(c.p, c.s); got != c.ok {
			t.Errorf("globMatch(%q,%q)=%v", c.p, c.s, got)
		}
	}
	start := time.Now()
	globMatch(strings.Repeat("a*", 28)+"b", strings.Repeat("a", 250))
	if time.Since(start) > time.Second {
		t.Error("a pathological pattern took too long")
	}
}
