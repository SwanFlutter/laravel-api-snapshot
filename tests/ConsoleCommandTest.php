<?php

declare(strict_types=1);

namespace SwanFlutter\LaravelApiSnapshot\Tests;

use PHPUnit\Framework\Attributes\Test;

final class ConsoleCommandTest extends TestCase
{
    private string $output;

    protected function setUp(): void
    {
        parent::setUp();

        $this->output = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'api-snapshot-test.json';

        @unlink($this->output);
    }

    protected function tearDown(): void
    {
        @unlink($this->output);

        parent::tearDown();
    }

    #[Test]
    public function it_writes_a_collection_file_from_the_artisan_command(): void
    {
        $this->artisan('api-snapshot:generate', [
            '--output'   => $this->output,
            '--name'     => 'Console API',
            '--base-url' => 'https://api.test',
            '--force'    => true,
        ])->assertExitCode(0);

        $this->assertFileExists($this->output);

        $collection = json_decode((string) file_get_contents($this->output), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('Console API', $collection['info']['name']);
        $this->assertSame('https://api.test', $collection['variable'][0]['value']);
        $this->assertGreaterThan(0, $this->countRequests($collection['item']));
    }

    #[Test]
    public function it_leaves_values_empty_with_no_fakes(): void
    {
        $this->artisan('api-snapshot:generate', [
            '--output'  => $this->output,
            '--no-fakes' => true,
            '--force'   => true,
        ])->assertExitCode(0);

        $collection = json_decode((string) file_get_contents($this->output), true, 512, JSON_THROW_ON_ERROR);
        $item       = $this->firstRequestWithBody($collection['item']);
        $body       = json_decode((string) $item['request']['body']['raw'], true);

        $this->assertNotEmpty($body);

        foreach ($body as $key => $value) {
            $this->assertSame('', $value, "Field {$key} should be left empty with --no-fakes");
        }
    }

    #[Test]
    public function it_falls_back_and_warns_when_a_filter_matches_nothing(): void
    {
        $this->artisan('api-snapshot:generate', [
            '--output' => $this->output,
            '--paths'  => 'nothing/like/this',
            '--force'  => true,
        ])->assertExitCode(0);

        $collection = json_decode((string) file_get_contents($this->output), true, 512, JSON_THROW_ON_ERROR);

        $this->assertGreaterThan(0, $this->countRequests($collection['item']), 'The snapshot still documents the API when a filter is wrong');
    }

    #[Test]
    public function it_clears_the_cache_from_the_command(): void
    {
        config()->set('api-snapshot.cache', ['enabled' => true, 'ttl' => 3600, 'prefix' => 'console_cache_']);
        config()->set('api-snapshot.output_file', $this->output);

        $this->artisan('api-snapshot:generate', ['--force' => true])->assertExitCode(0);
        $this->artisan('api-snapshot:clear-cache')->assertExitCode(0);
        $this->artisan('api-snapshot:generate', ['--force' => true])->assertExitCode(0);
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

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>|null
     */
    private function firstRequestWithBody(array $items): ?array
    {
        foreach ($items as $item) {
            if (isset($item['request'])) {
                if (($item['request']['body']['mode'] ?? null) === 'raw') {
                    return $item;
                }

                continue;
            }

            $found = $this->firstRequestWithBody($item['item'] ?? []);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
