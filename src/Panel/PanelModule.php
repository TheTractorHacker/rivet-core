<?php

declare(strict_types=1);

namespace RivetCore\Panel;

use RivetCore\Contracts\ClockInterface;
use RivetCore\Database\DatabaseInterface;
use RivetCore\Panel\Contracts\PanelTicketGatewayInterface;
use RivetCore\Panel\Http\PanelApi;
use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Contracts\RmmTenancyInterface;
use RivetCore\Rmm\Enrollment\AttemptLog;
use RivetCore\Rmm\Support\Sql;

/**
 * Wires the RivetPanel API from the ports an edition supplies. It shares the RMM tables (devices, enrollment tokens, attempt log), the tenancy and audit ports and
 * the rate limiter closure, but not the RMM module, so it works in an edition that has the RMM module switched off.
 *
 * Options: `limits` ({@see PanelLimits} or an array for {@see PanelLimits::fromArray()}), `enabled` (\Closure():bool), `allow_insecure_http` (bool, loopback tests only).
 *
 * @api
 */
final class PanelModule
{
    private ?Sql $sql = null;
    private ?PanelSettings $settings = null;
    private ?PanelLimits $limits = null;
    private ?PanelEnrollment $enrollment = null;
    private ?PanelService $service = null;

    /** @param array<string,mixed> $options */
    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly ClockInterface $clock,
        private readonly RmmTenancyInterface $tenancy,
        private readonly RmmAuditInterface $audit,
        private readonly PanelTicketGatewayInterface $gateway,
        private readonly array $options = [],
    ) {
    }

    public function sql(): Sql
    {
        return $this->sql ??= new Sql($this->database, $this->clock);
    }

    public function limits(): PanelLimits
    {
        $o = $this->options['limits'] ?? null;

        return $this->limits ??= $o instanceof PanelLimits ? $o : PanelLimits::fromArray(is_array($o) ? $o : []);
    }

    public function settings(): PanelSettings
    {
        return $this->settings ??= new PanelSettings($this->sql(), $this->audit);
    }

    public function enrollment(): PanelEnrollment
    {
        return $this->enrollment ??= new PanelEnrollment($this->sql(), $this->tenancy, $this->gateway, $this->settings(), $this->audit, new AttemptLog($this->sql()), $this->limits());
    }

    public function authenticator(): PanelAuthenticator
    {
        return new PanelAuthenticator($this->sql(), $this->gateway, $this->tenancy);
    }

    public function service(): PanelService
    {
        return $this->service ??= new PanelService($this->sql(), $this->gateway, $this->settings(), $this->tenancy, $this->audit, $this->limits());
    }

    public function admin(): PanelAdmin
    {
        return new PanelAdmin($this->sql(), $this->audit);
    }

    /** @param \Closure(string,int,int):bool $rateLimit (bucket, limit, windowSeconds): true when within budget (the same closure shape the RMM device API takes) */
    public function api(\Closure $rateLimit): PanelApi
    {
        $enabled = $this->options['enabled'] ?? null;

        return new PanelApi($this->sql(), $this->enrollment(), $this->authenticator(), $this->service(), $this->limits(), $rateLimit,
            $enabled instanceof \Closure ? $enabled : null, (bool) ($this->options['allow_insecure_http'] ?? false));
    }
}
