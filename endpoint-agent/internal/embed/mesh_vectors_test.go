package embed

import (
	"bytes"
	"encoding/json"
	"os"
	"reflect"
	"strings"
	"testing"
	"time"

	"rivetit-agent/internal/mesh"
)

// agent_installer_mesh_vectors.json is the ADDITIVE vector file of the optional MeshCentral members (the frozen
// agent_installer_trailer_vectors.json is untouched). The server writes these payloads; every one must read with the current agent, and every one
// must read with the agent that existed BEFORE the members did.
type meshVectorFile struct {
	DefaultAgentIDs map[string]int      `json:"default_agent_ids"`
	DefaultArgs     map[string][]string `json:"default_install_args"`
	GroupForms      []struct {
		Name    string `json:"name"`
		Hex     string `json:"hex"`
		URLForm string `json:"url_form"`
	} `json:"group_forms"`
	Vectors []struct {
		Name    string `json:"name"`
		ExeHex  string `json:"exe_hex"`
		Payload string `json:"payload"`
		Stamped string `json:"stamped_hex"`
		Expect  struct {
			Outcome string         `json:"outcome"`
			URL     string         `json:"url"`
			PinLen  int            `json:"pin_len"`
			AgentID map[string]int `json:"agent_id"`
			// ADDED in rc.15: the group id as it goes to the Mesh server, and the exact download target per platform
			WireMeshID    string            `json:"wire_meshid"`
			DownloadQuery map[string]string `json:"download_query"`
		} `json:"expect"`
	} `json:"vectors"`
}

func loadMeshVectors(t *testing.T) meshVectorFile {
	b, err := os.ReadFile("../../testdata/vectors/agent_installer_mesh_vectors.json")
	if err != nil {
		t.Fatal(err)
	}
	var v meshVectorFile
	if err := json.Unmarshal(b, &v); err != nil || len(v.Vectors) < 8 {
		t.Fatalf("vector file: %v (%d vectors)", err, len(v.Vectors))
	}
	return v
}

// oldPayload is the Payload struct exactly as it was before the Mesh members existed (agents <= 1.0.0-rc.11 decode with this shape).
type oldPayload struct {
	Version         int     `json:"version"`
	InstallerID     string  `json:"installer_id"`
	ServerURL       string  `json:"server_url"`
	EnrollmentToken string  `json:"enrollment_token"`
	Department      string  `json:"department"`
	CAPEM           *string `json:"ca_pem"`
	CreatedAt       string  `json:"created_at"`
	ExpiresAt       string  `json:"expires_at"`
}

func TestMeshVectorsReadWithTheCurrentAgent(t *testing.T) {
	v := loadMeshVectors(t)
	now := time.Date(2026, 10, 7, 12, 0, 0, 0, time.UTC)
	for _, c := range v.Vectors {
		t.Run(c.Name, func(t *testing.T) {
			exe, stamped := unhex(t, c.ExeHex), unhex(t, c.Stamped)
			if !bytes.Equal(Build(exe, []byte(c.Payload)), stamped) {
				t.Fatal("Build differs from the server's stamped file")
			}
			pl, err := ParseBytes(stamped, now)
			if err != nil {
				t.Fatalf("a Mesh payload must never make the installer unreadable: %v", err)
			}
			spec, err := mesh.ParseSpec(pl.InstallMeshAgent, pl.Mesh)
			switch c.Expect.Outcome {
			case "install":
				if err != nil || spec.URL != c.Expect.URL || len(spec.Pin) != c.Expect.PinLen {
					t.Fatalf("%v %+v", err, spec)
				}
				for k, want := range c.Expect.AgentID {
					var goos, arch string
					for i := 0; i < len(k); i++ {
						if k[i] == '/' {
							goos, arch = k[:i], k[i+1:]
						}
					}
					if got, ok := spec.AgentID(goos, arch); !ok || got != want {
						t.Fatalf("agent id for %s: %d (%v), want %d", k, got, ok, want)
					}
				}
				if c.Expect.WireMeshID != "" && spec.WireMeshID() != c.Expect.WireMeshID {
					t.Fatalf("wire meshid %q, want %q", spec.WireMeshID(), c.Expect.WireMeshID)
				}
				for k, want := range c.Expect.DownloadQuery {
					goos, arch, _ := strings.Cut(k, "/")
					id, _ := spec.AgentID(goos, arch)
					if got := strings.TrimPrefix(spec.DownloadURL(id), spec.URL); got != want {
						t.Fatalf("download target for %s: %q, want %q", k, got, want)
					}
				}
			case "disabled":
				if err != mesh.ErrDisabled {
					t.Fatalf("want ErrDisabled, got %v", err)
				}
			case "unusable":
				if err == nil || err == mesh.ErrDisabled {
					t.Fatalf("want a validation error, got %v", err)
				}
			default:
				t.Fatalf("unknown outcome %q", c.Expect.Outcome)
			}
		})
	}
}

