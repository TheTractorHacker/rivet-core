package patch

import (
	"reflect"
	"testing"
	"time"
)

func TestParseAptList(t *testing.T) {
	for _, c := range []struct {
		file string
		n    int
		last aptPkg
	}{
		{"apt_ubuntu2204_list.txt", 6, aptPkg{Name: "vim", Arch: "amd64", New: "2:8.2.3995-1ubuntu2.19", Old: "2:8.2.3995-1ubuntu2.17", Suites: []string{"jammy-updates"}}},
		{"apt_ubuntu2404_list.txt", 4, aptPkg{Name: "systemd", Arch: "amd64", New: "255.4-1ubuntu8.4", Old: "255.4-1ubuntu8.1", Suites: []string{"noble-updates", "noble-security"}}},
		{"apt_debian12_list.txt", 4, aptPkg{Name: "nano", Arch: "amd64", New: "7.2-1+deb12u1", Old: "7.2-1", Suites: []string{"stable"}}},
	} {
		got := parseAptList(fixture(t, c.file))
		if len(got) != c.n {
			t.Fatalf("%s: %d packages: %+v", c.file, len(got), got)
		}
		if !reflect.DeepEqual(got[len(got)-1], c.last) {
			t.Errorf("%s: last = %+v", c.file, got[len(got)-1])
		}
	}
	// noise is ignored
	if got := parseAptList("WARNING: apt does not have a stable CLI interface.\nListing... Done\nbroken line\n\n/x 1 amd64 [upgradable from: 0]\n"); len(got) != 0 {
		t.Errorf("%+v", got)
	}
}

func TestParseAptPolicy(t *testing.T) {
	p := parseAptPolicy(fixture(t, "apt_ubuntu2204_policy.txt"))
	want := map[string]bool{"libssl3": true, "linux-image-generic": true, "openssl": true, "tzdata": true, "vim": false}
	for name, sec := range want {
		got, ok := p[name]
		if !ok || got.Security != sec {
			t.Errorf("%s: %+v ok=%v want security=%v", name, got, ok, sec)
		}
	}
	// vim: an OLDER version in the security archive must not make the candidate a security update
	if p["vim"].Candidate != "2:8.2.3995-1ubuntu2.19" || p["vim"].Installed != "2:8.2.3995-1ubuntu2.17" {
		t.Errorf("vim: %+v", p["vim"])
	}
	d := parseAptPolicy(fixture(t, "apt_debian12_policy.txt"))
	if !d["bash"].Security || d["nano"].Security || !d["libc6:amd64"].Security || !d["libc6:i386"].Security {
		t.Errorf("debian: %+v", d)
	}
	if len(parseAptPolicy("")) != 0 {
		t.Error("empty input")
	}
}

func TestParseAptSimAndHolds(t *testing.T) {
	s := parseAptSim(fixture(t, "apt_sim_upgrade.txt"))
	if len(s.Remove) != 0 || !s.Inst["openssl"] || !s.Inst["libssl3"] || len(s.Inst) != 2 {
		t.Errorf("%+v", s)
	}
	r := parseAptSim(fixture(t, "apt_sim_remove.txt"))
	if !reflect.DeepEqual(r.Remove, []string{"libfoo1"}) || !r.Inst["openssl"] {
		t.Errorf("%+v", r)
	}
	h := parseHolds("held-tool\n\n bad name \n--x\nlibc6:i386\n")
	if !h["held-tool"] || !h["libc6:i386"] || len(h) != 2 {
		t.Errorf("%v", h)
	}
	v := parseDpkgVersions("openssl:amd64 3.0.2-0ubuntu1.18\nlibc6:i386 2.36-9+deb12u7\ngarbage\n")
	if v["openssl"][0] != "3.0.2-0ubuntu1.18" || v["openssl:amd64"][0] != "3.0.2-0ubuntu1.18" || v["libc6:i386"][0] != "2.36-9+deb12u7" {
		t.Errorf("%v", v)
	}
}

func TestParseCheckUpdate(t *testing.T) {
	rows := parseCheckUpdate(fixture(t, "rpm_rocky9_checkupdate.txt"))
	if len(rows) != 6 {
		t.Fatalf("rocky: %d rows %+v", len(rows), rows)
	}
	if rows[2] != (rpmUpdate{Name: "openssl", Arch: "x86_64", Version: "1:3.0.7-28.el9_4", Repo: "baseos"}) || rows[4].ID() != "tzdata.noarch" {
		t.Errorf("%+v", rows)
	}
	for _, r := range rows {
		if r.Name == "foo-new" || r.Name == "foo-old" {
			t.Errorf("the obsoleting section must be ignored: %+v", r)
		}
	}
	if got := parseCheckUpdate(fixture(t, "rpm_alma9_checkupdate.txt")); len(got) != 3 || got[0].Version != "32:9.16.23-15.el9_4.1" {
		t.Errorf("alma: %+v", got)
	}
	if got := parseCheckUpdate(fixture(t, "rpm_fedora_dnf5_checkupdate.txt")); len(got) != 3 || got[0].Repo != "updates" {
		t.Errorf("fedora: %+v", got)
	}
	y := parseCheckUpdate(fixture(t, "rpm_yum7_checkupdate.txt"))
	if len(y) != 3 || y[1].Version != "1:1.18.8-2.el7_9" || y[1].Name != "NetworkManager-config-server" {
		t.Errorf("yum wrapped row: %+v", y)
	}
}

