package logs

import (
	"context"
	"fmt"
	"strings"
	"testing"
	"time"

	"rivetit-agent/internal/api"
)

func failedLogon(pos int, ts time.Time, user, ip string) Item {
	return Item{Pos: fmt.Sprintf("%06d", pos), Event: api.LogEvent{TS: ts.UTC().Format(time.RFC3339), Level: "info", Source: "Microsoft-Windows-Security-Auditing", EventID: ptr(4625), Message: "x | " + user + " | " + ip},
		Fields: map[string]string{"TargetUserName": user, "IpAddress": ip, "LogonType": "3"}}
}

func ptr(n int) *int { return &n }

func aggCfg() *Config {
	return Normalize(&api.LogsConfig{Version: "v9", IntervalS: 60, BatchMax: 200, MaxMessageBytes: 1024, RatePerMin: 600, Sources: []api.LogSource{
		{Kind: "journal", EventIDs: []int{4625}, Levels: []string{}, Aggregate: &api.LogAggregate{WindowS: 300, Min: 5, By: []string{"TargetUserName", "IpAddress"}}}}})
}

func TestFailedLogonsAreSummedUpPerWindowAndAccountAndOnlyWhenTheyReachTheMinimum(t *testing.T) {
	clock := t0 // 12:00:00
	var all []Item
	for i := 0; i < 7; i++ { // bob from 10.0.0.9: 7 failures
		all = append(all, failedLogon(i+1, t0.Add(time.Duration(i)*10*time.Second), "bob", "10.0.0.9"))
	}
	for i := 0; i < 3; i++ { // amy: only 3
		all = append(all, failedLogon(10+i, t0.Add(time.Duration(i)*10*time.Second), "amy", "10.0.0.7"))
	}
	rd := &fakeReader{items: all}
	sk := &sink{}
	m, _ := newMgr(t, rd, sk, &clock)
	m.Configure(aggCfg())
	m.Tick(context.Background())
	if sk.total() != 0 {
		t.Fatalf("an open window ships nothing: %d", sk.total())
	}
	clock = t0.Add(6 * time.Minute) // the 12:00-12:05 window has ended
	m.Tick(context.Background())
	if sk.total() != 1 {
		t.Fatalf("one summary for bob, none for amy (3 < 5): %d", sk.total())
	}
	ev := sk.bodies[0].Events[0]
	if ev.Level != "warning" || ev.EventID == nil || *ev.EventID != 4625 || ev.Message != "7 events in 300s: TargetUserName=bob IpAddress=10.0.0.9" || ev.TS != "2026-10-11T12:05:00Z" {
		t.Errorf("%+v", ev)
	}
	// nothing raw was shipped: the thousands of events of a spray never reach the server one by one
	for _, b := range sk.bodies {
		for _, e := range b.Events {
			if !strings.HasPrefix(e.Message, "7 events") {
				t.Errorf("a raw event was shipped: %+v", e)
			}
		}
	}
	// flushed once
	clock = t0.Add(20 * time.Minute)
	m.Tick(context.Background())
	if sk.total() != 1 {
		t.Errorf("%d", sk.total())
	}
}

func TestTheEventsOfASprayAreBoundedInGroupsAndBuckets(t *testing.T) {
	g := newAggregator(&api.LogAggregate{WindowS: 300, Min: 1, By: []string{"TargetUserName"}})
	for i := 0; i < 1000; i++ {
		g.add(failedLogon(i, t0, fmt.Sprintf("user%d", i), "1.1.1.1"), t0)
	}
	out := g.flush(t0.Add(time.Hour), nil)
	if len(out) > maxAggGroups+1 {
		t.Fatalf("%d summaries", len(out))
	}
	other := ""
	for _, e := range out {
		if strings.Contains(e.Message, aggOtherGroup) {
			other = e.Message
		}
	}
	if !strings.HasPrefix(other, "801 events") && !strings.HasPrefix(other, "800 events") {
		t.Errorf("the overflow is one (other) group holding the 800 events of the groups beyond the cap: %q", other)
	}
	for i := 0; i < 50; i++ {
		g.add(failedLogon(i, t0.Add(time.Duration(i)*time.Hour), "x", "y"), t0)
	}
	if len(g.buckets) > maxBucketsKept {
		t.Errorf("%d buckets", len(g.buckets))
	}
}

