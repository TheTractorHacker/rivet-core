// Package strictjson decodes a JSON object into a struct with exact,
// case-sensitive member names.
//
// encoding/json matches object members to struct fields case-insensitively and
// lets a later member win, so {"script":"a","Script":"b"} decodes to "b". The
// PHP server validates the exact-case key, so anything the agent acts on that
// the server did not look at must be refused rather than decoded.
package strictjson

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"reflect"
	"strings"
)

// Unmarshal decodes data, which must be one JSON object, into v (a pointer to a
// struct). It refuses duplicate members and any member whose name matches a
// field of v case-insensitively but not exactly. Unknown members are ignored
// (forward compatibility).
func Unmarshal(data []byte, v any) error {
	if string(bytes.TrimSpace(data)) == "null" {
		return nil // as encoding/json: null leaves v untouched
	}
	names := fieldNames(reflect.TypeOf(v))
	dec := json.NewDecoder(bytes.NewReader(bytes.TrimSpace(data)))
	t, err := dec.Token()
	if d, ok := t.(json.Delim); err != nil || !ok || d != '{' {
		return errors.New("not a JSON object")
	}
	seen := map[string]bool{}
	for dec.More() {
		kt, err := dec.Token()
		if err != nil {
			return err
		}
		k, _ := kt.(string)
		if seen[k] {
			return fmt.Errorf("duplicate member %q", k)
		}
		seen[k] = true
		if exact, known := names[strings.ToLower(k)]; known && exact != k {
			return fmt.Errorf("member %q is not spelled %q", k, exact)
		}
		var skip json.RawMessage
		if err := dec.Decode(&skip); err != nil {
			return err
		}
	}
	if _, err := dec.Token(); err != nil {
		return err
	}
	if _, err := dec.Token(); err != io.EOF {
		return errors.New("trailing data after the JSON object")
	}
	return json.Unmarshal(data, v)
}

// fieldNames maps the lower-cased member name of every decodable top-level
// field of the struct t points to onto its exact spelling.
func fieldNames(t reflect.Type) map[string]string {
	out := map[string]string{}
	for t != nil && t.Kind() == reflect.Pointer {
		t = t.Elem()
	}
	if t == nil || t.Kind() != reflect.Struct {
		return out
	}
	for i := 0; i < t.NumField(); i++ {
		f := t.Field(i)
		if !f.IsExported() {
			continue
		}
		tag := f.Tag.Get("json")
		if tag == "-" {
			continue
		}
		name := strings.Split(tag, ",")[0]
		if name == "" {
			name = f.Name
		}
		out[strings.ToLower(name)] = name
	}
	return out
}
