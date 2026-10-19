package change

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// fileRig watches a temp folder through the local extra-roots setting (the real default roots are /etc and friends).
func fileRig(t *testing.T) (*rig, string) {
	r := newRig(t, "linux")
	dir, _ := filepath.EvalSymlinks(t.TempDir())
	root := filepath.Join(dir, "etc")
	if err := os.MkdirAll(root, 0o755); err != nil {
		t.Fatal(err)
	}
	r.roots = []string{root}
	return r, root
}

func write(t *testing.T, path, text string, mode os.FileMode) {
	t.Helper()
	if err := os.WriteFile(path, []byte(text), mode); err != nil {
		t.Fatal(err)
	}
}

func TestAChangedFileIsReportedWithTheLinesThatDiffer(t *testing.T) {
	r, root := fileRig(t)
	cfgFile := filepath.Join(root, "sshd_config")
	write(t, cfgFile, "Port 22\nPermitRootLogin no\nPasswordAuthentication no\n", 0o644)
	r.configure("0f0f0f0f0f0f0f0f", fileWatch("lnx.ssh", map[string]any{"path": cfgFile}))
	r.tick()
	write(t, cfgFile, "Port 22\nPermitRootLogin yes\nPasswordAuthentication no\nAllowUsers bob\n", 0o644)
	r.next()
	recs := r.records()
	if len(recs) != 1 {
		t.Fatalf("%+v", recs)
	}
	x := recs[0]
	if x.Kind != "file" || x.Change != "modified" || x.Target != cfgFile || x.Item != "" || x.Summary != "+2 -1 lines" || x.Old != "PermitRootLogin no" || x.New != "PermitRootLogin yes | AllowUsers bob" {
		t.Errorf("%+v", x)
	}
	if len(x.OldHash) != 64 || x.OldHash == x.NewHash {
		t.Errorf("hashes: %+v", x)
	}
	// an unchanged file is silent, however often it is read
	r.reset()
	r.next()
	r.next()
	if len(r.records()) != 0 {
		t.Errorf("%+v", r.records())
	}
}

func TestPermissionChangesAreChangesToo(t *testing.T) {
	r, root := fileRig(t)
	f := filepath.Join(root, "sudoers")
	write(t, f, "root ALL=(ALL) ALL\n", 0o440)
	os.Chmod(f, 0o440)
	r.configure("1f1f1f1f1f1f1f1f", fileWatch("lnx.sudo", map[string]any{"path": f}))
	r.tick()
	os.Chmod(f, 0o666)
	r.next()
	recs := r.records()
	if len(recs) != 1 || !strings.Contains(recs[0].Summary, "permissions or owner changed") || !strings.Contains(recs[0].Summary, "0440") || !strings.Contains(recs[0].Summary, "0666") || recs[0].OldHash != recs[0].NewHash {
		t.Fatalf("%+v", recs)
	}
}

func TestAFolderIsWatchedForAddedChangedAndRemovedFiles(t *testing.T) {
	r, root := fileRig(t)
	d := filepath.Join(root, "sudoers.d")
	os.MkdirAll(d, 0o755)
	write(t, filepath.Join(d, "10-ops"), "ops ALL=(ALL) NOPASSWD: ALL\n", 0o644)
	write(t, filepath.Join(d, "README"), "docs\n", 0o444)
	write(t, filepath.Join(d, "20-skipped.bak"), "x\n", 0o444)
	r.configure("2f2f2f2f2f2f2f2e", fileWatch("lnx.sudo", map[string]any{"path": d, "depth": 1}))
	r.tick()
	write(t, filepath.Join(d, "30-new"), "evil ALL=(ALL) NOPASSWD: ALL\nsecond line\n", 0o440)
	os.Remove(filepath.Join(d, "README"))
	write(t, filepath.Join(d, "10-ops"), "ops ALL=(ALL) ALL\n", 0o644)
	r.next()
	recs := r.records()
	if len(recs) != 3 {
		t.Fatalf("%+v", recs)
	}
	byTarget := map[string]Record{}
	for _, x := range recs {
		byTarget[filepath.Base(x.Target)] = x
	}
	if a := byTarget["30-new"]; a.Change != "added" || a.Summary != "file added (2 lines)" || a.New != "evil ALL=(ALL) NOPASSWD: ALL | second line" {
		t.Errorf("added: %+v", a)
	}
	if a := byTarget["README"]; a.Change != "removed" || a.Summary != "file removed" || a.Old != "docs" {
		t.Errorf("removed: %+v", a)
	}
	if a := byTarget["10-ops"]; a.Change != "modified" || a.Old != "ops ALL=(ALL) NOPASSWD: ALL" || a.New != "ops ALL=(ALL) ALL" {
		t.Errorf("modified: %+v", a)
	}
}

