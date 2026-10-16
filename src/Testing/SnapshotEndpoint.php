<?php

namespace SwanFlutter\LaravelApiSnapshot\Testing;

/**
 * One executable API call derived from the route snapshot: the concrete URI,
 * a payload that satisfies the documented validation rules and the auth
 * contract the endpoint was found to require.
 */
final class SnapshotEndpoint
{
    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     * @param  list<string>  $middleware
     */
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly array  $query = [],
        public readonly array  $body = [],
        public readonly bool   $multipart = false,
        public readonly bool   $authRequired = false,
        public readonly string $surface = '',
        public readonly string $group = '',
        public readonly string $action = '',
        public readonly array  $middleware = [],
        public readonly string $expectation = self::EXPECT_HANDLED,
    ) {}

    public const EXPECT_HANDLED = 'handled';

    public const EXPECT_SUCCESS = 'success';

    public function isReadOnly(): bool
    {
        return in_array(strtoupper($this->method), ['GET', 'HEAD', 'DELETE', 'OPTIONS'], true);
    }

    public function expectsSuccess(): bool
    {
        return $this->expectation === self::EXPECT_SUCCESS;
    }

    /** Data the request actually carries: query string for reads, body otherwise. */
    public function payload(): array
    {
        return $this->isReadOnly() ? $this->query : $this->body;
    }

    public function title(): string
    {
        $prefix = $this->group === '' ? '' : $this->group . ' › ';

        return sprintf('%s%s %s', $prefix, strtoupper($this->method), '/' . ltrim($this->uri, '/'));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'method'      => $this->method,
            'uri'         => $this->uri,
            'query'       => $this->query,
            'body'        => $this->body,
            'multipart'   => $this->multipart,
            'auth'        => $this->authRequired,
            'surface'     => $this->surface,
            'group'       => $this->group,
            'action'      => $this->action,
            'middleware'  => $this->middleware,
            'expectation' => $this->expectation,
        ];
    }
}
