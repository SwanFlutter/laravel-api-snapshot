<?php

declare(strict_types=1);

namespace SwanFlutter\LaravelApiSnapshot\Tests;

use PHPUnit\Framework\Attributes\Test;

final class SnapshotTestsCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'snapshot-tests-' . getmypid();

        $this->clean();
        @mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->clean();

        @rmdir($this->dir);

        parent::tearDown();
    }

    private function clean(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        return json_decode(
            (string) file_get_contents($this->dir . DIRECTORY_SEPARATOR . '_snapshot-manifest.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    #[Test]
    public function it_writes_generated_test_files_and_a_manifest(): void
    {
        $this->artisan('api-snapshot:tests', ['--output' => $this->dir, '--driver' => 'pest', '--force' => true])
            ->assertExitCode(0);

        $this->assertFileExists($this->dir . DIRECTORY_SEPARATOR . 'AdminTest.php');
        $this->assertFileExists($this->dir . DIRECTORY_SEPARATOR . 'PatientTest.php');

        $manifest = $this->manifest();

        $this->assertSame('pest', $manifest['driver']);
        $this->assertArrayHasKey('AdminTest.php', $manifest['files']);
        $this->assertContains('GET /api/v1/admin/users/example/status', $manifest['files']['AdminTest.php']);
    }

    #[Test]
    public function it_can_render_phpunit_classes_instead(): void
    {
        $this->artisan('api-snapshot:tests', [
            '--output'  => $this->dir,
            '--driver'  => 'phpunit',
            '--force'   => true,
            '--exclude' => 'panel',
        ])->assertExitCode(0);

        $this->assertStringContainsString(
            'final class AdminTest extends TestCase',
            (string) file_get_contents($this->dir . DIRECTORY_SEPARATOR . 'AdminTest.php'),
        );
    }

    #[Test]
    public function it_dry_runs_without_touching_the_disk(): void
    {
        $this->artisan('api-snapshot:tests', ['--output' => $this->dir, '--dry-run' => true])->assertExitCode(0);

        $this->assertSame([], glob($this->dir . DIRECTORY_SEPARATOR . '*'));
    }

    #[Test]
    public function it_removes_generated_files_whose_route_disappeared(): void
    {
        $this->artisan('api-snapshot:tests', [
            '--output' => $this->dir,
            '--driver' => 'pest',
            '--paths'  => 'api/v1/admin,api/v1/patients',
            '--force'  => true,
        ])->assertExitCode(0);

        $this->assertFileExists($this->dir . DIRECTORY_SEPARATOR . 'AdminTest.php');
        $this->assertFileExists($this->dir . DIRECTORY_SEPARATOR . 'PatientTest.php');

        $this->artisan('api-snapshot:tests', [
            '--output' => $this->dir,
            '--driver' => 'pest',
            '--paths'  => 'api/v1/patients',
            '--force'  => true,
        ])->assertExitCode(0);

        $this->assertFileDoesNotExist($this->dir . DIRECTORY_SEPARATOR . 'AdminTest.php');
        $this->assertFileExists($this->dir . DIRECTORY_SEPARATOR . 'PatientTest.php');
    }

    #[Test]
    public function it_keeps_every_file_when_pruning_is_off(): void
    {
        $this->artisan('api-snapshot:tests', ['--output' => $this->dir, '--driver' => 'pest', '--paths' => 'api/v1/admin', '--force' => true]);

        $this->artisan('api-snapshot:tests', [
            '--output'   => $this->dir,
            '--driver'   => 'pest',
            '--paths'    => 'api/v1/patients',
            '--no-prune' => true,
            '--force'    => true,
        ])->assertExitCode(0);

        $this->assertFileExists($this->dir . DIRECTORY_SEPARATOR . 'AdminTest.php');
    }

    #[Test]
    public function it_never_deletes_a_file_it_did_not_generate(): void
    {
        file_put_contents($this->dir . DIRECTORY_SEPARATOR . 'AdminTest.php', "<?php\n// hand written\n");

        $this->artisan('api-snapshot:tests', [
            '--output' => $this->dir,
            '--driver' => 'pest',
            '--paths'  => 'api/v1/patients',
            '--force'  => true,
        ])->assertExitCode(0);

        $this->assertStringContainsString('hand written', (string) file_get_contents($this->dir . DIRECTORY_SEPARATOR . 'AdminTest.php'));
    }

    #[Test]
    public function it_warns_and_scans_everything_when_a_filter_matches_nothing(): void
    {
        $this->artisan('api-snapshot:tests', ['--output' => $this->dir, '--driver' => 'pest', '--paths' => 'api/v1/nothing'])
            ->assertExitCode(0);

        $this->assertNotEmpty(glob($this->dir . DIRECTORY_SEPARATOR . '*.php'));
    }

    #[Test]
    public function the_installer_reports_what_it_changed(): void
    {
        $this->artisan('api-snapshot:install-testing', ['--dry-run' => true, '--seeders' => 'RolePermissionSeeder'])
            ->assertExitCode(0)
            ->expectsOutputToContain('test environment setup');
    }
}
