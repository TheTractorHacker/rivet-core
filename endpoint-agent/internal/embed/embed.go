// Package embed reads (and, for tests and the dev stamp tool, writes) the
// per-department payload that the RivetIT server appends to the installer exe.
//
// File format (fixed contract):
//
//	stamped_exe = <original exe bytes> || payload || footer
//	payload     = UTF-8 JSON, at most 16384 bytes
//	footer      = 52 bytes: uint32 big-endian payload length ||
//	              32-byte raw SHA-256 of the payload || "RIVETIT-EMBED-v1"
//
// The reader looks only at the LAST 52 bytes of the file, so anything appended
// after the footer makes the file read as "not stamped". The payload carries a
// one-shot enrollment token: it is never logged, printed or included in any
// error produced by this package.
package embed

import (
	"bytes"
	"crypto/sha256"
	"crypto/x509"
	"encoding/binary"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"io"
	"net/url"
	"os"
	"regexp"
	"strings"
	"time"
	"unicode"
	"unicode/utf8"
)

const (
	Magic         = "RIVETIT-EMBED-v1"
	FooterSize    = 4 + sha256.Size + len(Magic) // 52
	MaxPayload    = 16384
	maxCAPEM      = 12288
	maxDepartment = 255
	maxServerURL  = 2048
	maxToken      = 256
)

var (
	// ErrNotStamped: the file does not end in a footer with the right magic.
	ErrNotStamped = errors.New("installer is not stamped (no embedded configuration)")
	// ErrInvalid wraps every structural or semantic failure of a stamped file.
	ErrInvalid = errors.New("embedded configuration is invalid")
	// ErrExpired: a well-formed payload whose expires_at has passed.
	ErrExpired = errors.New("embedded configuration has expired; download a new installer")
)

// Payload is the decoded embedded configuration.
type Payload struct {
	Version         int     `json:"version"`
	InstallerID     string  `json:"installer_id"`
	ServerURL       string  `json:"server_url"`
	EnrollmentToken string  `json:"enrollment_token"`
	Department      string  `json:"department"`
	CAPEM           *string `json:"ca_pem"`
	CreatedAt       string  `json:"created_at"`
	ExpiresAt       string  `json:"expires_at"`
	created         time.Time
	expires         time.Time
}

// Expires returns the parsed expiry.
func (p *Payload) Expires() time.Time { return p.expires }

// Created returns the parsed creation time.
func (p *Payload) Created() time.Time { return p.created }

// RedactedToken is what logs may show instead of the token.
func (p *Payload) RedactedToken() string { return "rvte1.[redacted]" }

// String never includes the token.
func (p *Payload) String() string {
	return fmt.Sprintf("embed.Payload{installer_id=%s server=%s department=%q token=%s expires=%s}",
		p.InstallerID, p.ServerURL, p.Department, p.RedactedToken(), p.ExpiresAt)
}

// GoString keeps %#v from leaking the token too.
func (p *Payload) GoString() string { return p.String() }

var (
	uuidRe  = regexp.MustCompile(`^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$`)
	tokPart = regexp.MustCompile(`^[A-Za-z0-9_-]+$`)
)

func invalid(format string, a ...any) error {
	return fmt.Errorf("%w: %s", ErrInvalid, fmt.Sprintf(format, a...))
}

// footerOf splits the trailing footer out of a file of the given size and
// returns the payload offset and length. rd must support ReadAt.
func locate(rd io.ReaderAt, size int64) (off int64, n int, sum [sha256.Size]byte, err error) {
	if size < int64(FooterSize) {
		return 0, 0, sum, ErrNotStamped
	}
	var f [52]byte
	if _, err = rd.ReadAt(f[:], size-int64(FooterSize)); err != nil {
		return 0, 0, sum, fmt.Errorf("read footer: %w", err)
	}
	if string(f[36:]) != Magic {
		return 0, 0, sum, ErrNotStamped
	}
	l := binary.BigEndian.Uint32(f[0:4])
	if l == 0 || l > MaxPayload {
		return 0, 0, sum, invalid("payload length %d out of range", l)
	}
	if int64(l) > size-int64(FooterSize) {
		return 0, 0, sum, invalid("payload length exceeds file size")
	}
	copy(sum[:], f[4:36])
	return size - int64(FooterSize) - int64(l), int(l), sum, nil
}

// UnstampedSize returns the length of the original exe bytes inside a file:
// size-52-payload when stamped, the whole size otherwise. A file that carries
// the magic but a corrupt footer is an error.
func UnstampedSize(rd io.ReaderAt, size int64) (int64, error) {
	off, _, _, err := locate(rd, size)
	if errors.Is(err, ErrNotStamped) {
		return size, nil
	}
	if err != nil {
		return 0, err
	}
	return off, nil
}

// Extract does the format-level work only: locate the footer, bound the
// length, verify the SHA-256 and return the payload bytes and the length of
// the original exe in front of them. No JSON or semantic checks.
func Extract(rd io.ReaderAt, size int64) (payload []byte, exeLen int64, err error) {
	off, n, sum, err := locate(rd, size)
	if err != nil {
		return nil, 0, err
	}
	buf := make([]byte, n)
	if _, err := rd.ReadAt(buf, off); err != nil {
		return nil, 0, fmt.Errorf("read payload: %w", err)
	}
	if sha256.Sum256(buf) != sum {
		return nil, 0, invalid("payload checksum mismatch")
	}
	return buf, off, nil
}

// Parse validates a stamped file image: footer, hash, strict JSON, semantics, expiry.
func Parse(rd io.ReaderAt, size int64, now time.Time) (*Payload, error) {
	buf, _, err := Extract(rd, size)
	if err != nil {
		return nil, err
	}
	return decode(buf, now)
}

