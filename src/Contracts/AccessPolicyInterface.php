<?php

declare(strict_types=1);

namespace RivetCore\Contracts;

/**
 * Asks the edition whether someone may do something. Core never encodes an edition's roles: an edition implements this
 * over its own permission helpers and injects it into the services that act on someone's behalf (see ADR-003).
 *
 * Contract (checked by Testing\AccessPolicyConformanceTestCase): can() never throws, whatever the user, ability, subject or context
 * (an unknown subject is a plain "no", not an error), gives the same answer for the same question, and accepts a null user
 * (a system actor). A policy that enforces anything should deny abilities it has no rule for, whoever asks and whatever the
 * context says; the kit checks that when the edition declares it (declaresDenyByDefault()).
 *
 * @api
 */
interface AccessPolicyInterface
{
    /**
     * @param int|null              $userId      the acting user; null for a system actor (cron, automation)
     * @param string                $ability     dotted verb documented by the module, e.g. "itsm.problem.close"
     * @param string|null           $subjectType kind of record, e.g. "problem", "client"
     * @param string|int|null       $subjectId   the record id, so an edition can check per-client access
     * @param array<string, mixed>  $context     anything else the edition may need (client id, acting agent)
     */
    public function can(?int $userId, string $ability, ?string $subjectType = null, string|int|null $subjectId = null, array $context = []): bool;
}
