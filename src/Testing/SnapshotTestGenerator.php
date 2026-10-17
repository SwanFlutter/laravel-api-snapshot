<?php

namespace SwanFlutter\LaravelApiSnapshot\Testing;

use SwanFlutter\LaravelApiSnapshot\Support\Utf8;

/**
 * Renders the scanned endpoints into Pest or PHPUnit test files, one file per
 * route group.
 */
final class SnapshotTestGenerator
{
    public const DRIVER_PEST = 'pest';

    public const DRIVER_PHPUNIT = 'phpunit';

    public const VERSION = '1.6.0';

    /** @param array<string, mixed> $config the "tests" section of api-snapshot config */
    public function __construct(
        private readonly array $config,
        private readonly string $driver = self::DRIVER_PEST,
        private readonly string $version = self::VERSION,
    ) {}

    public function driver(): string
    {
        return $this->driver;
    }

    /**
     * Which file each endpoint lands in, and how many tests it produces.
     *
     * @param  list<SnapshotEndpoint>  $endpoints
     * @return array<string, array{endpoints: list<SnapshotEndpoint>, tests: int}>
     */
    public function plan(array $endpoints): array
    {
        $plan = [];

        foreach ($this->buckets($endpoints) as $bucket => $group) {
            $name  = $this->filename($bucket);
            $tests = 0;

            foreach ($group as $endpoint) {
                $tests += $this->authTests() && $endpoint->authRequired ? 2 : 1;
            }

            $plan[$name] = ['endpoints' => $group, 'tests' => $tests];
        }

        return $plan;
    }

    /**
     * @param  list<SnapshotEndpoint>  $endpoints
     * @return array<string, string>  filename => file contents
     */
    public function files(array $endpoints): array
    {
        $files = [];

        foreach ($this->buckets($endpoints) as $bucket => $group) {
            $name         = $this->filename($bucket);
            $files[$name] = $this->driver === self::DRIVER_PHPUNIT
                ? $this->renderPhpUnit($bucket, $name, $group)
                : $this->renderPest($bucket, $group);
        }

        return $files;
    }

    /**
     * @param  list<SnapshotEndpoint>  $endpoints
     * @return array<string, list<SnapshotEndpoint>>
     */
    private function buckets(array $endpoints): array
    {
        $depth = max(1, (int) ($this->config['file_group_depth'] ?? 1));
        $buckets = [];

        foreach ($endpoints as $endpoint) {
            $parts  = $endpoint->group === '' ? [] : explode(' › ', $endpoint->group);
            $bucket = implode(' › ', array_slice($parts, 0, $depth)) ?: 'General';

            $buckets[$bucket][] = $endpoint;
        }

        ksort($buckets);

        return $buckets;
    }

