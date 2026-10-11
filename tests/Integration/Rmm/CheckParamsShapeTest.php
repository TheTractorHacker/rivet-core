<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Alerting\AlertingEngine;
use RivetCore\Rmm\Policy\EffectiveConfig;
use RivetCore\Rmm\Policy\PolicyLayer;
use RivetCore\Rmm\Policy\PolicyResolver;
use RivetCore\Rmm\Settings\ChecksValidator;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Tests\Support\RmmTestCase;

/**
 * Regression: a check definition's params is an array everywhere inside the module. The default check list used to carry an empty
 * params as \stdClass, so reading `$def['params']['thresholds']` of `pending_reboot` died with "Cannot use object of type stdClass as array".
 * The wire format still carries `{}` (the signed message and the agent protocol require an object).
 */
final class CheckParamsShapeTest extends RmmTestCase
{
    private int $dev = 0;

    /** The check list of the real agent. */
    private const REAL = [
        ['key' => 'disk_c', 'type' => 'disk', 'params' => ['mount' => 'C:', 'warn_pct' => 85, 'fail_pct' => 95], 'interval_s' => 300],
        ['key' => 'pending_reboot', 'type' => 'pending_reboot', 'params' => [], 'interval_s' => 3600],
        ['key' => 'svc_eventlog', 'type' => 'service', 'params' => ['name' => 'EventLog', 'expect' => 'running'], 'interval_s' => 300],
        ['key' => 'agent_update', 'type' => 'service', 'params' => ['name' => 'RivetITAgent', 'expect' => 'running'], 'interval_s' => 3600],
    ];

    private function enrolled(): void
    {
        $this->h->enable();
        $f = ['monitoring' => true, 'metrics' => true, 'jobs' => true, 'updates' => true, 'alerting' => true];
        $this->assertSame([], $this->h->module->settings()->update(['features_json' => $f]));
        $this->h->module->alertingEngine()->forget();
        $this->h->asset(['name' => 'Box', 'serial' => 'CPS-1', 'client_id' => $this->h->clientA]);
        [, , $j] = $this->h->enroll($this->h->token(null, 24, 50), $this->h::device(['serial' => 'CPS-1', 'hostname' => 'H-CPS-1']));
        $this->dev = (int) $j['device_id'];
    }

    /** @return list<string> */
    private function allRoutes(): array
    {
        $who = $this->h->principal();
        $seen = [];
        [$c, $j] = $this->h->tech('GET', [(string) $this->dev, 'alerting'], $who);
        $this->assertSame(200, $c, json_encode($j));
        foreach ($this->h->module->settings()->checks() as $chk) {
            $k = (string) $chk['key'];
            [$c, $j] = $this->h->tech('GET', [(string) $this->dev, 'checks', $k, 'thresholds'], $who);
            $this->assertSame(200, $c, "$k: " . json_encode($j));
            $this->assertSame($k, $j['check_key']);
            $this->assertNull($j['defined'], "$k declares no thresholds");
            $seen[] = $k;
            [$c, $j] = $this->h->tech('PUT', [(string) $this->dev, 'checks', $k, 'thresholds'], $who, ['warn' => ['op' => 'gt', 'value' => 80]]);
            $this->assertSame(409, $c, "$k: " . json_encode($j));
            [$c] = $this->h->tech('DELETE', [(string) $this->dev, 'checks', $k, 'thresholds'], $who);
            $this->assertSame(409, $c, 'clearing an override of a check without thresholds is a conflict, not a crash');
        }

        return $seen;
    }

    public function testTheDefaultCheckListHasArrayParamsAndTheThresholdRoutesWork(): void
    {
        foreach (RmmSettings::defaultChecks() as $c) {
            $this->assertIsArray($c['params'], $c['key']);
        }
        $this->enrolled();
        $this->assertNull($this->h->one("SELECT checks_json FROM endpoint_agent_settings LIMIT 1"), 'defaults, nothing stored');
        $this->assertSame(['disk_c', 'pending_reboot', 'svc_eventlog'], $this->allRoutes());
        [$c, $j] = $this->h->tech('GET', [(string) $this->dev, 'checks', 'nope', 'thresholds'], $this->h->principal());
        $this->assertSame(404, $c, json_encode($j));
    }

