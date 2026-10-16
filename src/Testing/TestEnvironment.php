<?php

namespace SwanFlutter\LaravelApiSnapshot\Testing;

/**
 * Writes the test environment the generated snapshot tests need: an isolated
 * database in .env.testing and phpunit.xml, plus the config keys that drive
 * seeding and authentication. Every method reports what it changed.
 */
final class TestEnvironment
{
    /** @var list<string> */
    private array $changes = [];

    public function __construct(
        private readonly string $basePath,
        private readonly bool $force = false,
        private readonly bool $dryRun = false,
    ) {}

    /** @return array<string, string> */
    public static function defaults(string $connection = 'sqlite', string $database = ':memory:'): array
    {
        return [
            'APP_ENV'          => 'testing',
            'DB_CONNECTION'    => $connection,
            'DB_DATABASE'      => $database,
            'CACHE_STORE'      => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER'   => 'array',
            'MAIL_MAILER'      => 'array',
        ];
    }

    /**
     * @param  array<string, string>  $values
     * @return list<string>
     */
    public function patchEnv(array $values): array
    {
        $before = count($this->changes);

        $path   = $this->basePath . DIRECTORY_SEPARATOR . '.env.testing';
        $exists = is_file($path);
        $lines  = $exists
            ? (file($path, FILE_IGNORE_NEW_LINES) ?: [])
            : $this->startingEnvLines();

        foreach ($values as $key => $value) {
            $line    = sprintf('%s=%s', $key, $value);
            $current = self::findEnvLine($lines, $key);

            if ($current === null) {
                $lines[] = $line;
                $this->changes[] = sprintf('.env.testing: added %s', $line);
                continue;
            }

            if (trim((string) $lines[$current]) === $line) {
                continue;
            }

            // A file copied from .env is a starting point, not a decision the
            // developer made, so its production values are still replaced.
            if (! $this->force && $exists) {
                $this->changes[] = sprintf('.env.testing: kept %s (use --force to change it)', $key);
                continue;
            }

            $lines[$current] = $line;
            $this->changes[] = sprintf('.env.testing: %s', $line);
        }

        $this->write($path, implode("\n", $lines) . "\n");

        return $this->since($before);
    }

    /**
     * With `.env.testing` present Laravel loads only that file, so every value
     * the application reads from `.env` has to be copied over first — an
     * environment of just the test keys would break the app.
     *
     * @return list<string>
     */
    private function startingEnvLines(): array
    {
        $base = $this->basePath . DIRECTORY_SEPARATOR . '.env';

        if (is_readable($base)) {
            $this->changes[] = '.env.testing: started from .env';

            return file($base, FILE_IGNORE_NEW_LINES) ?: [];
        }

        return [
            '# Test environment used by "php artisan test" and ./vendor/bin/pest.',
            '# Written by swanflutter/laravel-api-snapshot (api-snapshot:install-testing).',
        ];
    }

    /**
     * @param  array<string, string>  $values
     * @return list<string>
     */
    public function patchPhpUnit(array $values): array
    {
        $before = count($this->changes);

        $path = $this->basePath . DIRECTORY_SEPARATOR . 'phpunit.xml';

        if (! is_readable($path)) {
            $this->changes[] = 'phpunit.xml: not found, set the test environment by hand';

            return $this->since($before);
        }

        $xml = (string) file_get_contents($path);

        foreach ($values as $key => $value) {
            $pattern = sprintf('#<env name="%s" value="[^"]*"/>#', preg_quote($key, '#'));
            $tag     = sprintf('<env name="%s" value="%s"/>', $key, htmlspecialchars($value, ENT_QUOTES));

            if (preg_match($pattern, $xml) === 1) {
                $xml = preg_replace($pattern, $tag, $xml, 1) ?? $xml;
                continue;
            }

            $xml = self::insertEnvTag($xml, $tag);
            $this->changes[] = sprintf('phpunit.xml: <env name="%s" value="%s"/>', $key, $value);
        }

        $this->write($path, $xml);

        return $this->since($before);
    }