    private function filename(string $bucket): string
    {
        $studly = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $bucket)));
        $clean  = preg_replace('/[^A-Za-z0-9]/', '', $studly) ?: 'General';

        return ($clean === '' ? 'General' : $clean) . 'Test.php';
    }

    /** The words a case title uses: a "handled" run only rules out 5xx. */
    private function phrase(SnapshotEndpoint $endpoint): string
    {
        return $endpoint->expectation === SnapshotEndpoint::EXPECT_SUCCESS
            ? 'responds successfully'
            : 'responds without a server error';
    }

    private function snakePhrase(SnapshotEndpoint $endpoint): string
    {
        return str_replace(' ', '_', $this->phrase($endpoint));
    }

    /** @param list<SnapshotEndpoint> $endpoints */
    private function renderPest(string $bucket, array $endpoints): string
    {
        $lines   = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = 'declare(strict_types=1);';
        $lines[] = '';
        $lines[] = $this->header($bucket, $endpoints);
        $lines[] = '';
        $lines[] = 'use SwanFlutter\\LaravelApiSnapshot\\Testing\\SnapshotEndpoint;';
        $lines[] = 'use SwanFlutter\\LaravelApiSnapshot\\Testing\\SnapshotRequests;';

        if ($this->refreshDatabase()) {
            $lines[] = 'use Illuminate\\Foundation\\Testing\\RefreshDatabase;';
        }

        $lines[] = '';

        if ($this->refreshDatabase()) {
            $lines[] = 'uses(RefreshDatabase::class);';
            $lines[] = '';
        }

        foreach ($this->cases($endpoints) as $identifier => [$endpoint, $variable]) {
            $lines[] = sprintf('$%s = new SnapshotEndpoint(', $variable);
            $lines[] = $this->constructorArguments($endpoint, 1);
            $lines[] = ');';
            $lines[] = '';
            $lines[] = sprintf(
                "it('%s %s', function () use (\$%s) {",
                $this->escape($endpoint->title()),
                $this->phrase($endpoint),
                $variable,
            );
            $lines[] = sprintf('    SnapshotRequests::run($this, $%s);', $variable);
            $lines[] = '});';

            if ($this->authTests() && $endpoint->authRequired) {
                $lines[] = '';
                $lines[] = sprintf(
                    "it('%s rejects a request without credentials', function () use (\$%s) {",
                    $this->escape($endpoint->title()),
                    $variable,
                );
                $lines[] = sprintf('    SnapshotRequests::runUnauthenticated($this, $%s);', $variable);
                $lines[] = '});';
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<SnapshotEndpoint>  $endpoints
     */
    private function renderPhpUnit(string $bucket, string $filename, array $endpoints): string
    {
        $class  = basename($filename, '.php');
        $lines  = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = 'declare(strict_types=1);';
        $lines[] = '';
        $lines[] = 'namespace ' . rtrim((string) ($this->config['namespace'] ?? 'Tests\\Feature\\ApiSnapshot'), '\\') . ';';
        $lines[] = '';
        $lines[] = $this->header($bucket, $endpoints);
        $lines[] = '';
        $lines[] = 'use SwanFlutter\\LaravelApiSnapshot\\Testing\\SnapshotEndpoint;';
        $lines[] = 'use SwanFlutter\\LaravelApiSnapshot\\Testing\\SnapshotRequests;';

        if ($this->refreshDatabase()) {
            $lines[] = 'use Illuminate\\Foundation\\Testing\\RefreshDatabase;';
        }

        $lines[] = 'use ' . ($this->config['base_class'] ?? 'Tests\\TestCase') . ';';
        $lines[] = '';
        $lines[] = "final class {$class} extends TestCase";
        $lines[] = '{';

        if ($this->refreshDatabase()) {
            $lines[] = '    use RefreshDatabase;';
            $lines[] = '';
        }

        foreach ($this->cases($endpoints) as $index => [$endpoint, $variable]) {
            $method = $this->methodName($endpoint, (int) $index);

            $lines[] = sprintf('    private function %sCase(): SnapshotEndpoint', $method);
            $lines[] = '    {';
            $lines[] = sprintf('        return new SnapshotEndpoint(');
            $lines[] = $this->constructorArguments($endpoint, 3);
            $lines[] = '        );';
            $lines[] = '    }';
            $lines[] = '';
            $lines[] = sprintf('    public function test_%s_%s(): void', $method, $this->snakePhrase($endpoint));
            $lines[] = '    {';
            $lines[] = sprintf('        SnapshotRequests::run($this, $this->%sCase());', $method);
            $lines[] = '    }';

            if ($this->authTests() && $endpoint->authRequired) {
                $lines[] = '';
                $lines[] = sprintf('    public function test_%s_rejects_a_request_without_credentials(): void', $method);
                $lines[] = '    {';
                $lines[] = sprintf('        SnapshotRequests::runUnauthenticated($this, $this->%sCase());', $method);
                $lines[] = '    }';
            }

            $lines[] = '';
        }

        $lines[] = '}';

        return implode("\n", $lines);
    }

    /**
     * @param  list<SnapshotEndpoint>  $endpoints
     * @return array<string, array{0: SnapshotEndpoint, 1: string}>
     */
    private function cases(array $endpoints): array
    {
        $cases    = [];
        $used     = [];

        foreach ($endpoints as $index => $endpoint) {
            $base = $this->identifier($endpoint);
            $name = $base;
            $slot = 2;

            while (isset($used[$name])) {
                $name = $base . (++$slot);
            }

            $used[$name] = true;
            $cases[(string) $index] = [$endpoint, $name];
        }

        return $cases;
    }

    private function identifier(SnapshotEndpoint $endpoint): string
    {
        $parts = array_merge(
            [strtolower($endpoint->method)],
            preg_split('#[^A-Za-z0-9]+#', trim($endpoint->uri, '/')) ?: [],
        );

        $parts = array_filter($parts, fn ($part) => $part !== '' && ! ctype_digit($part));
        $camel = array_shift($parts) ?: 'case';

        foreach ($parts as $part) {
            $camel .= ucfirst($part);
        }

        return lcfirst($camel) === '' ? 'case' : lcfirst($camel);
    }

    private function methodName(SnapshotEndpoint $endpoint, int $index): string
    {
        $camel = $this->identifier($endpoint);
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $camel) ?? $camel);

        return $snake === '' ? 'case_' . $index : $snake . '_' . $index;
    }

    private function constructorArguments(SnapshotEndpoint $endpoint, int $indentLevel): string
    {
        $pad = str_repeat('    ', $indentLevel);

        $arguments = [
            'method'       => var_export($endpoint->method, true),
            'uri'          => var_export($endpoint->uri, true),
            'query'        => $endpoint->isReadOnly() ? Code::export($endpoint->query, $indentLevel) : '[]',
            'body'         => $endpoint->isReadOnly() ? '[]' : Code::export($endpoint->body, $indentLevel),
            'multipart'    => $endpoint->multipart ? 'true' : 'false',
            'authRequired' => $endpoint->authRequired ? 'true' : 'false',
            'surface'      => var_export($endpoint->surface, true),
            'group'        => var_export($endpoint->group, true),
            'action'       => var_export($endpoint->action, true),
            'middleware'   => Code::export($endpoint->middleware, $indentLevel),
            'expectation'  => $endpoint->expectsSuccess()
                ? 'SnapshotEndpoint::EXPECT_SUCCESS'
                : 'SnapshotEndpoint::EXPECT_HANDLED',
        ];

        $lines = [];

        foreach ($arguments as $name => $value) {
            $lines[] = sprintf('%s%s: %s,', $pad, $name, $value);
        }

        return implode("\n", $lines);
    }

    /** @param list<SnapshotEndpoint> $endpoints */
    private function header(string $bucket, array $endpoints): string
    {
        $protected = count(array_filter($endpoints, fn (SnapshotEndpoint $e) => $e->authRequired));

        return implode("\n", [
            '/**',
            sprintf(' * Generated by swanflutter/laravel-api-snapshot %s (api-snapshot:tests).', $this->version),
            sprintf(' * Group: %s — %d endpoints, %d protected by authentication.', Utf8::sanitize($bucket), count($endpoints), $protected),
            ' *',
            ' * Do not edit by hand: regenerate with `php artisan api-snapshot:tests`.',
            ' */',
        ]);
    }

    private function refreshDatabase(): bool
    {
        return (bool) ($this->config['refresh_database'] ?? true);
    }

    private function authTests(): bool
    {
        return (bool) ($this->config['auth_denial_tests'] ?? true);
    }

    private function escape(string $value): string
    {
        return str_replace(["\\", "'"], ["\\\\", "\\'"], Utf8::sanitize($value));
    }
}
