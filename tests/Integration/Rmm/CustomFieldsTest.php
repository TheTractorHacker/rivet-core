<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Fields\CustomFieldService;
use RivetCore\Tests\Support\AutomationTestCase;

/** Custom fields: definitions, typed values per client, site and device, secrets, who may do what, and the technician routes. */
final class CustomFieldsTest extends AutomationTestCase
{
    private int $dev = 0;
    private int $devB = 0;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->dev] = $this->device('PC-1');
        [$this->devB] = $this->device('PC-B', $this->h->clientB);
    }

    private function actions(): \RivetCore\Rmm\Technician\FieldActions
    {
        return $this->h->module->fieldActions();
    }

    /** @param array<string,mixed> $over @return array<string,mixed> */
    private function define(string $name, string $scope, string $type, array $over = []): array
    {
        $r = $this->actions()->define($this->admin, ['name' => $name, 'scope' => $scope, 'type' => $type] + $over);
        $this->assertTrue($r->ok, $r->message);

        return $r->data['field'];
    }

    public function testDefinitionsAreValidated(): void
    {
        $a = $this->actions();
        foreach ([
            [['name' => 'Bad Name', 'scope' => 'client', 'type' => 'text'], 'field name is lower case'],
            [['name' => 'ok', 'scope' => 'planet', 'type' => 'text'], 'scope must be'],
            [['name' => 'ok', 'scope' => 'client', 'type' => 'blob'], 'type must be'],
            [['name' => 'ok', 'scope' => 'client', 'type' => 'list'], 'needs options'],
            [['name' => 'ok', 'scope' => 'client', 'type' => 'text', 'options' => ['a']], 'Only a list field has options'],
            [['name' => 'ok', 'scope' => 'client', 'type' => 'number', 'default' => 'x'], 'number'],
            [['name' => 'ok', 'scope' => 'client', 'type' => 'secret', 'default' => 'x'], 'no default'],
            [['name' => 'ok', 'scope' => 'client', 'type' => 'list', 'options' => ['a', ''], 'default' => 'a'], 'option is text'],
            [['name' => 'ok', 'scope' => 'client', 'type' => 'list', 'options' => ['a'], 'default' => 'z'], 'options'],
        ] as [$in, $needle]) {
            $r = $a->define($this->admin, $in);
            $this->assertSame(422, $r->http, $needle);
            $this->assertStringContainsString($needle, $r->message);
        }
        $this->define('ok', 'client', 'text');
        $this->assertSame(422, $a->define($this->admin, ['name' => 'ok', 'scope' => 'device', 'type' => 'text'])->http, 'names are unique');
        $this->assertSame(403, $a->define($this->tech, ['name' => 'nope', 'scope' => 'client', 'type' => 'text'])->http);
        $this->assertSame(403, $a->define($this->scoped, ['name' => 'nope', 'scope' => 'client', 'type' => 'text'])->http);
    }

    public function testTheTypesAndTheirValues(): void
    {
        $a = $this->actions();
        $cases = [
            ['t_text', 'text', 'hello', 'hello', ['bad' => str_repeat('x', 2001)]],
            ['t_num', 'number', '12.50', '12.5', ['bad' => 'abc']],
            ['t_bool', 'bool', 'yes', 'true', ['bad' => 'perhaps']],
            ['t_date', 'date', '2026-10-10', '2026-10-10', ['bad' => '10/10/2026']],
            ['t_list', 'list', 'red', 'red', ['bad' => 'blue', 'options' => ['red', 'green']]],
        ];
        foreach ($cases as [$name, $type, $in, $stored, $extra]) {
            $f = $this->define($name, 'device', $type, isset($extra['options']) ? ['options' => $extra['options']] : []);
            $r = $a->setValue($this->admin, (int) $f['field_id'], $this->dev, $in);
            $this->assertTrue($r->ok, "$type: " . $r->message);
            $this->assertSame($stored, $r->data['value']['value']);
            $bad = $a->setValue($this->admin, (int) $f['field_id'], $this->dev, $extra['bad']);
            $this->assertSame(422, $bad->http, $type);
        }
        $vals = array_column($this->h->module->customFields()->valuesFor('device', $this->dev), 'value', 'name');
        $this->assertSame(['t_bool' => 'true', 't_date' => '2026-10-10', 't_list' => 'red', 't_num' => '12.5', 't_text' => 'hello'], $vals);
    }

    public function testClearingAndDefaults(): void
    {
        $f = $this->define('region', 'client', 'text', ['default' => 'emea']);
        $svc = $this->h->module->customFields();
        $dev = $this->h->module->devices()->find($this->dev) ?? [];
        $this->assertSame('emea', $svc->resolveFor($dev)['region']['value'], 'the default applies until a value is set');
        $this->actions()->setValue($this->admin, (int) $f['field_id'], $this->h->clientA, 'apac');
        $this->assertSame('apac', $svc->resolveFor($dev)['region']['value']);
        $r = $this->actions()->setValue($this->admin, (int) $f['field_id'], $this->h->clientA, '');
        $this->assertFalse($r->data['value']['set']);
        $this->assertSame('emea', $svc->resolveFor($dev)['region']['value']);
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_custom_field_values'));
    }

    public function testEachScopeReadsItsOwnValue(): void
    {
        $c = $this->define('c_f', 'client', 'text');
        $s = $this->define('s_f', 'site', 'text');
        $d = $this->define('d_f', 'device', 'text');
        $a = $this->actions();
        $loc = $this->h->tenancy->addLocation($this->h->clientA);
        $this->h->q("UPDATE endpoint_agent_devices SET location_id = $loc WHERE device_id = {$this->dev}");
        $this->assertTrue($a->setValue($this->admin, (int) $c['field_id'], $this->h->clientA, 'client value')->ok);
        $this->assertTrue($a->setValue($this->admin, (int) $s['field_id'], $loc, 'site value', $this->h->clientA)->ok);
        $this->assertTrue($a->setValue($this->admin, (int) $d['field_id'], $this->dev, 'device value')->ok);
        $svc = $this->h->module->customFields();
        $r = $svc->resolveFor($this->h->module->devices()->find($this->dev) ?? []);
        $this->assertSame(['client value', 'site value', 'device value'], [$r['c_f']['value'], $r['s_f']['value'], $r['d_f']['value']]);
        $other = $svc->resolveFor($this->h->module->devices()->find($this->devB) ?? []);
        $this->assertSame([null, null, null], [$other['c_f']['value'], $other['s_f']['value'], $other['d_f']['value']], 'another client, another site, another device');
        $this->assertSame(422, $a->setValue($this->admin, (int) $s['field_id'], $loc, 'x')->http, 'a site value names its client');
        $this->assertSame(422, $a->setValue($this->admin, (int) $s['field_id'], $loc, 'x', $this->h->clientB)->http, 'and the site must belong to it');
        $this->assertSame(404, $a->setValue($this->admin, (int) $d['field_id'], 99999, 'x')->http);
        $this->assertSame(404, $a->setValue($this->admin, 99999, 1, 'x')->http);
        $this->assertSame(422, $a->setValue($this->admin, (int) $c['field_id'], 99999, 'x')->http, 'a client that does not exist');
    }

    public function testSecretsAreSealedMaskedAndAdministratorOnly(): void
    {
        $f = $this->define('api_key', 'client', 'secret');
        $id = (int) $f['field_id'];
        $a = $this->actions();
        $this->assertSame(403, $a->setValue($this->scoped, $id, $this->h->clientB, 'sk-1')->http, 'a technician who may manage devices still cannot set a secret');
        $this->assertSame(403, $a->setValue($this->tech, $id, $this->h->clientA, 'sk-1')->http);
        $r = $a->setValue($this->admin, $id, $this->h->clientA, 'sk-live-0123456789');
        $this->assertTrue($r->ok);
        $this->assertSame(CustomFieldService::SECRET_MASK, $r->data['value']['value']);
        $row = $this->h->rows('SELECT * FROM rmm_custom_field_values')[0];
        $this->assertNull($row['value_text']);
        $this->assertStringNotContainsString('sk-live', (string) $row['value_enc']);
        $svc = $this->h->module->customFields();
        $dev = $this->h->module->devices()->find($this->dev) ?? [];
        $this->assertSame(CustomFieldService::SECRET_MASK, $svc->resolveFor($dev)['api_key']['value'], 'a normal read never decrypts');
        $this->assertSame('sk-live-0123456789', $svc->resolveFor($dev, true)['api_key']['value']);
        $this->assertSame(CustomFieldService::SECRET_MASK, $svc->valuesFor('client', $this->h->clientA)[0]['value']);
        $all = json_encode([$this->h->module->readModel()->automation()?->deviceFields($dev), $svc->fields(), $this->h->audit->records()]) ?: '';
        $this->assertStringNotContainsString('sk-live', $all);
        $this->assertStringContainsString('secret, value not logged', $all);
        [$c, $j] = $this->h->tech('GET', [(string) $this->dev, 'fields'], $this->tech);
        $this->assertStringNotContainsString('sk-live', json_encode($j) ?: '');
        $this->assertSame(CustomFieldService::SECRET_MASK, $j['data'][0]['value']);
    }

    public function testWhoMaySetValues(): void
    {
        $c = $this->define('note', 'client', 'text');
        $d = $this->define('rack', 'device', 'text');
        $a = $this->actions();
        $this->assertSame(403, $a->setValue($this->tech, (int) $d['field_id'], $this->dev, 'x')->http, 'running scripts is not managing devices');
        $this->assertSame(403, $a->setValue($this->viewer, (int) $d['field_id'], $this->dev, 'x')->http);
        $this->assertTrue($a->setValue($this->scoped, (int) $c['field_id'], $this->h->clientB, 'ok')->ok, 'a device manager inside their own client');
        $this->assertSame(404, $a->setValue($this->scoped, (int) $c['field_id'], $this->h->clientA, 'no')->http, 'a client outside their scope looks missing');
        $this->assertSame(404, $a->setValue($this->scoped, (int) $d['field_id'], $this->dev, 'no')->http, 'and so does a device of one');
        $this->assertTrue($a->setValue($this->scoped, (int) $d['field_id'], $this->devB, 'R1')->ok);
    }

    public function testChangingAndDeletingFields(): void
    {
        $f = $this->define('tier', 'client', 'list', ['options' => ['gold', 'silver']]);
        $id = (int) $f['field_id'];
        $a = $this->actions();
        $a->setValue($this->admin, $id, $this->h->clientA, 'gold');
        $r = $a->change($this->admin, $id, ['type' => 'text']);
        $this->assertSame(422, $r->http, 'the type is fixed once defined');
        $r = $a->change($this->admin, $id, ['label' => 'Service tier', 'options' => ['gold', 'silver', 'bronze'], 'description' => 'SLA']);
        $this->assertSame(['Service tier', ['gold', 'silver', 'bronze'], 1], [$r->data['field']['label'], $r->data['field']['options'], $r->data['field']['values']]);
        $this->assertSame(403, $a->change($this->tech, $id, ['label' => 'x'])->http);
        $this->assertSame(404, $a->change($this->admin, 99999, ['label' => 'x'])->http);
        $this->assertSame(403, $a->delete($this->tech, $id)->http);
        $this->assertTrue($a->delete($this->admin, $id)->ok);
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_custom_field_values'), 'its values go with it');
        $this->assertSame(404, $a->delete($this->admin, $id)->http);
    }

    public function testTheRoutes(): void
    {
        [$c, $j] = $this->h->tech('POST', ['fields'], $this->admin, ['name' => 'rack', 'scope' => 'device', 'type' => 'text']);
        $this->assertSame(201, $c);
        $fid = (string) $j['field']['field_id'];
        [$c, $j] = $this->h->tech('GET', ['fields'], $this->viewer, null, ['scope' => 'device']);
        $this->assertSame([200, 'rack'], [$c, $j['data'][0]['name']]);
        [$c] = $this->h->tech('PUT', ['fields', $fid, 'values', (string) $this->dev], $this->scoped, ['value' => 'x']);
        $this->assertSame(404, $c, 'a device outside the technician\'s clients');
        [$c, $j] = $this->h->tech('PUT', ['fields', $fid, 'values', (string) $this->devB], $this->scoped, ['value' => 'R-7']);
        $this->assertSame([200, 'R-7'], [$c, $j['value']['value']]);
        [$c, $j] = $this->h->tech('PUT', [(string) $this->dev, 'fields', $fid], $this->admin, ['value' => 'R-9']);
        $this->assertSame([200, 'R-9'], [$c, $j['value']['value']]);
        [$c, $j] = $this->h->tech('GET', [(string) $this->dev, 'fields'], $this->viewer);
        $this->assertSame([200, 'device', 'R-9'], [$c, $j['data'][0]['scope'], $j['data'][0]['value']]);
        [$c] = $this->h->tech('PUT', [(string) $this->dev, 'fields', $fid], $this->viewer, ['value' => 'x']);
        $this->assertSame(403, $c);
        [$c, $j] = $this->h->tech('PATCH', ['fields', $fid], $this->admin, ['label' => 'Rack']);
        $this->assertSame([200, 'Rack'], [$c, $j['field']['label']]);
        [$c] = $this->h->tech('DELETE', ['fields', $fid], $this->admin);
        $this->assertSame(200, $c);
        [$c] = $this->h->tech('GET', ['fields', $fid], $this->viewer);
        $this->assertSame(404, $c);
    }
}
