// Package jobs verifies, executes and durably tracks server-issued jobs.
package jobs

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"sort"
)

const maxDepth = 32

// Canonical returns the canonical JSON of a job object with the `signature`
// member removed: keys sorted lexicographically (bytewise) at every level,
// no insignificant whitespace, UTF-8, numbers exactly as written, no HTML
// escaping. Duplicate keys, trailing data and non-objects are rejected so a
// parser disagreement cannot be used to smuggle fields past the signature.
//
// The authoritative vectors are the server's
// tests/fixtures/agent_job_signing_vectors.json; fixture_test.go replays them
// when the file is present in the repository.
func Canonical(raw []byte) ([]byte, error) {
	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.UseNumber()
	v, err := parseValue(dec, 0)
	if err != nil {
		return nil, err
	}
	if _, err := dec.Token(); err != io.EOF {
		return nil, errors.New("trailing data after job object")
	}
	obj, ok := v.(map[string]any)
	if !ok {
		return nil, errors.New("job is not a JSON object")
	}
	delete(obj, "signature")
	var buf bytes.Buffer
	if err := emit(&buf, obj); err != nil {
		return nil, err
	}
	return buf.Bytes(), nil
}

func parseValue(dec *json.Decoder, depth int) (any, error) {
	if depth > maxDepth {
		return nil, errors.New("json nesting too deep")
	}
	tok, err := dec.Token()
	if err != nil {
		return nil, err
	}
	switch t := tok.(type) {
	case json.Delim:
		switch t {
		case '{':
			m := map[string]any{}
			for dec.More() {
				kt, err := dec.Token()
				if err != nil {
					return nil, err
				}
				k, ok := kt.(string)
				if !ok {
					return nil, errors.New("non-string key")
				}
				if _, dup := m[k]; dup {
					return nil, fmt.Errorf("duplicate key %q", k)
				}
				val, err := parseValue(dec, depth+1)
				if err != nil {
					return nil, err
				}
				m[k] = val
			}
			if _, err := dec.Token(); err != nil { // '}'
				return nil, err
			}
			return m, nil
		case '[':
			arr := []any{}
			for dec.More() {
				val, err := parseValue(dec, depth+1)
				if err != nil {
					return nil, err
				}
				arr = append(arr, val)
			}
			if _, err := dec.Token(); err != nil { // ']'
				return nil, err
			}
			return arr, nil
		}
		return nil, errors.New("unexpected delimiter")
	default:
		return tok, nil // string, json.Number, bool, nil
	}
}

func emit(buf *bytes.Buffer, v any) error {
	switch t := v.(type) {
	case map[string]any:
		keys := make([]string, 0, len(t))
		for k := range t {
			keys = append(keys, k)
		}
		sort.Strings(keys)
		buf.WriteByte('{')
		for i, k := range keys {
			if i > 0 {
				buf.WriteByte(',')
			}
			if err := emitString(buf, k); err != nil {
				return err
			}
			buf.WriteByte(':')
			if err := emit(buf, t[k]); err != nil {
				return err
			}
		}
		buf.WriteByte('}')
	case []any:
		buf.WriteByte('[')
		for i, e := range t {
			if i > 0 {
				buf.WriteByte(',')
			}
			if err := emit(buf, e); err != nil {
				return err
			}
		}
		buf.WriteByte(']')
	case string:
		return emitString(buf, t)
	case json.Number:
		buf.WriteString(t.String())
	case bool:
		if t {
			buf.WriteString("true")
		} else {
			buf.WriteString("false")
		}
	case nil:
		buf.WriteString("null")
	default:
		return fmt.Errorf("unsupported value %T", v)
	}
	return nil
}

// emitString writes s using the escaping rule of the server's signing vectors
// (tests/fixtures/agent_job_signing_vectors.json): only " \\ \b \f \n \r \t
// are short-escaped, every other code point below U+0020 is \u00xx (lowercase
// hex), everything else (including / < > & U+007F U+2028 U+2029) is raw UTF-8.
func emitString(buf *bytes.Buffer, s string) error {
	const hex = "0123456789abcdef"
	buf.WriteByte('"')
	for _, r := range s {
		switch {
		case r == '"':
			buf.WriteString(`\"`)
		case r == '\\':
			buf.WriteString(`\\`)
		case r == '\b':
			buf.WriteString(`\b`)
		case r == '\f':
			buf.WriteString(`\f`)
		case r == '\n':
			buf.WriteString(`\n`)
		case r == '\r':
			buf.WriteString(`\r`)
		case r == '\t':
			buf.WriteString(`\t`)
		case r < 0x20:
			buf.WriteString(`\u00`)
			buf.WriteByte(hex[r>>4])
			buf.WriteByte(hex[r&0xf])
		default:
			buf.WriteRune(r)
		}
	}
	buf.WriteByte('"')
	return nil
}
