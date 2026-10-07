package api

import (
	"bytes"
	"context"
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"os"
	"strings"
	"time"
)

const (
	MaxRequestBytes  = 1 << 20 // refuse to send more than 1 MiB
	MaxResponseBytes = 4 << 20 // refuse to read more than 4 MiB
)

// APIError is a non-2xx response (or a transport failure when Status==0).
type APIError struct {
	Status     int
	Code       string
	Message    string
	RetryAfter time.Duration // Retry-After capped at 1 h (MaxRetryAfter)
	// RetryAfterRaw is the same header capped at 24 h (MaxModuleRetryAfter). Only the
	// module_disabled back-off uses it; everything else keeps the 1 h cap.
	RetryAfterRaw time.Duration
	Err           error
}

func (e *APIError) Error() string {
	if e.Status == 0 {
		return fmt.Sprintf("transport error: %v", e.Err)
	}
	return fmt.Sprintf("HTTP %d code=%q: %s", e.Status, e.Code, e.Message)
}
func (e *APIError) Unwrap() error { return e.Err }

// Transient reports whether retrying later may succeed.
func (e *APIError) Transient() bool {
	return e.Status == 0 || e.Status == 429 || e.Status >= 500
}

// Codes a server answers with when the RMM module (or the capability behind an
// endpoint) is switched off. They ride on HTTP 503 with a Retry-After.
const (
	CodeModuleDisabled  = "module_disabled"
	CodeFeatureDisabled = "feature_disabled"
)

// ModuleDisabled is the disabled-server answer: 503 with module_disabled (or
// feature_disabled). The agent keeps its credential and buffer and backs off.
// A 403 from a server that predates the switch is NOT this (see Transient/default handling).
func (e *APIError) ModuleDisabled() bool {
	return e.Status == 503 && (e.Code == CodeModuleDisabled || e.Code == CodeFeatureDisabled)
}

// Revoked is a 401 with code "revoked".
func (e *APIError) Revoked() bool { return e.Status == 401 && e.Code == "revoked" }

// AuthRejected is a 401 that is not revocation (invalid_token / expired).
func (e *APIError) AuthRejected() bool { return e.Status == 401 && e.Code != "revoked" }

// Options configure the TLS/HTTP client.
type Options struct {
	ServerURL     string
	CAFile        string
	PinSPKISHA256 string
	Token         func() string // current device token ("" for enrollment)
	UserAgent     string
	Timeout       time.Duration
	Now           func() time.Time
}

type Client struct {
	base *url.URL
	hc   *http.Client
	opt  Options
}

// New builds a Client. TLS verification is always on; there is no insecure
// option. Plain http is accepted only in builds with the agenttest tag.
func New(o Options) (*Client, error) {
	u, err := url.Parse(strings.TrimRight(o.ServerURL, "/"))
	if err != nil || u.Host == "" {
		return nil, fmt.Errorf("invalid server URL %q", o.ServerURL)
	}
	if err := checkScheme(u); err != nil {
		return nil, err
	}
	tlsCfg := &tls.Config{MinVersion: tls.VersionTLS12}
	if o.CAFile != "" {
		pem, err := os.ReadFile(o.CAFile)
		if err != nil {
			return nil, fmt.Errorf("read CA file: %w", err)
		}
		pool, err := x509.SystemCertPool()
		if err != nil || pool == nil {
			pool = x509.NewCertPool()
		}
		if !pool.AppendCertsFromPEM(pem) {
			return nil, errors.New("CA file contains no valid PEM certificates")
		}
		tlsCfg.RootCAs = pool
	}
	if o.PinSPKISHA256 != "" {
		want, err := hex.DecodeString(strings.ToLower(strings.ReplaceAll(o.PinSPKISHA256, ":", "")))
		if err != nil || len(want) != sha256.Size {
			return nil, errors.New("pin_spki_sha256 must be 64 hex characters")
		}
		// Runs after normal chain verification (we never set InsecureSkipVerify).
		tlsCfg.VerifyConnection = func(cs tls.ConnectionState) error {
			if len(cs.PeerCertificates) == 0 {
				return errors.New("no peer certificate")
			}
			sum := sha256.Sum256(cs.PeerCertificates[0].RawSubjectPublicKeyInfo)
			if !bytes.Equal(sum[:], want) {
				return errors.New("server public key does not match the pinned SPKI hash")
			}
			return nil
		}
	}
	tr := &http.Transport{
		Proxy:                 http.ProxyFromEnvironment,
		DialContext:           (&net.Dialer{Timeout: 15 * time.Second, KeepAlive: 30 * time.Second}).DialContext,
		TLSClientConfig:       tlsCfg,
		TLSHandshakeTimeout:   15 * time.Second,
		ResponseHeaderTimeout: 30 * time.Second,
		MaxIdleConns:          2,
		IdleConnTimeout:       60 * time.Second,
	}
	to := o.Timeout
	if to == 0 {
		to = 60 * time.Second
	}
	if o.Now == nil {
		o.Now = time.Now
	}
	hc := &http.Client{
		Transport: tr,
		Timeout:   to,
		// Never follow redirects: the bearer token must not leave the host.
		CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
	}
	return &Client{base: u, hc: hc, opt: o}, nil
}

