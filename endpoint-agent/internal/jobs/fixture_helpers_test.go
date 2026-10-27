package jobs

import (
	"bytes"
	"encoding/json"
	"io"
	"strings"
)

func stringsReader(s string) io.Reader { return strings.NewReader(s) }

func replaceOnce(s, a, b string) string { return strings.Replace(s, a, b, 1) }

// canonicalAny canonicalises any JSON value (the production entry point only
// accepts objects, because jobs and checks are objects).
func canonicalAny(in []byte) ([]byte, error) {
	dec := json.NewDecoder(bytes.NewReader(in))
	dec.UseNumber()
	v, err := parseValue(dec, 0)
	if err != nil {
		return nil, err
	}
	var buf bytes.Buffer
	if err := emit(&buf, v); err != nil {
		return nil, err
	}
	return buf.Bytes(), nil
}
