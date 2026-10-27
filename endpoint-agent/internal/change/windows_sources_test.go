package change

import (
	"encoding/binary"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"unicode/utf16"

	"rivetit-agent/internal/remote"
)

const netEnglish = "Alias name     Administrators\r\nComment        Administrators have complete and unrestricted access to the computer/domain\r\n\r\nMembers\r\n\r\n-------------------------------------------------------------------------------\r\nAdministrator\r\nCORP\\Domain Admins\r\nblake\r\nThe command completed successfully.\r\n\r\n"
const netGerman = "Aliasname     Administratoren\r\nKommentar     Administratoren haben uneingeschraenkten Vollzugriff\r\n\r\nMitglieder\r\n\r\n-------------------------------------------------------------------------------\r\nAdministrator\r\nHans M\u00fcller\r\nDer Befehl wurde erfolgreich ausgef\u00fchrt.\r\n"
const netEmpty = "Alias name     Empty\r\n\r\nMembers\r\n\r\n-------------------------------------------------------------------------------\r\nThe command completed successfully.\r\n"

func TestNetLocalgroupIsParsedWithoutReadingAnyWording(t *testing.T) {
	for _, c := range []struct {
		out  string
		want string
	}{{netEnglish, "Administrator|CORP\\Domain Admins|blake"}, {netGerman, "Administrator|Hans M\u00fcller"}, {netEmpty, ""}} {
		m, err := ParseNetLocalgroup([]byte(c.out))
		if err != nil || strings.Join(m, "|") != c.want {
			t.Errorf("%v %q", err, m)
		}
	}
	if _, err := ParseNetLocalgroup([]byte("System error 1376 has occurred.\r\nThe specified local group does not exist.\r\n")); err == nil {
		t.Error("an error message is not a member list")
	}
}

func groupRig(t *testing.T) *rig {
	r := newRig(t, "windows")
	r.configure("d0d0d0d0d0d0d0d0", map[string]any{"id": "win.local_admins", "kind": "group", "name": "Administrators"})
	return r
}

func TestANewLocalAdministratorIsReported(t *testing.T) {
	r := groupRig(t)
	members := netEnglish
	r.run.fn = func(name string, args []string) (remote.RunResult, error) {
		if exeName(name) != "net.exe" || len(args) != 2 || args[0] != "localgroup" || args[1] != "Administrators" {
			t.Errorf("only net.exe localgroup Administrators is run: %s %v", name, args)
		}
		return remote.RunResult{Stdout: []byte(members)}, nil
	}
	r.tick()
	members = strings.Replace(netEnglish, "blake\r\n", "blake\r\nmallory\r\n", 1)
	r.next()
	recs := r.records()
	if len(recs) != 1 || recs[0].Change != "added" || recs[0].Item != "mallory" || recs[0].Target != "group:Administrators" || recs[0].Kind != "group" {
		t.Fatalf("%+v", recs)
	}
	r.reset()
	members = netEnglish
	r.next()
	if recs := r.records(); len(recs) != 1 || recs[0].Change != "removed" || recs[0].Item != "mallory" {
		t.Fatalf("%+v", recs)
	}
}

func TestALocalisedWindowsIsResolvedBySid(t *testing.T) {
	r := groupRig(t)
	r.run.fn = func(name string, args []string) (remote.RunResult, error) {
		switch {
		case exeName(name) == "net.exe" && args[1] == "Administrators":
			return remote.RunResult{ExitCode: 2, Stderr: []byte("System error 1376")}, nil
		case exeName(name) == "powershell.exe":
			if !strings.Contains(strings.Join(args, " "), "S-1-5-32-544") {
				t.Errorf("%v", args)
			}
			return remote.RunResult{Stdout: []byte("BUILTIN\\Administratoren\r\n")}, nil
		case exeName(name) == "net.exe" && args[1] == "Administratoren":
			return remote.RunResult{Stdout: []byte(netGerman)}, nil
		}
		t.Errorf("unexpected %s %v", name, args)
		return remote.RunResult{ExitCode: 1}, nil
	}
	r.tick()
	if n := len(r.m.state.Watches["win.local_admins"].Snap); n != 2 {
		t.Fatalf("members: %d", n)
	}
}

func TestAFailingGroupReadIsAnErrorNotAnEmptyGroup(t *testing.T) {
	r := groupRig(t)
	ok := true
	r.run.fn = func(string, []string) (remote.RunResult, error) {
		if ok {
			return remote.RunResult{Stdout: []byte(netEnglish)}, nil
		}
		return remote.RunResult{ExitCode: 1}, nil
	}
	r.tick()
	ok = false
	r.next()
	if len(r.records()) != 0 {
		t.Fatalf("a failed read must not look like 'everyone was removed': %+v", r.records())
	}
	if !strings.Contains(string(r.batches()[len(r.batches())-1].Status), `"partial"`) {
		t.Errorf("%s", r.batches()[len(r.batches())-1].Status)
	}
}

