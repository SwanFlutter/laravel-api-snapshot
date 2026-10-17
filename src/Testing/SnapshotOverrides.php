<?php

namespace SwanFlutter\LaravelApiSnapshot\Testing;

use Closure;
use Illuminate\Support\Arr;

/**
 * The per-endpoint declarations config("api-snapshot.tests") makes for the test
 * suite: which fields to send, which audience signs in, which status a route
 * must answer and which URI an operation should call.
 *
 * A target is "METHOD /uri" or a bare "/uri" that matches every verb, and "*"
 * stands for anything in the path, so "PUT /api/v1/tickets/*" covers every
 * ticket the scanner produced.
 */
final class SnapshotOverrides
{
    /** Declared value that removes a field the scanner invented for this endpoint. */
    public const FORGET = 'snapshot-forget://';

    public static function matches(string $target, string $method, string $uri): bool
    {
        $target = trim($target);
        $space  = strpos($target, ' ');

        if ($space !== false && ! str_starts_with($target, '/')) {
            if (strtoupper(rtrim(substr($target, 0, $space))) !== strtoupper($method)) {
                return false;
            }

            $target = ltrim(substr($target, $space));
        }

        return fnmatch('/' . ltrim($target, '/'), $uri, FNM_NOESCAPE);
    }

    /**
     * Fields a Closure override declares for this endpoint. Resolved when the
     * request is sent, because a one-time code, a signature over a fresh
     * payload or tomorrow's weekday only exist in that moment.
     *
     * Every declaration that matches contributes, in config order, so a broad
     * "POST /api/v1/nurse/*" rule and a specific "POST /api/v1/nurse/chat/*"
     * one compose instead of the first hiding the second.
     *
     * @return array<string, mixed>
     */
    public static function latePayload(SnapshotEndpoint $endpoint): array
    {
        return self::merge(
            (array) config('api-snapshot.tests.payload_overrides', []),
            $endpoint->method,
            $endpoint->uri,
            fn (Closure $fields) => $fields($endpoint),
            static fn (mixed $fields): bool => $fields instanceof Closure,
        );
    }

    /**
     * The declared fields that can be written into a generated file: a plain
     * array is baked, a Closure is left for the runner.
     *
     * @param  array<array-key, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function bakedPayload(array $overrides, string $method, string $uri): array
    {
        return self::merge($overrides, $method, $uri, static fn (mixed $fields) => $fields, static fn (mixed $fields): bool => ! $fields instanceof Closure);
    }

    /**
     * The payload the declarations ask for: their fields merged over what the
     * scanner found, minus every path a declaration marks with FORGET.
     *
     * Merging alone is not enough — a controller that reads `array_key_exists`
     * or validates `days.*` needs the scanner's own key gone, not overwritten.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public static function apply(array $payload, array $fields): array
    {
        $merged = array_replace_recursive($payload, $fields);

        foreach (self::marked($fields, '') as $path) {
            Arr::forget($merged, $path);
        }

        return $merged;
    }

    /**
     * Dotted paths the declaration marks with FORGET.
     *
     * @param  array<string, mixed>  $fields
     * @return list<string>
     */
    private static function marked(array $fields, string $prefix): array
    {
        $paths = [];

        foreach ($fields as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if ($value === self::FORGET) {
                $paths[] = $path;
            } elseif (is_array($value)) {
                $paths = array_merge($paths, self::marked($value, $path));
            }
        }

        return $paths;
    }

    /**
     * @param  array<array-key, mixed>  $overrides
     * @param  callable(mixed): mixed            $resolve
     * @param  callable(mixed): bool             $accepts
     * @return array<string, mixed>
     */
    private static function merge(array $overrides, string $method, string $uri, callable $resolve, callable $accepts): array
    {
        $fields = [];

        foreach ($overrides as $target => $value) {
            if (! $accepts($value) || ! self::matches((string) $target, $method, $uri)) {
                continue;
            }

            $resolved = $resolve($value);

            if (is_array($resolved)) {
                $fields = array_replace_recursive($fields, $resolved);
            }
        }

        return $fields;
    }
}
