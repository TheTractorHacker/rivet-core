<?php

declare(strict_types=1);

namespace RivetCore\Rmm;

use RivetCore\Rmm\Contracts\RmmModuleStateInterface;
use RivetCore\Rmm\Http\DeviceApi;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;

/**
 * The effective module switch, answered from the state file when there is a valid one and from the settings row otherwise (design
 * 12.2): `enabled() = editionAllows() AND master`, a feature is on when the module is on and its sub-switch is. The answer is cached
 * for the rest of the request. When the file is unknown (missing, garbled, stale version) the database answers, and the file is
 * rewritten from the settings row on the spot, so the next request is the cheap one again.
 *
 * {@see sync()} is the writer. It is called by {@see RmmSettings} after every change of the master switch, a sub-switch, the limits,
 * the shed level or the ingest mode, and by the edition when ITS kill switch changes (the edition owns that flag, Core cannot
 * observe it). Without a state directory the class degrades to one settings SELECT per request.
 *
 * @api
 */
final class RmmState
{
    /** Settings columns whose change makes the file stale; {@see RmmSettings} calls {@see sync()} when one is written. */
    public const MIRRORED_COLUMNS = ['enabled', 'features_json', 'limits_json', 'shed_level', 'ingest_mode', 'mesh_enabled', 'max_devices'];

    /** @var array<string,mixed>|null */
    private ?array $memo = null;

    public function __construct(
        private readonly RmmSettings $settings,
        private readonly Sql $sql,
        private readonly ?RmmModuleStateInterface $edition = null,
    ) {
    }

    public function directory(): ?string
    {
        return $this->edition === null ? null : $this->edition->stateDirectory();
    }

    /** Edition kill switch AND master switch. */
    public function enabled(): bool
    {
        return $this->editionAllows() && (bool) $this->load()['master'];
    }

    /** True when the module is on and the sub-switch is. */
    public function featureOn(string $feature): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        $f = $this->load()['features'];

        return !empty($f[$feature]);
    }

    /** The load-shedding level in force, 0 when the recorded one has expired (see {@see RmmStateFile::SHED_TTL_S}). */
    public function shedLevel(): int
    {
        $m = $this->load();

        return $m['from_file'] === true ? RmmStateFile::shedLevel($m['file'], $this->sql->time()) : (int) $m['shed'];
    }

    /** Drop the per-request cache (after the state changed inside this request). */
    public function forget(): void
    {
        $this->memo = null;
    }

    /**
     * Mirror the settings row (and the edition's kill switch) into the state file. Returns false when there is no directory or it is
     * not writable; the module keeps working either way. Always reads the row fresh. A non-zero shed level keeps its original timestamp
     * (so its expiry is measured from when it was set) unless $refreshShed says the level was just re-confirmed.
     */
    public function sync(bool $refreshShed = false): bool
    {
        $this->memo = null;
        $dir = $this->directory();
        if ($dir === null) {
            return false;
        }
        $snap = $this->snapshot($this->settings->get(true), $refreshShed ? null : RmmStateFile::read($dir));

        return RmmStateFile::write($dir, $snap, $this->sql->time());
    }

    private function editionAllows(): bool
    {
        if ($this->edition === null) {
            return true;
        }
        try {
            return $this->edition->editionAllows();
        } catch (\Throwable) {
            return false;   // the contract says false on any failure
        }
    }

    /**
     * @param array<string,mixed> $row endpoint_agent_settings
     * @param array<string,mixed>|null $previous the file as it was, to keep the shed timestamp of an unchanged level
     * @return array<string,mixed>
     */
    private function snapshot(array $row, ?array $previous): array
    {
        $master = (int) ($row['enabled'] ?? 0) === 1;
        $edition = $this->editionAllows();
        $limits = $this->settings->limits();
        $shed = max(0, min(3, (int) ($row['shed_level'] ?? 0)));
        $shedAt = 0;
        if ($shed > 0) {
            $shedAt = ($previous !== null && $previous['shed'] === $shed && $previous['shed_at'] > 0) ? (int) $previous['shed_at'] : $this->sql->time();
        }
        $mode = (string) ($row['ingest_mode'] ?? 'sync');

        return [
            'enabled' => $master && $edition,
            'edition' => $edition,
            'master' => $master,
            'features' => $this->settings->features(),
            'shed' => $shed,
            'shed_at' => $shedAt,
            'shed_retry' => [$limits['shed_retry_min_s'], $limits['shed_retry_max_s']],
            'retry_after' => DeviceApi::DISABLED_RETRY_AFTER_S,
            'ingest_mode' => in_array($mode, RmmSettings::INGEST_MODES, true) ? $mode : 'sync',
            'limits' => $limits,
        ];
    }

    /**
     * @return array{master:bool,features:array<string,bool>,shed:int,from_file:bool,file:?array<string,mixed>}
     */
    private function load(): array
    {
        if ($this->memo !== null) {
            /** @var array{master:bool,features:array<string,bool>,shed:int,from_file:bool,file:?array<string,mixed>} */
            return $this->memo;
        }
        $dir = $this->directory();
        $file = RmmStateFile::read($dir);
        if ($file !== null) {
            $out = ['master' => $file['master'], 'features' => $file['features'], 'shed' => $file['shed'], 'from_file' => true, 'file' => $file];
        } else {
            $row = $this->settings->get();
            $out = ['master' => (int) ($row['enabled'] ?? 0) === 1, 'features' => $this->settings->features(), 'shed' => (int) ($row['shed_level'] ?? 0), 'from_file' => false, 'file' => null];
            if ($dir !== null) {
                RmmStateFile::write($dir, $this->snapshot($row, null), $this->sql->time());
            }
        }
        $this->memo = $out;

        return $out;
    }
}