// ---- scheduled tasks ----

func utf16File(s string) []byte {
	u := utf16.Encode([]rune(s))
	b := []byte{0xFF, 0xFE}
	for _, c := range u {
		b = binary.LittleEndian.AppendUint16(b, c)
	}
	return b
}

const taskXMLText = `<?xml version="1.0" encoding="UTF-16"?>
<Task version="1.4" xmlns="http://schemas.microsoft.com/windows/2004/02/mit/task">
  <RegistrationInfo><URI>\Acme\Backup</URI><Author>CORP\admin</Author></RegistrationInfo>
  <Triggers><CalendarTrigger><StartBoundary>2026-01-01T02:00:00</StartBoundary></CalendarTrigger><BootTrigger/></Triggers>
  <Principals><Principal id="Author"><UserId>S-1-5-18</UserId><RunLevel>HighestAvailable</RunLevel></Principal></Principals>
  <Settings><Enabled>true</Enabled></Settings>
  <Actions Context="Author"><Exec><Command>C:\Tools\backup.exe</Command><Arguments>--full --password=hunter2</Arguments></Exec></Actions>
</Task>`

func TestScheduledTasksAreReadFromTheDefinitionFiles(t *testing.T) {
	r := newRig(t, "windows")
	root := t.TempDir()
	os.MkdirAll(filepath.Join(root, "Acme"), 0o755)
	os.MkdirAll(filepath.Join(root, "Microsoft", "Windows", "Defrag"), 0o755)
	os.WriteFile(filepath.Join(root, "Acme", "Backup"), utf16File(taskXMLText), 0o644)
	os.WriteFile(filepath.Join(root, "Microsoft", "Windows", "Defrag", "ScheduledDefrag"), utf16File(strings.ReplaceAll(taskXMLText, `C:\Tools\backup.exe`, `C:\Windows\system32\defrag.exe`)), 0o644)
	r.taskRt = root
	r.build()
	r.configure("e0e0e0e0e0e0e0e0", map[string]any{"id": "win.tasks", "kind": "task"})
	r.tick()
	snap := r.m.state.Watches["win.tasks"].Snap
	var backup Item
	for _, it := range snap {
		if it.Name == `\Acme\Backup` {
			backup = it
		}
	}
	if backup.Target != "scheduled-tasks" || !strings.Contains(backup.Value, `run: C:\Tools\backup.exe --full`) || !strings.Contains(backup.Value, "as: S-1-5-18 HighestAvailable") || !strings.Contains(backup.Value, "enabled: true") || !strings.Contains(backup.Value, "triggers: CalendarTrigger,BootTrigger") {
		t.Fatalf("%+v", backup)
	}
	if strings.Contains(backup.Value+r.stateText(), "hunter2") {
		t.Fatal("a secret in a task's arguments was kept")
	}
	// a malicious task appears, another is disabled, one is deleted
	os.WriteFile(filepath.Join(root, "Evil"), utf16File(strings.ReplaceAll(taskXMLText, `C:\Tools\backup.exe`, `powershell.exe -enc AAAA`)), 0o644)
	os.WriteFile(filepath.Join(root, "Acme", "Backup"), utf16File(strings.ReplaceAll(taskXMLText, "<Enabled>true", "<Enabled>false")), 0o644)
	os.Remove(filepath.Join(root, "Microsoft", "Windows", "Defrag", "ScheduledDefrag"))
	r.next()
	recs := r.records()
	if len(recs) != 3 {
		t.Fatalf("%+v", recs)
	}
	for _, x := range recs {
		if x.Kind != "task" || x.Target != "scheduled-tasks" {
			t.Errorf("%+v", x)
		}
	}
	if e := find(recs, `\Evil`); e == nil || e.Change != "added" || !strings.Contains(e.New, "powershell.exe -enc AAAA") {
		t.Errorf("%+v", e)
	}
	if e := find(recs, `\Acme\Backup`); e == nil || e.Change != "modified" || !strings.Contains(e.New, "enabled: false") {
		t.Errorf("%+v", e)
	}
	if e := find(recs, `\Microsoft\Windows\Defrag\ScheduledDefrag`); e == nil || e.Change != "removed" {
		t.Errorf("%+v", e)
	}
}

func TestATaskFileThatIsNotXMLIsStillWatchedByItsHash(t *testing.T) {
	r := newRig(t, "windows")
	root := t.TempDir()
	os.WriteFile(filepath.Join(root, "Odd"), []byte("not xml at all"), 0o644)
	r.taskRt = root
	r.build()
	r.configure("e1e1e1e1e1e1e1e1", map[string]any{"id": "win.tasks", "kind": "task"})
	r.tick()
	os.WriteFile(filepath.Join(root, "Odd"), []byte("still not xml"), 0o644)
	r.next()
	if recs := r.records(); len(recs) != 1 || recs[0].Change != "modified" || !strings.Contains(recs[0].New, "definition (") {
		t.Fatalf("%+v", recs)
	}
}

