<?php

namespace SwanFlutter\LaravelApiSnapshot\Extractors;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use SwanFlutter\LaravelApiSnapshot\Generators\FakeValueGenerator;
use Throwable;

class RouteExtractor
{
    public function __construct(
        private readonly RuleExtractor      $ruleExtractor,
        private readonly FakeValueGenerator $fakeValueGenerator,
    ) {}

    public function extract(Route $route): array
    {
        [$controller, $method] = $this->parseAction($route);
        $httpMethods  = $this->httpMethods($route);
        $uri          = ltrim($route->uri(), '/');
        $middleware   = $this->extractMiddleware($route);
        $formRequest  = $this->resolveFormRequest($controller, $method);
        $rules        = $formRequest ? $this->ruleExtractor->extract($formRequest) : [];

        $pathVariables              = $this->buildPathVariables($uri, $rules);
        [$queryParams, $bodyParams] = $this->splitParams($httpMethods, $uri, $rules);

        return [
            'controller'       => $controller,
            'method'           => $method,
            'httpMethods'      => $httpMethods,
            'uri'              => $uri,
            'middleware'       => $middleware,
            'pathVariables'    => $pathVariables,
            'queryParams'      => $queryParams,
            'bodyParams'       => $bodyParams,
            'formRequestClass' => $formRequest,
            'rules'            => $rules,
        ];
    }

    private function parseAction(Route $route): array
    {
        $action = $route->getAction();
        if (isset($action['controller'])) {
            $parts = explode('@', $action['controller']);
            return [$parts[0] ?? null, $parts[1] ?? null];
        }
        return [null, null];
    }

    private function httpMethods(Route $route): array
    {
        return array_values(array_filter($route->methods(), fn ($m) => $m !== 'HEAD'));
    }

    private function extractMiddleware(Route $route): array
    {
        return array_values(array_map(
            fn ($m) => is_string($m) ? $m : (is_object($m) ? get_class($m) : (string) $m),
            $route->gatherMiddleware()
        ));
    }

    private function resolveFormRequest(?string $controller, ?string $method): ?string
    {
        if (!$controller || !$method || !class_exists($controller)) return null;

        try {
            $ref = new ReflectionMethod($controller, $method);
        } catch (ReflectionException) {
            return null;
        }

        foreach ($ref->getParameters() as $param) {
            try {
                $type = $param->getType();
                if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) continue;
                $typeName = $type->getName();
                if (!class_exists($typeName)) continue;
                if ((new ReflectionClass($typeName))->isSubclassOf(FormRequest::class)) {
                    return $typeName;
                }
            } catch (Throwable) {
                continue;
            }
        }
        return null;
    }

    private function buildPathVariables(string $uri, array $rules): array
    {
        preg_match_all('/\{(\w+)\??}/', $uri, $matches);
        $variables = [];

        foreach ($matches[1] as $param) {
            $value = isset($rules[$param])
                ? ($this->fakeValueGenerator->generate($param, $rules[$param]) ?? 1)
                : (str_contains(strtolower($param), 'id') ? 1 : 'example');

            $variables[] = [
                'id'          => $param,
                'key'         => $param,
                'value'       => (string) $value,
                'description' => $this->describeParam($param),
            ];
        }

        return $variables;
    }

    private function splitParams(array $httpMethods, string $uri, array $rules): array
    {
        preg_match_all('/\{(\w+)\??}/', $uri, $matches);
        $pathParams = array_flip($matches[1]);
        $remaining  = array_filter($rules, fn ($k) => !isset($pathParams[$k]), ARRAY_FILTER_USE_KEY);

        $isRead  = !empty(array_intersect($httpMethods, ['GET', 'DELETE', 'HEAD']));
        $isWrite = !empty(array_intersect($httpMethods, ['POST', 'PUT', 'PATCH']));

        $queryParams = [];
        $bodyParams  = null;

        if ($isRead && !empty($remaining)) {
            foreach ($remaining as $field => $fieldRules) {
                $value = $this->fakeValueGenerator->generate($field, $fieldRules);
                $queryParams[] = [
                    'key'         => $field,
                    'value'       => $value === null ? '' : (string) $value,
                    'description' => implode(', ', array_filter($fieldRules, fn ($r) => !in_array($r, ['required', 'nullable', 'sometimes']))),
                    'disabled'    => false,
                ];
            }
        } elseif ($isWrite && !empty($remaining)) {
            $bodyParams = [];
            foreach ($remaining as $field => $fieldRules) {
                $bodyParams[$field] = $this->fakeValueGenerator->generate($field, $fieldRules);
            }
        }

        return [$queryParams, $bodyParams];
    }

    private function describeParam(string $param): string
    {
        $hints = ['id' => 'The unique identifier', 'uuid' => 'The UUID', 'slug' => 'The URL slug', 'token' => 'The token'];
        foreach ($hints as $hint => $desc) {
            if (str_contains(strtolower($param), $hint)) return $desc;
        }
        return 'The ' . str_replace('_', ' ', $param);
    }
}
