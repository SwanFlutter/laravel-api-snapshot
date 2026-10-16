<?php

declare(strict_types=1);

namespace SwanFlutter\LaravelApiSnapshot\Tests;

use PHPUnit\Framework\Attributes\Test;

final class CacheTest extends TestCase
{
    #[Test]
    public function it_returns_the_cached_collection_on_the_second_run(): void
    {
        $first  = $this->collection(['cache' => ['enabled' => true, 'prefix' => 'test_cache_']], false);
        $second = $this->collection(['cache' => ['enabled' => true, 'prefix' => 'test_cache_']], false);

        $this->assertSame($first['info']['_postman_id'], $second['info']['_postman_id']);
    }

    #[Test]
    public function it_does_not_serve_a_stale_collection_after_the_options_change(): void
    {
        $this->collection(['base_url' => 'http://one.test', 'cache' => ['prefix' => 'test_cache_']], false);

        $after = $this->collection(['base_url' => 'http://two.test', 'cache' => ['prefix' => 'test_cache_']], false);

        $this->assertSame('http://two.test', $after['variable'][0]['value']);
    }

    #[Test]
    public function clear_cache_removes_entries_written_with_other_options(): void
    {
        $stale = $this->collection(['base_url' => 'http://stale.test', 'cache' => ['prefix' => 'test_cache_']], false);

        $this->generator(['cache' => ['prefix' => 'test_cache_']], false)->clearCache();

        $fresh = $this->collection(['base_url' => 'http://stale.test', 'cache' => ['prefix' => 'test_cache_']], false);

        $this->assertNotSame($stale['info']['_postman_id'], $fresh['info']['_postman_id']);
    }

    #[Test]
    public function it_skips_the_cache_when_requested(): void
    {
        $this->collection(['cache' => ['prefix' => 'test_cache_']], false);

        $uncached = $this->collection(['cache' => ['prefix' => 'test_cache_']], true);
        $cached   = $this->collection(['cache' => ['prefix' => 'test_cache_']], false);

        $this->assertNotSame($uncached['info']['_postman_id'], $cached['info']['_postman_id']);
    }

    /**
     * An app can ship a database cache while the database is unreachable; a
     * documentation command must not die over that.
     */
    #[Test]
    public function it_still_generates_when_the_cache_store_is_unreachable(): void
    {
        config(['cache.default' => 'database']);

        $generator = $this->generator(['cache' => ['prefix' => 'test_cache_']], false);

        $collection = $generator->generate();

        $this->assertNotEmpty($collection['item']);
        $this->assertTrue(
            (bool) preg_grep('/cache store is not reachable/', $generator->warnings()),
            'expected a cache warning, got: ' . implode(' | ', $generator->warnings()),
        );
    }
}
