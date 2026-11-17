# Windows test plan: endpoint-agent fixes from the 2026-11-15 deep scan (CORE-DS-*)

**Status: UNVERIFIED on Windows.** Every item below was written and `GOOS=windows go vet`/`go build` checked on Linux only; the platform-independent logic has Linux unit tests. Nobody has run these on a real Windows machine. Use the same disposable VM setup as `AGENT_STATE_DIRECTORY_WINDOWS_TEST.md` (administrator `admin1`, standard user `user1`, service `RivetITAgent`, state `C:\ProgramData\RivetIT\Agent`). Record results next to each item.

| ID | What to verify | Result |
|---|---|---|
| CORE-DS-11 | Install agent A (previous release), let it self-update to the new build. Before the update, as `user1`: `mkdir C:\ProgramData\RivetIT\x`. After the swap the new agent refuses the state directory; expect it to exit 75 up to 3 times and then roll back to `rivetit-agent.exe.prev` (the log or Application event shows "new version cannot start; rolled back"), the service runs the old version again and `update.json` reads `rolled_back`. Also confirm a normal update (no planted folder) still confirms and drops `update.json`. | not run |