func TestAnAggregationTheAgentCannotHonourIsSkippedNeverReadRaw(t *testing.T) {
	for _, bad := range []*api.LogAggregate{{WindowS: 1, Min: 5}, {WindowS: 300, Min: 0}, {WindowS: 99999, Min: 5}, {WindowS: 300, Min: 5, By: []string{"a b"}}, {WindowS: 300, Min: 5, By: []string{"a", "b", "c", "d", "e"}}} {
		if newAggregator(bad) != nil {
			t.Errorf("%+v accepted", bad)
		}
	}
	clock := t0
	rd := &fakeReader{items: []Item{failedLogon(1, t0, "bob", "1.2.3.4")}}
	sk := &sink{}
	m, _ := newMgr(t, rd, sk, &clock)
	m.Configure(Normalize(&api.LogsConfig{Version: "v9", IntervalS: 60, Sources: []api.LogSource{{Kind: "journal", Aggregate: &api.LogAggregate{WindowS: 1, Min: 1}}}}))
	m.Tick(context.Background())
	clock = t0.Add(time.Hour)
	m.Tick(context.Background())
	if sk.total() != 0 || len(rd.froms) != 0 {
		t.Errorf("the source is not even read: shipped %d, reads %d", sk.total(), len(rd.froms))
	}
}

func TestEventLogXMLKeepsTheNamedFieldsForTheAggregation(t *testing.T) {
	xml := `<Event><System><Provider Name="Microsoft-Windows-Security-Auditing"/><EventID>4625</EventID><Level>0</Level><TimeCreated SystemTime="2026-10-11T12:00:30.000000000Z"/><EventRecordID>77</EventRecordID></System>
<EventData><Data Name="TargetUserName">bob</Data><Data Name="IpAddress">10.0.0.9</Data><Data Name="LogonType">3</Data><Data Name="Empty"></Data></EventData></Event>`
	recs, err := ParseEventLogXML([]byte(xml))
	if err != nil || len(recs) != 1 {
		t.Fatal(err, recs)
	}
	if f := recs[0].Fields; f["TargetUserName"] != "bob" || f["IpAddress"] != "10.0.0.9" || f["LogonType"] != "3" || len(f) != 3 {
		t.Errorf("%v", f)
	}
	if recs[0].Event.Level != "info" || !strings.Contains(recs[0].Event.Message, "bob") {
		t.Errorf("a level 0 audit event reads as info: %+v", recs[0].Event)
	}
}

func TestAnEmptyLevelListReadsEveryLevelOfASecurityChannel(t *testing.T) {
	q, err := EventLogXPath(nil, []int{4720, 4722}, 100)
	if err != nil || q != "*[System[EventRecordID>100 and (EventID=4720 or EventID=4722)]]" {
		t.Errorf("%q %v", q, err)
	}
}

func TestAnImplausibleAccountNameIsNeverKept(t *testing.T) {
	g := newAggregator(&api.LogAggregate{WindowS: 300, Min: 1, By: []string{"TargetUserName", "IpAddress"}})
	for _, n := range []string{"bob", "Hunter2 is my pass!", "averyveryveryverylongusername", "a b"} {
		g.add(failedLogon(1, t0, n, "1.2.3.4"), t0)
	}
	var all string
	for _, e := range g.flush(t0.Add(time.Hour), nil) {
		all += e.Message + "\n"
	}
	if strings.Contains(all, "Hunter2") || strings.Contains(all, "averyvery") || !strings.Contains(all, "TargetUserName=bob") || !strings.Contains(all, "(not an account name)") {
		t.Errorf("%s", all)
	}
}
