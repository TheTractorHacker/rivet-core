package api

import (
	"bytes"
	"context"
	"io"
	"net/http"
	"net/url"
	"strconv"
)

// MaxTransferChunk is the largest body of one agent_transfer request or response.
const MaxTransferChunk = 1 << 20

// TransferRequest is one agent_transfer call (file_get uploads with POST, file_put
// downloads with GET). Header values are set verbatim next to the device
// Authorization; Body is the raw chunk (nil for GET).
type TransferRequest struct {
	Method string
	Query  url.Values
	Header map[string]string
	Body   []byte
}

// TransferResponse is a 2xx answer: the status, the headers the caller cross-checks
// and the body (raw bytes for a download, JSON for an upload acknowledgement).
type TransferResponse struct {
	Status int
	Header http.Header
	Body   []byte
}

// Transfer sends one agent_transfer request with the same base URL, bearer
// token and TLS client as every other device endpoint. A non-2xx answer is an
// *APIError (Received is set on a 409 resync).
func (c *Client) Transfer(ctx context.Context, tr TransferRequest) (*TransferResponse, error) {
	if len(tr.Body) > MaxTransferChunk {
		return nil, &APIError{Err: io.ErrShortBuffer, Message: "transfer chunk exceeds " + strconv.Itoa(MaxTransferChunk) + " bytes"}
	}
	target := c.endpoint("agent_transfer")
	if len(tr.Query) > 0 {
		target += "?" + tr.Query.Encode()
	}
	var rd io.Reader
	if tr.Body != nil {
		rd = bytes.NewReader(tr.Body)
	}
	method := tr.Method
	if method == "" {
		method = http.MethodGet
	}
	req, err := http.NewRequestWithContext(ctx, method, target, rd)
	if err != nil {
		return nil, err
	}
	if c.opt.UserAgent != "" {
		req.Header.Set("User-Agent", c.opt.UserAgent)
	}
	if c.opt.Token != nil {
		if t := c.opt.Token(); t != "" {
			req.Header.Set("Authorization", "Bearer "+t)
		}
	}
	for k, v := range tr.Header {
		req.Header.Set(k, v)
	}
	resp, err := c.hc.Do(req)
	if err != nil {
		return nil, &APIError{Err: err}
	}
	defer resp.Body.Close()
	data, err := io.ReadAll(io.LimitReader(resp.Body, MaxResponseBytes+1))
	if err != nil {
		return nil, &APIError{Err: err}
	}
	if len(data) > MaxResponseBytes {
		return nil, &APIError{Err: io.ErrShortBuffer, Message: "response exceeds size cap"}
	}
	if resp.StatusCode < 200 || resp.StatusCode > 299 {
		return nil, c.errorFrom(resp, data)
	}
	return &TransferResponse{Status: resp.StatusCode, Header: resp.Header, Body: data}, nil
}
