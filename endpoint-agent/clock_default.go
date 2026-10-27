//go:build !agenttest

package main

import "time"

// testClock is nil in every release build: the agent reads the real clock.
func testClock() func() time.Time { return nil }