func TestFileNamesFilterAndCredentialFilesInsideAWatchedFolderAreSkipped(t *testing.T) {
	r, root := fileRig(t)
	d := filepath.Join(root, "conf.d")
	os.MkdirAll(d, 0o755)
	write(t, filepath.Join(d, "a.conf"), "a\n", 0o644)
	write(t, filepath.Join(d, "b.txt"), "b\n", 0o644)
	write(t, filepath.Join(d, "id_rsa"), "-----BEGIN OPENSSH PRIVATE KEY-----\nAAAA\n", 0o600)
	write(t, filepath.Join(d, "site.key"), "key\n", 0o600)
	write(t, filepath.Join(d, "db.conf"), "x\n", 0o644)
	r.configure("3f3f3f3f3f3f3f3f", fileWatch("custom.web", map[string]any{"path": d, "depth": 1, "match": "*.conf"}))
	r.tick()
	var names []string
	for _, it := range r.m.state.Watches["custom.web"].Snap {
		names = append(names, filepath.Base(it.Target))
	}
	if len(names) != 2 {
		t.Errorf("only *.conf: %v", names)
	}
	r.configure("3f3f3f3f3f3f3f30", fileWatch("custom.web", map[string]any{"path": d, "depth": 1}))
	r.tick()
	names = nil
	for _, it := range r.m.state.Watches["custom.web"].Snap {
		names = append(names, filepath.Base(it.Target))
	}
	for _, n := range names {
		if n == "id_rsa" || n == "site.key" {
			t.Errorf("a credential file was read: %s", n)
		}
	}
	if strings.Contains(r.stateText(), "PRIVATE KEY") {
		t.Error("the content of a credential file reached the state file")
	}
}

func TestSecretsInFileLinesAreRedactedBeforeTheyAreKept(t *testing.T) {
	r, root := fileRig(t)
	f := filepath.Join(root, "app.conf")
	write(t, f, "host = db1\npassword = hunter2\n", 0o644)
	r.configure("4f4f4f4f4f4f4f4f", fileWatch("lnx.app", map[string]any{"path": f}))
	r.tick()
	if strings.Contains(r.stateText(), "hunter2") {
		t.Fatal("the snapshot kept a secret")
	}
	write(t, f, "host = db2\npassword = hunter3\ntoken: abc123def456ghi\n", 0o644)
	r.next()
	x := r.records()[0]
	if strings.Contains(x.Old+x.New, "hunter") || !x.Redacted {
		t.Errorf("%+v", x)
	}
	// the change of ONLY the secret still shows (the hash covers the whole file) but says nothing about it
	r.reset()
	write(t, f, "host = db2\npassword = hunter4\ntoken: abc123def456ghi\n", 0o644)
	r.next()
	recs := r.records()
	if len(recs) != 1 || strings.Contains(recs[0].Old+recs[0].New, "hunter") || !strings.Contains(recs[0].Summary, "whitespace, ordering or line endings only") {
		t.Errorf("%+v", recs)
	}
}

