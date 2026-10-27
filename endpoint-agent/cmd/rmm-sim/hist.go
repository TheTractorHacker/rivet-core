package main

import (
	"fmt"
	"math"
	"strings"
	"sync/atomic"
	"time"
)

// hist is a lock-free log-scale latency histogram: bucket i covers up to base*growth^i. Percentiles are the upper bound of the bucket
// that holds the rank, so they overstate by at most one growth step (7 %), never understate.
type hist struct {
	counts [histBuckets]atomic.Uint64
	n      atomic.Uint64
	sumNs  atomic.Uint64
	maxNs  atomic.Uint64
}

const (
	histBuckets = 280
	histBaseNs  = 50_000.0 // 50 us
	histGrowth  = 1.07
)

func bucketOf(d time.Duration) int {
	ns := float64(d.Nanoseconds())
	if ns <= histBaseNs {
		return 0
	}
	i := int(math.Ceil(math.Log(ns/histBaseNs) / math.Log(histGrowth)))
	if i >= histBuckets {
		i = histBuckets - 1
	}
	return i
}

func bucketUpper(i int) time.Duration {
	return time.Duration(histBaseNs * math.Pow(histGrowth, float64(i)))
}

func (h *hist) add(d time.Duration) {
	h.counts[bucketOf(d)].Add(1)
	h.n.Add(1)
	h.sumNs.Add(uint64(d.Nanoseconds()))
	for {
		cur := h.maxNs.Load()
		if uint64(d.Nanoseconds()) <= cur || h.maxNs.CompareAndSwap(cur, uint64(d.Nanoseconds())) {
			break
		}
	}
}

// snapshot copies the counters so a percentile query sees one consistent view.
type histSnap struct {
	counts [histBuckets]uint64
	n      uint64
	sumNs  uint64
	maxNs  uint64
}

func (h *hist) snapshot() histSnap {
	var s histSnap
	for i := range h.counts {
		s.counts[i] = h.counts[i].Load()
		s.n += s.counts[i]
	}
	s.sumNs = h.sumNs.Load()
	s.maxNs = h.maxNs.Load()
	return s
}

// sub returns the observations made between an earlier snapshot and this one (the per-interval view).
func (s histSnap) sub(prev histSnap) histSnap {
	var d histSnap
	for i := range s.counts {
		d.counts[i] = s.counts[i] - prev.counts[i]
		d.n += d.counts[i]
	}
	d.sumNs = s.sumNs - prev.sumNs
	d.maxNs = s.maxNs
	return d
}

func (s histSnap) percentile(p float64) time.Duration {
	if s.n == 0 {
		return 0
	}
	rank := uint64(math.Ceil(p / 100 * float64(s.n)))
	if rank < 1 {
		rank = 1
	}
	var cum uint64
	for i, c := range s.counts {
		cum += c
		if cum >= rank {
			up := bucketUpper(i)
			if m := time.Duration(s.maxNs); m > 0 && up > m {
				return m // never report above the slowest request seen
			}
			return up
		}
	}
	return time.Duration(s.maxNs)
}

func (s histSnap) mean() time.Duration {
	if s.n == 0 {
		return 0
	}
	return time.Duration(s.sumNs / s.n)
}

// bars renders the non-empty part of the distribution as text, one row per power-of-two latency band.
func (s histSnap) bars() string {
	bands := []time.Duration{time.Millisecond, 2 * time.Millisecond, 5 * time.Millisecond, 10 * time.Millisecond, 25 * time.Millisecond, 50 * time.Millisecond,
		100 * time.Millisecond, 250 * time.Millisecond, 500 * time.Millisecond, time.Second, 2500 * time.Millisecond, 5 * time.Second, 10 * time.Second, time.Hour}
	counts := make([]uint64, len(bands))
	for i, c := range s.counts {
		up := bucketUpper(i)
		for j, b := range bands {
			if up <= b {
				counts[j] += c
				break
			}
		}
	}
	var max uint64 = 1
	for _, c := range counts {
		if c > max {
			max = c
		}
	}
	var sb strings.Builder
	prev := time.Duration(0)
	for j, b := range bands {
		if counts[j] > 0 {
			label := fmt.Sprintf("%v-%v", prev, b)
			if b == time.Hour {
				label = fmt.Sprintf(">%v", prev)
			}
			fmt.Fprintf(&sb, "  %-14s %8d %s\n", label, counts[j], strings.Repeat("#", int(1+counts[j]*40/max)))
		}
		prev = b
	}
	return sb.String()
}