// ParseBytes is Parse over an in-memory file image.
func ParseBytes(b []byte, now time.Time) (*Payload, error) {
	return Parse(bytes.NewReader(b), int64(len(b)), now)
}

// Read opens path (os.Executable() in production) and parses its payload.
func Read(path string, now time.Time) (*Payload, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	fi, err := f.Stat()
	if err != nil {
		return nil, err
	}
	return Parse(f, fi.Size(), now)
}

func decode(b []byte, now time.Time) (*Payload, error) {
	if !utf8.Valid(b) {
		return nil, invalid("payload is not valid UTF-8")
	}
	dec := json.NewDecoder(bytes.NewReader(b))
	var p Payload
	if err := dec.Decode(&p); err != nil {
		// json errors can echo input fragments; never forward their text.
		return nil, invalid("payload is not the expected JSON object")
	}
	if dec.More() {
		return nil, invalid("trailing data after payload JSON")
	}
	if _, err := dec.Token(); err != io.EOF {
		return nil, invalid("trailing data after payload JSON")
	}
	if err := p.validate(); err != nil {
		return nil, err
	}
	if !now.Before(p.expires) {
		return nil, ErrExpired
	}
	return &p, nil
}

func (p *Payload) validate() error {
	if p.Version != 1 {
		return invalid("unsupported version %d", p.Version)
	}
	if !uuidRe.MatchString(p.InstallerID) {
		return invalid("installer_id is not a UUID")
	}
	if err := checkServerURL(p.ServerURL); err != nil {
		return err
	}
	if err := checkToken(p.EnrollmentToken); err != nil {
		return err
	}
	d := p.Department
	if d == "" || !utf8.ValidString(d) || utf8.RuneCountInString(d) > maxDepartment || strings.TrimSpace(d) != d {
		return invalid("department must be 1-%d characters without leading/trailing space", maxDepartment)
	}
	for _, r := range d {
		if unicode.IsControl(r) {
			return invalid("department contains control characters")
		}
	}
	if p.CAPEM != nil {
		if err := checkCA(*p.CAPEM); err != nil {
			return err
		}
	}
	var err error
	if p.created, err = time.Parse(time.RFC3339, p.CreatedAt); err != nil {
		return invalid("created_at is not RFC3339")
	}
	if p.expires, err = time.Parse(time.RFC3339, p.ExpiresAt); err != nil {
		return invalid("expires_at is not RFC3339")
	}
	if !p.expires.After(p.created) {
		return invalid("expires_at is not after created_at")
	}
	return nil
}

func checkServerURL(s string) error {
	if s == "" || len(s) > maxServerURL {
		return invalid("server_url missing or too long")
	}
	u, err := url.Parse(s)
	if err != nil || u.Host == "" || u.Hostname() == "" {
		return invalid("server_url is not a valid URL")
	}
	if u.User != nil || u.RawQuery != "" || u.Fragment != "" || u.ForceQuery || u.Opaque != "" {
		return invalid("server_url must be scheme://host[/prefix] only")
	}
	switch {
	case u.Scheme == "https":
	case u.Scheme == "http" && httpAllowed(u):
	default:
		return invalid("server_url must use https")
	}
	for _, r := range s {
		if r <= 0x20 || r == 0x7f {
			return invalid("server_url contains whitespace or control characters")
		}
	}
	return nil
}

func checkToken(t string) error {
	if len(t) > maxToken {
		return invalid("enrollment_token has the wrong shape")
	}
	parts := strings.Split(t, ".")
	if len(parts) != 3 || parts[0] != "rvte1" || !tokPart.MatchString(parts[1]) || !tokPart.MatchString(parts[2]) {
		return invalid("enrollment_token has the wrong shape")
	}
	return nil
}

func checkCA(s string) error {
	if s == "" || len(s) > maxCAPEM {
		return invalid("ca_pem is empty or too large")
	}
	rest, n := []byte(s), 0
	for {
		var blk *pem.Block
		blk, rest = pem.Decode(rest)
		if blk == nil {
			break
		}
		if blk.Type != "CERTIFICATE" {
			return invalid("ca_pem contains a non-certificate block")
		}
		if _, err := x509.ParseCertificate(blk.Bytes); err != nil {
			return invalid("ca_pem contains an unparsable certificate")
		}
		n++
	}
	if n == 0 || len(bytes.TrimSpace(rest)) != 0 {
		return invalid("ca_pem must contain only PEM certificates")
	}
	return nil
}

// Build returns exe || payload || footer for a payload that has already been
// marshalled. It does not validate (tests build deliberately bad files).
func Build(exe, payload []byte) []byte {
	out := make([]byte, 0, len(exe)+len(payload)+FooterSize)
	out = append(out, exe...)
	out = append(out, payload...)
	var l [4]byte
	binary.BigEndian.PutUint32(l[:], uint32(len(payload)))
	sum := sha256.Sum256(payload)
	out = append(out, l[:]...)
	out = append(out, sum[:]...)
	return append(out, Magic...)
}

// Marshal produces the payload JSON for p and refuses one that would not pass
// the reader (so the dev stamp tool cannot create unreadable installers).
func Marshal(p Payload, now time.Time) ([]byte, error) {
	b, err := json.Marshal(p)
	if err != nil {
		return nil, err
	}
	if len(b) > MaxPayload {
		return nil, invalid("payload exceeds %d bytes", MaxPayload)
	}
	if _, err := decode(b, now); err != nil {
		return nil, err
	}
	return b, nil
}
