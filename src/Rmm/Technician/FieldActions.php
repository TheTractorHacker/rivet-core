<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Technician;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmAuthorizer;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Device\DeviceRepository;
use RivetCore\Rmm\Fields\CustomFieldService;

/**
 * Custom fields: definitions (administrators), values (`rmm.device.manage` for the client the value belongs to; a SECRET value needs `rmm.admin`).
 * A value for a device belongs to the device's client; for a client to that client; for a site (the edition's location) to the client named with it,
 * which must contain the location. A secret value is audited by field name only and is never returned by any read.
 *
 * @api
 */
final class FieldActions
{
    public function __construct(
        private readonly RmmAuthorizer $authz,
        private readonly CustomFieldService $fields,
        private readonly DeviceRepository $devices,
        private readonly RmmAuditInterface $audit,
        private readonly ?\RivetCore\Rmm\Contracts\RmmTenancyInterface $tenancy = null,
    ) {
    }

    private function admin(RmmPrincipal $who): ?ActionResult
    {
        $why = $this->authz->check($who->userId, RmmAbility::ADMIN, 0);

        return $why === null ? null : ActionResult::fail(403, 'forbidden', $why);
    }

    /** @param array<string,mixed> $in see {@see CustomFieldService::define()} */
    public function define(RmmPrincipal $who, array $in): ActionResult
    {
        if (($e = $this->admin($who)) !== null) {
            return $e;
        }
        try {
            $f = $this->fields->define($in, $who->userId);
        } catch (\InvalidArgumentException $ex) {
            return ActionResult::fail(422, 'invalid', $ex->getMessage());
        }
        $this->audit->record('Custom Field Defined', "{$who->userName} defined custom field \"{$f['name']}\" ({$f['scope']}, {$f['type']})", 0, (int) $f['field_id']);

        return ActionResult::ok('Field saved.', 201, 'ok', ['field' => $f]);
    }

    /** @param array<string,mixed> $in */
    public function change(RmmPrincipal $who, int $fieldId, array $in): ActionResult
    {
        if (($e = $this->admin($who)) !== null) {
            return $e;
        }
        try {
            $f = $this->fields->change($fieldId, $in);
        } catch (\InvalidArgumentException $ex) {
            return ActionResult::fail($ex->getMessage() === 'Field not found.' ? 404 : 422, $ex->getMessage() === 'Field not found.' ? 'not_found' : 'invalid', $ex->getMessage());
        }
        $this->audit->record('Custom Field Changed', "{$who->userName} changed custom field \"{$f['name']}\"", 0, $fieldId);

        return ActionResult::ok('Field saved.', 200, 'ok', ['field' => $f]);
    }

    public function delete(RmmPrincipal $who, int $fieldId): ActionResult
    {
        if (($e = $this->admin($who)) !== null) {
            return $e;
        }
        $f = $this->fields->field($fieldId);
        if ($f === null || !$this->fields->delete($fieldId)) {
            return ActionResult::fail(404, 'not_found', 'Field not found.');
        }
        $this->audit->record('Custom Field Deleted', "{$who->userName} deleted custom field \"{$f['name']}\" and its {$f['values']} value(s)", 0, $fieldId);

        return ActionResult::ok('Field deleted.');
    }

    /**
     * Set or clear a value. `$scopeId` is the client, location or device id the field's scope names; `$clientId` is the client a site value belongs to.
     */
    public function setValue(RmmPrincipal $who, int $fieldId, int $scopeId, mixed $value, int $clientId = 0): ActionResult
    {
        $f = $this->fields->field($fieldId);
        if ($f === null) {
            return ActionResult::fail(404, 'not_found', 'Field not found.');
        }
        $client = 0;
        $asset = 0;
        if ($f['scope'] === 'device') {
            $view = $this->authz->check($who->userId, RmmAbility::DEVICE_VIEW, 0);
            if ($view !== null) {
                return ActionResult::fail(403, 'forbidden', $view);
            }
            $dev = $this->devices->find($scopeId);
            if ($dev === null || $this->authz->check($who->userId, RmmAbility::DEVICE_VIEW, (int) $dev['client_id']) !== null) {
                return ActionResult::fail(404, 'not_found', 'Device not found.');
            }
            $client = (int) $dev['client_id'];
            $asset = (int) ($dev['asset_id'] ?? 0);
        } elseif ($f['scope'] === 'site') {
            $client = $clientId;
            if ($client <= 0 || ($this->tenancy !== null && !$this->tenancy->locationInClient($scopeId, $client))) {
                return ActionResult::fail(422, 'invalid', 'Give the client the site belongs to (client_id), and a site of that client.');
            }
        } else {
            $client = $scopeId;
        }
        if ($f['scope'] === 'client' && !$this->authz->clientOk($who->userId, $client)) {
            return ActionResult::fail(404, 'not_found', 'Client not found.');
        }
        $why = $this->authz->check($who->userId, $f['type'] === 'secret' ? RmmAbility::ADMIN : RmmAbility::DEVICE_MANAGE, $client);
        if ($why !== null) {
            return ActionResult::fail(403, 'forbidden', $why);
        }
        try {
            $v = $this->fields->setValue($fieldId, $scopeId, $value, $who->userId);
        } catch (\InvalidArgumentException $ex) {
            return ActionResult::fail(422, 'invalid', $ex->getMessage());
        }
        $this->audit->record('Custom Field Value', "{$who->userName} " . ($v['set'] ? 'set' : 'cleared') . " custom field \"{$f['name']}\" for {$f['scope']} $scopeId" . ($f['type'] === 'secret' ? ' (secret, value not logged)' : ''), $client, $asset);

        return ActionResult::ok($v['set'] ? 'Saved.' : 'Cleared.', 200, 'ok', ['value' => $v]);
    }
}
