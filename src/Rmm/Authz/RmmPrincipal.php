<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Authz;

/**
 * Who is acting: the authenticated technician or administrator the edition hands to the technician API and the action services.
 * Deliberately minimal: roles and client scope are not carried here, they are decided on every call by the edition's
 * AccessPolicy and RmmTenancy, so a stale object can never grant anything.
 *
 * @api
 */
final class RmmPrincipal
{
    /**
     * @param int $userId the edition's user id (greater than zero)
     * @param string $userName display name, used for audit text and the MeshCentral account template
     */
    public function __construct(public readonly int $userId, public readonly string $userName = '')
    {
    }
}