func TestParseUpdateinfo(t *testing.T) {
	a := parseUpdateinfo(fixture(t, "rpm_rocky9_updateinfo.txt"))
	if a["kernel.x86_64"].Severity != "important" || a["kernel-core.x86_64"].ID != "RLSA-2024:4264" {
		t.Errorf("%+v", a)
	}
	// two advisories for openssl: the highest severity wins
	if a["openssl.x86_64"].Severity != "critical" || a["openssl.x86_64"].ID != "RLSA-2024:2448" {
		t.Errorf("%+v", a["openssl.x86_64"])
	}
	if a["openssl-libs.x86_64"].Severity != "moderate" {
		t.Errorf("%+v", a["openssl-libs.x86_64"])
	}
	// "Unknown/Sec." is security with no severity
	if got, ok := a["tzdata.noarch"]; !ok || got.Severity != "unspecified" {
		t.Errorf("%+v", a["tzdata.noarch"])
	}
	f := parseUpdateinfo(fixture(t, "rpm_fedora_dnf5_updateinfo.txt"))
	if f["openssl.x86_64"].Severity != "important" || len(f) != 1 {
		t.Errorf("dnf5 (bugfix rows and the header are not security advisories): %+v", f)
	}
	if y := parseUpdateinfo(fixture(t, "rpm_yum7_updateinfo.txt")); y["openssl.x86_64"].Severity != "important" || len(y) != 1 {
		t.Errorf("yum: %+v", y)
	}
	if id, ok := nevraToID("openssl-libs-1:3.0.7-28.el9_4.x86_64"); !ok || id != "openssl-libs.x86_64" {
		t.Errorf("nevra: %q %v", id, ok)
	}
	for _, bad := range []string{"", "openssl", "a-b.x86_64", "a-1-2.bogusarch"} {
		if _, ok := nevraToID(bad); ok {
			t.Errorf("%q", bad)
		}
	}
}

func TestParseRpmVersionsAndRemovals(t *testing.T) {
	v := parseRpmVersions(fixture(t, "rpm_versions.txt"))
	if len(v["kernel.x86_64"]) != 2 || v["tzdata.noarch"][0] != "2024a-1.el9" || v["openssl.x86_64"][0] != "1:3.0.7-27.el9" || len(v) != 3 {
		t.Errorf("%v", v)
	}
	if dnfWouldRemove(fixture(t, "dnf_sim_ok.txt")) {
		t.Error("no removal in the ok simulation")
	}
	if !dnfWouldRemove(fixture(t, "dnf_sim_remove.txt")) {
		t.Error("removal not detected")
	}
	if !zypperWouldRemove("The following 2 packages are going to be REMOVED:\n  a b") || zypperWouldRemove("The following package is going to be upgraded:") {
		t.Error("zypper removal detection")
	}
}

func TestParseZypperPatches(t *testing.T) {
	ps := parseZypperPatches(fixture(t, "zypper_list_patches.txt"))
	if len(ps) != 3 {
		t.Fatalf("%d patches (the not-needed row must be skipped): %+v", len(ps), ps)
	}
	if ps[0].Name != "SUSE-SLE-Module-Basesystem-15-SP5-2024-1234" || ps[0].Category != "security" || ps[0].Severity != "important" {
		t.Errorf("%+v", ps[0])
	}
	if ps[1].Interactive != "reboot" || ps[1].Severity != "critical" || ps[2].Category != "recommended" {
		t.Errorf("%+v", ps)
	}
	if got := parseZypperPatches("Loading repository data...\nNo updates found.\n"); len(got) != 0 {
		t.Errorf("%+v", got)
	}
	// a patch name with shell metacharacters is dropped even when zypper "printed" it
	if got := parseZypperPatches("Name | Category | Status\n--|--|--\nx;rm -rf | security | needed\n"); len(got) != 0 {
		t.Errorf("%+v", got)
	}
}

func TestHistoryParsers(t *testing.T) {
	utc := time.UTC
	if got := aptHistoryAt(fixture(t, "apt_history.log"), utc); !got.Equal(time.Date(2024, 5, 2, 6, 25, 12, 0, utc)) {
		t.Errorf("apt history (a Remove block must not count): %v", got)
	}
	if got := dpkgLogAt(fixture(t, "dpkg.log"), utc); !got.Equal(time.Date(2024, 5, 2, 6, 25, 12, 0, utc)) {
		t.Errorf("dpkg.log: %v", got)
	}
	if got := dnfLogAt(fixture(t, "dnf.rpm.log")); !got.Equal(time.Date(2024, 5, 2, 6, 25, 12, 0, utc)) {
		t.Errorf("dnf.rpm.log (Cleanup lines do not count): %v", got)
	}
	if got := dnfLogAt("2024-05-02T06:25:12+0200 INFO Upgraded: x\n"); !got.Equal(time.Date(2024, 5, 2, 4, 25, 12, 0, utc)) {
		t.Errorf("dnf5 offset form: %v", got)
	}
	if got := zyppHistoryAt(fixture(t, "zypp_history"), utc); !got.Equal(time.Date(2024, 5, 3, 7, 30, 9, 0, utc)) {
		t.Errorf("zypp history: %v", got)
	}
	// local time zones are honoured
	cet := time.FixedZone("CET", 3600)
	if got := aptHistoryAt(fixture(t, "apt_history.log"), cet); !got.Equal(time.Date(2024, 5, 2, 5, 25, 12, 0, utc)) {
		t.Errorf("zone: %v", got)
	}
	for _, f := range []func() time.Time{
		func() time.Time { return aptHistoryAt("", utc) }, func() time.Time { return dpkgLogAt("junk", utc) },
		func() time.Time { return dnfLogAt("junk Upgraded: x") }, func() time.Time { return zyppHistoryAt("a|b", utc) },
	} {
		if !f().IsZero() {
			t.Error("nothing found must be the zero time")
		}
	}
}
