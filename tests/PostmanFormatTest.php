<?php

declare(strict_types=1);

namespace SwanFlutter\LaravelApiSnapshot\Tests;

use PHPUnit\Framework\Attributes\Test;

final class PostmanFormatTest extends TestCase
{
    private array $collection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->collection = $this->collection(['base_url' => 'https://api.test']);
    }

    #[Test]
    public function it_declares_the_collection_v2_schema_and_base_url_variable(): void
    {
        $this->assertSame(
            'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            $this->collection['info']['schema']
        );

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $this->collection['info']['_postman_id']
        );

        $baseUrl = $this->collection['variable'][0];

        $this->assertSame('baseUrl', $baseUrl['key']);
        $this->assertSame('https://api.test', $baseUrl['value']);
    }

    #[Test]
    public function it_names_a_request_after_the_controller_method(): void
    {
        $item = $this->findRequest($this->collection, 'POST', '/api/v1/patients');

        $this->assertSame('Store', $item['name']);

        $multiWord = $this->findRequest($this->collection, 'POST', '/api/v1/reports/generate');

        $this->assertSame('Generate', $multiWord['name']);
    }

    #[Test]
    public function it_writes_urls_that_postman_can_resolve(): void
    {
        $item = $this->findRequest($this->collection, 'PUT', '/api/v1/patients/:id');

        $this->assertNotNull($item, 'Path variables must be addressed as :id');

        $this->assertSame(['api', 'v1', 'patients', ':id'], $item['request']['url']['path']);
        $this->assertSame('{{baseUrl}}/api/v1/patients/:id', $item['request']['url']['raw']);
        $this->assertSame('id', $item['request']['url']['variable'][0]['key']);
        $this->assertSame('1', $item['request']['url']['variable'][0]['value']);
    }

    #[Test]
    public function it_encodes_a_json_body_as_a_string_with_a_language_hint(): void
    {
        $item = $this->findRequest($this->collection, 'POST', '/api/v1/reports/generate');
        $body = $item['request']['body'];

        $this->assertSame('raw', $body['mode']);
        $this->assertSame('json', $body['options']['raw']['language']);
        $this->assertIsString($body['raw'], 'Postman requires body.raw to be a JSON string');

        $decoded = json_decode($body['raw'], true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('from', $decoded);
    }

    #[Test]
    public function it_keeps_headers_typed_and_json_defaults_present(): void
    {
        $item = $this->findRequest($this->collection, 'GET', '/api/v1/health');

        foreach ($item['request']['header'] as $header) {
            $this->assertArrayHasKey('key', $header);
            $this->assertArrayHasKey('value', $header);
            $this->assertSame('text', $header['type']);
        }
    }

    #[Test]
    public function it_describes_each_request_with_route_middleware_and_rules(): void
    {
        $item = $this->findRequest($this->collection, 'POST', '/api/v1/attachments');

        $this->assertStringContainsString('Upload a document with an optional note.', $item['request']['description']);
        $this->assertStringContainsString('inline', $item['request']['description']);
    }

    #[Test]
    public function it_uses_the_persian_templates_by_default(): void
    {
        $item = $this->findRequest($this->collection, 'GET', '/api/v1/health');

        $this->assertStringContainsString('این endpoint عملیات', $item['request']['description']);
    }

    #[Test]
    public function it_switches_to_english_when_persian_is_disabled(): void
    {
        $collection = $this->collection(['persian_descriptions' => false]);
        $item       = $this->findRequest($collection, 'GET', '/api/v1/health');

        $this->assertStringContainsString('Executes the', $item['request']['description']);
        $this->assertStringNotContainsString('این', $item['request']['description']);
    }

    #[Test]
    public function it_leaves_values_empty_when_fake_values_are_disabled(): void
    {
        $collection = $this->collection(['generate_fake_values' => false]);
        $item       = $this->findRequest($collection, 'POST', '/api/v1/reports/generate');

        $decoded = json_decode($item['request']['body']['raw'], true);

        $this->assertSame('', $decoded['from']);
    }
}
