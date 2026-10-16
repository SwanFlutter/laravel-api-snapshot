<?php

namespace SwanFlutter\LaravelApiSnapshot\Console;

use Illuminate\Console\Command;
use SwanFlutter\LaravelApiSnapshot\ApiSnapshotGenerator;

class SnapshotGenerateCommand extends Command
{
    protected $signature = 'api-snapshot:generate
        {--output=       : Output filename (default: from config)}
        {--name=         : Collection name}
        {--base-url=     : Base URL for requests}
        {--include=      : Comma-separated middleware names that select routes}
        {--paths=        : Comma-separated URI prefixes or wildcards that select routes}
        {--exclude=      : Comma-separated URI patterns to exclude}
        {--all           : Scan every route, ignoring the include filters}
        {--group-by=     : Grouping strategy: prefix or controller}
        {--depth=        : Number of folder levels in the collection}
        {--no-persian    : Disable Persian/Farsi descriptions}
        {--no-fakes      : Leave parameter and body values empty}
        {--force         : Overwrite output file without prompting}
        {--no-cache      : Skip cache and regenerate a fresh collection}
        {--clear-cache   : Clear cache before generating}';

    protected $description = 'Generate a Postman Collection v2.1 JSON from all registered Laravel routes';

    public function handle(): int
    {
        $this->components->info('Laravel API Snapshot — Postman Collection Generator');

        $config     = $this->buildConfig();
        $outputFile = $this->resolveOutputPath((string) $config['output_file']);

        if (file_exists($outputFile) && ! $this->option('force')) {
            if (! $this->confirm("File [{$outputFile}] already exists. Overwrite?", true)) {
                $this->components->warn('Aborted.');

                return self::SUCCESS;
            }
        }

        $generator = new ApiSnapshotGenerator(
            $this->laravel['router'],
            $config,
            $this->laravel->make('cache'),
            (bool) $this->option('no-cache'),
        );

        if ($this->option('clear-cache')) {
            $generator->clearCache();
            $this->components->info('Cache cleared.');
        }

        $collection = $generator->generate();
        $json       = json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            $this->components->error('The collection could not be encoded as JSON.');

            return self::FAILURE;
        }

        if (file_put_contents($outputFile, $json) === false) {
            $this->components->error("Unable to write [{$outputFile}].");

            return self::FAILURE;
        }

        foreach ($generator->warnings() as $warning) {
            $this->components->warn($warning);
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green>Collection written to</>', $outputFile);
        $this->printStats($collection, $outputFile);

        if ($this->countRequests($collection['item'] ?? []) === 0) {
            $this->components->error('No endpoints were found. Relax the filters or run with --all.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function buildConfig(): array
    {
        $config = config('api-snapshot', []);

        if ($name = $this->option('name')) {
            $config['name'] = $name;
        }

        if ($url = $this->option('base-url')) {
            $config['base_url'] = $url;
        }

        if ($output = $this->option('output')) {
            $config['output_file'] = $output;
        }

        if ($groupBy = $this->option('group-by')) {
            $config['group_by'] = $groupBy;
        }

        if ($depth = $this->option('depth')) {
            $config['group_depth'] = max(1, (int) $depth);
        }

        if ($this->option('no-persian')) {
            $config['persian_descriptions'] = false;
        }

        if ($this->option('no-fakes')) {
            $config['generate_fake_values'] = false;
        }

        if ($this->option('all')) {
            $config['scan_all_routes'] = true;
        }

        if ($this->option('include') !== null) {
            $config['include_middleware'] = $this->csv((string) $this->option('include'));
        }

        if ($this->option('paths') !== null) {
            $config['include_paths'] = $this->csv((string) $this->option('paths'));
        }

        if ($exclude = $this->option('exclude')) {
            $config['exclude_patterns'] = array_values(array_unique(array_merge(
                (array) ($config['exclude_patterns'] ?? []),
                $this->csv($exclude),
            )));
        }

        return $config;
    }

    /** @return list<string> */
    private function csv(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), fn ($item) => $item !== ''));
    }

    private function resolveOutputPath(string $filename): string
    {
        if (str_starts_with($filename, '/') || str_contains($filename, ':\\') || (bool) preg_match('#^[A-Za-z]:[/\\\\]#', $filename)) {
            return $filename;
        }

        return base_path($filename);
    }

    /** @param array<string, mixed> $collection */
    private function printStats(array $collection, string $outputFile): void
    {
        $items   = $collection['item'] ?? [];
        $size    = filesize($outputFile) ?: 0;

        $this->table(
            ['Folders', 'Endpoints', 'File size'],
            [[
                $this->countFolders($items),
                $this->countRequests($items),
                $size < 1024 ? $size . ' B' : round($size / 1024, 1) . ' KB',
            ]],
        );
    }

    /** @param list<array<string, mixed>> $items */
    private function countRequests(array $items): int
    {
        $total = 0;

        foreach ($items as $item) {
            $total += isset($item['request']) ? 1 : $this->countRequests($item['item'] ?? []);
        }

        return $total;
    }

    /** @param list<array<string, mixed>> $items */
    private function countFolders(array $items): int
    {
        $total = 0;

        foreach ($items as $item) {
            if (isset($item['item'])) {
                $total += 1 + $this->countFolders($item['item']);
            }
        }

        return $total;
    }
}
