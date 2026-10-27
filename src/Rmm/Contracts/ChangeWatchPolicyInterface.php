<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Contracts;

/**
 * Optional: the say an edition (or Core's own default, {@see \RivetCore\Rmm\Change\ChangeWatchPolicy}) has over WHAT a device may be told to watch (change monitoring, the
 * `change_monitor` switch). Core asks it when an administrator saves a custom watch, again when the signed configuration for a device is built (so a rule that was tightened
 * stops watches that were already saved), and again for every change record a device reports (so a rogue device cannot record a change outside what it was told to watch).
 *
 * This is a privacy boundary: a registry key or file that holds credentials must never be read, stored or shown. What every policy must do (the conformance kit
 * {@see \RivetCore\Testing\ChangeWatchPolicyConformanceTestCase} checks it): answer a bool and never throw, whatever the text; answer the same to the same question;
 * refuse the credential hives (SAM, SECURITY and the LSA secret keys) in every spelling, a whole hive, a relative or traversing path, control characters and NUL; refuse
 * private keys, shadow files and other credential stores of the file system; and not change any of this because of the client id it is given.
 *
 * @api
 */
interface ChangeWatchPolicyInterface
{
    /**
     * @param string $kind `registry` (a key path such as `HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Run`) or `file` (an absolute path to a file or a folder)
     * @param string $target the key path or file path
     * @param int $clientId the client the device belongs to (0 when the question is not about a client, e.g. when a custom watch is saved)
     */
    public function allows(string $kind, string $target, int $clientId): bool;
}
