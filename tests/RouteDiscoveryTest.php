<?php

declare(strict_types=1);

namespace SwanFlutter\LaravelApiSnapshot\Tests;

use PHPUnit\Framework\Attributes\Test;

final class RouteDiscoveryTest extends TestCase
{
    #[Test]
    public function it_scans_the_api_middleware_group_only_by_default(): void
    {
        $labels = $this->endpointLabels($this->collection());

        $this->assertNotEmpty($labels);

        foreach ($labels as $label) {
            $this->assertStringNotContainsString('/web/page', $label);
            $this->assertStringNotContainsString('fallback', $label);
        }

        $this->assertContains('GET {{baseUrl}}/api/v1/health', $labels);
    }

    #[Test]
    public function it_drops_the_application_fallback_route(): void
    {
        $labels = $this->endpointLabels($this->collection(['scan_all_routes' => true]));

        $this->assertContains('GET {{baseUrl}}/web/page', $labels);
        $this->assertNotContains('GET {{baseUrl}}/:any', $labels);
    }

    #[Test]
    public function it_applies_the_exclude_patterns(): void
    {
        $this->assertStringNotContainsString('horizon', implode(' ', $this->endpointLabels($this->collection())));

        $labels = $this->endpointLabels($this->collection(['exclude_paths' => ['api/v1/admin']]));

        $this->assertStringNotContainsString('admin', implode(' ', $labels));
    }

    #[Test]
    public function it_selects_routes_by_uri_prefix_or_wildcard(): void
    {
        $collection = $this->collection([
            'include_middleware' => [],
            'include_paths'      => ['api/v1/admin'],
        ]);

        $labels = $this->endpointLabels($collection);

        $this->assertCount(1, $labels);
        $this->assertStringContainsString('/api/v1/admin/users/:user/status', $labels[0]);

        $wildcard = $this->endpointLabels($this->collection([
            'include_middleware' => [],
            'include_paths'      => ['api/v1/reports/*'],
        ]));

        $this->assertSame([
            'POST {{baseUrl}}/api/v1/reports/days',
            'POST {{baseUrl}}/api/v1/reports/generate',
        ], $wildcard);
    }

    /**
     * Both include options are filters, so a path chosen on top of a middleware
     * narrows the scan instead of letting every route with that middleware in.
     */
    #[Test]
    public function it_narrows_the_middleware_selection_with_a_path(): void
    {
        $labels = $this->endpointLabels($this->collection([
            'include_middleware' => ['api'],
            'include_paths'      => ['api/v1/reports'],
        ]));

        $this->assertSame([
            'POST {{baseUrl}}/api/v1/reports/days',
            'POST {{baseUrl}}/api/v1/reports/generate',
        ], $labels);
    }

    #[Test]
    public function it_falls_back_to_scanning_everything_when_a_filter_matches_nothing(): void
    {
        $generator = $this->generator(['include_middleware' => ['never-registered']]);
        $collection = $generator->generate();

        $this->assertNotEmpty($this->endpointLabels($collection));
        $this->assertNotEmpty($generator->warnings());
        $this->assertStringContainsString('No route matched', $generator->warnings()[0]);
    }

    #[Test]
    public function it_scans_one_item_per_http_method_of_a_multi_verb_route(): void
    {
        $labels = $this->endpointLabels($this->collection());

        $this->assertContains('GET {{baseUrl}}/api/v1/patients', $labels);
        $this->assertContains('POST {{baseUrl}}/api/v1/patients', $labels);
        $this->assertContains('GET {{baseUrl}}/api/v1/patients/:id', $labels);
        $this->assertContains('PUT {{baseUrl}}/api/v1/patients/:id', $labels);
    }

    #[Test]
    public function it_keeps_closure_and_invokable_routes_in_the_collection(): void
    {
        $collection = $this->collection();

        $this->assertNotNull($this->findRequest($collection, 'GET', '/api/v1/health'));
        $this->assertNotNull($this->findRequest($collection, 'GET', '/api/v1/admin/users/:user/status'));
    }

    #[Test]
    public function it_protects_routes_behind_auth_middleware_with_a_token_variable(): void
    {
        $collection = $this->collection();
        $item       = $this->findRequest($collection, 'POST', '/api/v1/patients');

        $this->assertSame('bearer', $item['request']['auth']['type']);
        $this->assertSame('{{authToken}}', $item['request']['auth']['bearer'][0]['value']);

        $keys = array_column($collection['variable'], 'key');

        $this->assertContains('authToken', $keys);

        $public = $this->findRequest($collection, 'GET', '/api/v1/health');

        $this->assertSame('noauth', $public['request']['auth']['type']);
    }

    #[Test]
    public function it_uses_the_configured_auth_for_every_request(): void
    {
        $collection = $this->collection(['auth' => ['type' => 'bearer', 'token' => 'fixed-token']]);
        $item       = $this->findRequest($collection, 'GET', '/api/v1/health');

        $this->assertSame('bearer', $item['request']['auth']['type']);
        $this->assertSame('fixed-token', $item['request']['auth']['bearer'][0]['value']);
    }

    /**
     * A published config can point the bearer token at a variable of the app's
     * own; Postman only fills the header when the collection defines it.
     */
    #[Test]
    public function it_defines_the_collection_variable_named_by_the_config_token(): void
    {
        $collection = $this->collection(['auth' => ['type' => 'bearer', 'token' => '{{access_token}}']]);
        $item       = $this->findRequest($collection, 'POST', '/api/v1/patients');

        $this->assertSame('{{access_token}}', $item['request']['auth']['bearer'][0]['value']);

        $variables = collect($collection['variable'])->keyBy('key');

        $this->assertNotNull($variables->get('access_token'));
        $this->assertSame('', $variables->get('access_token')['value']);
        $this->assertNull($variables->get('authToken'));
    }

    #[Test]
    public function every_variable_it_references_is_defined_on_the_collection(): void
    {
        $defined = array_column($this->collection()['variable'] ?? [], 'key');

        $missing = [];

        $walk = static function (mixed $node) use (&$walk, $defined, &$missing): void {
            if (is_array($node)) {
                foreach ($node as $value) {
                    $walk($value);
                }

                return;
            }

            if (! is_string($node) || ! str_starts_with($node, '{{')) {
                return;
            }

            preg_match_all('/\{\{\s*([A-Za-z0-9_.-]+)\s*\}\}/', $node, $matches);

            foreach ($matches[1] as $reference) {
                if (! in_array($reference, $defined, true)) {
                    $missing[] = $reference;
                }
            }
        };

        $walk($this->collection()['item'] ?? []);

        $this->assertSame([], $missing, 'undefined Postman variables: ' . implode(', ', $missing));
    }
}