func TestPasswdStyleFilesNeverKeepAPasswordField(t *testing.T) {
	r, root := fileRig(t)
	f := filepath.Join(root, "passwd")
	write(t, f, "root:x:0:0:root:/root:/bin/bash\nbob:$6$salt$hashhashhashhash:1000:1000::/home/bob:/bin/sh\n", 0o644)
	r.configure("5f5f5f5f5f5f5f5f", fileWatch("lnx.accounts", map[string]any{"path": f}))
	r.tick()
	if strings.Contains(r.stateText(), "$6$") || strings.Contains(r.stateText(), "hashhash") {
		t.Fatal("a password hash in the second field was kept")
	}
	write(t, f, "root:x:0:0:root:/root:/bin/bash\nbob:$6$salt$hashhashhashhash:1000:1000::/home/bob:/bin/sh\nmallory:x:0:0::/root:/bin/bash\n", 0o644)
	r.next()
	x := r.records()[0]
	if x.Summary != "+1 -0 lines" || x.New != "mallory:x:0:0::/root:/bin/bash" {
		t.Errorf("%+v", x)
	}
}

func TestBinaryAndBigFilesKeepNoLines(t *testing.T) {
	r, root := fileRig(t)
	bin := filepath.Join(root, "blob.bin")
	big := filepath.Join(root, "big.conf")
	write(t, bin, "abc\x00def\n", 0o644)
	write(t, big, strings.Repeat("line of text\n", 10000), 0o644) // 130 KB: over the diff limit, under the hash limit
	huge := filepath.Join(root, "huge.log")
	write(t, huge, strings.Repeat("x", MaxFileBytes+500), 0o644)
	r.configure("6f6f6f6f6f6f6f6f", fileWatch("custom.f", map[string]any{"path": bin}, map[string]any{"path": big}, map[string]any{"path": huge}))
	r.tick()
	for _, it := range r.m.state.Watches["custom.f"].Snap {
		if it.Lines != nil {
			t.Errorf("%s kept lines", it.Target)
		}
	}
	write(t, bin, "abc\x00xyz\n", 0o644)
	write(t, big, strings.Repeat("line of text\n", 10001), 0o644)
	write(t, huge, strings.Repeat("x", MaxFileBytes+500)+"y", 0o644) // changes only beyond the hashed part: the size is part of the hash
	r.next()
	recs := r.records()
	if len(recs) != 3 {
		t.Fatalf("%+v", recs)
	}
	for _, x := range recs {
		if !strings.Contains(x.Summary, "content changed") || !strings.Contains(x.Summary, "no line detail") {
			t.Errorf("%+v", x)
		}
	}
}

func TestSymlinksAreRecordedAsLinksAndNeverFollowed(t *testing.T) {
	r, root := fileRig(t)
	d := filepath.Join(root, "units")
	os.MkdirAll(d, 0o755)
	secret := filepath.Join(t.TempDir(), "secret.txt")
	write(t, secret, "TOP SECRET CONTENT\n", 0o600)
	os.Symlink("/etc/hosts", filepath.Join(d, "good.service"))
	r.configure("7f7f7f7f7f7f7f7f", fileWatch("lnx.systemd", map[string]any{"path": d, "depth": 2}))
	r.tick()
	os.Remove(filepath.Join(d, "good.service"))
	os.Symlink(secret, filepath.Join(d, "good.service"))
	r.next()
	recs := r.records()
	if len(recs) != 1 || recs[0].Change != "modified" || recs[0].New != "-> "+secret {
		t.Fatalf("%+v", recs)
	}
	if strings.Contains(r.stateText(), "TOP SECRET") {
		t.Error("a symlink target's content was read")
	}
	// a watched path that is itself a link to a credential file is still just a link
	os.Symlink("/etc/shadow", filepath.Join(d, "shadow.service"))
	r.next()
	for _, x := range r.records() {
		if strings.Contains(x.New+x.Old, "root:") {
			t.Errorf("%+v", x)
		}
	}
}

