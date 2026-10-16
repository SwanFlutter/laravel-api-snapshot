<?php

namespace SwanFlutter\LaravelApiSnapshot\Extractors;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use SwanFlutter\LaravelApiSnapshot\Generators\FakeValueGenerator;
use SwanFlutter\LaravelApiSnapshot\Support\ParamTree;
use SwanFlutter\LaravelApiSnapshot\Support\Utf8;
use Throwable;

class RouteExtractor
{
    public function __construct(
        private readonly RuleExtractor       $ruleExtractor,
        private readonly FakeValueGenerator  $fakeValueGenerator,
        private readonly InlineRuleExtractor $inlineExtractor = new InlineRuleExtractor(),
        private readonly bool                $generateFakeValues = true,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function extract(Route $route): array
    {
        [$controller, $method]  = $this->parseAction($route);
        $httpMethods            = $this->httpMethods($route);
        $uri                    = ltrim($route->uri(), '/');
        $middleware             = $this->extractMiddleware($route);
        $pathNames              = $this->pathParameterNames($route, $uri);
        $pathSample             = $this->pathSampleValues($pathNames);

        $rules       = $this->collectRules($route, $controller, $method, $uri, $httpMethods, $pathSample);
        $formRequest = $this->resolveFormRequest($controller, $method);

        // A read endpoint with no declared rules still documents the query
        // string it actually reads ($request->input('page') and friends).
        if ($rules === [] && $controller !== null && $method !== null
            && array_intersect($httpMethods, ['GET', 'HEAD', 'DELETE']) !== []) {
            $rules = $this->inlineExtractor->inferredInputs($controller, $method);
        }

        $bodyRules   = array_diff_key($rules, array_flip($pathNames));
        $tree        = ParamTree::fromRules($bodyRules);
        $queryParams = $tree->queryParameters($this->fakeValueGenerator, $this->leafOverride());

        return [
            'controller'       => $controller,
            'method'           => $method,
            'actionName'       => $this->actionName($route, $controller, $method, $uri, $httpMethods),
            'summary'          => $this->summary($controller, $method),
            'httpMethods'      => $httpMethods,
            'uri'              => $uri,
            'middleware'       => $middleware,
            'authRequired'     => $this->requiresAuth($middleware),
            'pathVariables'    => $this->buildPathVariables($pathNames, $rules),
            'queryParams'      => $queryParams,
            'bodyTree'         => $tree,
            'formRequestClass' => $formRequest,
            'rules'            => $rules,
        ];
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function parseAction(Route $route): array
    {
        $action = $route->getAction('controller');

        if (! is_string($action) || $action === '') {
            return [null, null];
        }

        // Invokable controllers register as "App\Http\Controllers\Foo" or "Foo@__invoke".
        if (! str_contains($action, '@')) {
            return [$action, '__invoke'];
        }

        [$class, $method] = explode('@', $action, 2);

        return [$class, $method];
    }

    /** @return list<string> */
    private function httpMethods(Route $route): array
    {
        return array_values(array_filter($route->methods(), fn ($m) => $m !== 'HEAD'));
    }

    /** @return list<string> */
    private function extractMiddleware(Route $route): array
    {
        return array_values(array_filter(array_map(
            fn ($m) => match (true) {
                is_string($m) => $m,
                is_object($m) => $m::class,
                default       => null,
            },
            $route->gatherMiddleware()
        )));
    }

    /** @return list<string> */
    private function pathParameterNames(Route $route, string $uri): array
    {
        try {
            $names = $route->parameterNames();
        } catch (Throwable) {
            $names = [];
        }

        if ($names === []) {
            preg_match_all('/\{(\w+)(?::[^}]*)?\??\}/', $uri, $matches);
            $names = $matches[1];
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  list<string>  $names
     * @return array<string, mixed>
     */
    private function pathSampleValues(array $names): array
    {
        $values = [];

        foreach ($names as $name) {
            $values[$name] = $this->guessPathParam($name);
        }

        return $values;
    }

    /**
     * @param  array<string, list<string>>  $rules
     * @param  list<string>  $names
     * @return list<array<string, mixed>>
     */
    private function buildPathVariables(array $names, array $rules): array
    {
        $variables = [];

        foreach ($names as $name) {
            $fieldRules = $rules[$name] ?? [];

            $value = match (true) {
                ! $this->generateFakeValues => '',
                $fieldRules !== []          => $this->generate($name, $fieldRules) ?? 1,
                default                      => $this->guessPathParam($name),
            };

            $variables[] = [
                'id'          => $name,
                'key'         => $name,
                'value'       => (string) $value,
                'description' => $this->describeParam($name),
            ];
        }

        return $variables;
    }

    private function guessPathParam(string $name): mixed
    {
        $lower = strtolower($name);

        return match (true) {
            str_contains($lower, 'uuid')       => '550e8400-e29b-41d4-a716-446655440000',
            str_contains($lower, 'slug')       => 'sample-slug',
            str_ends_with($lower, '_at')       => '2026-05-29',
            str_contains($lower, 'date')       => '2026-05-29',
            $lower === 'id'
            || str_contains($lower, '_id')
            || str_contains($lower, 'id_')     => 1,
            default                             => 'example',
        };
    }

    /**
     * @return array<string, list<string>>
     */
    private function collectRules(
        Route $route,
        ?string $controller,
        ?string $method,
        string $uri,
        array $httpMethods,
        array $pathSample,
    ): array {
        $formRequest = $this->resolveFormRequest($controller, $method);
        $rules       = [];

        if ($formRequest !== null) {
            $pathParameters = $this->routePathParameters($route, $pathSample);
            $rules          = $this->ruleExtractor->extract($formRequest, $uri, $httpMethods, $pathParameters);
        }

        if ($controller !== null && $method !== null) {
            foreach ($this->inlineExtractor->extract($controller, $method) as $field => $fieldRules) {
                $rules[$field] ??= $fieldRules;
            }
        }

        return $rules;
    }

    /**
     * Real values a FormRequest may read through $this->route('param').
     *
     * @param  array<string, string|int>  $pathSample
     * @return array<string, mixed>
     */
    private function routePathParameters(Route $route, array $pathSample): array
    {
        $values = [];

        foreach ($pathSample as $name => $sample) {
            try {
                $value = $route->parameter($name);
            } catch (Throwable) {
                $value = null;
            }

            $values[$name] = $value ?? $sample;
        }

        return $values;
    }

    private function resolveFormRequest(?string $controller, ?string $method): ?string
    {
        if ($controller === null || $method === null || ! class_exists($controller)) {
            return null;
        }

        try {
            $reflection = new ReflectionMethod($controller, $method);
        } catch (ReflectionException) {
            return null;
        }

        foreach ($reflection->getParameters() as $parameter) {
            try {
                $type = $parameter->getType();

                if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }

                $typeName = $type->getName();

                if (class_exists($typeName) && (new ReflectionClass($typeName))->isSubclassOf(FormRequest::class)) {
                    return $typeName;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * @param  array<string, list<string>>  $rules
     */
    private function generate(string $field, array $rules): mixed
    {
        return $this->fakeValueGenerator->generate($field, $rules);
    }

    private function leafOverride(): ?callable
    {
        return $this->generateFakeValues ? null : fn (string $key, array $rules) => '';
    }

    /** @param list<string> $middleware */
    private function requiresAuth(array $middleware): bool
    {
        foreach ($middleware as $name) {
            $base = strtolower(strtok($name, ':') ?: $name);

            if (in_array($base, ['auth', 'role', 'permission', 'can'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A readable label for the Postman request item.
     *
     * @param  list<string>  $httpMethods
     */
    private function actionName(Route $route, ?string $controller, ?string $method, string $uri, array $httpMethods): string
    {
        if ($method !== null && $method !== '__invoke') {
            // "activityLogs" reads as "Activity Logs" in a Postman sidebar.
            return ucfirst(preg_replace('/(?<!^)[A-Z]/', ' $0', $method) ?? $method);
        }

        $name = $route->getName();

        if (is_string($name) && $name !== '') {
            return $name;
        }

        return strtoupper($httpMethods[0] ?? 'GET') . ' /' . $uri;
    }

    /**
     * The first line of the method docblock, when the developer wrote one.
     */
    private function summary(?string $controller, ?string $method): string
    {
        if ($controller === null || $method === null || ! class_exists($controller)) {
            return '';
        }

        try {
            $doc = (new ReflectionMethod($controller, $method))->getDocComment();
        } catch (Throwable) {
            return '';
        }

        if ($doc === false) {
            return '';
        }

        // Split on the byte literals only: /\R/ without the /u modifier treats
        // a raw 0x85 (NEL) inside a multi-byte character as a line break and
        // cuts it in half, which makes the description unencodable as JSON.
        // Split on the byte literals only: /\R/ without the /u modifier treats
        // a raw 0x85 (NEL) inside a multi-byte character as a line break and
        // cuts it in half, which makes the description unencodable as JSON.
        foreach (preg_split('/\r\n|\r|\n/', $doc) ?: [] as $line) {
            $text = trim($line, " \t/*#@");

            if ($text !== '' && ! str_starts_with($text, '@')) {
                return Utf8::sanitize($text);
            }
        }

        return '';
    }

    private function describeParam(string $param): string
    {
        $hints = [
            'id'    => 'The unique identifier',
            'uuid'  => 'The UUID',
            'slug'  => 'The URL slug',
            'token' => 'The token',
            'date'  => 'A date (YYYY-MM-DD)',
        ];

        foreach ($hints as $hint => $description) {
            if (str_contains(strtolower($param), $hint)) {
                return $description;
            }
        }

        return 'The ' . str_replace('_', ' ', $param);
    }
}
