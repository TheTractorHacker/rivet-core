<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Tests\Support\RmmTestCase;

/**
 * Red team 2026-11-21 (core-api fuzz, Info): a tag or group body whose text fields are JSON arrays or objects raised PHP "Array to string conversion"
 * warnings (InventoryActions createTag/createGroup and the update paths) before failing or storing "Array". Such a field is now a 422 with no warning
 * (failOnWarning makes any warning fail this test); text and numbers keep working.
 */
final class InventoryTypeConfusionTest extends RmmTestCase
{
    public function testNonScalarTextFieldsAre422WithoutAWarningAndNothingIsStored(): void
    {
        $this->h->enable();
        $admin = $this->h->principal();
        [$c, $j] = $this->h->tech('POST', ['tags'], $admin, ['name' => 'ok-tag']);
        $this->assertSame(201, $c);
        $tagId = (string) $j['tag']['tag_id'];
        [$c, $j] = $this->h->tech('POST', ['groups'], $admin, ['name' => 'ok-group']);
        $this->assertSame(201, $c);
        $groupId = (string) $j['group']['group_id'];
        $tags = $this->h->one('SELECT COUNT(*) FROM rmm_tags');
        $groups = $this->h->one('SELECT COUNT(*) FROM rmm_groups');

        foreach ([['x'], ['a' => ['b' => 1]], [[]], []] as $bad) {
            foreach ([['POST', ['tags'], 'name'], ['POST', ['tags'], 'color'], ['POST', ['tags'], 'description'], ['PATCH', ['tags', $tagId], 'name'],
                ['PATCH', ['tags', $tagId], 'color'], ['PATCH', ['tags', $tagId], 'description'], ['POST', ['groups'], 'name'], ['POST', ['groups'], 'description'],
                ['PATCH', ['groups', $groupId], 'name'], ['PATCH', ['groups', $groupId], 'description']] as [$m, $seg, $field]) {
                $body = $field === 'name' ? [$field => $bad] : ['name' => 'n-' . bin2hex(random_bytes(3)), $field => $bad];
                [$c, $j] = $this->h->tech($m, $seg, $admin, $body);
                $this->assertSame(422, $c, "$m " . implode('/', $seg) . " $field=" . json_encode($bad) . ': ' . json_encode($j));
                $this->assertSame('invalid', $j['code']);
            }
        }
        $this->assertSame($tags, $this->h->one('SELECT COUNT(*) FROM rmm_tags'), 'no tag was created');
        $this->assertSame($groups, $this->h->one('SELECT COUNT(*) FROM rmm_groups'), 'no group was created');
        $this->assertSame('ok-tag', $this->h->one("SELECT name FROM rmm_tags WHERE tag_id = $tagId"));
        $this->assertSame('ok-group', $this->h->one("SELECT name FROM rmm_groups WHERE group_id = $groupId"));
        // the control: text (and a number, as before) still works
        $this->assertSame(200, $this->h->tech('PATCH', ['tags', $tagId], $admin, ['name' => 'renamed', 'color' => '#1a2b3c', 'description' => 'd'])[0]);
        $this->assertSame(201, $this->h->tech('POST', ['groups'], $admin, ['name' => 2026])[0]);
    }
}
