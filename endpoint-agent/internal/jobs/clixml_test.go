package jobs

import (
	"strings"
	"testing"
)

// The two documents below were captured from Windows PowerShell 5.1 on Windows 11 (an error stream, and a module-load progress record).
const clixmlError = "== T ==\n#< CLIXML\n\n\n<Objs Version=\"1.1.0.1\" xmlns=\"http://schemas.microsoft.com/powershell/2004/04\"><S S=\"Error\">Get-Item : Cannot find path 'C:\\x' because it does not exist._x000D__x000A_</S><S S=\"Error\">At line:1 char:322_x000D__x000A_</S><S S=\"Error\">+ ... Get-Item C:\\x_x000D__x000A_</S><S S=\"Error\"> _x000D__x000A_</S></Objs>"

const clixmlProgress = "== Pending ==\n#< CLIXML\nPending updates: 4\n | A | 0 MB\n<Objs Version=\"1.1.0.1\" xmlns=\"http://schemas.microsoft.com/powershell/2004/04\"><Obj S=\"progress\" RefId=\"0\"><TN RefId=\"0\"><T>System.Management.Automation.PSCustomObject</T><T>System.Object</T></TN><MS><I64 N=\"SourceId\">1</I64><PR N=\"Record\"><AV>Preparing modules for first use.</AV><AI>0</AI><Nil /><PI>-1</PI><PC>-1</PC><T>Completed</T><SR>-1</SR><SD> </SD></PR></MS></Obj></Objs>"

func TestCleanCLIXMLError(t *testing.T) {
	got := CleanCLIXML(clixmlError)
	if strings.Contains(got, "CLIXML") || strings.Contains(got, "<Objs") || strings.Contains(got, "_x000D_") {
		t.Fatalf("not cleaned: %q", got)
	}
	if !strings.Contains(got, "Get-Item : Cannot find path 'C:\\x' because it does not exist.\r\nAt line:1 char:322\r\n") {
		t.Fatalf("error text lost or not decoded: %q", got)
	}
	if !strings.HasPrefix(got, "== T ==\n") {
		t.Fatalf("stdout text before the marker lost: %q", got)
	}
}

func TestCleanCLIXMLProgressDropped(t *testing.T) {
	got := CleanCLIXML(clixmlProgress)
	if got != "== Pending ==\nPending updates: 4\n | A | 0 MB\n" {
		t.Fatalf("got %q", got)
	}
}

func TestCleanCLIXMLUntouched(t *testing.T) {
	for _, s := range []string{"", "plain output\nwith lines", "<xml>not powershell</xml>", "x_x000D_y"} {
		if CleanCLIXML(s) != s {
			t.Errorf("%q changed", s)
		}
	}
}

func TestCleanCLIXMLMalformedLeftAlone(t *testing.T) {
	s := "#< CLIXML\n<Objs Version=\"1.1.0.1\" xmlns=\"http://schemas.microsoft.com/powershell/2004/04\"><S S=\"Error\">oops"
	// a truncated document (the output cap cut it) has no closing tag: nothing matches, the text is kept, only the marker goes
	if got := CleanCLIXML(s); !strings.Contains(got, "oops") {
		t.Fatalf("lost text: %q", got)
	}
}
