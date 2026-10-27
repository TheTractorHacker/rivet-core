package api

import (
	"context"
	"net/http"
)

// FeatureChange is the capability string (announced by the agent) and the
// feature string (offered by the server) of change monitoring.
const FeatureChange = "change:monitor"

// ChangeAck is the 200 answer of agent_changes.
type ChangeAck struct {
	OK         bool `json:"ok"`
	Accepted   int  `json:"accepted"`
	Dropped    int  `json:"dropped"`
	Duplicates int  `json:"duplicates"`
}

// SendChanges POSTs one batch of change records.
func (c *Client) SendChanges(ctx context.Context, body []byte) (*ChangeAck, error) {
	var out ChangeAck
	if err := c.DoRaw(ctx, http.MethodPost, "agent_changes", body, &out); err != nil {
		return nil, err
	}
	return &out, nil
}
