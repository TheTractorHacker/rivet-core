<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Scripts;

use RivetCore\Rmm\Fields\CustomFieldService;
use RivetCore\Rmm\Fields\FieldPlaceholders;
use RivetCore\Rmm\Job\JobExtras;
use RivetCore\Rmm\Job\JobService;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Rmm\Support\RmmEventPublisher;

/**
 * Turns a library script and the caller's parameters into a job for one device: checks the platform, fills `{{field.name}}` placeholders from the
 * custom fields that apply to THAT device, validates the result against the script's parameter definition, keeps secret parameters out of the job row
 * and queues the job with its sidecar ({@see JobExtras}). Authorization, approval and audit are the caller's.
 *
 * @api
 */
final class ScriptRunner
{
    public function __construct(
        private readonly JobService $jobs,
        private readonly CustomFieldService $fields,
        private readonly ?RmmEventPublisher $events = null,
    ) {
    }

    /**
     * Run a batch of dispatches (a transaction over a page of devices): the `rmm.script.run` events of the jobs in it are delivered when it has
     * committed, and dropped when it rolled back.
     *
     * @template T
     * @param \Closure():T $work
     * @return T
     */
    public function batch(\Closure $work): mixed
    {
        $this->events?->hold();
        try {
            $r = $work();
        } catch (\Throwable $e) {
            $this->events?->discard();

            throw $e;
        }
        $this->events?->release();

        return $r;
    }

    /**
     * Check supplied parameters without a device (an approval request, a schedule): names, types and required parameters, and that a secret
     * parameter holds a `{{field.x}}` placeholder instead of a literal value that would otherwise have to be stored in plain text.
     * Values with placeholders are only fully checked per device, at run time.
     *
     * @param list<array<string,mixed>> $schema
     * @param array<array-key,mixed> $supplied
     */
    public function checkStatic(array $schema, array $supplied, bool $secretsOnlyAsPlaceholders): ?string
    {
        $by = [];
        foreach ($schema as $p) {
            $by[(string) $p['name']] = $p;
        }
        $probe = [];
        foreach ($supplied as $k => $v) {
            $def = $by[(string) $k] ?? null;
            if ($def === null) {
                $probe[$k] = $v;   // an unknown name: validate() below reports it

                continue;
            }
            if (is_string($v) && FieldPlaceholders::names($v) !== []) {
                if ($def['type'] === 'secret' && FieldPlaceholders::whole($v) === null) {
                    return 'A secret parameter takes a single {{field.name}} placeholder and nothing else.';
                }
                // Stand in a value of the right shape so the remaining checks (required, unknown names) still run.
                $probe[$k] = match ($def['type']) {
                    'int' => (int) ($def['min'] ?? 0),
                    'bool' => true,
                    'choice' => (string) ($def['choices'][0] ?? ''),
                    default => 'x',
                };
                continue;
            }
            if ($def['type'] === 'secret' && $secretsOnlyAsPlaceholders && $v !== null && $v !== '') {
                return 'In a scheduled or approved run a secret parameter must be a {{field.name}} placeholder: a literal secret would have to be stored in plain text.';
            }
            $probe[$k] = $v;
        }
        [, , $err] = ParamSchema::validate($schema, $probe);

        return $err;
    }

