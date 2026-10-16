<?php

declare(strict_types=1);

namespace SwanFlutter\LaravelApiSnapshot\Tests;

use PHPUnit\Framework\Attributes\Test;
use SwanFlutter\LaravelApiSnapshot\Testing\TestEnvironment;

final class TestEnvironmentTest extends TestCase
{
    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'snapshot-env-' . getmypid();
        @mkdir($this->sandbox, 0777, true);

        file_put_contents($this->sandbox . '/phpunit.xml', <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <phpunit>
                <testsuites>
                    <testsuite name="Unit"><directory>tests/Unit</directory></testsuite>
                </testsuites>
                <php>
                    <env name="APP_ENV" value="production"/>
                    <env name="DB_CONNECTION" value="mysql"/>
                </php>
            </phpunit>
            XML);

        @unlink($this->sandbox . '/.env.testing');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->sandbox . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @unlink($this->sandbox . '/config/api-snapshot.php');
        @unlink($this->sandbox . '/tests/Support/SnapshotAuthenticator.php');
        @rmdir($this->sandbox . '/config');
        @rmdir($this->sandbox . '/tests/Support');
        @rmdir($this->sandbox . '/tests');
        @rmdir($this->sandbox);

        parent::tearDown();
    }

    private function environment(bool $force = false, bool $dryRun = false): TestEnvironment
    {
        return new TestEnvironment($this->sandbox, $force, $dryRun);
    }

    #[Test]
    public function it_writes_a_complete_env_testing_file(): void
    {
        $this->environment()->patchEnv(TestEnvironment::defaults());

        $env = (string) file_get_contents($this->sandbox . '/.env.testing');

        $this->assertStringContainsString('APP_ENV=testing', $env);
        $this->assertStringContainsString('DB_CONNECTION=sqlite', $env);
        $this->assertStringContainsString('DB_DATABASE=:memory:', $env);
        $this->assertStringContainsString('CACHE_STORE=array', $env);
    }

    #[Test]
    public function it_keeps_an_existing_value_until_forced(): void
    {
        file_put_contents($this->sandbox . '/.env.testing', "DB_CONNECTION=mysql\nAPP_KEY=old\n");

        $changes = $this->environment()->patchEnv(TestEnvironment::defaults());

        $this->assertStringContainsString('DB_CONNECTION=mysql', (string) file_get_contents($this->sandbox . '/.env.testing'));
        $this->assertStringContainsString('kept', implode(' ', $changes));
        $this->assertStringContainsString('CACHE_STORE=array', (string) file_get_contents($this->sandbox . '/.env.testing'));

        $this->environment(force: true)->patchEnv(TestEnvironment::defaults());

        $this->assertStringContainsString('DB_CONNECTION=sqlite', (string) file_get_contents($this->sandbox . '/.env.testing'));
        $this->assertStringNotContainsString('DB_CONNECTION=mysql', (string) file_get_contents($this->sandbox . '/.env.testing'));
    }

    #[Test]
    public function it_copies_the_base_env_because_testing_replaces_it(): void
    {
        file_put_contents($this->sandbox . '/.env', "APP_NAME=My API\nREDIS_HOST=127.0.0.1\nDB_CONNECTION=mysql\nDB_DATABASE=forge\n");

        $changes = $this->environment()->patchEnv(TestEnvironment::defaults());

        $env = (string) file_get_contents($this->sandbox . '/.env.testing');

        $this->assertStringContainsString('started from .env', implode(' ', $changes));
        $this->assertStringContainsString('APP_NAME=My API', $env);
        $this->assertStringContainsString('REDIS_HOST=127.0.0.1', $env);

        // The copied production values are the ones being tested against, so they change.
        $this->assertStringContainsString('DB_CONNECTION=sqlite', $env);
        $this->assertStringContainsString('DB_DATABASE=:memory:', $env);
        $this->assertStringContainsString('CACHE_STORE=array', $env);
        $this->assertStringNotContainsString('DB_CONNECTION=mysql', $env);
        $this->assertStringNotContainsString('DB_DATABASE=forge', $env);

        // An existing .env.testing keeps its own decisions.
        file_put_contents($this->sandbox . '/.env.testing', "DB_CONNECTION=sqlite\nDB_DATABASE=database/test.sqlite\n");

        $this->environment()->patchEnv(TestEnvironment::defaults());

        $this->assertStringContainsString('DB_DATABASE=database/test.sqlite', (string) file_get_contents($this->sandbox . '/.env.testing'));

        $this->environment(force: true)->patchEnv(TestEnvironment::defaults());

        $this->assertStringContainsString('DB_DATABASE=:memory:', (string) file_get_contents($this->sandbox . '/.env.testing'));
    }

    #[Test]
    public function it_updates_the_env_tags_of_a_phpunit_file(): void
    {
        $this->environment()->patchEnv(TestEnvironment::defaults('sqlite', 'database/test.sqlite'));
        $this->environment()->patchPhpUnit(TestEnvironment::defaults('sqlite', 'database/test.sqlite'));

        $xml = (string) file_get_contents($this->sandbox . '/phpunit.xml');

        $this->assertStringContainsString('<env name="APP_ENV" value="testing"/>', $xml);
        $this->assertStringContainsString('<env name="DB_CONNECTION" value="sqlite"/>', $xml);
        $this->assertStringContainsString('<env name="DB_DATABASE" value="database/test.sqlite"/>', $xml);
        $this->assertStringContainsString('<env name="SESSION_DRIVER" value="array"/>', $xml);
        $this->assertSame(1, substr_count($xml, '</php>'));
        $this->assertNotFalse(simplexml_load_string($xml));
    }

    #[Test]
    public function it_creates_the_sqlite_file_only_when_it_is_missing(): void
    {
        $changes = $this->environment()->createSqliteFile('database/test.sqlite');

        $this->assertFileExists($this->sandbox . '/database/test.sqlite');
        $this->assertSame(['created database/test.sqlite'], $changes);

        $this->assertSame([], $this->environment()->createSqliteFile('database/test.sqlite'));
    }

    #[Test]
    public function it_stores_seeders_and_the_authenticator_in_the_published_config(): void
    {
        @mkdir($this->sandbox . '/config', 0777, true);

        file_put_contents($this->sandbox . '/config/api-snapshot.php', <<<PHP
            <?php

            return [
                'tests' => [
                    'seeders' => [],
                    'authenticator' => null,
                ],
            ];
            PHP);

        $this->environment()->patchConfig(['Database\\Seeders\\RolePermissionSeeder'], 'Tests\\Support\\SnapshotAuthenticator');

        $config = (string) file_get_contents($this->sandbox . '/config/api-snapshot.php');

        $this->assertStringContainsString("'seeders' => [Database\\Seeders\\RolePermissionSeeder::class]", $config);
        $this->assertStringContainsString("'authenticator' => 'Tests\\Support\\SnapshotAuthenticator'", $config);
    }

    #[Test]
    public function it_adds_the_tests_section_to_a_config_published_by_an_older_version(): void
    {
        @mkdir($this->sandbox . '/config', 0777, true);

        file_put_contents($this->sandbox . '/config/api-snapshot.php', <<<PHP
            <?php

            return [
                'name' => 'My API',
                'auth' => ['type' => 'bearer'],
            ];
            PHP);

        $changes = $this->environment()->patchConfig(['Database\\Seeders\\RolePermissionSeeder'], 'Tests\\Support\\SnapshotAuthenticator');

        $path     = $this->sandbox . '/config/api-snapshot.php';
        $contents = (string) file_get_contents($path);

        $this->assertStringContainsString('added the tests section', implode(' ', $changes));
        $this->assertStringContainsString("'seeders' => [Database\\Seeders\\RolePermissionSeeder::class]", $contents);
        $this->assertStringContainsString("'authenticator' => 'Tests\\Support\\SnapshotAuthenticator'", $contents);

        // Still a config the application can load, with the old keys untouched.
        $config = require $path;

        $this->assertSame('My API', $config['name']);
        $this->assertSame('bearer', $config['auth']['type']);
        $this->assertSame('handled', $config['tests']['expect']);

        // A second run must not append a second section.
        $this->environment()->patchConfig([], 'Tests\\Support\\SnapshotAuthenticator');

        $this->assertSame(1, substr_count((string) file_get_contents($path), "'tests' => ["));
    }

    #[Test]
    public function it_writes_an_authenticator_stub_once(): void
    {
        $this->environment()->writeAuthenticatorStub();

        $path = $this->sandbox . '/tests/Support/SnapshotAuthenticator.php';

        $this->assertFileExists($path);
        $this->assertStringContainsString('namespace Tests\Support;', (string) file_get_contents($path));
        $this->assertStringContainsString('implements SnapshotAuthenticatorContract', (string) file_get_contents($path));

        $changes = $this->environment()->writeAuthenticatorStub();

        $this->assertStringContainsString('already exists and was kept', implode(' ', $changes));
    }

    #[Test]
    public function dry_run_reports_without_writing(): void
    {
        $this->environment(dryRun: true)->patchEnv(TestEnvironment::defaults());
        $this->environment(dryRun: true)->writeAuthenticatorStub();

        $this->assertFileDoesNotExist($this->sandbox . '/.env.testing');
        $this->assertFileDoesNotExist($this->sandbox . '/tests/Support/SnapshotAuthenticator.php');
    }
}
