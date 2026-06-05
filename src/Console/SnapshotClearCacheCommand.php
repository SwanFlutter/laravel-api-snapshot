<?php

namespace SwanFlutter\LaravelApiSnapshot\Console;

use Illuminate\Console\Command;
use SwanFlutter\LaravelApiSnapshot\ApiSnapshotGenerator;

class SnapshotClearCacheCommand extends Command
{
    protected $signature = 'api-snapshot:clear-cache';
    protected $description = 'Clear the cached API snapshot';

    public function handle(): int
    {
        $this->components->info('Laravel API Snapshot — Clear Cache');

        /** @var ApiSnapshotGenerator $generator */
        $generator = $this->laravel->make(ApiSnapshotGenerator::class);
        $generator->clearCache();

        $this->components->info('✅ Cache cleared successfully!');
        return self::SUCCESS;
    }
}
