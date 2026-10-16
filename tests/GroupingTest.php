<?php

declare(strict_types=1);

namespace SwanFlutter\LaravelApiSnapshot\Tests;

use PHPUnit\Framework\Attributes\Test;

final class GroupingTest extends TestCase
{
    #[Test]
    public function it_groups_by_uri_segments_and_singularises_folder_names(): void
    {
        $collection = $this->collection();

        $this->assertContains('Patient', array_column($collection['item'], 'name'));
        $this->assertContains('Admin', array_column($collection['item'], 'name'));
        $this->assertStringContainsString('api/v1/patients', implode(' ', $this->groupLabels('Patient')));
        $this->assertNotEmpty($this->group($collection, 'Patient')['item']);
    }

    #[Test]
    public function it_nests_deeper_than_two_levels_when_asked(): void
    {
        $collection = $this->collection(['group_depth' => 3]);
        $admin      = $this->group($collection, 'Admin');

        $this->assertSame('User', $admin['item'][0]['name']);
        $this->assertSame('Status', $admin['item'][0]['item'][0]['name']);
    }

    #[Test]
    public function it_strips_configured_prefixes_and_versions(): void
    {
        $collection = $this->collection(['strip_prefixes' => ['api', 'v1']]);

        $names = array_column($collection['item'], 'name');

        $this->assertNotContains('V1', $names);
        $this->assertContains('Patient', $names);
    }

    #[Test]
    public function it_groups_by_controller_without_the_shared_namespace(): void
    {
        $collection = $this->collection(['group_by' => 'controller']);
        $names      = array_column($collection['item'], 'name');

        $this->assertContains('Patient', $names);
        $this->assertContains('Report', $names);
        $this->assertNotContains('SwanFlutter', $names);
        $this->assertNotEmpty($this->group($collection, 'Patient')['item']);
    }

    /**
     * Depth 2 cannot show "Panel › Staff Attendance" for every controller, and
     * dropping the controller name would leave folders full of requests that
     * all answer to the same method name.
     */
    #[Test]
    public function controller_grouping_keeps_the_controller_name_at_any_depth(): void
    {
        $nested = $this->group($this->collection(['group_by' => 'controller']), 'Panel');

        $this->assertSame('Staff Attendance', $nested['item'][0]['name']);

        $flat = $this->collection(['group_by' => 'controller', 'group_depth' => 1]);

        $this->assertContains('Staff Attendance', array_column($flat['item'], 'name'));
        $this->assertNotContains('Panel', array_column($flat['item'], 'name'));
    }

    #[Test]
    public function it_does_not_create_a_general_folder_for_real_routes(): void
    {
        $names = array_column($this->collection()['item'], 'name');

        $this->assertNotContains('General', $names);
    }

    /** @return array<string, mixed> */
    private function group(array $collection, string $name): array
    {
        foreach ($collection['item'] ?? [] as $item) {
            if (($item['name'] ?? null) === $name) {
                return $item;
            }
        }

        $this->fail("Group {$name} missing");
    }

    /** @return list<string> */
    private function groupLabels(string $group): array
    {
        $labels = [];

        $walk = static function (array $items) use (&$walk, &$labels): void {
            foreach ($items as $item) {
                if (isset($item['request'])) {
                    $labels[] = $item['request']['url']['path'] ? implode('/', $item['request']['url']['path']) : '';
                } else {
                    $walk($item['item'] ?? []);
                }
            }
        };

        $walk($this->group($this->collection(), $group)['item'] ?? []);

        return $labels;
    }
}
