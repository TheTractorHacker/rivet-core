# Clock and time zone

An RMM agent stamps every sample, job report and log record with the time on the PC. If the PC's clock is wrong the server cannot accept those times.
Up to rc.22 a PC more than five minutes ahead was refused at every check-in and the console only said "OS and agent not reported yet". Since rc.23:

* the server accepts a clock up to **15 minutes** ahead;
* beyond that the refusal says so in the answer (`server_time` and `skew_s`, see [PROTOCOL.md 3.2.8](PROTOCOL.md));
* the **agent corrects itself**: it measures the difference from the server's answers, stamps what it sends with the server-aligned time, retries once at once after a refusal, and writes a warning to its log once an hour
  ("this PC's clock is N minutes ahead of the server; times are being corrected. Fix the PC's time zone and time sync");
* the device page shows the measured difference and, for a device that has never checked in, why the last check-in was refused;
* `rmm.device.clock_skew` fires at most once a day per device when the difference exceeds `clock_skew_alert_s` (default 120 s; set it to 0 in the limits to turn the event off).

The correction keeps the data usable. It does not fix the PC: a wrong clock also breaks Kerberos, TLS and every log timestamp, so fix the cause.

## Fixing the PC (Windows, from an elevated prompt)

```
tzutil /g                  :: the time zone the PC uses now (for example "Pacific Standard Time")
tzutil /s "Central Standard Time"   :: set the right zone (tzutil /l lists the names)
net start w32time          :: make sure the time service runs
w32tm /query /status       :: source, last successful sync
w32tm /config /syncfromflags:manual /manualpeerlist:"time.windows.com" /update
w32tm /resync /force       :: sync now
```

A PC that is a few hours off is almost always a wrong time zone (UTC kept as local time, or the zone of the image's origin); a few minutes is a drifting clock with no working sync (a VM or a domain with an unreachable time source).
On Linux: `timedatectl status`, `timedatectl set-timezone Zone/Name`, `timedatectl set-ntp true`.

## What the agent does and does not do

* It never changes the PC's clock or zone.
* It applies a correction only when the difference exceeds 60 seconds, never more than 24 hours, and only to what it sends. The server still checks every time against its own clock and window.
* The estimate is kept in the agent's state directory (`clock_skew_s`), so a restart, or the service starting right after `enroll`, stamps correctly from the first check-in.
* An agent older than rc.23 has none of this and is still limited by the server window (now 15 minutes).
* **Signed job expiry uses a trusted floor, not the correction alone (CORE-AGENT-3).** The correction comes from the unsigned `server_time` of the
  server's answers (or their `Date` header). A response that claimed a time far in the past could otherwise move the agent's clock back and make a signed
  job that expired hours ago look current again (red team 2026-11-21, Chain H). The agent therefore checks a job's `expires_at` and `issued_at` against
  the later of the corrected clock and the **server-time floor**: the latest server time it has ever seen, plus the time elapsed since on the PC's
  monotonic clock. The floor only moves forward and is kept in `state.json` (`server_time_floor`, written at most every 5 minutes; a new enrolment starts a
  new one). A PC whose clock or zone is wrong is unaffected (its offset and the floor come from the same answers). A server time that goes backwards by
  more than 10 minutes is logged once an hour ("the server's time went backwards"). Side effect to know: if the SERVER's clock was ahead and is
  corrected backwards by more than a job's lifetime, jobs created in the next stretch (as long as the correction) are refused as expired on devices that
  saw the wrong time; they can be queued again once that window has passed.
