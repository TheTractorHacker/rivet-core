<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Technician;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmAuthorizer;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Device\DeviceRepository;
use RivetCore\Rmm\Device\DeviceState;
use RivetCore\Rmm\Tags\GroupService;
use RivetCore\Rmm\Tags\TagService;

/**
 * Technician and administrator actions of the Phase 1 inventory area: tags on devices, tag and group management, and asking a device for a
 * fresh software list. Same decision order as {@see TechnicianActions}: no view access at all -> 403, a device that is missing or outside the
 * caller's clients -> the same 404, a missing grant -> 403. Reads need `rmm.device.view` (done by the callers); changing a device's tags needs
 * `rmm.device.manage` for the device's client; creating, renaming and deleting tags and groups needs `rmm.device.manage` as a role
 * (`clientId = 0`); a software refresh needs the same grant as queueing a collect job (`rmm.job.run_saved`).
 *
 * @api
 */
final class InventoryActions
{
    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly RmmAuthorizer $authz,
        private readonly TagService $tags,
        private readonly GroupService $groups,
        private readonly DeviceState $state,
        private readonly RmmAuditInterface $audit,
    ) {
    }

    /** @return ActionResult|null a refusal, or null when the role may manage */
    private function role(int $userId, string $ability): ?ActionResult
    {
        $why = $this->authz->check($userId, $ability, 0);

        return $why === null ? null : ActionResult::fail(403, 'forbidden', $why);
    }

    /**
     * @return array{0:?array<string,mixed>,1:?ActionResult}
     */
    private function device(int $userId, int $deviceId, string $ability): array
    {
        $view = $this->authz->check($userId, RmmAbility::DEVICE_VIEW, 0);
        if ($view !== null) {
            return [null, ActionResult::fail(403, 'forbidden', $view)];
        }
        $dev = $this->devices->find($deviceId);
        if ($dev === null || $this->authz->check($userId, RmmAbility::DEVICE_VIEW, (int) $dev['client_id']) !== null) {
            return [null, ActionResult::fail(404, 'not_found', 'Device not found.')];
        }
        $why = $this->authz->check($userId, $ability, (int) $dev['client_id']);
        if ($why !== null) {
            return [null, ActionResult::fail(403, 'forbidden', $why)];
        }

        return [$dev, null];
    }

    // ------------------------------------------------------------------ tags on a device

    /** @param int|string $tag a tag id, or a name (created when it does not exist) */
    public function tagDevice(RmmPrincipal $who, int $deviceId, int|string $tag): ActionResult
    {
        [$dev, $err] = $this->device($who->userId, $deviceId, RmmAbility::DEVICE_MANAGE);
        if ($err !== null || $dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }
        try {
            $t = $this->tags->assign($deviceId, $tag, 'manual', $who->userId);
        } catch (\InvalidArgumentException $e) {
            return ActionResult::fail(422, 'invalid', $e->getMessage());
        }
        $this->audit->record('Tag Added', "User {$who->userId} tagged device $deviceId with \"{$t['name']}\"", (int) $dev['client_id'], (int) ($dev['asset_id'] ?? 0));

        return ActionResult::ok('Tag added.', 200, 'ok', ['tag' => $t]);
    }

    public function untagDevice(RmmPrincipal $who, int $deviceId, int $tagId): ActionResult
    {
        [$dev, $err] = $this->device($who->userId, $deviceId, RmmAbility::DEVICE_MANAGE);
        if ($err !== null || $dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }
        if (!$this->tags->unassign($deviceId, $tagId)) {
            return ActionResult::fail(404, 'not_found', 'That device does not carry that tag.');
        }
        $this->audit->record('Tag Removed', "User {$who->userId} removed tag $tagId from device $deviceId", (int) $dev['client_id'], (int) ($dev['asset_id'] ?? 0));

        return ActionResult::ok('Tag removed.');
    }

    // ------------------------------------------------------------------ tags

    /** @param array<string,mixed> $in {name, color?, description?} */
    public function createTag(RmmPrincipal $who, array $in): ActionResult
    {
        if (($err = $this->role($who->userId, RmmAbility::DEVICE_MANAGE)) !== null) {
            return $err;
        }
        try {
            $t = $this->tags->create((string) ($in['name'] ?? ''), (string) ($in['color'] ?? ''), (string) ($in['description'] ?? ''), $who->userId);
        } catch (\InvalidArgumentException $e) {
            return ActionResult::fail(422, 'invalid', $e->getMessage());
        }
        $this->audit->record('Tag Created', "User {$who->userId} created tag \"{$t['name']}\"", 0, 0);

        return ActionResult::ok('Tag saved.', 201, 'ok', ['tag' => $t]);
    }

    /** @param array<string,mixed> $in {name?, color?, description?} */
    public function updateTag(RmmPrincipal $who, int $tagId, array $in): ActionResult
    {
        if (($err = $this->role($who->userId, RmmAbility::DEVICE_MANAGE)) !== null) {
            return $err;
        }
        if ($this->tags->find($tagId) === null) {
            return ActionResult::fail(404, 'not_found', 'Tag not found.');
        }
        try {
            $t = $this->tags->update($tagId, array_intersect_key($in, array_flip(['name', 'color', 'description'])));
        } catch (\InvalidArgumentException $e) {
            return ActionResult::fail(422, 'invalid', $e->getMessage());
        }
        $this->audit->record('Tag Updated', "User {$who->userId} updated tag $tagId (\"{$t['name']}\")", 0, 0);

        return ActionResult::ok('Tag saved.', 200, 'ok', ['tag' => $t]);
    }

    public function deleteTag(RmmPrincipal $who, int $tagId): ActionResult
    {
        if (($err = $this->role($who->userId, RmmAbility::DEVICE_MANAGE)) !== null) {
            return $err;
        }
        if (!$this->tags->delete($tagId)) {
            return ActionResult::fail(404, 'not_found', 'Tag not found.');
        }
        $this->audit->record('Tag Deleted', "User {$who->userId} deleted tag $tagId", 0, 0);

        return ActionResult::ok('Tag deleted.');
    }

    // ------------------------------------------------------------------ groups

    /** @param array<string,mixed> $in {name, description?} */
    public function createGroup(RmmPrincipal $who, array $in): ActionResult
    {
        if (($err = $this->role($who->userId, RmmAbility::DEVICE_MANAGE)) !== null) {
            return $err;
        }
        try {
            $g = $this->groups->create((string) ($in['name'] ?? ''), (string) ($in['description'] ?? ''), $who->userId);
        } catch (\InvalidArgumentException $e) {
            return ActionResult::fail(422, 'invalid', $e->getMessage());
        }
        $this->audit->record('Group Created', "User {$who->userId} created group \"{$g['name']}\"", 0, 0);

        return ActionResult::ok('Group saved.', 201, 'ok', ['group' => $g]);
    }

    /** @param array<string,mixed> $in {name?, description?} */
    public function updateGroup(RmmPrincipal $who, int $groupId, array $in): ActionResult
    {
        if (($err = $this->role($who->userId, RmmAbility::DEVICE_MANAGE)) !== null) {
            return $err;
        }
        if ($this->groups->find($groupId) === null) {
            return ActionResult::fail(404, 'not_found', 'Group not found.');
        }
        try {
            $g = $this->groups->update($groupId, array_intersect_key($in, array_flip(['name', 'description'])));
        } catch (\InvalidArgumentException $e) {
            return ActionResult::fail(422, 'invalid', $e->getMessage());
        }
        $this->audit->record('Group Updated', "User {$who->userId} updated group $groupId (\"{$g['name']}\")", 0, 0);

        return ActionResult::ok('Group saved.', 200, 'ok', ['group' => $g]);
    }

    public function deleteGroup(RmmPrincipal $who, int $groupId): ActionResult
    {
        if (($err = $this->role($who->userId, RmmAbility::DEVICE_MANAGE)) !== null) {
            return $err;
        }
        if (!$this->groups->delete($groupId)) {
            return ActionResult::fail(404, 'not_found', 'Group not found.');
        }
        $this->audit->record('Group Deleted', "User {$who->userId} deleted group $groupId", 0, 0);

        return ActionResult::ok('Group deleted.');
    }

    /**
     * Add devices by hand. Every device must be one the caller may manage; if any is not, nothing is added (the same 404 as a missing device).
     *
     * @param list<mixed> $deviceIds
     */
    public function addGroupDevices(RmmPrincipal $who, int $groupId, array $deviceIds): ActionResult
    {
        if (($err = $this->role($who->userId, RmmAbility::DEVICE_MANAGE)) !== null) {
            return $err;
        }
        if ($this->groups->find($groupId) === null) {
            return ActionResult::fail(404, 'not_found', 'Group not found.');
        }
        $ids = [];
        foreach ($deviceIds as $id) {
            if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
                return ActionResult::fail(422, 'invalid', 'device_ids must be a list of device ids.');
            }
            $ids[] = (int) $id;
        }
        foreach ($ids as $id) {
            [, $e] = $this->device($who->userId, $id, RmmAbility::DEVICE_MANAGE);
            if ($e !== null) {
                return $e;
            }
        }
        try {
            $n = $this->groups->addDevices($groupId, $ids);
        } catch (\InvalidArgumentException $e) {
            return ActionResult::fail(422, 'invalid', $e->getMessage());
        }
        $this->audit->record('Group Devices Added', "User {$who->userId} added $n device(s) to group $groupId", 0, 0);

        return ActionResult::ok('Devices added.', 200, 'ok', ['added' => $n]);
    }

    public function removeGroupDevice(RmmPrincipal $who, int $groupId, int $deviceId): ActionResult
    {
        if (($err = $this->role($who->userId, RmmAbility::DEVICE_MANAGE)) !== null) {
            return $err;
        }
        [, $e] = $this->device($who->userId, $deviceId, RmmAbility::DEVICE_MANAGE);
        if ($e !== null) {
            return $e;
        }
        if (!$this->groups->removeDevice($groupId, $deviceId)) {
            return ActionResult::fail(404, 'not_found', 'That device is not a member added by hand.');
        }
        $this->audit->record('Group Device Removed', "User {$who->userId} removed device $deviceId from group $groupId", 0, 0);

        return ActionResult::ok('Device removed.');
    }

    /** @param list<mixed> $tagIds */
    public function setGroupTags(RmmPrincipal $who, int $groupId, array $tagIds): ActionResult
    {
        if (($err = $this->role($who->userId, RmmAbility::DEVICE_MANAGE)) !== null) {
            return $err;
        }
        $ids = [];
        foreach ($tagIds as $id) {
            if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
                return ActionResult::fail(422, 'invalid', 'tag_ids must be a list of tag ids.');
            }
            $ids[] = (int) $id;
        }
        if ($this->groups->find($groupId) === null) {
            return ActionResult::fail(404, 'not_found', 'Group not found.');
        }
        try {
            $this->groups->setTags($groupId, $ids);
        } catch (\InvalidArgumentException $e) {
            return ActionResult::fail(422, 'invalid', $e->getMessage());
        }
        $this->audit->record('Group Tags Set', "User {$who->userId} set the tags of group $groupId", 0, 0);

        return ActionResult::ok('Group tags saved.');
    }

    // ------------------------------------------------------------------ software

    /** Ask a device for a full software list at its next check-in. */
    public function refreshSoftware(RmmPrincipal $who, int $deviceId): ActionResult
    {
        [$dev, $err] = $this->device($who->userId, $deviceId, RmmAbility::JOB_RUN_SAVED);
        if ($err !== null || $dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }
        $this->state->requestSoftwareResync($deviceId);
        $this->audit->record('Software Refresh', "User {$who->userId} asked device $deviceId for a full software list", (int) $dev['client_id'], (int) ($dev['asset_id'] ?? 0));

        return ActionResult::ok('The device will send its full software list at its next check-in.', 202, 'queued');
    }
}
