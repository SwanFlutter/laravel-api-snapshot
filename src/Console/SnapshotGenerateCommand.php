<?php

namespace SwanFlutter\LaravelApiSnapshot\Console;

use Illuminate\Console\Command;
use SwanFlutter\LaravelApiSnapshot\ApiSnapshotGenerator;

class SnapshotGenerateCommand extends Command
{
    protected $signature = 'api-snapshot:generate
        {--output=      : Output filename (default: from config)}
        {--name=        : Collection name}
        {--base-url=    : Base URL for requests}
        {--exclude=     : Comma-separated URI patterns to exclude}
        {--group-by=    : Grouping strategy: prefix or controller}
        {--no-persian   : Disable Persian/Farsi descriptions}
        {--force        : Overwrite output file without prompting}';

    protected $description = 'Generate a Postman Collection v2.1 JSON from all registered Laravel routes';

    public function handle(): int
    {
        $this->components->info('Laravel API Snapshot — Postman Collection Generator');

        $config     = $this->buildConfig();
        $outputFile = $this->resolveOutputPath($config['output_file']);

        if (file_exists($outputFile) && !$this->option('force')) {
            if (!$this->confirm("File [{$outputFile}] already exists. Overwrite?", true)) {
                $this->components->warn('Aborted.');
                return self::SUCCESS;
            }
        }

        $success = false;

        $this->components->task('Scanning routes and generating collection', function () use ($config, $outputFile, &$success): bool {
            $generator  = new ApiSnapshotGenerator($this->laravel['router'], $config);
            $collection = $generator->generate();
            $json       = json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($json === false) {
                return false;
            }

            file_put_contents($outputFile, $json);
            $success = true;
            return true;
        });

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green>Collection written to</>', $outputFile);
        $this->printStats($outputFile);

        return self::SUCCESS;
    }

    private function buildConfig(): array
    {
        $config = config('api-snapshot', []);

        if ($name    = $this->option('name'))     $config['name']             = $name;
        if ($url     = $this->option('base-url')) $config['base_url']         = $url;
        if ($output  = $this->option('output'))   $config['output_file']      = $output;
        if ($groupBy = $this->option('group-by')) $config['group_by']         = $groupBy;
        if ($this->option('no-persian'))          $config['persian_descriptions'] = false;

        if ($exclude = $this->option('exclude')) {
            $config['exclude_patterns'] = array_unique(array_merge(
                $config['exclude_patterns'] ?? [],
                array_map('trim', explode(',', $exclude))
            ));
        }

        return $config;
    }

    private function resolveOutputPath(string $filename): string
    {
        if (str_starts_with($filename, '/') || str_contains($filename, ':\\')) {
            return $filename;
        }
        return base_path($filename);
    }

    private function printStats(string $outputFile): void
    {
        if (!file_exists($outputFile)) return;

        $data    = json_decode(file_get_contents($outputFile), true);
        $groups  = $data['item'] ?? [];
        $total   = 0;
        $subCnt  = 0;

        foreach ($groups as $group) {
            foreach ($group['item'] ?? [] as $sub) {
                $subCnt++;
                $total += count($sub['item'] ?? []);
            }
        }

        $size = filesize($outputFile) ?: 0;
        $this->table(
            ['Groups', 'Sub-groups', 'Endpoints', 'File size'],
            [[count($groups), $subCnt, $total, $size < 1024 ? $size . ' B' : round($size / 1024, 1) . ' KB']]
        );
    }
}