// The agent that predates the Mesh members decodes the payload strictly enough to validate every other field: prove that it reads every Mesh vector
// (and gets exactly the same values for the fields it knows).
func TestMeshVectorsReadWithTheAgentThatPredatesThem(t *testing.T) {
	for _, c := range loadMeshVectors(t).Vectors {
		var old oldPayload
		dec := json.NewDecoder(bytes.NewReader([]byte(c.Payload)))
		if err := dec.Decode(&old); err != nil || dec.More() {
			t.Fatalf("%s: the old decoder refuses the payload: %v", c.Name, err)
		}
		var cur Payload
		if err := json.Unmarshal([]byte(c.Payload), &cur); err != nil {
			t.Fatal(err)
		}
		got := oldPayload{cur.Version, cur.InstallerID, cur.ServerURL, cur.EnrollmentToken, cur.Department, cur.CAPEM, cur.CreatedAt, cur.ExpiresAt}
		if !reflect.DeepEqual(old, got) {
			t.Fatalf("%s: old and new readers disagree on the known fields", c.Name)
		}
		if old.Version != 1 {
			t.Fatalf("%s: the payload version must stay 1", c.Name)
		}
	}
}

func TestDefaultTablesMatchTheServersVectors(t *testing.T) {
	v := loadMeshVectors(t)
	if !reflect.DeepEqual(v.DefaultAgentIDs, mesh.DefaultAgentIDs) {
		t.Fatalf("agent ids differ: vectors %v, agent %v", v.DefaultAgentIDs, mesh.DefaultAgentIDs)
	}
	if !reflect.DeepEqual(v.DefaultArgs, mesh.DefaultInstallArgs) {
		t.Fatalf("install args differ: vectors %v, agent %v", v.DefaultArgs, mesh.DefaultInstallArgs)
	}
}

func TestGroupFormVectorsConvertBothWays(t *testing.T) {
	v := loadMeshVectors(t)
	if len(v.GroupForms) < 3 {
		t.Fatal("group_forms vectors missing")
	}
	for _, f := range v.GroupForms {
		for _, in := range []string{f.Hex, f.URLForm} {
			s := mesh.Spec{URL: "https://mesh.example.com", MeshID: in}
			if err := s.Validate(); err != nil || s.WireMeshID() != f.URLForm {
				t.Fatalf("%s: %q -> %q (%v), want %q", f.Name, in, s.WireMeshID(), err, f.URLForm)
			}
		}
		if u, ok := mesh.GroupURLForm(f.Hex); !ok || u != f.URLForm {
			t.Fatalf("%s: hex -> URL form %q", f.Name, u)
		}
	}
}

func TestFrozenVectorsStillCarryNoMeshMembers(t *testing.T) {
	for _, c := range loadVectors(t).Vectors {
		if bytes.Contains([]byte(c.Payload), []byte("mesh")) {
			t.Fatalf("%s: the frozen vectors must not change", c.Name)
		}
	}
}