func TestAnUnreadableFileIsNotARemovedFile(t *testing.T) {
	if os.Geteuid() == 0 {
		t.Skip("root reads everything")
	}
	r, root := fileRig(t)
	f := filepath.Join(root, "locked.conf")
	write(t, f, "a=1\n", 0o644)
	r.configure("8f8f8f8f8f8f8f8f", fileWatch("custom.l", map[string]any{"path": root, "depth": 1}))
	r.tick()
	os.Chmod(f, 0)
	r.next()
	if len(r.records()) != 0 {
		t.Fatalf("%+v", r.records())
	}
	os.Chmod(f, 0o644)
	r.next()
	if len(r.records()) != 0 {
		t.Fatalf("%+v", r.records())
	}
	os.Remove(f)
	r.next()
	if recs := r.records(); len(recs) != 1 || recs[0].Change != "removed" {
		t.Fatalf("%+v", recs)
	}
}

func TestAMissingPathIsSimplyEmptyAndAppearsWhenCreated(t *testing.T) {
	r, root := fileRig(t)
	f := filepath.Join(root, "later.conf")
	r.configure("9f9f9f9f9f9f9f9f", fileWatch("custom.m", map[string]any{"path": f}))
	r.tick()
	write(t, f, "x\n", 0o644)
	r.next()
	if recs := r.records(); len(recs) != 1 || recs[0].Change != "added" {
		t.Fatalf("%+v", recs)
	}
}

func TestAPathOutsideThePolicyIsNeverOpened(t *testing.T) {
	r, root := fileRig(t)
	outside := filepath.Join(filepath.Dir(root), "other", "x.conf")
	os.MkdirAll(filepath.Dir(outside), 0o755)
	write(t, outside, "x\n", 0o644)
	r.configure("afafafafafafafaf", fileWatch("custom.o", map[string]any{"path": outside}, map[string]any{"path": filepath.Join(root, "ok.conf")}))
	if len(r.m.want.Watches[0].Paths) != 1 {
		t.Fatalf("the outside path must not survive the policy: %+v", r.m.want.Watches[0].Paths)
	}
}

func TestAFolderOfThousandsOfFilesIsReadOnlyToItsCap(t *testing.T) {
	r, root := fileRig(t)
	d := filepath.Join(root, "many")
	os.MkdirAll(d, 0o755)
	for i := 0; i < 700; i++ {
		write(t, filepath.Join(d, fmt.Sprintf("f%04d", i)), "x\n", 0o644)
	}
	r.configure("bfbfbfbfbfbfbfbf", fileWatch("custom.many", map[string]any{"path": d, "depth": 1}))
	r.tick()
	if n := len(r.m.state.Watches["custom.many"].Snap); n != maxDirEntries {
		t.Fatalf("files kept: %d", n)
	}
	if !strings.Contains(string(r.batches()[0].Status), "more than 500 entries") {
		t.Errorf("the status says the folder is too big: %s", r.batches()[0].Status)
	}
}

func TestSudoersTagsAreNotSecrets(t *testing.T) {
	r, root := fileRig(t)
	d := filepath.Join(root, "sudoers.d")
	os.MkdirAll(d, 0o755)
	r.configure("c1c1c1c1c1c1c1c1", fileWatch("lnx.sudo", map[string]any{"path": d, "depth": 1}))
	r.tick()
	write(t, filepath.Join(d, "90-x"), "mallory ALL=(ALL) NOPASSWD: ALL\nbob ALL=(ALL) PASSWD: /bin/ls\nDefaults passwd_tries=3 password=hunter2\n", 0o644)
	r.next()
	x := r.records()[0]
	if !strings.Contains(x.New, "mallory ALL=(ALL) NOPASSWD: ALL") || !strings.Contains(x.New, "bob ALL=(ALL) PASSWD: /bin/ls") {
		t.Errorf("the one thing a sudoers change is about must stay readable: %+v", x)
	}
	if strings.Contains(x.New, "hunter2") || !x.Redacted {
		t.Errorf("a real secret in the same file is still masked: %+v", x)
	}
	// the exception is for sudoers files only
	other := filepath.Join(root, "app.conf")
	write(t, other, "x\n", 0o644)
	r.configure("c1c1c1c1c1c1c1c2", fileWatch("lnx.sudo", map[string]any{"path": d, "depth": 1}), fileWatch("lnx.app", map[string]any{"path": other}))
	r.tick()
	r.reset()
	write(t, other, "NOPASSWD: ALL\n", 0o644)
	r.next()
	if recs := r.records(); len(recs) != 1 || strings.Contains(recs[0].New, "NOPASSWD: ALL") || !strings.Contains(strings.ToUpper(recs[0].New), "[REDACTED]") {
		t.Errorf("%+v", recs)
	}
}

