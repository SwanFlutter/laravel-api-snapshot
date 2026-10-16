<?php

namespace SwanFlutter\LaravelApiSnapshot\Extractors;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use ReflectionClass;
use ReflectionException;
use SwanFlutter\LaravelApiSnapshot\Support\RuleParser;
use Throwable;

class RuleExtractor
{
    /**
     * Read the validation rules declared by a FormRequest without running the
     * HTTP lifecycle.
     *
     * @param  array<string, string>  $pathParameters  sample values for {param} segments
     * @return array<string, list<string>>
     */
    public function extract(string $requestClass, string $uri = '', array $httpMethods = [], array $pathParameters = []): array
    {
        if (! $this->isFormRequest($requestClass)) {
            return [];
        }

        foreach ($this->candidates($requestClass, $uri, $httpMethods, $pathParameters) as $instance) {
            try {
                $rules = $this->normalise($instance->rules());

                if ($rules !== []) {
                    return $rules;
                }
            } catch (Throwable) {
                // A rules() method may depend on authenticated state or database
                // rows that are unavailable offline; try the next candidate.
                continue;
            }
        }

        return [];
    }

    /**
     * Request instances to try, from most to least capable.
     *
     * @return iterable<FormRequest>
     */
    private function candidates(string $requestClass, string $uri, array $httpMethods, array $pathParameters): iterable
    {
        yield from $this->resolvedCandidates($requestClass, $uri, $httpMethods, $pathParameters);

        try {
            yield (new ReflectionClass($requestClass))->newInstanceWithoutConstructor();
        } catch (Throwable) {
            // Nothing else to try.
        }
    }

    /**
     * @return list<FormRequest>
     */
    private function resolvedCandidates(string $requestClass, string $uri, array $httpMethods, array $pathParameters): array
    {
        try {
            $method  = strtoupper($httpMethods[0] ?? 'POST');
            $request = $requestClass::create('/' . ltrim($uri, '/'), $method, $pathParameters);

            if (! $request instanceof FormRequest) {
                return [];
            }

            $request->setRouteResolver($this->routeResolver($uri, $httpMethods, $pathParameters));
            $request->setUserResolver(fn () => null);

            try {
                $request->setContainer(Container::getInstance());
            } catch (Throwable) {
                // Container-less environments (unit tests) can still read rules().
            }

            return [$request];
        } catch (Throwable) {
            return [];
        }
    }

    private function routeResolver(string $uri, array $httpMethods, array $pathParameters): Closure
    {
        return function () use ($uri, $httpMethods, $pathParameters) {
            try {
                $route = new Route($httpMethods === [] ? ['POST'] : $httpMethods, $uri, fn () => null);

                foreach ($pathParameters as $name => $value) {
                    $route->setParameter((string) $name, $value);
                }

                return $route;
            } catch (Throwable) {
                return null;
            }
        };
    }

    private function isFormRequest(string $class): bool
    {
        if (! class_exists($class)) {
            return false;
        }

        try {
            return (new ReflectionClass($class))->isSubclassOf(FormRequest::class);
        } catch (ReflectionException) {
            return false;
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function normalise(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $result = [];

        foreach ($raw as $field => $rules) {
            $result[(string) $field] = is_string($rules)
                ? RuleParser::split($rules)
                : (is_iterable($rules) || is_object($rules) ? RuleParser::normalise($rules) : []);
        }

        return $result;
    }
}
