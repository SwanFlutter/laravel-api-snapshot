<?php

namespace SwanFlutter\LaravelApiSnapshot\Console;

use Illuminate\Console\Command;
use SwanFlutter\LaravelApiSnapshot\ApiSnapshotGenerator;
use SwanFlutter\LaravelApiSnapshot\Testing\SnapshotEndpoint;
use SwanFlutter\LaravelApiSnapshot\Testing\SnapshotTestGenerator;
use Throwable;

class SnapshotTestsCommand extends Command
{
    protected $signature = 'api-snapshot:tests
        {--driver=        : Test framework to generate for: pest or phpunit}
        {--output=        : Directory the test files are written to}
        {--file-depth=    : How many route-group levels make one test file}
        {--expect=        : What a request must answer: handled (no 5xx) or success (2xx/3xx)}
        {--namespace=     : Namespace for generated PHPUnit classes}
        {--include=       : Comma-separated middleware names that select routes}
        {--paths=         : Comma-separated URI prefixes or wildcards that select routes}
        {--exclude=       : Comma-separated URI patterns to exclude}
        {--all            : Scan every route, ignoring the include filters}
        {--group-by=      : Grouping strategy: prefix or controller}
        {--depth=         : Number of route-group levels used for titles}
        {--no-fakes       : Leave parameter and body values empty}
        {--no-auth-tests  : Skip the "rejects a request without credentials" tests}
        {--no-refresh-db  : Do not add RefreshDatabase to the generated tests}
        {--no-prune       : Keep generated files whose endpoint no longer exists}
        {--dry-run        : Show what would be written without touching the disk}
        {--force          : Overwrite existing files without prompting}
        {--no-cache       : Ignore the cached snapshot and rescan}
        {--clear-cache    : Clear cache before scanning}';

    protected $description = 'Generate integration tests for every API route found by the snapshot scanner';

    private const MANIFEST = '_snapshot-manifest.json';

    /** @var array<string, mixed> */
    private array $config = [];

