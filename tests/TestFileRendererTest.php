<?php

declare(strict_types=1);

namespace SwanFlutter\LaravelApiSnapshot\Tests;

use PHPUnit\Framework\Attributes\Test;
use SwanFlutter\LaravelApiSnapshot\Testing\SnapshotEndpoint;
use SwanFlutter\LaravelApiSnapshot\Testing\SnapshotTestGenerator;

final class TestFileRendererTest extends TestCase
{
    /** @var list<string> */
    private array $temporary = [];

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            @unlink($path);
        }

        $this->temporary = [];

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $tests
     * @return array<string, string>
     */
    private function render(array $tests = [], string $driver = SnapshotTestGenerator::DRIVER_PEST): array
    {
        $endpoints = $this->generator()->scanEndpoints();

        return (new SnapshotTestGenerator(
            array_replace(['file_group_depth' => 1, 'auth_denial_tests' => true, 'refresh_database' => true], $tests),
            $driver,
        ))->files($endpoints);
    }

    #[Test]
    public function it_writes_one_pest_file_per_route_group(): void
    {
        $files = $this->render();

        $this->assertArrayHasKey('AdminTest.php', $files);
        $this->assertStringContainsString('declare(strict_types=1);', $files['AdminTest.php']);
        $this->assertStringContainsString('uses(RefreshDatabase::class);', $files['AdminTest.php']);
        $this->assertStringContainsString('new SnapshotEndpoint(', $files['AdminTest.php']);
        $this->assertStringContainsString("it('Admin", $files['AdminTest.php']);
        $this->assertStringContainsString('rejects a request without credentials', $files['AdminTest.php']);
    }

    #[Test]
    public function the_case_title_states_the_expectation_of_its_own_endpoint(): void
    {
        $pest = $this->renderPest('Report', [
            new SnapshotEndpoint(method: 'GET', uri: '/api/v1/reports/days', group: 'Report', action: 'Days'),
            new SnapshotEndpoint(method: 'POST', uri: '/api/v1/reports/generate', group: 'Report', action: 'Generate', expectation: SnapshotEndpoint::EXPECT_SUCCESS),
        ]);

        $this->assertStringContainsString('GET /api/v1/reports/days responds without a server error', $pest);
        $this->assertStringContainsString('POST /api/v1/reports/generate responds successfully', $pest);
    }

    /**
     * @param  list<SnapshotEndpoint>  $endpoints
     */
    private function renderPest(string $bucket, array $endpoints): string
    {
        $method = new \ReflectionMethod(SnapshotTestGenerator::class, 'renderPest');

        return $method->invoke(
            new SnapshotTestGenerator(['file_group_depth' => 1], SnapshotTestGenerator::DRIVER_PEST),
            $bucket,
            $endpoints,
        );
    }

    #[Test]
    public function every_generated_file_is_valid_php(): void
    {
        foreach ($this->render() as $name => $contents) {
            $this->assertValidPhp($name, $contents);
        }

        foreach ($this->render([], SnapshotTestGenerator::DRIVER_PHPUNIT) as $name => $contents) {
            $this->assertValidPhp($name, $contents);
        }
    }

    #[Test]
    public function it_renders_phpunit_classes_with_one_accessor_per_endpoint(): void
    {
        $files = $this->render(['namespace' => 'Tests\\Feature\\ApiSnapshot'], SnapshotTestGenerator::DRIVER_PHPUNIT);
        $admin = $files['AdminTest.php'];

        $this->assertStringContainsString('namespace Tests\\Feature\\ApiSnapshot;', $admin);
        $this->assertStringContainsString('final class AdminTest extends TestCase', $admin);
        $this->assertStringContainsString('use RefreshDatabase;', $admin);
        $this->assertStringContainsString('public function test_', $admin);
        $this->assertStringContainsString('private function ', $admin);
        $this->assertStringNotContainsString('function it(', $admin);
    }

    #[Test]
    public function it_can_drop_the_auth_and_refresh_database_blocks(): void
    {
        $files = $this->render(['auth_denial_tests' => false, 'refresh_database' => false]);
        $admin = $files['AdminTest.php'];

        $this->assertStringNotContainsString('rejects a request without credentials', $admin);
        $this->assertStringNotContainsString('RefreshDatabase', $admin);
    }

    #[Test]
    public function it_writes_the_snapshot_header_with_the_endpoint_count(): void
    {
        $files = $this->render();

        $this->assertMatchesRegularExpression('/\d+ endpoints, \d+ protected by authentication/', $files['AdminTest.php']);
        $this->assertStringContainsString('api-snapshot:tests', $files['AdminTest.php']);
    }

    private function assertValidPhp(string $name, string $contents): void
    {
        $path  = tempnam(sys_get_temp_dir(), 'snapshot-') . '.php';
        $this->temporary[] = $path;

        file_put_contents($path, $contents);

        $output = [];
        $code   = 0;

        exec(sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($path)), $output, $code);

        $this->assertSame(0, $code, sprintf("[%s] is not valid PHP:\n%s", $name, implode("\n", $output)));
    }
}
