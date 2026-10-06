# Cron

`RivetCore\Cron`: `JobRunner` starts an allowlisted script in the background for an admin "Run now" button; `JobCatalog` holds the
edition's plain-language facts about its cron scripts. Locking so two copies of a cron job do not overlap is
`Redis\CronGuard`, see [redis.md](redis.md).

## What it owns

No tables. `JobRunner` keeps a PID file and a log per job key in a state directory (default `sys_get_temp_dir()/rivetcore-jobs-<uid>`;
an edition may pin its own by subclassing, which is why the class is not final).

## You supply

The application root (`new JobRunner($appRoot)`), and the catalog entries (the edition's data): which scripts exist, what they do,
and which need arguments and therefore cannot be started from the UI.

## Flags

None.

## Use it

<!-- run -->
```php
use RivetCore\Cron\{JobCatalog, JobRunner};

$root = sys_get_temp_dir() . '/rc-doc-cron-' . getmypid();
mkdir($root . '/cron', 0777, true);
file_put_contents($root . '/cron/hello.php', "<?php echo 'hello from cron';");

$runner = new JobRunner($root);
$script = $root . '/cron/hello.php';
$result = $runner->start('hello', $script, [], PHP_BINARY);
for ($i = 0; $i < 60 && $runner->state('hello', $script)['running']; $i++) { usleep(50000); }
$state = $runner->state('hello', $script);                    // running, pid, started_at, finished_at, exit, lines
echo $result['message'], ' exit=', var_export($state['exit'], true), ' output: ', implode(' | ', $state['lines']), "\n";
array_map('unlink', glob($root . '/cron/*') ?: []);
```

## How it fails

`start()` never throws for bad input; it returns `['ok' => false, 'message' => ...]` and refuses:

- a script that does not resolve to a file under `<appRoot>/cron/` or `<appRoot>/scripts/`;
- a PHP binary other than `/usr/bin/php[version]` or the running `PHP_BINARY`;
- any argument that is not `--name` or `--name=value` with a restricted character set;
- a job already running under the same key;
- a state directory that is not a real directory owned by the current user, not group/world writable.

The log is created exclusively after removing whatever sat at that path, so a planted symlink is not written through.
Nothing takes a command from a request. Failure of the script itself shows up as an `[exit N]` line at the end of the log.
