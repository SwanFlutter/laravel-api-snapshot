<?php

namespace SwanFlutter\LaravelApiSnapshot\Testing;

use Illuminate\Http\Testing\File;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Illuminate\Support\Facades\Artisan;
use Throwable;
use WeakMap;

/**
 * Executes a {@see SnapshotEndpoint} against the application under test and
 * turns the outcome into a readable assertion failure.
 */
final class SnapshotRequests
{
    /** Marks a payload value that must be swapped for a fake uploaded file. */
    public const FILE_MARKER = 'snapshot-file://';

    private static ?WeakMap $prepared = null;

    /**
     * Runs the endpoint the way the snapshot documents it: seeds the test
     * database, authenticates through the configured authenticator, sends the
     * request and asserts the response is not a server error.
     */
    public static function run(object $test, SnapshotEndpoint $endpoint): TestResponse
    {
        // The fixtures an authenticator builds usually need the seeded lookup
        // rows, so seeding comes before signing in.
        self::prepare($test);

        $headers = $endpoint->authRequired ? self::authenticate($test, $endpoint) : [];

        return self::assertHandled($endpoint, self::send($test, $endpoint, $headers));
    }

    /**
     * Sends the same request with no credentials, which is the one behaviour a
     * protected endpoint guarantees before any fixture exists.
     */
    public static function runUnauthenticated(object $test, SnapshotEndpoint $endpoint): TestResponse
    {
        if (! $endpoint->authRequired) {
            Assert::markTestSkipped(sprintf('%s is not protected by authentication.', $endpoint->title()));
        }

        self::prepare($test);

        return self::assertDenied($endpoint, self::send($test, $endpoint));
    }

    public static function send(object $test, SnapshotEndpoint $endpoint, array $headers = []): TestResponse
    {
        self::prepare($test);

        $data    = self::withFakeUploads($endpoint->payload());
        $headers = array_replace(['Accept' => 'application/json'], $headers);
        $uri     = '/' . ltrim($endpoint->uri, '/');

        if ($endpoint->isReadOnly()) {
            $query = $data === [] ? '' : (str_contains($uri, '?') ? '&' : '?') . http_build_query($data);

            // A read-only endpoint carries its payload in the query string, but
            // the verb still has to be the one the route was declared with.
            return in_array(strtoupper($endpoint->method), ['GET', 'HEAD'], true)
                ? $test->{strtolower($endpoint->method)}($uri . $query, $headers)
                : $test->json($endpoint->method, $uri . $query, [], $headers);
        }

        if ($endpoint->multipart) {
            return $test->{strtolower($endpoint->method)}($uri, $data, $headers);
        }

        return $test->json($endpoint->method, $uri, $data, $headers);
    }

    public static function assertHandled(SnapshotEndpoint $endpoint, TestResponse $response): TestResponse
    {
        $status  = $response->getStatusCode();
        $allowed = $endpoint->expectsSuccess() ? [200, 201, 202, 203, 204, 205, 301, 302, 303, 304, 307, 308] : null;

        if ($allowed === null) {
            Assert::assertLessThan(500, $status, self::describe($endpoint, $response, $allowed));

            return $response;
        }

        Assert::assertContains($status, $allowed, self::describe($endpoint, $response, $allowed));

        return $response;
    }

    /** @param list<string> $middleware */
    public static function assertDenied(SnapshotEndpoint $endpoint, TestResponse $response): TestResponse
    {
        Assert::assertContains(
            $response->getStatusCode(),
            [401, 403, 302, 303, 307],
            self::describe($endpoint, $response, [401, 403, 302, 303, 307]),
        );

        return $response;
    }

    /**
     * Runs the seeders listed in config("api-snapshot.tests.seeders") once per
     * test, inside the transaction RefreshDatabase opens for it.
     */
    public static function prepare(object $test): void
    {
        $seeders = (array) config('api-snapshot.tests.seeders', []);

        if ($seeders === []) {
            return;
        }

        self::$prepared ??= new WeakMap();

        if (self::$prepared[$test] ?? false) {
            return;
        }

        self::$prepared[$test] = true;

        foreach ($seeders as $seeder) {
            Artisan::call('db:seed', ['--class' => (string) $seeder]);
        }
    }

    private static function authenticate(object $test, SnapshotEndpoint $endpoint): array
    {
        $authenticator = self::authenticator();

        if ($authenticator === null) {
            Assert::markTestSkipped(sprintf(
                'The snapshot config has no tests.authenticator class, so the protected %s endpoint cannot be reached. '
                . 'Point api-snapshot.tests.authenticator at a class implementing %s.',
                $endpoint->title(),
                SnapshotAuthenticator::class,
            ));
        }

        $headers = $authenticator->authenticate($test, $endpoint);

        if ($headers === null) {
            Assert::markTestSkipped(sprintf(
                'The authenticator cannot sign in the "%s" surface yet.',
                $endpoint->surface === '' ? $endpoint->group : $endpoint->surface,
            ));
        }

        return $headers;
    }

    private static function authenticator(): ?SnapshotAuthenticator
    {
        $class = config('api-snapshot.tests.authenticator');

        if (! is_string($class) || $class === '' || ! class_exists($class)) {
            return null;
        }

        $instance = app($class);

        return $instance instanceof SnapshotAuthenticator ? $instance : null;
    }

    /**
     * Turns FILE_MARKER strings into fake uploads, wherever they sit in the
     * payload, and leaves everything else untouched.
     */
    private static function withFakeUploads(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::withFakeUploads($value);
                continue;
            }

            if (is_string($value) && str_starts_with($value, self::FILE_MARKER)) {
                $data[$key] = File::create(substr($value, strlen(self::FILE_MARKER)), 12);
            }
        }

        return $data;
    }

    /** @param list<int>|null $allowed */
    private static function describe(SnapshotEndpoint $endpoint, TestResponse $response, ?array $allowed): string
    {
        $status = $response->getStatusCode();
        $expect = $allowed === null
            ? 'a status below 500'
            : 'one of ' . implode(', ', $allowed);

        $lines = [sprintf(
            '%s expected %s, got %d.',
            $endpoint->title(),
            $expect,
            $status,
        )];

        if ($endpoint->authRequired) {
            $lines[] = 'middleware: ' . implode(', ', $endpoint->middleware);
        }

        $exception = $response->exception;

        if ($exception instanceof Throwable) {
            $lines[] = sprintf(
                "%s: %s\n%s",
                $exception::class,
                $exception->getMessage(),
                self::trace($exception),
            );
        }

        $lines[] = self::body($response);

        return implode("\n", $lines);
    }

    private static function body(TestResponse $response): string
    {
        $content = trim(preg_replace('/\s+/', ' ', (string) $response->getContent()) ?: '');

        return $content === '' ? '' : 'response: ' . mb_substr($content, 0, 500);
    }

    private static function trace(Throwable $exception): string
    {
        $frames = [];

        foreach (array_slice($exception->getTrace(), 0, 5) as $frame) {
            if (! isset($frame['file'])) {
                continue;
            }

            $frames[] = sprintf('%s:%d', $frame['file'], $frame['line'] ?? 0);
        }

        return implode("\n", $frames);
    }
}
