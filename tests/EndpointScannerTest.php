<?php

declare(strict_types=1);

namespace SwanFlutter\LaravelApiSnapshot\Tests;

use PHPUnit\Framework\Attributes\Test;
use SwanFlutter\LaravelApiSnapshot\Testing\SnapshotEndpoint;
use SwanFlutter\LaravelApiSnapshot\Testing\SnapshotOverrides;
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
        $this->assertSame(SnapshotRequests::FILE_MARKER . 'document.pdf', $attachment->body['document']);
    }

    #[Test]
    public function it_names_an_upload_with_the_extension_the_rules_allow(): void
    {
        $poster = $this->find('POST', '/api/v1/attachments/poster');

        $this->assertTrue($poster->multipart);
        $this->assertSame(SnapshotRequests::FILE_MARKER . 'poster.png', $poster->body['poster']);
    }

    #[Test]
    public function it_sees_an_upload_the_controller_reads_without_any_rules(): void
    {
        $logo = $this->find('POST', '/api/v1/attachments/logo');

        $this->assertTrue($logo->multipart);
        $this->assertSame(SnapshotRequests::FILE_MARKER . 'logo.jpg', $logo->body['logo']);
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

    #[Test]
    public function it_marks_exists_rules_as_database_references(): void
    {
        $body = $this->find('POST', '/api/v1/patients')->body;

        $this->assertSame(SnapshotRequests::REFERENCE_MARKER . 'users/id', $body['visits'][0]['doctor_id']);
    }

    #[Test]
    public function it_keeps_relative_dates_as_markers_the_runner_resolves(): void
    {
        $body = $this->find('POST', '/api/v1/patients')->body;

        $this->assertSame('2026-05-29T08:09:35', $body['contract_from'], 'A date nobody compares stays a sample.');
        $this->assertSame(
            SnapshotRequests::DYNAMIC_MARKER . rawurlencode('today +1 day') . '/Y-m-d',
            $body['next_visit'],
        );
        $this->assertSame(
            SnapshotRequests::DYNAMIC_MARKER . rawurlencode('+1 day') . '/Y-m-d/' . rawurlencode('contract_from'),
            $body['contract_to'],
        );
    }

    #[Test]
    public function it_reads_rules_spread_from_a_helper_method(): void
    {
        $body = $this->find('POST', '/api/v1/reports/days')->body;

        // The helper declares "date_format:Y-m-d" and "regex:…", which the sample
        // generator can only honour when the spread is followed.
        $this->assertSame('2026-05-29', $body['day']);
    }

    #[Test]
    public function it_keeps_a_declared_string_a_string_when_its_name_looks_numeric(): void
    {
        $body = $this->find('POST', '/api/v1/reports/days')->body;

        // "position" reads like a counter to the name hints, but the rules answer
        // to Laravel's "string" rule, which rejects a number.
        $this->assertIsString($body['position']);
    }

    #[Test]
    public function it_replaces_declared_fields_and_keeps_the_rest_generated(): void
    {
        $endpoint = $this->endpointFrom(
            $this->endpoints(['tests' => ['payload_overrides' => [
                'POST /api/v1/patients' => ['email' => 'admin@example.com', 'password' => 'Secret#123'],
            ]]]),
            'POST',
            '/api/v1/patients',
        );

        $this->assertSame('admin@example.com', $endpoint->body['email']);
        $this->assertSame('Secret#123', $endpoint->body['password']);
        $this->assertArrayHasKey('phone', $endpoint->body, 'Fields nobody declared stay as the scanner made them.');
    }

    #[Test]
    public function declarations_of_different_width_are_both_baked(): void
    {
        $endpoint = $this->endpointFrom(
            $this->endpoints(['tests' => ['payload_overrides' => [
                'POST /api/v1/*'      => ['note' => 'broad'],
                'POST /api/v1/patients' => ['email' => 'specific@example.com'],
            ]]]),
            'POST',
            '/api/v1/patients',
        );

        $this->assertSame('broad', $endpoint->body['note']);
        $this->assertSame('specific@example.com', $endpoint->body['email']);
    }

    #[Test]
    public function a_declaration_can_forget_a_field_the_scanner_invented(): void
    {
        $endpoint = $this->endpointFrom(
            $this->endpoints(['tests' => ['payload_overrides' => [
                'POST /api/v1/patients' => [
                    'email'  => SnapshotOverrides::FORGET,
                    'visits' => ['0' => SnapshotOverrides::FORGET],
                ],
            ]]]),
            'POST',
            '/api/v1/patients',
        );

        $this->assertArrayNotHasKey('email', $endpoint->body);
        $this->assertSame([], $endpoint->body['visits']);
    }

    #[Test]
    public function an_override_wildcard_covers_the_concrete_path(): void
    {
        $endpoint = $this->endpointFrom(
            $this->endpoints(['tests' => ['payload_overrides' => ['GET /api/v1/patients/*' => ['page' => 3]]]]),
            'GET',
            '/api/v1/patients/1',
        );

        $this->assertSame(3, $endpoint->query['page']);
    }

    #[Test]
    public function it_declares_credentials_for_an_endpoint_without_auth_middleware(): void
    {
        $endpoints = $this->endpoints(['tests' => [
            'auth_overrides' => ['GET /api/v1/patients' => 'staff', 'GET /api/v1/health' => false],
        ]]);

        $this->assertTrue($this->endpointFrom($endpoints, 'GET', '/api/v1/patients')->authRequired);
        $this->assertSame('staff', $this->endpointFrom($endpoints, 'GET', '/api/v1/patients')->surface);
        $this->assertFalse($this->endpointFrom($endpoints, 'GET', '/api/v1/health')->authRequired);
    }

    #[Test]
    public function it_calls_the_uri_declared_for_one_operation(): void
    {
        $endpoints = $this->endpoints(['tests' => [
            'uri_overrides' => ['PUT /api/v1/patients/*' => '/api/v1/patients/2'],
        ]]);

        $this->assertSame('/api/v1/patients/2', $this->endpointFrom($endpoints, 'PUT', '/api/v1/patients/2')->uri);
    }

    #[Test]
    public function it_honours_the_expectation_of_one_endpoint(): void
    {
        $endpoints = $this->endpoints(['tests' => [
            'expect' => 'success',
            'expect_overrides' => ['GET /api/v1/health' => 'handled'],
        ]]);

        $this->assertSame(
            SnapshotEndpoint::EXPECT_HANDLED,
            $this->endpointFrom($endpoints, 'GET', '/api/v1/health')->expectation,
        );
        $this->assertSame(
            SnapshotEndpoint::EXPECT_SUCCESS,
            $this->endpointFrom($endpoints, 'GET', '/api/v1/patients')->expectation,
        );
    }

    /**
     * @param  list<SnapshotEndpoint>  $endpoints
     */
    private function endpointFrom(array $endpoints, string $method, string $uri): SnapshotEndpoint
    {
        foreach ($endpoints as $endpoint) {
            if ($endpoint->method === $method && $endpoint->uri === $uri) {
                return $endpoint;
            }
        }

        $this->fail(sprintf('%s %s was not scanned.', $method, $uri));
    }
}