    public function testTheFourChecksOfTheRealAgentStoredAsConfigured(): void
    {
        $this->enrolled();
        $this->assertSame([], $this->h->module->settings()->update(['checks_json' => json_encode(self::REAL)]));
        $this->assertSame(['disk_c', 'pending_reboot', 'svc_eventlog', 'agent_update'], $this->allRoutes());
    }

    /** `{}` and `[]` both mean "no params", on input and in storage. */
    public function testEmptyParamsAsObjectOrListBehaveTheSame(): void
    {
        $this->enrolled();
        foreach (['{}', '[]'] as $empty) {
            $json = '[{"key":"pending_reboot","type":"pending_reboot","params":' . $empty . ',"interval_s":3600}]';
            $this->assertSame([], $this->h->module->settings()->update(['checks_json' => $json]), $empty);
            $stored = (string) $this->h->one('SELECT checks_json FROM endpoint_agent_settings LIMIT 1');
            $this->assertStringContainsString('"params":{}', $stored, 'storage keeps the object form');
            $this->assertSame(['pending_reboot'], $this->allRoutes());
        }
    }

    public function testThresholdsOnAPhase3CheckTypeStillResolveAndOverride(): void
    {
        $this->enrolled();
        $list = [self::REAL[1], ['key' => 'cpu1', 'type' => 'cpu', 'params' => ['thresholds' => ['warn' => ['op' => 'gt', 'value' => 80]]], 'interval_s' => 60]];
        $this->assertSame([], $this->h->module->settings()->update(['checks_json' => json_encode($list)]));
        $who = $this->h->principal();
        [$c, $j] = $this->h->tech('GET', [(string) $this->dev, 'checks', 'cpu1', 'thresholds'], $who);
        $this->assertSame(200, $c, json_encode($j));
        $this->assertSame(80, $j['defined']['warn']['value']);
        [$c] = $this->h->tech('PUT', [(string) $this->dev, 'checks', 'cpu1', 'thresholds'], $who, ['warn' => ['op' => 'gt', 'value' => 90]]);
        $this->assertSame(200, $c);
        [$c, $j] = $this->h->tech('GET', [(string) $this->dev, 'checks', 'pending_reboot', 'thresholds'], $who);
        $this->assertSame(200, $c, json_encode($j));
        $this->assertNull($j['defined']);
    }

    public function testThePolicyProvidedTemplateWithEmptyParamsReachesTheDeviceAsAnObjectAndTheEngineAsAnArray(): void
    {
        $this->h->enable();
        $layer = new PolicyLayer(1, 1, 'client', 100, false, ['check.reboot2' => ['mode' => 'override', 'value' => ['type' => 'pending_reboot', 'params' => [], 'interval_s' => 600]]]);
        $e = EffectiveConfig::compose(PolicyResolver::resolve([$layer]), RmmSettings::defaultChecks(), 300, 60);
        foreach ($e['checks'] as $c) {
            $this->assertIsArray($c['params'], (string) $c['key']);
        }
        $this->assertSame([], AlertingEngine::definitionsOf($e['checks']));
        $signed = $this->h->module->settings()->signCheckList($e['checks']);
        foreach ($signed as $c) {
            if ($c['key'] === 'pending_reboot' || $c['key'] === 'reboot2') {
                $this->assertInstanceOf(\stdClass::class, $c['params']);
                $this->assertStringContainsString('"params":{}', (string) json_encode($c));
            }
        }
    }

    public function testNoStdClassSurvivesInAnyInternalCheckList(): void
    {
        $this->enrolled();
        $this->assertSame([], $this->h->module->settings()->update(['checks_json' => json_encode(self::REAL)]));
        foreach ([RmmSettings::defaultChecks(), $this->h->module->settings()->checks()] as $list) {
            $this->assertStringNotContainsString('stdClass', var_export($list, true));
        }
        [$list] = ChecksValidator::validate(json_encode(self::REAL));
        $this->assertStringNotContainsString('stdClass', var_export($list, true));
        $this->assertStringNotContainsString('stdClass', var_export(ChecksValidator::paramsOf(['params' => new \stdClass()]), true));
        $this->assertSame(['a' => ['b' => 1]], ChecksValidator::paramsOf(['params' => (object) ['a' => (object) ['b' => 1]]]));
    }
}
