<?php

namespace SwanFlutter\LaravelApiSnapshot;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use SwanFlutter\LaravelApiSnapshot\Extractors\RouteExtractor;
use SwanFlutter\LaravelApiSnapshot\Extractors\RuleExtractor;
use SwanFlutter\LaravelApiSnapshot\Generators\FakeValueGenerator;

/**
 * Main generator: scans all Laravel routes and produces a
 * Postman Collection v2.1 JSON structure.
 */
class ApiSnapshotGenerator
{
    private readonly RouteExtractor $routeExtractor;

    /** @param  array<string, mixed>  $config */
    public function __construct(
        private readonly Router $router,
        private readonly array  $config,
    ) {
        $this->routeExtractor = new RouteExtractor(
            new RuleExtractor(),
            new FakeValueGenerator(),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Generate and return the Postman collection as a PHP array.
     *
     * @return array<string, mixed>
     */
    public function generate(): array
    {
        $routes  = $this->collectRoutes();
        $grouped = $this->groupRoutes($routes);
        $items   = $this->buildItems($grouped);

        return $this->buildCollection($items);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Route collection & filtering
    // ─────────────────────────────────────────────────────────────────────────

    /** @return Route[] */
    private function collectRoutes(): array
    {
        $includeMiddleware = $this->config['include_middleware'] ?? [];
        $excludePatterns   = $this->config['exclude_patterns'] ?? [];

        return array_values(array_filter(
            $this->router->getRoutes()->getRoutes(),
            function (Route $route) use ($includeMiddleware, $excludePatterns): bool {
                $uri = $route->uri();

                foreach ($excludePatterns as $pattern) {
                    if (str_contains($uri, (string) $pattern)) {
                        return false;
                    }
                }

                if (! empty($includeMiddleware)) {
                    $routeMiddleware = $route->gatherMiddleware();
                    foreach ($includeMiddleware as $required) {
                        foreach ($routeMiddleware as $m) {
                            $mStr = is_string($m) ? $m : (is_object($m) ? get_class($m) : (string) $m);
                            if ($mStr === $required || str_starts_with($mStr, $required . ':')) {
                                return true;
                            }
                        }
                    }
                    return false;
                }

                return true;
            }
        ));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Grouping
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  Route[]  $routes
     * @return array<string, array<string, Route[]>>
     */
    private function groupRoutes(array $routes): array
    {
        $strategy = $this->config['group_by'] ?? 'prefix';
        $groups   = [];

        foreach ($routes as $route) {
            if ($strategy === 'controller') {
                [$group, $subGroup] = $this->groupByController($route);
            } else {
                [$group, $subGroup] = $this->groupByPrefix($route);
            }

            $groups[$group][$subGroup][] = $route;
        }

        ksort($groups);
        foreach ($groups as &$subGroups) {
            ksort($subGroups);
        }

        return $groups;
    }

    /** @return array{string, string} */
    private function groupByPrefix(Route $route): array
    {
        $uri      = ltrim($route->uri(), '/');
        $segments = array_values(array_filter(
            explode('/', $uri),
            fn ($s) => $s !== '' && ! str_starts_with($s, '{')
        ));

        if (isset($segments[0]) && strtolower($segments[0]) === 'api') {
            array_shift($segments);
        }
        if (isset($segments[0]) && preg_match('/^v\d+$/i', $segments[0])) {
            array_shift($segments);
        }

        return [
            $this->segmentToTitle($segments[0] ?? 'General'),
            $this->segmentToTitle($segments[1] ?? 'General'),
        ];
    }

    /** @return array{string, string} */
    private function groupByController(Route $route): array
    {
        $action = $route->getAction('controller');
        if (! $action) {
            return $this->groupByPrefix($route);
        }

        $class = explode('@', $action)[0];
        $parts = array_values(array_filter(
            explode('\\', $class),
            fn ($p) => ! in_array(strtolower($p), ['app', 'http', 'controllers', 'api'], true)
        ));

        $last = end($parts);
        if ($last && str_ends_with($last, 'Controller')) {
            $parts[array_key_last($parts)] = substr($last, 0, -10);
        }

        return [
            $this->segmentToTitle($parts[0] ?? 'General'),
            $this->segmentToTitle($parts[1] ?? ($parts[0] ?? 'General')),
        ];
    }

    private function segmentToTitle(string $segment): string
    {
        $segment = trim(preg_replace('/\{.*?}/', '', $segment) ?? $segment, '-_');
        if ($segment === '') return 'General';

        $words = array_map(
            fn ($w) => $this->singularise($w),
            preg_split('/[_\-]/', $segment) ?: [$segment]
        );

        return implode(' ', array_map('ucfirst', $words));
    }

    private function singularise(string $word): string
    {
        $lower      = strtolower($word);
        $irregulars = [
            'children' => 'child',  'people' => 'person', 'men'   => 'man',
            'women'    => 'woman',  'teeth'  => 'tooth',  'feet'  => 'foot',
            'mice'     => 'mouse',  'geese'  => 'goose',
        ];

        if (isset($irregulars[$lower])) return $irregulars[$lower];
        if (str_ends_with($lower, 'ies') && strlen($lower) > 3) return substr($word, 0, -3) . 'y';
        if (preg_match('/(s|x|z|ch|sh)es$/', $lower)) return substr($word, 0, -2);
        if (str_ends_with($lower, 'ves')) return substr($word, 0, -3) . 'f';
        if (str_ends_with($lower, 's') && ! str_ends_with($lower, 'ss')
            && ! str_ends_with($lower, 'us') && ! str_ends_with($lower, 'is')
            && strlen($lower) > 3) {
            return substr($word, 0, -1);
        }
        return $word;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Postman structure builders
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, array<string, Route[]>>  $grouped
     * @return list<array<string, mixed>>
     */
    private function buildItems(array $grouped): array
    {
        $items = [];

        foreach ($grouped as $groupName => $subGroups) {
            $groupItem = ['name' => $groupName, 'description' => '', 'item' => []];

            foreach ($subGroups as $subGroupName => $routes) {
                $subGroupItem = ['name' => $subGroupName, 'description' => '', 'item' => []];

                foreach ($routes as $route) {
                    foreach ($this->buildRequestItems($route) as $item) {
                        $subGroupItem['item'][] = $item;
                    }
                }

                $groupItem['item'][] = $subGroupItem;
            }

            $items[] = $groupItem;
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function buildRequestItems(Route $route): array
    {
        $data  = $this->routeExtractor->extract($route);
        $items = [];

        foreach ($data['httpMethods'] as $httpMethod) {
            $items[] = $this->buildSingleItem($httpMethod, $data);
        }

        return $items;
    }

    /** @return array<string, mixed> */
    private function buildSingleItem(string $httpMethod, array $data): array
    {
        $uri        = $data['uri'];
        $methodName = $data['method'] ?? 'handle';

        $body = null;
        if (in_array($httpMethod, ['POST', 'PUT', 'PATCH'], true) && ! empty($data['bodyParams'])) {
            $body = ['mode' => 'raw', 'raw' => $data['bodyParams']];
        }

        return [
            'name'    => preg_replace('/(?<!^)([A-Z])/', ' $1', $methodName) ?? $methodName,
            'request' => [
                'url'         => $this->buildUrl($uri, $data['pathVariables'], $data['queryParams']),
                'method'      => strtoupper($httpMethod),
                'header'      => $this->config['default_headers'] ?? [
                    ['key' => 'Content-Type', 'value' => 'application/json'],
                    ['key' => 'Accept',       'value' => 'application/json'],
                ],
                'body'        => $body,
                'description' => $this->buildDescription($methodName),
                'auth'        => $this->buildAuth(),
            ],
            'response' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function buildUrl(string $uri, array $pathVariables, array $queryParams): array
    {
        $rawQs = '';
        if (! empty($queryParams)) {
            $pairs = array_map(
                fn ($q) => rawurlencode($q['key']) . '=' . rawurlencode((string) ($q['value'] ?? '')),
                $queryParams
            );
            $rawQs = '?' . implode('&', $pairs);
        }

        $url = [
            'host'  => '{{baseUrl}}',
            'path'  => $uri,
            'query' => $queryParams,
            'raw'   => '{{baseUrl}}/' . $uri . $rawQs,
        ];

        if (! empty($pathVariables)) {
            $url['variable'] = $pathVariables;
        }

        return $url;
    }

    private function buildDescription(?string $methodName): string
    {
        $methodName = $methodName ?? 'handle';

        if ($this->config['persian_descriptions'] ?? true) {
            return "این endpoint عملیات «{$methodName}» را انجام می‌دهد.\n"
                . "ورودی‌ها: بر اساس فیلدهای ارسالی در Request و قواعد اعتبارسنجی همین متد بررسی می‌شوند.\n"
                . 'خروجی: پاسخ JSON شامل وضعیت عملیات، پیام و داده‌های مرتبط.';
        }

        return "Executes the «{$methodName}» operation.\n"
            . "Inputs: validated according to the FormRequest rules.\n"
            . 'Output: JSON response with operation status, message and related data.';
    }

    /** @return array<string, mixed> */
    private function buildAuth(): array
    {
        $type  = $this->config['auth']['type']  ?? 'noauth';
        $token = $this->config['auth']['token'] ?? null;

        if ($type === 'bearer' && $token) {
            return ['type' => 'bearer', 'bearer' => [['key' => 'token', 'value' => $token, 'type' => 'string']]];
        }
        if ($type === 'basic') {
            return ['type' => 'basic', 'basic' => []];
        }
        return ['type' => 'noauth'];
    }

    /** @return array<string, mixed> */
    private function buildCollection(array $items): array
    {
        $name    = $this->config['name']     ?? 'API Documentation';
        $baseUrl = rtrim($this->config['base_url'] ?? 'http://localhost', '/');

        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        $uuid    = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));

        return [
            'variable' => [['id' => 'baseUrl', 'key' => 'baseUrl', 'type' => 'string', 'name' => 'string', 'value' => $baseUrl]],
            'info'     => [
                'name'        => $name,
                '_postman_id' => $uuid,
                'description' => '',
                'schema'      => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'item' => $items,
        ];
    }
}