func TestAPEMBlockAndBase64LinesAreMaskedWholeAndCustomWatchesAreHashOnly(t *testing.T) {
	r, root := fileRig(t)
	f := filepath.Join(root, "app.conf")
	pem := "-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC7\nSECRETKEYBODYLINE2\n-----END PRIVATE KEY-----\nQUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVowMTIzNDU2Nzg5YWJj\nafter\n"
	write(t, f, "a\n", 0o644)
	r.configure("d1d1d1d1d1d1d1d1", fileWatch("lnx.app", map[string]any{"path": f}))
	r.tick()
	write(t, f, pem, 0o644)
	r.next()
	all := r.stateText()
	for _, b := range r.bodies {
		all += string(b)
	}
	for _, leak := range []string{"MIIEvQ", "SECRETKEYBODY", "QUJDREVG"} {
		if strings.Contains(all, leak) {
			t.Errorf("%s leaked", leak)
		}
	}
	// a custom watch keeps no lines at all
	r2, root2 := fileRig(t)
	g := filepath.Join(root2, "c.conf")
	write(t, g, "x=1\n", 0o644)
	r2.configure("d1d1d1d1d1d1d1d2", fileWatch("custom.c", map[string]any{"path": g}))
	r2.tick()
	write(t, g, "x=2\n", 0o644)
	r2.next()
	rec := r2.records()
	if len(rec) != 1 || rec[0].Old != "2 bytes" && !strings.Contains(rec[0].Summary, "no line detail") || strings.Contains(r2.stateText(), "x=1") {
		t.Errorf("%+v", rec)
	}
}

func TestCommandLineSecretsAreMasked(t *testing.T) {
	for in, leak := range map[string]string{
		"curl -u bob:s3cr3t http://x":                   "s3cr3t",
		"mysql -h db -u root -pS3CRET1 -e 'x'":          "S3CRET1",
		"net use Z: \\\\srv\\share /user:bob hunter9":   "hunter9",
		"schtasks /create /RU bob /RP topsecret9 /TN x": "topsecret9",
		"app /p:pw1234 /q":                              "pw1234",
		"git clone https://bob:tok3n123@host/r.git":     "tok3n123",
	} {
		out, red, _ := Display(in, 300, nil)
		if strings.Contains(out, leak) || !red {
			t.Errorf("%q -> %q", in, out)
		}
	}
}

func TestABigFileIsHashedWholeByStreamingAndAChangeBeyondTheFirstMiBIsSeen(t *testing.T) {
	r, root := fileRig(t)
	f := filepath.Join(root, "big.log")
	base := strings.Repeat("x", MaxFileBytes+500)
	write(t, f, base, 0o644)
	r.configure("e1e1e1e1e1e1e1e1", fileWatch("lnx.big", map[string]any{"path": f}))
	r.tick()
	write(t, f, base[:MaxFileBytes+100]+"Y"+base[MaxFileBytes+101:], 0o644) // same size, one byte changed beyond the first MiB
	r.next()
	recs := r.records()
	if len(recs) != 1 || recs[0].OldHash == recs[0].NewHash || recs[0].Truncated {
		t.Fatalf("%+v", recs)
	}
	// over the byte budget the first MiB plus the size stand in, flagged truncated
	r2, root2 := fileRig(t)
	g := filepath.Join(root2, "huge.log")
	write(t, g, base, 0o644)
	r2.configure("e1e1e1e1e1e1e1e2", fileWatch("lnx.big", map[string]any{"path": g}))
	r2.m.engine.budgetOverride = 10
	r2.tick()
	for _, it := range r2.m.state.Watches["lnx.big"].Snap {
		if it.Flags&FlagTruncated == 0 {
			t.Errorf("not flagged: %+v", it)
		}
	}
}