    /** @return list<string> */
    public function createSqliteFile(string $relative): array
    {
        $before = count($this->changes);

        $path = $this->basePath . DIRECTORY_SEPARATOR . $relative;

        if (is_file($path)) {
            return $this->since($before);
        }

        if ($this->dryRun) {
            $this->changes[] = sprintf('would create %s', $relative);

            return $this->since($before);
        }

        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0755, true) && ! is_dir(dirname($path))) {
            $this->changes[] = sprintf('cannot create the directory for %s', $relative);

            return $this->since($before);
        }

        touch($path);
        $this->changes[] = sprintf('created %s', $relative);

        return $this->since($before);
    }

    /**
     * @param  list<string>  $seeders
     * @return list<string>
     */
    public function patchConfig(array $seeders = [], ?string $authenticator = null): array
    {
        $before = count($this->changes);

        $path = $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'api-snapshot.php';

        if (! is_readable($path)) {
            if ($seeders !== [] || $authenticator !== null) {
                $this->changes[] = 'config/api-snapshot.php is not published, so these settings were not stored';
            }

            return $this->since($before);
        }

        $config = (string) file_get_contents($path);

        if ($seeders !== [] || $authenticator !== null) {
            $config = $this->ensureTestsSection($config);
        }

        if ($seeders !== []) {
            $list   = implode(', ', array_map(fn (string $class) => ltrim($class, '\\') . '::class', $seeders));
            $config = $this->replaceConfigValue($config, 'seeders', '[' . $list . ']');
        }

        if ($authenticator !== null && $authenticator !== '') {
            $config = $this->replaceConfigValue($config, 'authenticator', sprintf('\'%s\'', ltrim($authenticator, '\\')));
        }

        $this->write($path, $config);

        return $this->since($before);
    }

    /**
     * Config published by an older version has no "tests" block, which would
     * leave the seeder and authenticator settings nowhere to go.
     */
    private function ensureTestsSection(string $config): string
    {
        if (preg_match("/'tests'\s*=>\s*\[/", $config) === 1) {
            return $config;
        }

        $position = strrpos($config, '];');

        if ($position === false) {
            $this->changes[] = 'config/api-snapshot.php has no closing array bracket, left as is';

            return $config;
        }

        $block = <<<'PHP'
    'tests' => [
        'driver' => 'auto',
        'output' => 'tests/Feature/ApiSnapshot',
        'namespace' => 'Tests\\Feature\\ApiSnapshot',
        'base_class' => 'Tests\\TestCase',
        'file_group_depth' => 1,
        'expect' => 'handled',
        'auth_denial_tests' => true,
        'refresh_database' => true,
        'seeders' => [],
        'authenticator' => null,
    ],


PHP;

        $this->changes[] = 'config/api-snapshot.php: added the tests section';

        return substr($config, 0, $position) . $block . substr($config, $position);
    }

    public function authenticatorStubPath(): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Support'
            . DIRECTORY_SEPARATOR . 'SnapshotAuthenticator.php';
    }

    /** @return list<string> */
    public function writeAuthenticatorStub(?string $class = null): array
    {
        $before = count($this->changes);

        $path = $this->authenticatorStubPath();

        if (is_file($path)) {
            $this->changes[] = 'tests/Support/SnapshotAuthenticator.php already exists and was kept';

            return $this->since($before);
        }

        if ($this->dryRun) {
            $this->changes[] = 'would write tests/Support/SnapshotAuthenticator.php';

            return $this->since($before);
        }

        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0755, true) && ! is_dir(dirname($path))) {
            $this->changes[] = 'cannot create tests/Support';

            return $this->since($before);
        }

        $this->write($path, self::authenticatorStub($class ?? 'Tests\\Support'));
        $this->changes[] = 'wrote tests/Support/SnapshotAuthenticator.php';

        return $this->since($before);
    }

    /** @return list<string> */
    private function since(int $from): array
    {
        return array_slice($this->changes, $from);
    }

    private function write(string $path, string $contents): void
    {
        if ($this->dryRun) {
            return;
        }

        if (file_put_contents($path, $contents) === false) {
            $this->changes[] = sprintf('unable to write %s', $path);
        }
    }

    private function replaceConfigValue(string $config, string $key, string $value): string
    {
        $pattern = sprintf('/(\'%s\'\s*=>\s*)(\[[^\]]*\]|null|\'[^\']*\'|"[^"]*")/', $key);

        if (preg_match($pattern, $config) !== 1) {
            $this->changes[] = sprintf('config/api-snapshot.php has no "%s" key, left as is', $key);

            return $config;
        }

        $this->changes[] = sprintf('config: tests.%s', $key);

        return preg_replace_callback($pattern, fn (array $m) => $m[1] . $value, $config, 1) ?? $config;
    }

    /**
     * @param  list<string>  $lines
     */
    private static function findEnvLine(array $lines, string $key): ?int
    {
        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=/', (string) $line) === 1) {
                return (int) $index;
            }
        }

        return null;
    }

    private static function insertEnvTag(string $xml, string $tag): string
    {
        if (str_contains($xml, '</php>')) {
            return preg_replace('#[ \t]*</php>#', "        {$tag}\n    </php>", $xml, 1) ?? $xml;
        }

        return preg_replace('#</phpunit>#', "    <php>\n        {$tag}\n    </php>\n</phpunit>", $xml, 1) ?? $xml;
    }

    private static function authenticatorStub(string $namespace): string
    {
        return <<<PHP
        <?php

        namespace {$namespace};

        use SwanFlutter\\LaravelApiSnapshot\\Testing\\SnapshotAuthenticator as SnapshotAuthenticatorContract;
        use SwanFlutter\\LaravelApiSnapshot\\Testing\\SnapshotEndpoint;

        /**
         * Signs each generated snapshot test in for the surface its route belongs
         * to. Return headers to send them, [] when the test was authenticated in
         * place (Sanctum::actingAs() and friends), or null to skip the endpoint
         * until a fixture for that surface exists.
         */
        class SnapshotAuthenticator implements SnapshotAuthenticatorContract
        {
            public function authenticate(object \$test, SnapshotEndpoint \$endpoint): ?array
            {
                return match (\$endpoint->surface) {
                    // 'admin'  => (\$this->admin(\$test)) ? [] : null,
                    // 'doctor' => (\$this->doctor(\$test)) ? [] : null,
                    default => null,
                };
            }
        }

        PHP;
    }
}
