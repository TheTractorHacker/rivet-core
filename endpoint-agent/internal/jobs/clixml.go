package jobs

import (
	"encoding/xml"
	"io"
	"regexp"
	"strconv"
	"strings"
)

// Windows PowerShell 5.1 started with -EncodedCommand and redirected pipes writes its error, warning and progress streams as CLIXML ("#< CLIXML" and an
// <Objs> document) instead of text, even with -OutputFormat Text (found on a real Windows 11 machine, docs/rmm/WINDOWS_VERIFICATION.md). A technician
// reading a job's output saw an XML blob where the error message should be. CleanCLIXML turns those documents back into the plain text a console would
// have shown: error, warning, verbose and debug strings are kept, progress records are dropped.
var (
	clixmlMarker = regexp.MustCompile(`#< CLIXML\r?\n?`)
	clixmlDoc    = regexp.MustCompile(`(?s)<Objs Version="[^"]*" xmlns="http://schemas\.microsoft\.com/powershell/2004/04">.*?</Objs>`)
	clixmlEscape = regexp.MustCompile(`_x([0-9A-Fa-f]{4})_`)
)

// CleanCLIXML replaces every CLIXML document in s by its text. Text without one is returned unchanged (same string).
func CleanCLIXML(s string) string {
	if !strings.Contains(s, "<Objs Version=") && !strings.Contains(s, "#< CLIXML") {
		return s
	}
	s = clixmlDoc.ReplaceAllStringFunc(s, clixmlText)
	return clixmlMarker.ReplaceAllString(s, "")
}

// clixmlText extracts the text streams of one <Objs> document; a document that does not parse is left as it is.
func clixmlText(doc string) string {
	dec := xml.NewDecoder(strings.NewReader(doc))
	dec.Strict = false
	var b strings.Builder
	depth := 0
	for {
		tok, err := dec.Token()
		if err == io.EOF {
			break
		}
		if err != nil {
			return doc
		}
		switch t := tok.(type) {
		case xml.StartElement:
			depth++
			if t.Name.Local == "S" && depth == 2 { // a direct <S S="Error"> child of <Objs>; strings inside <Obj> (progress records) are not text output
				var v string
				if err := dec.DecodeElement(&v, &t); err != nil {
					return doc
				}
				depth--
				b.WriteString(clixmlUnescape(v))
			} else if t.Name.Local == "Obj" && depth == 2 {
				if err := dec.Skip(); err != nil {
					return doc
				}
				depth--
			}
		case xml.EndElement:
			depth--
		}
	}
	return b.String()
}

func clixmlUnescape(v string) string {
	return clixmlEscape.ReplaceAllStringFunc(v, func(m string) string {
		n, err := strconv.ParseUint(m[2:6], 16, 32)
		if err != nil {
			return m
		}
		return string(rune(n))
	})
}
