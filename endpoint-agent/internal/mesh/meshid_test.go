package mesh

import (
	"context"
	"encoding/json"
	"strings"
	"testing"
)

// A fresh random 48-byte device group id (NOT any real MeshCentral's) in hex and in MeshCentral's URL form. The URL form carries both '@' and '$',
// the two characters a plain base64 string does not have. The same pair is in testdata/vectors/agent_installer_mesh_vectors.json.
const (
	vecHex = "e10df2d66e829b53c567652d4d91add5c62f457fe0d70c32f9d2012df54eb7aad9e0cab782a7c99b0992317e235d849e"
	vecURL = "4Q3y1m6Cm1PFZ2UtTZGt1cYvRX$g1wwy@dIBLfVOt6rZ4Mq3gqfJmwmSMX4jXYSe"
)

func specFor(t *testing.T, serverURL, meshid, pin string) *Spec {
	t.Helper()
	b, _ := json.Marshal(map[string]any{"url": serverURL, "meshid": meshid, "pin": pin})
	s, err := ParseSpec(json.RawMessage(`true`), b)
	if err != nil {
		t.Fatalf("%q: %v", meshid, err)
	}
	return s
}

func TestGroupFormsRoundTrip(t *testing.T) {
	if len(vecURL) != 64 || strings.Count(vecURL, "@") == 0 || strings.Count(vecURL, "$") == 0 {
		t.Fatal("the vector must exercise both @ and $")
	}
	if u, ok := GroupURLForm(vecHex); !ok || u != vecURL {
		t.Fatalf("hex -> URL form: %q %v", u, ok)
	}
	if h, ok := groupURLToHex(vecURL); !ok || h != vecHex {
		t.Fatalf("URL form -> hex: %q %v", h, ok)
	}
	if u, ok := GroupURLForm(strings.ToUpper(vecHex)); !ok || u != vecURL {
		t.Fatal("upper-case hex")
	}
	for _, bad := range []string{"", vecHex[:94], vecHex + "00", "zz" + vecHex[2:]} {
		if _, ok := GroupURLForm(bad); ok {
			t.Fatalf("%q has no URL form", bad)
		}
	}
	for _, bad := range []string{"", vecURL[:63], vecURL + "A", strings.Replace(vecURL, "$", "+", 1), strings.Replace(vecURL, "@", " ", 1), strings.Replace(vecURL, "@", "&", 1), strings.Replace(vecURL, "@", "'", 1)} {
		if _, ok := groupURLToHex(bad); ok {
			t.Fatalf("%q must not decode", bad)
		}
	}
}

func TestSpecAcceptsBothFormsAndSendsTheURLForm(t *testing.T) {
	want := "https://mesh.example.com/meshagents?id=4&meshid=" + vecURL + "&installflags=0"
	for name, in := range map[string]string{"url form": vecURL, "hex": vecHex, "upper hex": strings.ToUpper(vecHex), "mesh// form": "mesh//" + vecURL} {
		s := specFor(t, "https://mesh.example.com", in, "")
		if got := s.DownloadURL(4); got != want {
			t.Fatalf("%s: %s", name, got)
		}
	}
	// the URL form is never lower-cased or otherwise rewritten
	if s := specFor(t, "https://mesh.example.com", vecURL, ""); s.MeshID != vecURL {
		t.Fatalf("MeshID rewritten: %q", s.MeshID)
	}
	// a hex id of another length has no URL form: it goes out as it is
	s := specFor(t, "https://mesh.example.com", strings.Repeat("ab", 20), "")
	if got := s.DownloadURL(4); got != "https://mesh.example.com/meshagents?id=4&meshid="+strings.Repeat("ab", 20)+"&installflags=0" {
		t.Fatal(got)
	}
	for _, bad := range []string{"", vecURL[:63], vecURL + "x", "a b" + vecURL[3:], strings.Replace(vecURL, "@", "&", 1), strings.Replace(vecURL, "$", "#", 1), strings.Replace(vecURL, "@", "\"", 1), "mesh//" + vecURL[:60], "zz" + vecHex[2:]} {
		b, _ := json.Marshal(map[string]any{"url": "https://mesh.example.com", "meshid": bad})
		if _, err := ParseSpec(json.RawMessage(`true`), b); err == nil {
			t.Fatalf("%q must be refused", bad)
		}
	}
}

// The exact request the Mesh server sees equals the address MeshCentral printed, shape for shape: /meshagents?id=4&meshid=<64 chars>&installflags=0,
// '@' and '$' literal (not percent-encoded).
func TestMockMeshReceivesTheExactAddressShape(t *testing.T) {
	exe := append([]byte("MZ"), make([]byte, 64)...)
	for name, in := range map[string]string{"URL form in the payload": vecURL, "hex in the payload": vecHex} {
		m := newMock(t, 200, "application/octet-stream", exe)
		o := opts(t)
		o.GOOS, o.GOARCH = "windows", "amd64"
		o.Run = func(context.Context, string, string, []string) (int, string, error) { return 0, "ok", nil }
		res := Install(context.Background(), specFor(t, m.URL, in, pinOf(m, true)), o)
		if !res.Installed || res.AgentID != 4 {
			t.Fatalf("%s: %+v", name, res)
		}
		if got, want := m.lastURI(), "/meshagents?id=4&meshid="+vecURL+"&installflags=0"; got != want {
			t.Fatalf("%s: the server received %q, want %q", name, got, want)
		}
		if strings.Contains(res.Error+res.Log, vecURL) || strings.Contains(res.Error+res.Log, vecHex) {
			t.Fatalf("%s: the group id leaked into the result", name)
		}
	}
}

func TestRedactRemovesEveryFormOfTheGroupID(t *testing.T) {
	s := specFor(t, "https://mesh.example.com", vecURL, "")
	got := s.redact("GET /meshagents?id=4&meshid=" + vecURL + " and " + vecHex)
	if strings.Contains(got, vecURL) || strings.Contains(got, vecHex) || strings.Count(got, "[meshid]") != 2 {
		t.Fatal(got)
	}
}

// The node id is reported in whatever form the agent finds it (96 hex characters or MeshCentral's 64-character URL form, with or without the node//
// prefix); the SERVER turns every spelling into the canonical node//<URL form>. These two vectors are the same fresh random 48 bytes as vecHex/vecURL.
func TestNodeIDIsReportedInBothFormsAndKeptAsFound(t *testing.T) {
	for in, want := range map[string]string{
		"node//" + vecURL: "node//" + vecURL,
		vecURL:            "node//" + vecURL,
		"node//" + vecHex: "node//" + vecHex,
		vecHex:            "node//" + vecHex,
	} {
		if id, ok := NormalizeNodeID(in); !ok || id != want {
			t.Errorf("NormalizeNodeID(%q) = %q %v, want %q", in, id, ok, want)
		}
		if id, ok := ParseNodeIDOutput("MeshCentral Agent\nNodeID: " + in + "\n"); !ok || id != want {
			t.Errorf("ParseNodeIDOutput(%q) = %q %v, want %q", in, id, ok, want)
		}
		if m := ParseMSH("MeshServer=wss://mesh.example.com/agent.ashx\nNodeID=" + in + "\n"); m.NodeID != want {
			t.Errorf("ParseMSH(%q) = %q, want %q", in, m.NodeID, want)
		}
	}
}
