<?php

declare(strict_types=1);

namespace SwanFlutter\LaravelApiSnapshot\Tests;

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\SkippedWithMessageException;
use SwanFlutter\LaravelApiSnapshot\Testing\SnapshotEndpoint;
use SwanFlutter\LaravelApiSnapshot\Testing\SnapshotOverrides;
use SwanFlutter\LaravelApiSnapshot\Testing\SnapshotRequests;
use SwanFlutter\LaravelApiSnapshot\Tests\Fixture\TestAuthenticator;

/**
 * Runs generated endpoints against a live application, which is what a
 * generated test does inside the host project.
 */
final class SnapshotRequestsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('auth.defaults.guard', 'web');
        $app['config']->set('auth.guards.web', ['driver' => 'session', 'provider' => 'users']);
        $app['config']->set('session.driver', 'array');
        $app['config']->set('database.default', 'snapshot');
        $app['config']->set('database.connections.snapshot', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    /**
     * Only the routes these tests need, and no fallback, so an unknown URI
     * really answers 404.
     */
    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->prefix('api/v1')->group(function (Router $router): void {
            $router->get('health', fn (Request $request) => ['verbose' => $request->query('verbose')]);

            $router->post('attachments', function (Request $request) {
                return [
                    'has_file' => $request->hasFile('document'),
                    'note'     => $request->input('note'),
                ];
            });

            $router->get('private/report', fn () => ['ok' => true])->middleware('auth');

            $router->delete('documents', fn () => ['removed' => true]);

            $router->post('widgets', fn (Request $request) => ['widget' => $request->input('widget_id')]);

            $router->post('slots', fn (Request $request) => [
                'start' => $request->input('start'),
                'end'   => $request->input('end'),
            ]);

            $router->post('briefs', fn (Request $request) => ['sent' => array_keys($request->all())]);

            $router->get('streams', fn () => response()->stream(fn () => print 'chunk', 404));

            $router->post('covers', function (Request $request) {
                $request->validate(['cover' => 'required|image|mimes:png,jpeg|max:2048']);

                return ['size' => getimagesize($request->file('cover')->getRealPath())];
            });
        });
    }

    private function health(): SnapshotEndpoint
    {
        return new SnapshotEndpoint(
            method: 'GET',
            uri: '/api/v1/health',
            query: ['verbose' => 'false'],
            group: 'Health',
            action: 'index',
        );
    }

    private function protectedReport(string $expectation = SnapshotEndpoint::EXPECT_HANDLED): SnapshotEndpoint
    {
        return new SnapshotEndpoint(
            method: 'GET',
            uri: '/api/v1/private/report',
            authRequired: true,
            surface: 'report',
            group: 'Report',
            action: 'show',
            middleware: ['api', 'auth'],
            expectation: $expectation,
        );
    }

    #[Test]
    public function it_sends_a_public_endpoint_and_accepts_the_answer(): void
    {
        $response = SnapshotRequests::run($this, $this->health());

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function it_sends_query_parameters_of_a_read_endpoint(): void
    {
        $response = SnapshotRequests::send($this, $this->health());

        $this->assertSame('false', $response->json('verbose'));
    }

    #[Test]
    public function it_turns_upload_markers_into_real_uploaded_files(): void
    {
        $endpoint = new SnapshotEndpoint(
            method: 'POST',
            uri: '/api/v1/attachments',
            body: [
                'document' => SnapshotRequests::FILE_MARKER . 'document.pdf',
                'note'     => 'hello',
            ],
            multipart: true,
            group: 'Attachment',
            action: 'store',
        );

        $response = SnapshotRequests::assertHandled($endpoint, SnapshotRequests::send($this, $endpoint));

        $this->assertTrue($response->json('has_file'));
        $this->assertSame('hello', $response->json('note'));
    }

    #[Test]
    public function it_sends_an_image_upload_a_controller_can_measure(): void
    {
        $endpoint = new SnapshotEndpoint(
            method: 'POST',
            uri: '/api/v1/covers',
            body: ['cover' => SnapshotRequests::FILE_MARKER . 'cover.png'],
            multipart: true,
            group: 'Cover',
            action: 'store',
        );

        $response = SnapshotRequests::assertHandled($endpoint, SnapshotRequests::send($this, $endpoint));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(256, $response->json('size.0'));
    }

    #[Test]
    public function it_replaces_a_reference_marker_with_a_real_row(): void
    {
        Schema::create('widgets', fn (Blueprint $table) => $table->integer('id'));
        DB::table('widgets')->insert([['id' => 7], ['id' => 3]]);

        $response = SnapshotRequests::run($this, new SnapshotEndpoint(
            method: 'POST',
            uri: '/api/v1/widgets',
            body: ['widget_id' => SnapshotRequests::REFERENCE_MARKER . 'widgets/id'],
            group: 'Widget',
            action: 'store',
        ));

        $this->assertSame(3, (int) $response->json('widget'));
    }

    #[Test]
    public function a_reference_without_rows_falls_back_to_the_canonical_fixture_id(): void
    {
        Schema::create('widgets', fn (Blueprint $table) => $table->integer('id'));

        $response = SnapshotRequests::run($this, new SnapshotEndpoint(
            method: 'POST',
            uri: '/api/v1/widgets',
            body: ['widget_id' => SnapshotRequests::REFERENCE_MARKER . 'widgets/id'],
            group: 'Widget',
            action: 'store',
        ));

        $this->assertSame(1, (int) $response->json('widget'));
    }

    #[Test]
    public function it_resolves_a_date_compared_with_a_sibling_when_sending(): void
    {
        $response = SnapshotRequests::run($this, new SnapshotEndpoint(
            method: 'POST',
            uri: '/api/v1/slots',
            body: [
                'start' => '2030-01-01',
                'end'   => SnapshotRequests::DYNAMIC_MARKER . rawurlencode('+1 day') . '/Y-m-d/' . rawurlencode('start'),
            ],
            group: 'Slot',
            action: 'store',
        ));

        $this->assertSame('2030-01-02', $response->json('end'));
    }

    #[Test]
    public function it_resolves_a_clock_relative_date_when_sending(): void
    {
        $response = SnapshotRequests::run($this, new SnapshotEndpoint(
            method: 'POST',
            uri: '/api/v1/slots',
            body: [
                'start' => SnapshotRequests::DYNAMIC_MARKER . rawurlencode('today +1 day') . '/Y-m-d',
            ],
            group: 'Slot',
            action: 'store',
        ));

        $this->assertSame(Carbon::today()->addDay()->format('Y-m-d'), $response->json('start'));
    }

    #[Test]
    public function it_applies_a_closure_override_at_request_time(): void
    {
        config()->set('api-snapshot.tests.payload_overrides', [
            'POST /api/v1/slots' => fn (SnapshotEndpoint $endpoint) => ['end' => '2030-12-31'],
        ]);

        try {
            $response = SnapshotRequests::run($this, new SnapshotEndpoint(
                method: 'POST',
                uri: '/api/v1/slots',
                body: ['start' => '2030-01-01'],
                group: 'Slot',
                action: 'store',
            ));
        } finally {
            config()->set('api-snapshot.tests.payload_overrides', []);
        }

        $this->assertSame('2030-01-01', $response->json('start'), 'Fields nobody declared are still sent.');
        $this->assertSame('2030-12-31', $response->json('end'));
    }

    #[Test]
    public function every_declaration_that_matches_contributes_to_the_payload(): void
    {
        config()->set('api-snapshot.tests.payload_overrides', [
            'POST /api/v1/*'   => fn () => ['start' => '2030-01-01'],
            'POST /api/v1/slots' => fn () => ['end' => '2030-06-06'],
        ]);

        try {
            $response = SnapshotRequests::run($this, new SnapshotEndpoint(
                method: 'POST',
                uri: '/api/v1/slots',
                body: [],
                group: 'Slot',
                action: 'store',
            ));
        } finally {
            config()->set('api-snapshot.tests.payload_overrides', []);
        }

        $this->assertSame('2030-01-01', $response->json('start'), 'The broad declaration still applies.');
        $this->assertSame('2030-06-06', $response->json('end'), 'The specific one adds to it instead of hiding it.');
    }

    #[Test]
    public function a_late_declaration_can_forget_a_field_the_scanner_invented(): void
    {
        config()->set('api-snapshot.tests.payload_overrides', [
            'POST /api/v1/briefs' => fn () => ['start' => SnapshotOverrides::FORGET],
        ]);

        try {
            $response = SnapshotRequests::run($this, new SnapshotEndpoint(
                method: 'POST',
                uri: '/api/v1/briefs',
                body: ['start' => '2026-01-01', 'end' => '2026-02-02'],
                group: 'Brief',
                action: 'store',
            ));
        } finally {
            config()->set('api-snapshot.tests.payload_overrides', []);
        }

        $this->assertSame(['end'], $response->json('sent'), 'The declared field is not sent at all.');
    }

    #[Test]
    public function it_sends_a_delete_endpoint_with_the_delete_verb(): void
    {
        $response = SnapshotRequests::run($this, new SnapshotEndpoint(
            method: 'DELETE',
            uri: '/api/v1/documents',
            query: ['reason' => 'cleanup'],
            group: 'Document',
            action: 'destroy',
        ));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->json('removed'));
    }

    #[Test]
    public function it_records_a_request_without_credentials_as_a_denial(): void
    {
        $response = SnapshotRequests::runUnauthenticated($this, $this->protectedReport());

        $this->assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function it_skips_a_protected_endpoint_until_an_authenticator_exists(): void
    {
        $this->expectException(SkippedWithMessageException::class);
        $this->expectExceptionMessageMatches('/cannot be reached/');

        SnapshotRequests::run($this, $this->protectedReport());
    }

    #[Test]
    public function it_uses_the_configured_authenticator_headers(): void
    {
        config()->set('api-snapshot.tests.authenticator', TestAuthenticator::class);

        try {
            $this->assertSame(200, SnapshotRequests::run($this, $this->protectedReport())->getStatusCode());
        } finally {
            config()->set('api-snapshot.tests.authenticator', null);
        }
    }

    #[Test]
    public function it_reports_a_status_that_is_not_a_success_as_a_failure(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageMatches('/expected one of/');

        SnapshotRequests::run($this, new SnapshotEndpoint(
            method: 'GET',
            uri: '/api/v1/nothing/here',
            group: 'Missing',
            action: 'show',
            expectation: SnapshotEndpoint::EXPECT_SUCCESS,
        ));
    }

    #[Test]
    public function a_failing_streamed_download_is_reported_without_a_second_error(): void
    {
        try {
            SnapshotRequests::run($this, new SnapshotEndpoint(
                method: 'GET',
                uri: '/api/v1/streams',
                group: 'Stream',
                action: 'download',
                expectation: SnapshotEndpoint::EXPECT_SUCCESS,
            ));
        } catch (AssertionFailedError $failure) {
            $this->assertStringContainsString('got 404', $failure->getMessage());

            return;
        }

        $this->fail('A streamed 404 must not pass as a successful download.');
    }
}
