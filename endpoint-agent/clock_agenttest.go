//go:build agenttest

package main

import (
	"os"
	"strconv"
	"time"
)

// testClock lets the clock proof (e2e/run_clock_proof.sh) run the REAL agent on a PC
// whose clock is wrong: RIVETIT_TEST_CLOCK_OFFSET_S seconds are added to the raw clock.
// It exists only in builds with the agenttest tag (which also allow plain http), never in a
// release build.
func testClock() func() time.Time {
	v := os.Getenv("RIVETIT_TEST_CLOCK_OFFSET_S")
	if v == "" {
		return nil
	}
	n, err := strconv.Atoi(v)
	if err != nil {
		return nil
	}
	d := time.Duration(n) * time.Second
	return func() time.Time { return time.Now().Add(d) }
}
