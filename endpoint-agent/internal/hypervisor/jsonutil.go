package hypervisor

import (
	"bytes"
	"encoding/json"
)

// decodeArray reads a JSON array of objects. A single object is accepted as a
// one-element list (PowerShell's ConvertTo-Json unwraps single-element arrays);
// null and empty input are an empty list. Elements that are not objects are skipped.
func decodeArray(b []byte) ([]map[string]any, bool) {
	b = bytes.TrimSpace(bytes.TrimPrefix(bytes.TrimSpace(b), []byte("\xef\xbb\xbf")))
	if len(b) == 0 || string(b) == "null" {
		return nil, true
	}
	if b[0] == '{' {
		var m map[string]any
		if json.Unmarshal(b, &m) != nil {
			return nil, false
		}
		return []map[string]any{m}, true
	}
	var raw []any
	if json.Unmarshal(b, &raw) != nil {
		return nil, false
	}
	out := make([]map[string]any, 0, len(raw))
	for _, x := range raw {
		if m, ok := x.(map[string]any); ok {
			out = append(out, m)
		}
	}
	return out, true
}

func decodeObject(b []byte) (map[string]any, bool) {
	b = bytes.TrimSpace(bytes.TrimPrefix(bytes.TrimSpace(b), []byte("\xef\xbb\xbf")))
	var m map[string]any
	if len(b) == 0 || json.Unmarshal(b, &m) != nil {
		return nil, false
	}
	return m, true
}
