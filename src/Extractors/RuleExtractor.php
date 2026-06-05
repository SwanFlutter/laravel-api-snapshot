<?php

namespace SwanFlutter\LaravelApiSnapshot\Extractors;

use Illuminate\Foundation\Http\FormRequest;
use ReflectionClass;
use ReflectionException;
use Throwable;

class RuleExtractor
{
    /** @return array<string, string[]> */
    public function extract(string $requestClass): array
    {
        if (!$this->isFormRequest($requestClass)) return [];

        try {
            $instance = (new ReflectionClass($requestClass))->newInstanceWithoutConstructor();
            return $this->normalise($instance->rules());
        } catch (Throwable) {
            return [];
        }
    }

    private function isFormRequest(string $class): bool
    {
        if (!class_exists($class)) return false;
        try {
            return (new ReflectionClass($class))->isSubclassOf(FormRequest::class);
        } catch (ReflectionException) {
            return false;
        }
    }

    /** @return array<string, string[]> */
    private function normalise(mixed $raw): array
    {
        if (!is_array($raw)) return [];

        $result = [];
        foreach ($raw as $field => $rules) {
            if (is_string($rules)) {
                $result[(string) $field] = explode('|', $rules);
            } elseif (is_array($rules)) {
                $result[(string) $field] = array_map(
                    fn ($r) => is_string($r) ? $r : (is_object($r) && method_exists($r, '__toString') ? (string) $r : get_class($r)),
                    array_filter($rules, fn ($r) => is_string($r) || is_object($r))
                );
            } else {
                $result[(string) $field] = [];
            }
        }
        return $result;
    }
}