// ---- the Windows actor lookup ----

const auditXML = `<Event><System><Provider Name="Microsoft-Windows-Security-Auditing"/><EventID>4657</EventID><Level>0</Level><TimeCreated SystemTime="2026-10-18T12:00:30.000000000Z"/><EventRecordID>9001</EventRecordID></System>
<EventData><Data Name="SubjectUserName">bob</Data><Data Name="SubjectDomainName">CORP</Data><Data Name="ObjectName">\REGISTRY\MACHINE\SOFTWARE\Microsoft\Windows\CurrentVersion\Run</Data><Data Name="ObjectValueName">Evil</Data><Data Name="ProcessName">C:\Windows\regedit.exe</Data></EventData></Event>
<Event><System><Provider Name="Microsoft-Windows-Security-Auditing"/><EventID>4657</EventID><Level>0</Level><TimeCreated SystemTime="2026-10-18T12:00:31.000000000Z"/><EventRecordID>9002</EventRecordID></System>
<EventData><Data Name="SubjectUserName">alice</Data><Data Name="SubjectDomainName">CORP</Data><Data Name="ObjectName">\REGISTRY\MACHINE\SOFTWARE\Other</Data><Data Name="ObjectValueName">Evil</Data><Data Name="ProcessName">C:\x.exe</Data></EventData></Event>`

func TestTheActorComesFromAuditEventsWhenWindowsRecordedThem(t *testing.T) {
	r := runRig(t)
	calls := 0
	r.run.fn = func(name string, args []string) (remote.RunResult, error) {
		calls++
		if exeName(name) != "wevtutil.exe" || args[0] != "qe" || args[1] != "Security" || !strings.Contains(strings.Join(args, " "), "EventID=4657") {
			t.Errorf("%s %v", name, args)
		}
		return remote.RunResult{Stdout: []byte(auditXML)}, nil
	}
	r.tick()
	if calls != 0 {
		t.Fatal("nothing changed, nothing is asked")
	}
	r.reg.set(runKey, nil, szVal("OneDrive", `C:\Program Files\OneDrive\OneDrive.exe /background`), szVal("Teams", `C:\Teams\update.exe --processStart`), szVal("Evil", `C:\x\evil.exe`), szVal("Quiet", `C:\x\quiet.exe`))
	r.next()
	if calls != 1 {
		t.Fatalf("one query for the whole poll, not one per record: %d", calls)
	}
	recs := r.records()
	if e := find(recs, "Evil"); e == nil || e.Actor != `CORP\bob` || e.Process != `C:\Windows\regedit.exe` {
		t.Errorf("%+v", e)
	}
	if q := find(recs, "Quiet"); q == nil || q.Actor != "" || q.Process != "" {
		t.Errorf("no audit event: blank, not guessed: %+v", q)
	}
}

func TestAFailingAuditLookupSwitchesItOffForAnHour(t *testing.T) {
	r := runRig(t)
	calls := 0
	r.run.fn = func(string, []string) (remote.RunResult, error) {
		calls++
		return remote.RunResult{ExitCode: 5, Stderr: []byte("Access is denied")}, nil
	}
	r.tick()
	r.reg.set(runKey, nil, szVal("A", "a.exe"))
	r.next()
	r.reg.set(runKey, nil, szVal("B", "b.exe"))
	r.next()
	if calls != 1 || len(r.records()) != 5 {
		t.Fatalf("calls %d, records %d", calls, len(r.records()))
	}
	r.clk.Advance(61 * 60 * 1e9)
	r.reg.set(runKey, nil, szVal("C", "c.exe"))
	r.tick()
	if calls != 2 {
		t.Errorf("tried again after the cooldown: %d", calls)
	}
}

// exeName is the program name of a path the agent started (the agent now starts system programs by absolute path).
func exeName(path string) string {
	if i := strings.LastIndexAny(path, `\/`); i >= 0 {
		return path[i+1:]
	}
	return path
}

// Security review F14: the agent, running as SYSTEM, starts the Windows helper programs by absolute path below %SystemRoot%\System32, never by a bare name that
// PATH would resolve.
func TestSystemProgramsAreStartedByAbsolutePath(t *testing.T) {
	t.Setenv("SystemRoot", `D:\Win`)
	for rel, want := range map[string]string{"net.exe": `D:\Win\System32\net.exe`, "wevtutil.exe": `D:\Win\System32\wevtutil.exe`} {
		if got := systemExe(rel); got != want {
			t.Errorf("%s: %q", rel, got)
		}
	}
	t.Setenv("SystemRoot", "")
	t.Setenv("windir", "")
	if got := systemExe("net.exe"); got != `C:\Windows\System32\net.exe` {
		t.Errorf("fallback: %q", got)
	}
	if got := systemExe(`WindowsPowerShell\v1.0\powershell.exe`); !strings.HasSuffix(got, `\System32\WindowsPowerShell\v1.0\powershell.exe`) {
		t.Errorf("powershell: %q", got)
	}
}
