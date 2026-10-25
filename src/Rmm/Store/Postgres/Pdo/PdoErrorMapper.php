<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Postgres\Pdo;

use RivetCore\Rmm\Store\Postgres\PostgresConnectionException;
use RivetCore\Rmm\Store\Postgres\PostgresException;
use RivetCore\Rmm\Store\Postgres\PostgresQueryException;
use RivetCore\Rmm\Store\Postgres\PostgresSerializationException;
use RivetCore\Rmm\Store\Postgres\PostgresTimeoutException;

/**
 * Turns the driver's exception into the library's: the class says what to do (connection: the store is down; timeout; deadlock: retry; query: a bug), the
 * message is the server's primary message only (no DETAIL or CONTEXT line, which carry the data of the failed row) with quoted values masked for data
 * exceptions, and never contains the password. The driver's exception is NOT kept as `previous`: its message still has the DETAIL line and its trace holds the bound
 * values, and anything that logs a whole exception chain (an error tracker, a global handler) would write them out.
 *
 * @internal
 */
final class PdoErrorMapper
{
    /** SQLSTATEs that mean "the server or the session is gone" besides the whole class 08. */
    private const CONNECTION_STATES = ['57P01', '57P02', '57P03', '53300', '53200', '53100', '53400', '58000', '58030', '3D000', '28000', '28P01'];

    /** Messages of failures the driver reports without a usable SQLSTATE. */
    private const CONNECTION_TEXT = ['server closed the connection', 'no connection to the server', 'terminating connection', 'could not receive data', 'could not send data',
        'SSL SYSCALL', 'server has gone away', 'connection to server', 'Connection refused', 'Connection timed out', 'could not translate host name', 'could not connect',
        'no route to host', 'Network is unreachable', 'timeout expired', 'connection lost', 'EOF detected'];

    public static function map(\PDOException $e, #[\SensitiveParameter] string $secret = ''): PostgresException
    {
        $info = is_array($e->errorInfo) ? $e->errorInfo : [];
        $state = isset($info[0]) && is_string($info[0]) ? $info[0] : (is_string($e->getCode()) ? $e->getCode() : '');
        if (preg_match('/^[0-9A-Z]{5}$/D', $state) !== 1) {
            $state = '';
        }
        $text = isset($info[2]) && is_string($info[2]) ? $info[2] : $e->getMessage();
        $message = self::clean($text, $state, $secret);
        $class = substr($state, 0, 2);
        if ($class === '08' || in_array($state, self::CONNECTION_STATES, true) || ($state === '' && self::looksLikeConnectionLoss($text)) || ($state === 'HY000' && self::looksLikeConnectionLoss($text))) {
            return new PostgresConnectionException('PostgreSQL connection failed: ' . $message, $state);
        }
        // A row routed to the DEFAULT partition just before an ATTACH PARTITION made that range someone else's: the server refuses it with a check violation on the default
        // partition. Nothing is wrong with the row; sent again, it goes to the new partition. Same treatment as a deadlock.
        if ($state === '40001' || $state === '40P01' || ($state === '23514' && str_contains($text, 'violates partition constraint'))) {
            return new PostgresSerializationException('PostgreSQL transaction rolled back (' . $message . ')', $state);
        }
        if ($state === '57014' || $state === '55P03' || $state === '55006') {
            return new PostgresTimeoutException('PostgreSQL statement timed out or could not get its lock (' . $message . ')', $state);
        }

        return new PostgresQueryException('PostgreSQL rejected the statement: ' . $message . ($state === '' ? '' : ' (SQLSTATE ' . $state . ')'), $state);
    }

    public static function looksLikeConnectionLoss(string $text): bool
    {
        foreach (self::CONNECTION_TEXT as $needle) {
            if (stripos($text, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private static function clean(string $text, string $state, #[\SensitiveParameter] string $secret): string
    {
        $first = trim(explode("\n", $text)[0]);
        $first = (string) preg_replace('/^(SQLSTATE\[[0-9A-Z]{5}\]:?\s*(\[[0-9]+\])?\s*)/', '', $first);
        $first = (string) preg_replace('/^(ERROR|FATAL|PANIC):\s*/', '', $first);
        if ($secret !== '') {
            $first = str_replace($secret, '***', $first);
        }
        if (str_starts_with($state, '22')) {
            $first = (string) preg_replace(['/"[^"]*"|\'[^\']*\'/', '/0x[0-9a-fA-F]{2}( 0x[0-9a-fA-F]{2})*/'], ['"…"', '0x…'], $first);   // a data exception quotes the offending value or its bytes
        }
        $first = (string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $first);

        return mb_substr($first, 0, 200);
    }
}
