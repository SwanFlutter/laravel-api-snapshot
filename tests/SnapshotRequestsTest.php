<?php

declare(strict_types=1);

namespace SwanFlutter\LaravelApiSnapshot\Tests;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\SkippedWithMessageException;
use SwanFlutter\LaravelApiSnapshot\Testing\SnapshotEndpoint;
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
}
