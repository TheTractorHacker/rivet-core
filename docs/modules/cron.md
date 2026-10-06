# Cron

## Overview

`RivetCore\Cron` backs an admin "Cron Manager": it describes an edition's cron scripts in plain language and starts an allowlisted script in the background on demand ("Run now"), reporting its state and output.

- **Owns no tables and no migration.**
- `JobCatalog` holds the edition's script facts and decides whether the UI may offer a Run-now button.
- `JobRunner` starts a script, tracks its PID and log file, and reports state.
- It does not schedule anything. Schedules live in the edition's root-owned `cron.d` entries.

## Contracts an edition must implement

No interfaces. The edition supplies data and paths:

- **Catalog entries** (`array<string, array{label:string, description:string, run_now:bool, note:string, dir?:string}>`), keyed by script file name. `dir` defaults to `cron`; the other allowed directory is `scripts`.
- **`needsArguments`**: list of scripts that only run from a schedule line that supplies arguments; they never get a Run-now button.
- **`appRoot`**: the application root. Scripts must resolve under `<appRoot>/cron/` or `<appRoot>/scripts/`.
- Optionally a **state directory** (see Configuration). `JobRunner` is deliberately not final so an edition can subclass it to pin its default directory.

## Key classes

```php
use RivetCore\Cron\JobCatalog;
use RivetCore\Cron\JobRunner;

$catalog = new JobCatalog(
    ['sync.php' => ['label' => 'Sync', 'description' => 'Directory sync', 'run_now' => true, 'note' => '']],
    ['report.php'],                       // needs arguments, not startable from the UI
);
$catalog->dir('sync.php');                // 'cron'
$catalog->describe('unknown.php');        // fallback entry with run_now = false
$catalog->needsArguments('report.php');   // true

$runner = new JobRunner('/var/www/app', '/var/lib/app/jobs');
$runner->start('sync', '/var/www/app/cron/sync.php', ['--days=3']);   // ['ok'=>bool, 'message'=>string]
$runner->state('sync', '/var/www/app/cron/sync.php');
// ['running'=>bool, 'pid'=>?int, 'started_at'=>?int, 'finished_at'=>?int, 'exit'=>?int, 'lines'=>list<string>]
```

Static helpers parse a `cron.d` command line the caller already trusts:

```php
JobRunner::argumentsFromCommand('/usr/bin/php /v/cron/x.php --full --days=3 >> /var/log/x.log 2>&1', 'x.php');
// ['--full', '--days=3']; null if any argument is not --name[=value]
JobRunner::logPathFromCommand($command);   // /var/log/<name> if redirected there, else null
JobRunner::phpBinaryFromCommand($command); // /usr/bin/php[N.N] or the default
JobRunner::tail($path, 8);                 // last lines, control characters stripped, 300 chars per line
```

`start()` runs `nohup sh -c "php script > log 2>&1; printf '\n[exit N]\n' >> log"` in the script's directory. `state()` reads the `[exit N]` marker from the log tail and checks `/proc/<pid>/cmdline` to see whether it still runs.

## Configuration

- `new JobRunner(string $appRoot, ?string $stateDir = null)`. Default state directory is `<sys_get_temp_dir()>/rivetcore-jobs-<uid>`, created with mode 0700.
- The state directory must be a real directory (not a symlink), owned by the current user, and not group/world writable. Otherwise `start()` refuses.
- `start($key, $script, $args = [], $php = '/usr/bin/php')`: `$key` is reduced to `[A-Za-z0-9_-]` and names the `.pid` and `.log` files.
- Accepted PHP binary: `/usr/bin/php[0-9.]*` or `PHP_BINARY`.

## How it fails

`start()` never throws for bad input; it returns `['ok' => false, 'message' => ...]`:

- `That job script was not found.` (missing, or outside `cron/` and `scripts/`, after `realpath`).
- `Unexpected PHP binary.`, `Unexpected argument.`
- `This job is already running.`
- `The job state directory is not private; refusing to start.`
- `Could not create the job log.` and `The job did not start.` (no PID file within about one second).

`JobCatalog::describe()` returns a safe "Custom or unrecognized job" entry with `run_now = false` for scripts it does not know. `tail()` and `state()` return empty results for missing or unreadable files.

## Security notes

- Nothing takes a path or command from a request. The caller passes a script that already matched an allowlisted `cron.d` line or a catalog entry.
- Arguments must match `^--[a-z][a-z-]*(=[A-Za-z0-9_.,-]+)?$`; everything is passed through `escapeshellarg`.
- The log is created exclusively (`x` mode) after removing whatever sits at that path, so a planted symlink cannot be written through; mode 0600.
- A script that sends real mail, writes to outside systems or needs arguments should be marked `run_now => false` with a note, so the UI shows the reason instead of a button.
- Log lines shown in the UI are stripped of control characters.

## Used by

- **RivetIT:** `src/Cron/JobRunner.php` (final subclass that pins RivetIT's state directory) and `src/Cron/JobCatalog.php` (RivetIT's job list wrapped around `RivetCore\Cron\JobCatalog`).
- **RivetMSP:** `src/Cron/JobRunner.php` and `src/Cron/JobCatalog.php`, same pattern with RivetMSP's own state directory and job list.
- The admin Cron Manager pages in both editions consume these wrappers.

## Links

- CHANGELOG: 0.3.0 (`JobRunner`, `JobCatalog`; exit marker fix), 0.18.1 (per-user state directory, private-directory check, exclusive log creation). See [../../CHANGELOG.md](../../CHANGELOG.md).
- Related: [redis.md](redis.md) (`CronGuard` stops two copies of one script), [jobs.md](jobs.md).
- Tests: `tests/Unit/CronTest.php`.
