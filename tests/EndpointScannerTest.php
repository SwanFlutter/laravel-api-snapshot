<?php

declare(strict_types=1);

namespace SwanFlutter\LaravelApiSnapshot\Tests;

use PHPUnit\Framework\Attributes\Test;
use SwanFlutter\LaravelApiSnapshot\Testing\SnapshotEndpoint;
use SwanFlutter\LaravelApiSnapshot\Testing\SnapshotRequests;
use SwanFlutter\LaravelApiSnapshot\Testing\SnapshotTestGenerator;

final class EndpointScannerTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return list<SnapshotEndpoint>
     */
    private function endpoints(array $overrides = []): array
    {
        return $this->generator($overrides)->scanEndpoints();
    }

    private function find(string $method, string $uri): SnapshotEndpoint
    {
        foreach ($this->endpoints() as $endpoint) {
            if ($endpoint->method === $method && $endpoint->uri === $uri) {
                return $endpoint;
            }
        }

        $this->fail(sprintf('%s %s was not scanned.', $method, $uri));
    }

    #[Test]
    public function it_fills_the_path_placeholders_with_sample_values(): void
    {
        $endpoint = $this->find('GET', '/api/v1/patients/1');

        $this->assertStringContainsString('GET /api/v1/patients/1', $endpoint->title());
    }

    #[Test]
    public function it_reads_query_input_of_a_get_endpoint_as_a_payload(): void
    {
        $endpoint = $this->find('GET', '/api/v1/patients');

        $this->assertSame([], $endpoint->body);
        $this->assertArrayHasKey('page', $endpoint->query);
        $this->assertSame($endpoint->query, $endpoint->payload());
    }

    #[Test]
    public function it_keeps_nested_and_list_rules_in_the_body(): void
    {
        $body = $this->find('POST', '/api/v1/patients')->body;

        $this->assertSame(['admin'], [$body['role']]);
        $this->assertArrayHasKey('weight', $body['meta']);
        $this->assertArrayHasKey('password_confirmation', $body);
        $this->assertIsArray($body['visits']);
    }

    #[Test]
    public function it_marks_upload_fields_and_switches_the_body_to_multipart(): void
    {
        $endpoint = $this->find('POST', '/api/v1/patients');

        $this->assertTrue($endpoint->multipart);
        $this->assertSame(SnapshotRequests::FILE_MARKER . 'avatar.jpg', $endpoint->body['avatar']);

        $attachment = $this->find('POST', '/api/v1/attachments');

        $this->assertTrue($attachment->multipart);
        $this->assertSame(SnapshotRequests::FILE_MARKER . 'document.txt', $attachment->body['document']);
    }

    #[Test]
    public function it_uses_the_first_role_name_as_the_surface(): void
    {
        $endpoint = $this->find('GET', '/api/v1/admin/users/example/status');

        $this->assertTrue($endpoint->authRequired);
        $this->assertSame('admin', $endpoint->surface);
        $this->assertSame(['api', 'auth:sanctum', 'role:admin|super_admin'], $endpoint->middleware);
        $this->assertSame('Admin › User', $endpoint->group);
    }

    #[Test]
    public function it_uses_the_first_group_as_surface_when_only_a_guard_protects_the_route(): void
    {
        $endpoint = $this->find('POST', '/api/v1/patients');

        $this->assertTrue($endpoint->authRequired);
        $this->assertSame('patient', $endpoint->surface);
    }

    #[Test]
    public function it_leaves_public_endpoints_unprotected(): void
    {
        $endpoint = $this->find('GET', '/api/v1/health');

        $this->assertFalse($endpoint->authRequired);
        $this->assertSame('health', $endpoint->surface);
    }

    #[Test]
    public function it_writes_no_fake_values_when_fakes_are_off(): void
    {
        $endpoint = $this->endpoints(['generate_fake_values' => false])[0];

        $this->assertSame([], array_filter($endpoint->payload()));
    }

    #[Test]
    public function it_respects_the_expected_status_from_config(): void
    {
        $endpoints = $this->endpoints(['tests' => ['expect' => 'success']]);

        $this->assertContains(SnapshotEndpoint::EXPECT_SUCCESS, array_map(fn ($e) => $e->expectation, $endpoints));
    }
}
