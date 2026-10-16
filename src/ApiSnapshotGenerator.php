<?php

namespace SwanFlutter\LaravelApiSnapshot;

use Illuminate\Cache\CacheManager;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use SwanFlutter\LaravelApiSnapshot\Extractors\InlineRuleExtractor;
use SwanFlutter\LaravelApiSnapshot\Extractors\RouteExtractor;
use SwanFlutter\LaravelApiSnapshot\Extractors\RuleExtractor;
use SwanFlutter\LaravelApiSnapshot\Generators\FakeValueGenerator;
use SwanFlutter\LaravelApiSnapshot\Support\ParamTree;
use SwanFlutter\LaravelApiSnapshot\Support\Utf8;
use Throwable;

/**
 * Scans the registered routes of a Laravel application and produces a
 * Postman Collection v2.1 structure.
 */
class ApiSnapshotGenerator
{
    private readonly RouteExtractor $routeExtractor;

    private readonly FakeValueGenerator $fakeValues;

    /** @var list<string> */
    private array $warnings = [];

    private bool $needsAuthVariable = false;

    private ?CacheManager $cache;

    private bool $cacheBroken = false;

    private readonly bool $skipCache;

    /** @var list<string> Namespace segments shared by every controller, stripped when grouping by controller. */
    private array $namespaceRoot = [];

