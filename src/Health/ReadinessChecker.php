<?php

declare(strict_types=1);

namespace RivetCore\Health;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Redis\RedisClientProviderInterface;

/**
 * Readiness report: the database answers and its schema matches the code. Redis is reported but never fails
 * readiness - every Redis feature fails open. Integrations are deliberately not checked. The report carries
 * only ok/fail per check: no hostnames, versions or error text.
 *
 * @api
 */
final class ReadinessChecker
{
    /** @param callable(): bool $schemaIsCurrent edition-supplied: does the stored schema version match this code? */
    public function __construct(
        private DatabaseInterface $database,
        private $schemaIsCurrent,
        private ?RedisClientProviderInterface $redis = null,
    ) {
    }

    /** @return array{ready:bool, checks:array{database:string, schema:string, redis:string}} */
    public function check(): array
    {
        $checks = ['database' => 'fail', 'schema' => 'fail', 'redis' => 'unavailable'];

        try {
            $this->database->fetchOne('SELECT 1 AS ok');
            $checks['database'] = 'ok';
            if (($this->schemaIsCurrent)()) {
                $checks['schema'] = 'ok';
            }
        } catch (\Throwable) {
            // reported as fail
        }

        try {
            $client = $this->redis?->client();
            if ($client && (string) $client->ping() === 'PONG') {
                $checks['redis'] = 'ok';
            }
        } catch (\Throwable) {
            // reported as unavailable
        }

        return ['ready' => $checks['database'] === 'ok' && $checks['schema'] === 'ok', 'checks' => $checks];
    }
}
