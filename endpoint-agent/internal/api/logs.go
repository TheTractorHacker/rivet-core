package api

import (
	"bytes"
	"context"
	"encoding/json"
	"net/http"
)

// FeatureLogs is the capability string (announced by the agent) and the feature
// string (offered by the server) of log collection.
const FeatureLogs = "logs:collect"

// FeatureLogPresets is announced by an agent that understands the security-log
// presets of a log source: the level "always" semantics (an empty level list
// reads every level) and the "aggregate" member. A server never offers an
// aggregated source to an agent that did not announce it.
const FeatureLogPresets = "logs:presets"

// LogAggregate asks the agent to sum up the events of a source on the device:
// one summary entry per window and per combination of the By fields, only when
// the count reaches Min (repeated failed logons, for example).
type LogAggregate struct {
	WindowS int      `json:"window_s"`
	Min     int      `json:"min"`
	By      []string `json:"by,omitempty"`
}

// LogSource is one entry of config.logs.sources. Windows sources have Kind
// "eventlog" (Channel, Levels, EventIDs, Contains), Linux sources "journal"
// (Units, Identifiers, MinLevel, Contains).
type LogSource struct {
	Kind        string   `json:"kind"`
	Channel     string   `json:"channel,omitempty"`
	Levels      []string `json:"levels,omitempty"`
	EventIDs    []int    `json:"event_ids,omitempty"`
	Units       []string `json:"units,omitempty"`
	Identifiers []string `json:"identifiers,omitempty"`
	MinLevel    string   `json:"min_level,omitempty"`
	Contains    []string `json:"contains,omitempty"`
	// Aggregate (security presets) is honoured only by an agent that announced
	// logs:presets; the server never offers it to any other.
	Aggregate *LogAggregate `json:"aggregate,omitempty"`
}

// LogsConfig is the config.logs object of a check-in response.
type LogsConfig struct {
	Version         string      `json:"version"`
	IntervalS       int         `json:"interval_s"`
	BatchMax        int         `json:"batch_max"`
	MaxMessageBytes int         `json:"max_message_bytes"`
	RatePerMin      int         `json:"rate_per_min"`
	Sources         []LogSource `json:"sources"`
}

// ParseLogsConfig reads config.logs leniently: absent, null, not an object or any
// type mismatch gives nil (no collection), never an error that could fail a check-in.
func ParseLogsConfig(raw json.RawMessage) *LogsConfig {
	raw = bytes.TrimSpace(raw)
	if len(raw) == 0 || raw[0] != '{' {
		return nil
	}
	var c LogsConfig
	if err := json.Unmarshal(raw, &c); err != nil {
		return nil
	}
	return &c
}

// LogEvent is one shipped log entry.
type LogEvent struct {
	TS      string `json:"ts"`
	Level   string `json:"level"`
	Source  string `json:"source"`
	EventID *int   `json:"event_id"`
	Message string `json:"message"`
}

// LogBatch is the body of POST agent_logs.
type LogBatch struct {
	Events        []LogEvent `json:"events"`
	Dropped       int        `json:"dropped"`
	ConfigVersion string     `json:"config_version"`
}

// LogAck is the 200 answer of agent_logs.
type LogAck struct {
	OK         bool `json:"ok"`
	Accepted   int  `json:"accepted"`
	Dropped    int  `json:"dropped"`
	Duplicates int  `json:"duplicates"`
}

// SendLogs POSTs one batch of log events.
func (c *Client) SendLogs(ctx context.Context, body []byte) (*LogAck, error) {
	var out LogAck
	if err := c.DoRaw(ctx, http.MethodPost, "agent_logs", body, &out); err != nil {
		return nil, err
	}
	return &out, nil
}