    /** @param  array<string, mixed>  $config */
    public function __construct(
        private readonly Router $router,
        private readonly array  $config,
        ?CacheManager $cache = null,
        bool $skipCache = false,
    ) {
        $this->fakeValues = new FakeValueGenerator();

        $this->routeExtractor = new RouteExtractor(
            new RuleExtractor(),
            $this->fakeValues,
            new InlineRuleExtractor(),
            (bool) ($this->config['generate_fake_values'] ?? true),
        );

        $this->cache = $cache;
        $this->skipCache = $skipCache;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function generate(): array
    {
        $this->warnings          = [];
        $this->needsAuthVariable = false;

        if ($this->cacheEnabled()) {
            $cached = $this->readCache($this->cacheKey());

            if (is_array($cached) && isset($cached['item'])) {
                return Utf8::cleanTree($cached);
            }
        }

        $collection = $this->buildCollection(
            $this->buildGroup($this->groupRoutes($this->collectRoutes()))
        );

        $collection = Utf8::cleanTree($collection);

        if ($this->cacheEnabled() && ! $this->cacheBroken) {
            $this->writeCache($this->cacheKey(), $collection, $this->cacheTtl());
            $this->rememberCacheKey($this->cacheKey());
        }

        return $collection;
    }

    /**
     * Non-fatal problems worth showing in the console (no routes matched,
     * filters too strict, ...).
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function clearCache(): void
    {
        if (! $this->cache) {
            return;
        }

        foreach ($this->cachedKeys() as $key) {
            $this->forgetCache($key);
        }

        $this->forgetCache($this->registryKey());
    }

    /**
     * Every snapshot key still registered, so api-snapshot:clear-cache can
     * remove collections generated with different options.
     *
     * @return list<string>
     */
    private function cachedKeys(): array
    {
        $keys = (array) $this->readCache($this->registryKey(), []);
        $keys[] = $this->cacheKey();

        return array_values(array_unique(array_filter($keys, 'is_string')));
    }

    private function rememberCacheKey(string $key): void
    {
        $this->writeCache($this->registryKey(), $this->cachedKeys(), 30 * 86400);
    }

    private function registryKey(): string
    {
        return ((string) ($this->config['cache']['prefix'] ?? 'api_snapshot_')) . 'keys';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Route collection & filtering
    // ─────────────────────────────────────────────────────────────────────────

    /** @return list<Route> */
    private function collectRoutes(): array
    {
        $scannable = [];
        $included  = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            if (! $this->isScannable($route)) {
                continue;
            }

            $scannable[] = $route;

            if ($this->matchesInclude($route)) {
                $included[] = $route;
            }
        }

        if ($this->scanAllRoutes()) {
            return $scannable;
        }

        if ($included === [] && $scannable !== []) {
            $this->warnings[] = sprintf(
                'No route matched %s, so all %d non-excluded routes were scanned instead. '
                . 'Set include_middleware/include_paths in config/api-snapshot.php to narrow this down.',
                $this->describeInclude(),
                count($scannable),
            );

            return $scannable;
        }

        if ($scannable === []) {
            $this->warnings[] = 'The application has no scannable routes.';
        }

        return $included;
    }

    private function isScannable(Route $route): bool
    {
        $uri = ltrim($route->uri(), '/');

        if ($uri === '' || $uri === '*' || $this->isCatchAll($uri)) {
            return false;
        }

        if ($this->isExcluded($uri, $route)) {
            return false;
        }

        return $this->httpMethods($route) !== [];
    }

    /**
     * Symfony's "everything else" fallback routes (SPA catches, asset proxies)
     * are not real endpoints.
     */
    private function isCatchAll(string $uri): bool
    {
        return (bool) preg_match('/^\{[A-Za-z_][A-Za-z0-9_]*\??(\s*:\s*\.\*)?\}?$/', $uri)
            || str_ends_with($uri, '/{any?}')
            || str_ends_with($uri, '/{any}');
    }

    private function isExcluded(string $uri, Route $route): bool
    {
        $middleware = array_map(
            fn ($name) => strtolower(strtok((string) $name, ':') ?: strtolower((string) $name)),
            $route->gatherMiddleware()
        );

        foreach ((array) ($this->config['exclude_middleware'] ?? []) as $name) {
            if (in_array(strtolower((string) $name), $middleware, true)) {
                return true;
            }
        }

        foreach ((array) ($this->config['exclude_patterns'] ?? []) as $pattern) {
            if ($pattern !== '' && str_contains($uri, (string) $pattern)) {
                return true;
            }
        }

        foreach ((array) ($this->config['exclude_paths'] ?? []) as $pattern) {
            if ($this->pathMatches($uri, (string) $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Both include options act as filters, so configuring paths on top of a
     * middleware narrows it down rather than widening it.
     */
    private function matchesInclude(Route $route): bool
    {
        $middleware = (array) ($this->config['include_middleware'] ?? []);
        $paths      = (array) ($this->config['include_paths'] ?? []);

        if ($paths !== [] && ! $this->matchesAnyPath($route, $paths)) {
            return false;
        }

        if ($middleware === []) {
            return true;
        }

        foreach ($route->gatherMiddleware() as $name) {
            $name = (string) $name;

            foreach ($middleware as $required) {
                $required = (string) $required;

                if ($name === $required || str_starts_with($name, $required . ':')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<mixed>  $paths
     */
    private function matchesAnyPath(Route $route, array $paths): bool
    {
        foreach ($paths as $pattern) {
            if ($this->pathMatches(ltrim($route->uri(), '/'), (string) $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Accepts a plain prefix ("api/v1") or a wildcard pattern where "*"
     * matches a single URI segment.
     */
    private function pathMatches(string $uri, string $pattern): bool
    {
        if ($pattern === '') {
            return false;
        }

        if (! str_contains($pattern, '*')) {
            return str_starts_with($uri, ltrim($pattern, '/'));
        }

        $regex = '#^' . str_replace('\*', '[^/]*', preg_quote(ltrim($pattern, '/'), '#')) . '$#i';

        return preg_match($regex, $uri) === 1;
    }

    private function scanAllRoutes(): bool
    {
        return (bool) ($this->config['scan_all_routes'] ?? false);
    }

    private function describeInclude(): string
    {
        $parts = [];

        if (($middleware = (array) ($this->config['include_middleware'] ?? [])) !== []) {
            $parts[] = 'include_middleware=' . implode('|', $middleware);
        }

        if (($paths = (array) ($this->config['include_paths'] ?? [])) !== []) {
            $parts[] = 'include_paths=' . implode('|', $paths);
        }

        return $parts === [] ? 'any route filter' : implode(' and ', $parts);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Grouping
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  list<Route>  $routes
     * @return array{folders: array<string, array>, requests: list<Route>}
     */
    private function groupRoutes(array $routes): array
    {
        $this->namespaceRoot = $this->commonNamespaceRoot($routes);
        $tree = $this->emptyGroup();

        foreach ($routes as $route) {
            $node  = &$tree;

            foreach ($this->groupSegments($route) as $segment) {
                if (! isset($node['folders'][$segment])) {
                    $node['folders'][$segment] = $this->emptyGroup();
                }

                $node = &$node['folders'][$segment];
            }

            $node['requests'][] = $route;

            unset($node);
        }

        return $tree;
    }

    /** @return array{folders: array<string, array>, requests: list<Route>} */
    private function emptyGroup(): array
    {
        return ['folders' => [], 'requests' => []];
    }

    /**
     * Folder path for a route, deepest level first being the last entry.
     *
     * @return list<string>
     */
    private function groupSegments(Route $route): array
    {
        $strategy = (string) ($this->config['group_by'] ?? 'prefix');
        $depth    = max(1, (int) ($this->config['group_depth'] ?? 2));

        $segments = $strategy === 'controller'
            ? $this->controllerSegments($route)
            : $this->prefixSegments($route);

        if ($strategy === 'prefix' || $depth >= count($segments)) {
            return array_slice($segments, 0, $depth);
        }

        // The controller name is the last segment and the only part that tells
        // two "PUT update" requests apart, so a depth limit must not drop it.
        return array_merge(array_slice($segments, 0, $depth - 1), [$segments[count($segments) - 1]]);
    }

    /** @return list<string> */
    private function prefixSegments(Route $route): array
    {
        $uri      = ltrim($route->uri(), '/');
        $segments = [];
        $stripped = array_map(
            fn ($prefix) => strtolower(trim((string) $prefix, '/')),
            (array) ($this->config['strip_prefixes'] ?? ['api'])
        );

        foreach (explode('/', $uri) as $segment) {
            $lower = strtolower($segment);

            if ($lower === '' || str_starts_with($segment, '{')
                || in_array($lower, $stripped, true)
                || preg_match('/^v\d+$/', $lower)) {
                continue;
            }

            $segments[] = $this->segmentToTitle($segment);
        }

        return $segments === [] ? ['General'] : $segments;
    }

    /** @return list<string> */
    private function controllerSegments(Route $route): array
    {
        $class = $this->controllerClass($route);

        if ($class === null) {
            return $this->prefixSegments($route);
        }

        $segments = array_slice(explode('\\', $class), count($this->namespaceRoot));
        $name     = preg_replace('/Controller$/', '', (string) array_pop($segments)) ?? '';
        $name     = trim(preg_replace('/(?<!^)[A-Z]/', ' $0', $name) ?? $name);

        if ($name === '') {
            return $this->prefixSegments($route);
        }

        $ignore = ['http', 'controllers', 'app', 'src', 'domain', 'actions'];

        $folders = array_values(array_filter(
            $segments,
            fn ($part) => ! in_array(strtolower($part), $ignore, true) && ! preg_match('/^v\d+$/i', $part)
        ));

        $folders[] = $name;

        return array_map(fn ($part) => $this->segmentToTitle($part), $folders);
    }

    /**
     * The namespace every scanned controller shares ("App\Http\Controllers"),
     * so grouping by controller shows folders users recognise instead of the
     * vendor or root namespace.
     *
     * @param  iterable<Route>  $routes
     * @return list<string>
     */
    private function commonNamespaceRoot(iterable $routes): array
    {
        $root = null;

        foreach ($routes as $route) {
            $class = $this->controllerClass($route);

            if ($class === null) {
                continue;
            }

            $parts = array_slice(explode('\\', $class), 0, -1);

            if ($root === null) {
                $root = $parts;
                continue;
            }

            $common = 0;

            foreach ($root as $index => $segment) {
                if (($parts[$index] ?? null) !== $segment) {
                    break;
                }

                $common++;
            }

            $root = array_slice($root, 0, $common);
        }

        return $root ?? [];
    }

    /**
     * Fully qualified controller class behind a route action, if it has one.
     */
    private function controllerClass(Route $route): ?string
    {
        $action = $route->getAction('controller');

        if (! is_string($action) || $action === '') {
            return null;
        }

        $class = explode('@', $action)[0];

        return class_exists($class) || str_contains($class, '\\') ? $class : null;
    }

    private function segmentToTitle(string $segment): string
    {
        $segment = trim(preg_replace('/\{.*?}/', '', $segment) ?? $segment, '-_');

        if ($segment === '') {
            return 'General';
        }

        $words = preg_split('/[_\-]/', $segment) ?: [$segment];

        return implode(' ', array_map(
            fn ($word) => ucfirst($this->singularise($word)),
            $words
        ));
    }

    private function singularise(string $word): string
    {
        $lower      = strtolower($word);
        $irregulars = [
            'children' => 'child', 'people' => 'person', 'men' => 'man',
            'women' => 'woman', 'teeth' => 'tooth', 'feet' => 'foot',
            'mice' => 'mouse', 'geese' => 'goose',
        ];

        if (isset($irregulars[$lower])) {
            return $irregulars[$lower];
        }

        if (str_ends_with($lower, 'ies') && strlen($lower) > 3) {
            return substr($word, 0, -3) . 'y';
        }

        if (preg_match('/(s|x|z|ch|sh)es$/', $lower)) {
            return substr($word, 0, -2);
        }

        if (str_ends_with($lower, 'ves')) {
            return substr($word, 0, -3) . 'f';
        }

        if (str_ends_with($lower, 's') && strlen($lower) > 3
            && ! str_ends_with($lower, 'ss') && ! str_ends_with($lower, 'us')
            && ! str_ends_with($lower, 'is')) {
            return substr($word, 0, -1);
        }

        return $word;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Postman structure builders
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  array{folders: array<string, array>, requests: list<Route>}  $group
     * @return list<array<string, mixed>>
     */
    private function buildGroup(array $group): array
    {
        $items = [];

        foreach ($group['requests'] as $route) {
            foreach ($this->buildRequestItems($route) as $item) {
                $items[] = $item;
            }
        }

        ksort($group['folders']);

        foreach ($group['folders'] as $name => $child) {
            $children = $this->buildGroup($child);

            if ($children === []) {
                continue;
            }

            $items[] = ['name' => $name, 'description' => '', 'item' => $children];
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function buildRequestItems(Route $route): array
    {
        try {
            $data = $this->routeExtractor->extract($route);
        } catch (Throwable $exception) {
            $this->warnings[] = sprintf(
                'Route "%s" could not be inspected (%s) and was added without parameters.',
                $route->uri(),
                $exception->getMessage(),
            );

            $data = null;
        }

        if ($data === null) {
            return [[
                'name'     => strtoupper(($this->httpMethods($route)[0] ?? 'GET')) . ' /' . ltrim($route->uri(), '/'),
                'request'  => [
                    'url'         => $this->buildUrl(ltrim($route->uri(), '/'), [], []),
                    'method'      => strtoupper($this->httpMethods($route)[0] ?? 'GET'),
                    'header'      => $this->headers('raw'),
                    'description' => '',
                    'auth'        => ['type' => 'noauth'],
                ],
                'response' => [],
            ]];
        }

        $items = [];

        foreach ($this->httpMethods($route) as $httpMethod) {
            $items[] = $this->buildSingleItem($httpMethod, $data);
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function buildSingleItem(string $httpMethod, array $data): array
    {
        $queryParams = $this->isReadOnly($httpMethod) ? $data['queryParams'] : [];
        $body        = $this->buildBody($httpMethod, $data['bodyTree']);

        $item = [
            'name'     => $data['actionName'],
            'request'  => [
                'url'         => $this->buildUrl($data['uri'], $data['pathVariables'], $queryParams),
                'method'      => strtoupper($httpMethod),
                'header'      => $this->headers($body['mode'] ?? 'raw'),
                'description' => $this->buildDescription($httpMethod, $data),
                'auth'        => $this->buildAuth((bool) $data['authRequired']),
            ],
            'response' => [],
        ];

        if ($body !== null) {
            $item['request']['body'] = $body;
        }

        return $item;
    }

    /** @return array<string, mixed> */
    private function buildUrl(string $uri, array $pathVariables, array $queryParams): array
    {
        $rawQuery = '';

        if ($queryParams !== []) {
            $rawQuery = '?' . implode('&', array_map(
                fn ($query) => rawurlencode((string) $query['key']) . '='
                    . rawurlencode((string) ($query['value'] ?? '')),
                $queryParams
            ));
        }

        $url = [
            'host'  => '{{baseUrl}}',
            'path'  => $this->urlSegments($uri),
            'query' => $queryParams,
            'raw'   => '{{baseUrl}}/' . implode('/', $this->urlSegments($uri)) . $rawQuery,
        ];

        if ($pathVariables !== []) {
            $url['variable'] = $pathVariables;
        }

        return $url;
    }

    /**
     * Postman addresses path variables as ":name" segments, so "{id}" and
     * "{id:[0-9]+}" both become ":id".
     *
     * @return list<string>
     */
    private function urlSegments(string $uri): array
    {
        return array_map(
            function (string $segment) {
                if (preg_match('/^\{(\w+)(?::[^}]*)?\??\}$/', $segment, $m) === 1) {
                    return ':' . $m[1];
                }

                return $segment;
            },
            array_values(array_filter(explode('/', $uri), fn ($part) => $part !== ''))
        );
    }

    /** @return array<string, mixed>|null */
    private function buildBody(string $httpMethod, ?ParamTree $tree): ?array
    {
        if ($this->isReadOnly($httpMethod) || $tree === null || $tree->isEmpty()) {
            return null;
        }

        $leaf = $this->leafOverride();

        if ($tree->containsFile()) {
            $fields = [];

            foreach ($tree->formFields($this->fakeValues, $leaf) as $field) {
                $fields[] = $field['type'] === 'file'
                    ? ['key' => $field['key'], 'type' => 'file', 'src' => [], 'description' => $field['description']]
                    : ['key' => $field['key'], 'type' => 'text', 'value' => $field['value'], 'description' => $field['description']];
            }

            return ['mode' => 'formdata', 'formdata' => $fields];
        }

        $json = json_encode(
            $tree->sample($this->fakeValues, $leaf),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return [
            'mode'    => 'raw',
            'raw'     => $json === false ? '{}' : $json,
            'options' => ['raw' => ['language' => 'json']],
        ];
    }

    /** @return (callable(string, list<string>): mixed)|null */
    private function leafOverride(): ?callable
    {
        return ($this->config['generate_fake_values'] ?? true)
            ? null
            : fn (string $key, array $rules) => '';
    }

    /**
     * @param  list<string>  $middleware
     * @return array<string, mixed>
     */
    private function buildAuth(bool $routeRequiresAuth): array
    {
        $type  = strtolower((string) ($this->config['auth']['type'] ?? 'noauth'));
        $token = $this->config['auth']['token'] ?? null;

        if ($type === 'bearer') {
            $value = $token === null || $token === ''
                ? '{{' . $this->bearerVariable() . '}}'
                : (string) $token;

            return ['type' => 'bearer', 'bearer' => [
                ['key' => 'token', 'value' => $value, 'type' => 'string'],
            ]];
        }

        if ($type === 'basic') {
            return ['type' => 'basic', 'basic' => [
                ['key' => 'username', 'value' => (string) ($this->config['auth']['username'] ?? ''), 'type' => 'string'],
                ['key' => 'password', 'value' => (string) ($this->config['auth']['password'] ?? ''), 'type' => 'string'],
            ]];
        }

        // Protected endpoints still need a usable Authorization header even when
        // the collection itself is configured as "noauth".
        if ($routeRequiresAuth && ($this->config['auth']['detect_middleware'] ?? true)) {
            $this->needsAuthVariable = true;

            return ['type' => 'bearer', 'bearer' => [
                ['key' => 'token', 'value' => '{{' . $this->bearerVariable() . '}}', 'type' => 'string'],
            ]];
        }

        return ['type' => 'noauth'];
    }

    /**
     * Name of the collection variable the bearer token is read from. A config
     * token of its own ({{access_token}}) names it, because Postman only
     * resolves a variable the collection actually defines.
     */
    private function bearerVariable(): string
    {
        $token = trim((string) ($this->config['auth']['token'] ?? ''));

        if (preg_match('/^\{\{\s*([A-Za-z0-9_.-]+)\s*\}\}$/', $token, $matches) === 1) {
            return $matches[1];
        }

        return 'authToken';
    }

    /**
     * @param  'raw'|'formdata'|'none'  $bodyMode
     * @return list<array<string, string>>
     */
    private function headers(string $bodyMode): array
    {
        $headers = [];
        $isMultipart = $bodyMode === 'formdata';

        foreach ((array) ($this->config['default_headers'] ?? [
            ['key' => 'Content-Type', 'value' => 'application/json'],
            ['key' => 'Accept', 'value' => 'application/json'],
        ]) as $header) {
            $key = (string) ($header['key'] ?? '');

            // Postman generates the multipart boundary itself.
            if ($isMultipart && strtolower($key) === 'content-type') {
                continue;
            }

            $headers[] = ['key' => $key, 'value' => (string) ($header['value'] ?? ''), 'type' => 'text'];
        }

        return $headers;
    }

    private function buildDescription(string $httpMethod, array $data): string
    {
        $persian = (bool) ($this->config['persian_descriptions'] ?? true);
        $lines   = [];

        if ($data['summary'] !== '') {
            $lines[] = $data['summary'];
        }

        $lines[] = $persian
            ? "این endpoint عملیات «{$data['actionName']}» را با متد {$httpMethod} انجام می‌دهد."
            : "Executes the «{$data['actionName']}» operation with {$httpMethod}.";

        if ($data['formRequestClass'] !== null || $data['rules'] !== []) {
            $source = $data['formRequestClass'] !== null
                ? ($persian ? 'کلاس اعتبارسنجی: ' : 'Validated by: ') . $data['formRequestClass']
                : ($persian ? 'اعتبارسنجی به‌صورت inline داخل کنترلر تعریف شده است.' : 'Validation rules are declared inline in the controller.');

            $lines[] = $source;
        } else {
            $lines[] = $persian
                ? 'این endpoint ورودی اعتبارسنجی‌شده‌ای اعلام نکرده است.'
                : 'This endpoint does not declare validated input.';
        }

        if ($data['middleware'] !== []) {
            $lines[] = ($persian ? 'میدل‌ورها: ' : 'Middleware: ') . implode(', ', $data['middleware']);
        }

        if ($this->isReadOnly($httpMethod)) {
            $lines[] = $persian
                ? 'ورودی‌ها به‌صورت پارامتر کوئری ارسال می‌شوند.'
                : 'Input is sent as query parameters.';
        } elseif ($data['bodyTree'] !== null && ! $data['bodyTree']->isEmpty()) {
            $lines[] = $persian
                ? 'بدنه‌ی درخواست با نمونه‌ی معتبر بر اساس قواعد اعتبارسنجی پر شده است.'
                : 'The request body is prefilled with a sample that satisfies the validation rules.';
        }

        $lines[] = $persian
            ? 'خروجی: پاسخ JSON شامل وضعیت عملیات، پیام و داده‌های مرتبط.'
            : 'Output: JSON response with operation status, message and related data.';

        return implode("\n", $lines);
    }

    /** @param  list<array<string, mixed>>  $items */
    private function buildCollection(array $items): array
    {
        $name    = (string) ($this->config['name'] ?? 'API Documentation');
        $baseUrl = rtrim((string) ($this->config['base_url'] ?? 'http://localhost'), '/');

        $variables = [[
            'id'    => 'baseUrl',
            'key'   => 'baseUrl',
            'type'  => 'string',
            'value' => $baseUrl,
        ]];

        if ($this->needsAuthVariable || strtolower((string) ($this->config['auth']['type'] ?? '')) === 'bearer') {
            $variable = $this->bearerVariable();
            $token    = trim((string) ($this->config['auth']['token'] ?? ''));

            $variables[] = [
                'id'    => $variable,
                'key'   => $variable,
                'type'  => 'string',
                // A reference such as {{access_token}} is filled in by the user
                // or by a login request, so it cannot carry its own value.
                'value' => $token !== '' && $token !== '{{' . $variable . '}}' ? $token : '',
            ];
        }

        return [
            'variable' => $variables,
            'info'     => [
                'name'        => $name,
                '_postman_id' => $this->uuid(),
                'description' => (string) ($this->config['description'] ?? $this->collectionDescription($items)),
                'schema'      => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'item' => $items,
        ];
    }

    /** @param  list<array<string, mixed>>  $items */
    private function collectionDescription(array $items): string
    {
        return sprintf(
            "Generated by swanflutter/laravel-api-snapshot on %s (%d endpoints).",
            date('Y-m-d H:i'),
            $this->countRequests($items),
        );
    }

    /** @param  list<array<string, mixed>>  $items */
    private function countRequests(array $items): int
    {
        $total = 0;

        foreach ($items as $item) {
            if (isset($item['request'])) {
                $total++;
            } elseif (isset($item['item']) && is_array($item['item'])) {
                $total += $this->countRequests($item['item']);
            }
        }

        return $total;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function httpMethods(Route $route): array
    {
        return array_values(array_filter($route->methods(), fn ($method) => ! in_array($method, ['HEAD', 'OPTIONS'], true)));
    }

    private function isReadOnly(string $httpMethod): bool
    {
        return in_array(strtoupper($httpMethod), ['GET', 'HEAD', 'DELETE', 'OPTIONS'], true);
    }

    private function cacheEnabled(): bool
    {
        return ($this->config['cache']['enabled'] ?? true) && $this->cache !== null && ! $this->skipCache;
    }

    private function cacheTtl(): int
    {
        return (int) ($this->config['cache']['ttl'] ?? 3600);
    }

    /**
     * A snapshot is documentation tooling: it should still work when the
     * configured store is unreachable (database cache with the DB down).
     */
    private function readCache(string $key, mixed $default = null): mixed
    {
        if ($this->cacheBroken) {
            return $default;
        }

        try {
            return $this->cache->get($key, $default);
        } catch (Throwable $e) {
            $this->markCacheBroken();

            return $default;
        }
    }

    private function writeCache(string $key, mixed $value, int $ttl): void
    {
        if ($this->cacheBroken) {
            return;
        }

        try {
            $this->cache->put($key, $value, $ttl);
        } catch (Throwable) {
            $this->markCacheBroken();
        }
    }

    private function forgetCache(string $key): void
    {
        if ($this->cacheBroken) {
            return;
        }

        try {
            $this->cache->forget($key);
        } catch (Throwable) {
            $this->markCacheBroken();
        }
    }

    private function markCacheBroken(): void
    {
        if ($this->cacheBroken) {
            return;
        }

        $this->cacheBroken = true;

        $this->warnings[] = 'The configured cache store is not reachable, so this run is neither read from nor written to the cache. '
            . 'Use --no-cache to skip it, or switch CACHE_STORE (file works without a database).';
    }

    /**
     * Ties the cached collection to the configuration and CLI options that
     * produced it, so a flag change is never served from a stale entry.
     */
    private function cacheKey(): string
    {
        $prefix = (string) ($this->config['cache']['prefix'] ?? 'api_snapshot_');

        $fingerprint = [
            'name'            => $this->config['name'] ?? null,
            'base_url'        => $this->config['base_url'] ?? null,
            'group_by'        => $this->config['group_by'] ?? null,
            'group_depth'     => $this->config['group_depth'] ?? null,
            'strip_prefixes'  => $this->config['strip_prefixes'] ?? null,
            'include_middleware' => $this->config['include_middleware'] ?? null,
            'include_paths'   => $this->config['include_paths'] ?? null,
            'exclude_patterns' => $this->config['exclude_patterns'] ?? null,
            'exclude_paths'   => $this->config['exclude_paths'] ?? null,
            'exclude_middleware' => $this->config['exclude_middleware'] ?? null,
            'scan_all_routes' => $this->config['scan_all_routes'] ?? null,
            'auth'            => $this->config['auth'] ?? null,
            'default_headers' => $this->config['default_headers'] ?? null,
            'generate_fake_values'  => $this->config['generate_fake_values'] ?? null,
            'persian_descriptions'  => $this->config['persian_descriptions'] ?? null,
        ];

        return $prefix . 'collection_' . substr(md5(json_encode($fingerprint) ?: ''), 0, 16);
    }

    private function uuid(): string
    {
        try {
            $data = random_bytes(16);
        } catch (Throwable) {
            $data = str_repeat("\0", 16);
        }

        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