    public function handle(): int
    {
        $this->components->info('Laravel API Snapshot — integration test generator');

        $this->config = $this->buildConfig();
        $tests        = (array) ($this->config['tests'] ?? []);
        $output       = $this->resolveOutputPath((string) ($tests['output'] ?? 'tests/Feature/ApiSnapshot'));

        $scanner = new ApiSnapshotGenerator(
            $this->laravel['router'],
            $this->config,
            $this->laravel->make('cache'),
            (bool) $this->option('no-cache'),
        );

        if ($this->option('clear-cache')) {
            $scanner->clearCache();
        }

        $endpoints = $scanner->scanEndpoints();

        foreach ($scanner->warnings() as $warning) {
            $this->components->warn($warning);
        }

        if ($endpoints === []) {
            $this->components->error('No endpoints matched the filters, so no tests were generated.');

            return self::FAILURE;
        }

        $writer = new SnapshotTestGenerator($tests, $this->resolveDriver($tests));
        $plan   = $writer->plan($endpoints);

        $this->table(['File', 'Endpoints', 'Tests'], $this->rows($plan));

        if ($this->option('dry-run')) {
            $this->components->info(sprintf(
                '[dry-run] %d file(s) and %d test(s) would be written to %s.',
                count($plan),
                $this->totalTests($plan),
                $output,
            ));

            return self::SUCCESS;
        }

        if (! $this->confirmWrite($output, array_keys($plan))) {
            $this->components->warn('Aborted.');

            return self::SUCCESS;
        }

        $files  = $writer->files($endpoints);
        $pruned = $this->write($output, $files, $plan, $writer->driver());

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green>Tests written to</>', $output);
        $this->components->twoColumnDetail('<fg=green>Total</>', sprintf(
            '%d file(s) · %d endpoint(s) · %d test(s)',
            count($files),
            count($endpoints),
            $this->totalTests($plan),
        ));

        if ($pruned > 0) {
            $this->components->info(sprintf('Removed %d stale generated file(s).', $pruned));
        }

        $this->newLine();
        $this->line('  Run them with <options=bold>' . ($writer->driver() === SnapshotTestGenerator::DRIVER_PEST
            ? './vendor/bin/pest'
            : 'php artisan test') . '</>');

        if (($tests['authenticator'] ?? null) !== null && $tests['authenticator'] !== '') {
            return self::SUCCESS;
        }

        $this->components->warn(
            'No tests.authenticator class is configured, so every protected endpoint will be skipped. '
            . 'Run "php artisan api-snapshot:install-testing --with-authenticator-stub" to set one up.',
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array{endpoints: list<SnapshotEndpoint>, tests: int}>  $plan
     * @return list<array<int, string>>
     */
    private function rows(array $plan): array
    {
        $rows = [];

        foreach ($plan as $name => $bucket) {
            $rows[] = [$name, (string) count($bucket['endpoints']), (string) $bucket['tests']];
        }

        return $rows;
    }

    /** @param array<string, array{endpoints: list<SnapshotEndpoint>, tests: int}> $plan */
    private function totalTests(array $plan): int
    {
        return array_sum(array_column($plan, 'tests'));
    }

    /**
     * @param  array<string, string>  $files
     * @param  array<string, array{endpoints: list<SnapshotEndpoint>, tests: int}>  $plan
     */
    private function write(string $output, array $files, array $plan, string $driver): int
    {
        if (! is_dir($output) && ! mkdir($output, 0755, true) && ! is_dir($output)) {
            $this->components->error("Unable to create [{$output}].");

            return 0;
        }

        $pruned = $this->prune($output, array_keys($files));

        foreach ($files as $name => $contents) {
            if (file_put_contents($output . DIRECTORY_SEPARATOR . $name, $contents . "\n") === false) {
                $this->components->error("Unable to write [{$name}].");
            }
        }

        $manifest = ['driver' => $driver, 'written_at' => date('c'), 'files' => []];

        foreach ($plan as $name => $bucket) {
            $manifest['files'][$name] = array_values(array_map(
                fn (SnapshotEndpoint $endpoint) => strtoupper($endpoint->method) . ' ' . $endpoint->uri,
                $bucket['endpoints'],
            ));
        }

        file_put_contents(
            $output . DIRECTORY_SEPARATOR . self::MANIFEST,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
        );

        return $pruned;
    }

    /**
     * Generated files whose endpoint disappeared from the route list. Only
     * files carrying the generator header are ever removed.
     *
     * @param  list<string>  $keep
     */
    private function prune(string $output, array $keep): int
    {
        if ($this->option('no-prune')) {
            return 0;
        }

        $manifestPath = $output . DIRECTORY_SEPARATOR . self::MANIFEST;

        if (! is_readable($manifestPath)) {
            return 0;
        }

        try {
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return 0;
        }

        $removed = 0;

        foreach (array_keys((array) ($manifest['files'] ?? [])) as $name) {
            $name = (string) $name;

            if (in_array($name, $keep, true) || ! is_file($output . DIRECTORY_SEPARATOR . $name)) {
                continue;
            }

            $head = (string) file_get_contents($output . DIRECTORY_SEPARATOR . $name, false, null, 0, 400);

            if (str_contains($head, 'api-snapshot:tests')) {
                unlink($output . DIRECTORY_SEPARATOR . $name);
                $removed++;
            }
        }

        return $removed;
    }

    /** @param  list<string>  $names */
    private function confirmWrite(string $output, array $names): bool
    {
        if ($this->option('force')) {
            return true;
        }

        $existing = array_values(array_filter($names, fn (string $name) => is_file($output . DIRECTORY_SEPARATOR . $name)));

        if ($existing === []) {
            return true;
        }

        $this->components->warn(sprintf('%d generated file(s) already exist.', count($existing)));

        return $this->confirm('Overwrite them?', true);
    }

    /** @param array<string, mixed> $tests */
    private function resolveDriver(array $tests): string
    {
        $option = strtolower((string) $this->option('driver'));

        if (in_array($option, [SnapshotTestGenerator::DRIVER_PEST, SnapshotTestGenerator::DRIVER_PHPUNIT], true)) {
            return $option;
        }

        $configured = strtolower((string) ($tests['driver'] ?? 'auto'));

        if (in_array($configured, [SnapshotTestGenerator::DRIVER_PEST, SnapshotTestGenerator::DRIVER_PHPUNIT], true)) {
            return $configured;
        }

        $composer = is_readable(base_path('composer.json'))
            ? (array) json_decode((string) file_get_contents(base_path('composer.json')), true)
            : [];

        $requires = array_merge((array) ($composer['require'] ?? []), (array) ($composer['require-dev'] ?? []));

        return isset($requires['pestphp/pest']) ? SnapshotTestGenerator::DRIVER_PEST : SnapshotTestGenerator::DRIVER_PHPUNIT;
    }

    /** @return array<string, mixed> */
    private function buildConfig(): array
    {
        $config = config('api-snapshot', []);
        $tests  = (array) ($config['tests'] ?? []);

        foreach ([
            'include_middleware' => 'include',
            'include_paths'      => 'paths',
        ] as $key => $option) {
            if ($this->option($option) !== null) {
                $config[$key] = $this->csv((string) $this->option($option));
            }
        }

        if ($this->option('all')) {
            $config['scan_all_routes'] = true;
        }

        if ($this->option('no-fakes')) {
            $config['generate_fake_values'] = false;
        }

        if ($groupBy = $this->option('group-by')) {
            $config['group_by'] = $groupBy;
        }

        if ($depth = $this->option('depth')) {
            $config['group_depth'] = max(1, (int) $depth);
        }

        if ($exclude = $this->option('exclude')) {
            $config['exclude_patterns'] = array_values(array_unique(array_merge(
                (array) ($config['exclude_patterns'] ?? []),
                $this->csv($exclude),
            )));
        }

        foreach ([
            'driver'            => 'driver',
            'output'            => 'output',
            'file_group_depth'  => 'file-depth',
            'namespace'         => 'namespace',
        ] as $key => $option) {
            $value = $this->option($option);

            if (is_string($value) && $value !== '') {
                $tests[$key] = $key === 'file_group_depth' ? max(1, (int) $value) : $value;
            }
        }

        if ($expect = $this->option('expect')) {
            $tests['expect'] = strtolower($expect) === SnapshotEndpoint::EXPECT_SUCCESS
                ? SnapshotEndpoint::EXPECT_SUCCESS
                : SnapshotEndpoint::EXPECT_HANDLED;
        }

        if ($this->option('no-auth-tests')) {
            $tests['auth_denial_tests'] = false;
        }

        if ($this->option('no-refresh-db')) {
            $tests['refresh_database'] = false;
        }

        $config['tests'] = $tests;

        return $config;
    }

    /** @return list<string> */
    private function csv(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), fn ($item) => $item !== ''));
    }

    private function resolveOutputPath(string $filename): string
    {
        if (str_starts_with($filename, '/') || (bool) preg_match('#^[A-Za-z]:[/\\\\]#', $filename)) {
            return $filename;
        }

        return base_path($filename);
    }
}
