package agent

import (
	"context"
	"errors"
	"os"
	"slices"
	"time"

	"rivetit-agent/internal/api"
	"rivetit-agent/internal/collect"
	"rivetit-agent/internal/store"
)

// The software inventory side of the check-in (docs/rmm/PROTOCOL.md 3.2.1).
//
// The agent sends a "software" block only while the LAST successful response
// offered the "software_inventory" feature (persisted as State.ServerFeatures, so a
// restart does not forget it and a server that stops offering silences the agent).
// It collects at most once an hour and keeps the list the server acknowledged on
// disk (software.json, verified against State.SoftwareHash on load) so the next
// report can be a delta. The block lives inside the persisted Inflight body, so a
// retry is byte-identical; the snapshot only advances once that body was acked.

const (
	featureSoftware   = "software_inventory"
	resyncSoftware    = "software"
	softwareEvery     = time.Hour        // collection interval
	softwareFullMax   = 24 * time.Hour   // a full list at least this often
	softwareResyncGap = 15 * time.Minute // a resync is honoured this long after the previous report
	softwareDeltaMax  = 300              // a larger delta is sent as a full list
)

// swSnapshot is software.json: the list the server last acknowledged.
type swSnapshot struct {
	Hash  string             `json:"hash"`
	Items []api.SoftwareItem `json:"items"`
}

// swPlan is a built (not yet acknowledged) report.
type swPlan struct {
	hash  string
	items []api.SoftwareItem // the complete list after the report
	full  bool
}

// swState is the in-memory software bookkeeping of the Agent (guarded by the
// check-in being a single goroutine; the mutex-protected fields of Agent are not involved).
type swState struct {
	collectedAt time.Time // last collection attempt
	last        *collect.SoftwareList
	resync      bool // the server's last response asked for a resync
	pending     *swPlan
	failedLog   time.Time
}

func hasFeature(list []string, f string) bool { return slices.Contains(list, f) }

// loadSnapshot returns the acknowledged list, or nil when there is none or it
// does not match State.SoftwareHash (re-hashed, so a torn or edited file is ignored).
func (a *Agent) loadSnapshot(st store.State) *swSnapshot {
	if st.SoftwareHash == "" {
		return nil
	}
	var sn swSnapshot
	ok, err := store.ReadJSON(a.o.Store.SoftwarePath(), &sn)
	if err != nil || !ok || sn.Hash != st.SoftwareHash || collect.SoftwareHash(sn.Items) != sn.Hash {
		return nil
	}
	return &sn
}

// softwareIfDue decides whether this check-in carries a software block and
// builds it. It returns nil when the server does not want one, when nothing is
// due and when nothing changed.
func (a *Agent) softwareIfDue(ctx context.Context, st store.State) (*api.SoftwareReport, *swPlan) {
	sw := &a.sw
	if !hasFeature(st.ServerFeatures, featureSoftware) {
		sw.pending, sw.last, sw.resync = nil, nil, false
		return nil, nil
	}
	now := a.o.Now()
	resyncDue := sw.resync && (st.SoftwareSentAt.IsZero() || now.Sub(st.SoftwareSentAt) >= softwareResyncGap)
	if sw.resync && !resyncDue {
		return nil, nil // the server wants a full list, but not yet; a delta would not help it
	}
	if sw.collectedAt.IsZero() || now.Sub(sw.collectedAt) >= softwareEvery {
		l, err := a.col.Software(ctx)
		sw.collectedAt = now // success or not, the next attempt is an hour away
		if err != nil {
			if !errors.Is(err, collect.ErrUnsupported) && (sw.failedLog.IsZero() || now.Sub(sw.failedLog) >= softwareEvery) {
				a.log.Warn("software inventory could not be collected", "err", err)
				sw.failedLog = now
			}
			return nil, nil
		}
		sw.last = &l
	} else if !resyncDue {
		return nil, nil
	}
	if sw.last == nil {
		return nil, nil // never collected successfully
	}
	cur := sw.last
	hash := collect.SoftwareHash(cur.Items)
	snap := a.loadSnapshot(st)
	full := snap == nil || resyncDue || st.SoftwareFullAt.IsZero() || now.Sub(st.SoftwareFullAt) >= softwareFullMax
	var changed []api.SoftwareItem
	var removed []api.SoftwareRef
	if !full {
		if hash == snap.Hash {
			return nil, nil
		}
		changed, removed = collect.SoftwareDiff(snap.Items, cur.Items)
		if len(changed)+len(removed) > softwareDeltaMax {
			full = true
		}
	}
	rep := &api.SoftwareReport{Hash: hash, Count: len(cur.Items), Truncated: cur.Truncated}
	if full {
		rep.Mode, rep.Items = "full", cur.Items
		if rep.Items == nil {
			rep.Items = []api.SoftwareItem{}
		}
	} else {
		rep.Mode, rep.BaseHash, rep.Items, rep.Removed = "delta", snap.Hash, changed, removed
	}
	return rep, &swPlan{hash: hash, items: cur.Items, full: full}
}

// ackSoftware advances the acknowledged snapshot after the check-in that carried
// the block was accepted. If the process restarted between building and acking
// there is no in-memory plan to commit: the snapshot is dropped so the next
// report is a full list (the server may or may not have applied this one).
func (a *Agent) ackSoftware(in *store.Inflight, now time.Time) {
	sw := &a.sw
	plan := sw.pending
	sw.pending = nil
	if plan != nil && plan.hash == in.SoftwareHash {
		sn := swSnapshot{Hash: plan.hash, Items: plan.items}
		if sn.Items == nil {
			sn.Items = []api.SoftwareItem{}
		}
		if err := store.WriteJSON(a.o.Store.SoftwarePath(), sn); err == nil {
			_ = a.o.Store.Update(func(st *store.State) error {
				st.SoftwareHash, st.SoftwareSentAt = plan.hash, now
				if plan.full {
					st.SoftwareFullAt = now
				}
				return nil
			})
			return
		} else {
			a.log.Warn("could not persist the software snapshot; the next report will be a full list", "err", err)
		}
	}
	a.dropSnapshot(now)
}

// dropSnapshot forgets the acknowledged list (the next report is full) and
// records that a report was just sent (it starts the resync throttle).
func (a *Agent) dropSnapshot(now time.Time) {
	_ = a.o.Store.Update(func(st *store.State) error {
		st.SoftwareHash, st.SoftwareSentAt = "", now
		return nil
	})
	removeFile(a.o.Store.SoftwarePath())
}

func removeFile(p string) { _ = os.Remove(p) }
