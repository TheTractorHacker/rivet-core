<?php

declare(strict_types=1);

namespace RivetCore\Panel;

/**
 * Wire constants of the RivetPanel device-token API. The two token shapes are deliberately different from the RMM ones
 * (`rvte1.` enrollment, 64 alphanumerics device) so a token of one family can never even parse in the other family's endpoints.
 *
 * @api
 */
final class PanelProtocol
{
    /** `kind` column value of a panel device and of a panel enrollment token ('agent' is every RMM row). */
    public const KIND = 'panel';
    public const KIND_AGENT = 'agent';
    /** `link_state` of a panel device row: it has no asset, so every RMM automation that requires 'linked' leaves it alone. */
    public const LINK_STATE = 'panel';

    /** `rvtp1.<12 hex>.<40 hex>`, single use. */
    public const ENROLL_TOKEN_PREFIX = 'rvtp1';
    public const ENROLL_TOKEN_SELECTOR_RE = '/^[0-9a-f]{12}$/';
    public const ENROLL_TOKEN_SECRET_RE = '/^[0-9a-f]{40}$/';
    /** The per-device bearer credential: `rvpd_` + 64 lower-case hex. */
    public const DEVICE_TOKEN_PREFIX = 'rvpd_';
    public const DEVICE_TOKEN_BEARER_RE = '/^Bearer\s+(rvpd_[0-9a-f]{64})$/';
    public const DEVICE_TOKEN_VALID_DAYS = 365;
    public const INSTALL_ID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    public const REQUEST_ID_RE = self::INSTALL_ID_RE;

    /** A ticket-create claim with no ticket after this long is treated as abandoned and may be taken over by a retry; an in-flight claim younger than this answers 409 `request_in_progress` with this Retry-After. */
    public const RESERVATION_TTL_S = 300;
    public const IN_PROGRESS_RETRY_AFTER_S = 2;

    public const ENROLL_DEFAULT_TTL_H = 24;
    public const ENROLL_MAX_TTL_H = 72;
    /** @internal the longest a multi-use (fleet) enrollment token lives, whatever {@see PanelLimits::$enrollMaxTtlH} says (1.0.0-rc.38) */
    public const FLEET_TOKEN_MAX_TTL_H = 72;
    public const ENROLL_MAX_BODY = 8192;
    public const ENROLL_RATE_SALT = 'panel-enroll|';
    public const ENROLL_RATE_WINDOW_S = 600;
    public const ENROLL_RATE_MAX_FAILURES = 10;
    public const ENROLL_RATE_MAX_ATTEMPTS = 60;

    /** [calls, window seconds] per device. */
    public const RATE_READ = [120, 60];
    public const RATE_CREATE = [10, 60];
    public const RATE_CHAT = [20, 60];
    /** Failed credential checks per client address (counted only on failure). */
    public const RATE_AUTH_FAIL = [30, 60];

    public const OSES = ['windows', 'linux', 'macos'];

    public const DISABLED_RETRY_AFTER_S = 3600;
}
