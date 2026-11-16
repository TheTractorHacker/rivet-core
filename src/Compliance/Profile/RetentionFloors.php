<?php

declare(strict_types=1);

namespace RivetCore\Compliance\Profile;

use RivetCore\Rmm\Settings\RetentionFloorsInterface;

/**
 * The retention minimums of the selected profile, as the RMM settings need them ({@see RetentionFloorsInterface}): pass it to the module as the
 * `retention_floors` option, or to {@see \RivetCore\Rmm\Settings\RmmSettings::setRetentionFloors()}. Read once per instance (build one per request), through
 * the single-query {@see FrameworkProfileStore::retentionFloors()}. Where an edition has a cache it can pass its own loader to {@see self::fromLoader()}.
 *
 * It never throws: if the profile tables cannot be read (migration 0046 not applied yet, database trouble) it logs once, WITH the error code, and answers
 * "no floor", so a code-before-migration deploy keeps working. It remembers the failure ({@see self::unavailable()}) so that a deleting caller (the RMM
 * housekeeping) can fail closed and skip the prune instead of pruning below a framework minimum it could not read (CORE-39). The compliance checks read
 * the profile directly and DO report such a failure.
 *
 * @api
 */
final class RetentionFloors implements RetentionFloorsInterface
{
    /** @var array<string,int>|null */
    private ?array $floors = null;
    private ?string $label = null;
    private bool $failed = false;

    /**
     * @param \Closure(): array<string,int> $loadFloors data class => minimum days
     * @param \Closure(): string $loadLabel the profile's display name, called only when a message is built
     */
    private function __construct(private readonly \Closure $loadFloors, private readonly \Closure $loadLabel)
    {
    }

    public static function fromStore(FrameworkProfileStore $store): self
    {
        return new self($store->retentionFloors(...), static fn (): string => $store->selectedProfile()?->displayName() ?? 'the selected framework');
    }

    /**
     * @param \Closure(): array<string,int> $loadFloors
     * @param \Closure(): string $loadLabel
     */
    public static function fromLoader(\Closure $loadFloors, \Closure $loadLabel): self
    {
        return new self($loadFloors, $loadLabel);
    }

    public static function fromProvider(RequirementsProviderInterface $source): self
    {
        return new self(static fn (): array => $source->selectedProfile()?->retentionFloors() ?? [], static fn (): string => $source->selectedProfile()?->displayName() ?? 'the selected framework');
    }

    /** @return array<string,int> */
    public function all(): array
    {
        if ($this->floors === null) {
            try {
                $this->floors = ($this->loadFloors)();
            } catch (\Throwable $e) {
                // The code is the database errno (1146 table missing, 2006 gone away, ...): without it a transient failure cannot be told from a missing migration. It holds no secret.
                error_log('compliance retention floors unavailable (treated as none): ' . get_class($e) . ' code ' . (string) $e->getCode());
                $this->floors = [];
                $this->failed = true;
            }
        }

        return $this->floors;
    }

    /**
     * True when the floors could not be read (the last read failed, so "no floor" is a guess, not a fact). A caller that DELETES data must not treat that
     * as "nothing is required" ({@see \RivetCore\Rmm\Settings\RmmSettings::retentionFloorsUnavailable()}). Reads the floors first when that has not happened yet.
     */
    public function unavailable(): bool
    {
        $this->all();

        return $this->failed;
    }

    public function floorDays(string $dataClass): int
    {
        return $this->all()[$dataClass] ?? 0;
    }

    /** The retention actually applied: the configured value, raised to the floor. A class without a floor is returned as configured. */
    public function effectiveDays(string $dataClass, int $configuredDays): int
    {
        $floor = $this->floorDays($dataClass);

        return $floor > 0 ? max($configuredDays, $floor) : $configuredDays;
    }

    public function requiredBy(): string
    {
        if ($this->label === null) {
            try {
                $this->label = ($this->loadLabel)();
            } catch (\Throwable) {
                $this->label = 'the selected framework';
            }
        }

        return $this->label;
    }

    /** Forget what was read (a long-running worker calls this after the selection may have changed). */
    public function refresh(): void
    {
        $this->floors = null;
        $this->label = null;
        $this->failed = false;
    }
}
