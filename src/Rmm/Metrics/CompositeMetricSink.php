<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Metrics;

use RivetCore\Rmm\Contracts\RmmMetricSinkInterface;

/**
 * Sends the same samples to several sinks in order, each one isolated: an exception from a sink is caught and recorded in
 * {@see failures()} and never stops the others and never propagates, EXCEPT for a sink flagged primary, whose first exception is rethrown
 * after every sink has run. Typical wiring: the database sink as primary (it feeds the live pages) and the external buffer as a
 * secondary (its trouble must not fail a check-in).
 *
 * @api
 */
final class CompositeMetricSink implements RmmMetricSinkInterface
{
    private const MAX_FAILURES = 100;

    /** @var list<array{sink:string, message:string}> */
    private array $failures = [];

    /**
     * @param list<array{0:RmmMetricSinkInterface, 1:bool}> $sinks [sink, primary]
     */
    public function __construct(private readonly array $sinks)
    {
    }

    /**
     * The first member sink of the given class (so the module can find the database sink, and the buffer of the external store, inside a composite).
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T|null
     */
    public function find(string $class): ?object
    {
        foreach ($this->sinks as [$sink]) {
            if ($sink instanceof $class) {
                return $sink;
            }
        }

        return null;
    }

    public function ingest(array $samples, int $integrationId): void
    {
        $primaryFailure = null;
        foreach ($this->sinks as [$sink, $primary]) {
            try {
                $sink->ingest($samples, $integrationId);
            } catch (\Throwable $e) {
                $this->failures[] = ['sink' => $sink::class, 'message' => mb_substr($e->getMessage(), 0, 200)];
                if (count($this->failures) > self::MAX_FAILURES) {
                    array_shift($this->failures);
                }
                if ($primary && $primaryFailure === null) {
                    $primaryFailure = $e;
                }
            }
        }
        if ($primaryFailure !== null) {
            throw $primaryFailure;
        }
    }

    /**
     * The failures caught so far, oldest first (the last 100).
     *
     * @return list<array{sink:string, message:string}>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    public function clearFailures(): void
    {
        $this->failures = [];
    }
}