    /**
     * @param array{script:array<string,mixed>,version:array<string,mixed>,schema:list<array<string,mixed>>} $loaded from {@see ScriptService::load()}
     * @param array<string,mixed> $dev the device row
     * @param array<array-key,mixed> $supplied
     * @return array{ok:true,type:string,script:string,params:array<string,string|int|bool>,secret:array<string,string>,destructive:bool,timeout_s:int}|array{ok:false,error:string}
     */
    public function prepare(array $loaded, array $dev, array $supplied, ?int $timeoutS = null): array
    {
        $script = $loaded['script'];
        $language = (string) $script['language'];
        $os = (string) ($dev['os'] ?? '');
        if (!in_array($os, ScriptLanguage::platforms($language), true)) {
            return ['ok' => false, 'error' => 'That ' . $language . ' script cannot run on a ' . ($os === '' ? 'device of unknown type' : $os . ' device') . '.'];
        }
        $by = [];
        foreach ($loaded['schema'] as $p) {
            $by[(string) $p['name']] = $p;
        }
        $values = null;
        $secret = [];
        $filled = [];
        $wanted = [];
        foreach ($supplied as $v) {
            if (is_string($v)) {
                foreach (FieldPlaceholders::names($v) as $n) {
                    $wanted[$n] = $n;
                }
            }
        }
        foreach ($supplied as $name => $v) {
            $def = $by[(string) $name] ?? null;
            if (!is_string($v) || ($names = FieldPlaceholders::names($v)) === []) {
                $filled[$name] = $v;
                continue;
            }
            $values ??= $this->fields->resolveFor($dev, true, array_values($wanted));
            $whole = FieldPlaceholders::whole($v);
            $map = [];
            foreach ($names as $fn) {
                $f = $values[$fn] ?? null;
                if ($f === null) {
                    return ['ok' => false, 'error' => "The custom field \"$fn\" does not exist."];
                }
                if ($f['type'] === 'secret' && (($def['type'] ?? '') !== 'secret' || $whole === null)) {
                    return ['ok' => false, 'error' => "The secret field \"$fn\" can only fill a secret parameter, alone."];
                }
                if ($f['value'] === null) {
                    return ['ok' => false, 'error' => "The custom field \"$fn\" has no value for this device."];
                }
                $map[$fn] = $f['value'];
            }
            $filled[$name] = FieldPlaceholders::fill($v, $map);
        }
        [$clean, $secretNames, $err] = ParamSchema::validate($loaded['schema'], $filled);
        if ($err !== null || $clean === null) {
            return ['ok' => false, 'error' => $err ?? 'Invalid parameters.'];
        }
        $params = [];
        foreach ($clean as $k => $v) {
            if (in_array($k, $secretNames, true)) {
                $secret[$k] = (string) $v;
                $params[$k] = JobExtras::SECRET_MARK;
            } else {
                $params[$k] = $v;
            }
        }

        return ['ok' => true, 'type' => ScriptLanguage::jobType($language), 'script' => (string) $loaded['version']['body'], 'params' => $params, 'secret' => $secret,
            'destructive' => (int) $script['destructive'] === 1, 'timeout_s' => $timeoutS ?? (int) $script['timeout_s']];
    }

    /**
     * Queue the prepared job. `$origin` is `manual`, `schedule` or `approval` (it goes into the event); `$opts` may carry schedule_id, run_id, approval_id,
     * idem_key and expires_in_s.
     *
     * @param array{script:array<string,mixed>,version:array<string,mixed>,schema:list<array<string,mixed>>} $loaded
     * @param array<string,mixed> $dev
     * @param array{ok:true,type:string,script:string,params:array<string,string|int|bool>,secret:array<string,string>,destructive:bool,timeout_s:int} $prepared
     * @param array{schedule_id?:int,run_id?:int,approval_id?:int,idem_key?:string,expires_in_s?:int} $opts
     * @return array{ok:bool,error?:string,job_id?:string,duplicate?:bool}
     */
    public function dispatch(array $loaded, array $dev, array $prepared, int $userId, string $origin, array $opts = []): array
    {
        $extra = ['script_id' => (int) $loaded['script']['script_id'], 'script_version' => (int) $loaded['version']['version'], 'secret' => $prepared['secret']];
        foreach (['schedule_id', 'run_id', 'approval_id', 'idem_key'] as $k) {
            if (isset($opts[$k])) {
                $extra[$k] = $opts[$k];
            }
        }
        $r = $this->jobs->create($dev, $prepared['type'], $prepared['script'], $prepared['params'], $prepared['timeout_s'], $prepared['destructive'], $userId,
            ['extra' => $extra] + (isset($opts['expires_in_s']) ? ['expires_in_s' => $opts['expires_in_s']] : []));
        if ($r['ok'] && empty($r['duplicate']) && $this->events !== null) {
            $this->events->emit(RmmEvent::SCRIPT_RUN, $dev, ['script_id' => (int) $loaded['script']['script_id'], 'script_name' => (string) $loaded['script']['name'],
                'script_version' => (int) $loaded['version']['version'], 'job_id' => (string) ($r['job_id'] ?? ''), 'origin' => $origin]);
        }

        return $r;
    }
}