// HTTPClient exposes the underlying client (the updater reuses its TLS config).
func (c *Client) HTTPClient() *http.Client { return c.hc }

// Host is the server host:port.
func (c *Client) Host() string { return c.base.Host }

func (c *Client) endpoint(name string) string {
	return c.base.String() + "/api/v1/" + name
}

// Do sends one request. body (if non-nil) is JSON-encoded.
func (c *Client) Do(ctx context.Context, method, name string, body any, out any) error {
	var raw []byte
	if body != nil {
		var err error
		if r, ok := body.(json.RawMessage); ok {
			raw = r
		} else if raw, err = json.Marshal(body); err != nil {
			return err
		}
	}
	return c.DoRaw(ctx, method, name, raw, out)
}

// DoRaw sends pre-encoded JSON (used to replay an in-flight check-in exactly).
func (c *Client) DoRaw(ctx context.Context, method, name string, raw []byte, out any) error {
	if len(raw) > MaxRequestBytes {
		return fmt.Errorf("request body %d bytes exceeds the %d byte cap", len(raw), MaxRequestBytes)
	}
	var rd io.Reader
	if raw != nil {
		rd = bytes.NewReader(raw)
	}
	req, err := http.NewRequestWithContext(ctx, method, c.endpoint(name), rd)
	if err != nil {
		return err
	}
	req.Header.Set("Accept", "application/json")
	if c.opt.UserAgent != "" {
		req.Header.Set("User-Agent", c.opt.UserAgent)
	}
	if raw != nil {
		req.Header.Set("Content-Type", "application/json; charset=utf-8")
	}
	if c.opt.Token != nil {
		if t := c.opt.Token(); t != "" {
			req.Header.Set("Authorization", "Bearer "+t)
		}
	}
	resp, err := c.hc.Do(req)
	if err != nil {
		return &APIError{Err: err}
	}
	defer resp.Body.Close()
	data, err := io.ReadAll(io.LimitReader(resp.Body, MaxResponseBytes+1))
	if err != nil {
		return &APIError{Err: err}
	}
	if len(data) > MaxResponseBytes {
		return &APIError{Err: errors.New("response exceeds size cap")}
	}
	if resp.StatusCode < 200 || resp.StatusCode > 299 {
		ra := resp.Header.Get("Retry-After")
		ae := &APIError{Status: resp.StatusCode, RetryAfter: ParseRetryAfter(ra, c.opt.Now()), RetryAfterRaw: ParseRetryAfterCap(ra, c.opt.Now(), MaxModuleRetryAfter)}
		var e struct {
			Error string `json:"error"`
			Code  string `json:"code"`
		}
		if json.Unmarshal(data, &e) == nil {
			ae.Code, ae.Message = e.Code, e.Error
		}
		if ae.Message == "" {
			ae.Message = http.StatusText(resp.StatusCode)
		}
		return ae
	}
	if out == nil {
		return nil
	}
	if o, ok := out.(*[]byte); ok {
		*o = data
		return nil
	}
	if err := json.Unmarshal(data, out); err != nil {
		return &APIError{Status: resp.StatusCode, Err: fmt.Errorf("decode response: %w", err), Message: "undecodable response"}
	}
	return nil
}

// Enroll POSTs agent_enroll.
func (c *Client) Enroll(ctx context.Context, r EnrollRequest) (*EnrollResponse, error) {
	var out EnrollResponse
	if err := c.Do(ctx, http.MethodPost, "agent_enroll", r, &out); err != nil {
		return nil, err
	}
	if out.DeviceToken == "" || out.DeviceID == "" {
		return nil, errors.New("enrollment response lacked device_id/device_token")
	}
	return &out, nil
}

// Checkin POSTs a pre-encoded check-in body.
func (c *Client) Checkin(ctx context.Context, body []byte) (*CheckinResponse, error) {
	var out CheckinResponse
	if err := c.DoRaw(ctx, http.MethodPost, "agent_checkin", body, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// Jobs GETs agent_jobs.
func (c *Client) Jobs(ctx context.Context) ([]Job, error) {
	var raw []byte
	if err := c.Do(ctx, http.MethodGet, "agent_jobs", nil, &raw); err != nil {
		return nil, err
	}
	jobs, err := UnmarshalJobs(raw)
	if err != nil {
		return nil, &APIError{Status: 200, Err: err, Message: "undecodable jobs response"}
	}
	return jobs, nil
}

// ReportJob POSTs a job state transition.
func (c *Client) ReportJob(ctx context.Context, r JobReport) error {
	return c.Do(ctx, http.MethodPost, "agent_jobs", r, nil)
}
