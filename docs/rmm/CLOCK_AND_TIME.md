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
