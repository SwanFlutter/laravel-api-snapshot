<?php

namespace SwanFlutter\LaravelApiSnapshot\Support;

use Closure;
use Throwable;

/**
 * Turns anything a Laravel rules() method may return into flat
 * "name:param" strings that the rest of the package can reason about.
 */
class RuleParser
{
    /**
     * Split a pipe-delimited rule string, keeping the "|" characters that
     * belong inside a regex pattern intact.
     *
     * @return list<string>
     */
    public static function split(string $rules): array
    {
        $parts  = explode('|', $rules);
        $merged = [];
        $count  = count($parts);

        for ($i = 0; $i < $count; $i++) {
            $current = $parts[$i];

            if (self::isRegexRule($current) && ! self::patternIsComplete(substr($current, strpos($current, ':') + 1))) {
                while ($i + 1 < $count && ! self::patternIsComplete(substr($current, strpos($current, ':') + 1))) {
                    $i++;
                    $current .= '|' . $parts[$i];
                }
            }

            if (trim($current) !== '') {
                $merged[] = trim($current);
            }
        }

        return $merged;
    }

    /**
     * Flatten a rule definition (string, array, rule object, closure) into
     * plain rule strings. Unrepresentable rules (closures, callables) are
     * dropped so a dynamic rules() method never breaks the snapshot.
     *
     * @param  string|iterable<mixed>|object  $rules
     * @return list<string>
     */
    public static function normalise(string|iterable|object $rules): array
    {
        if (is_string($rules)) {
            return self::split($rules);
        }

        $result = [];

        foreach ($rules as $rule) {
            if ($rule instanceof Closure) {
                continue;
            }

            if (is_array($rule)) {
                foreach (self::normalise($rule) as $nested) {
                    $result[] = $nested;
                }

                continue;
            }

            $string = is_object($rule) ? self::describeObject($rule) : (string) $rule;

            foreach (self::split($string) as $part) {
                $result[] = $part;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * Index rules by their rule name so lookups such as "min" or "in"
     * do not accidentally match substrings of other rules.
     *
     * @param  list<string>  $rules
     * @return array<string, string|null>
     */
    public static function index(array $rules): array
    {
        $indexed = [];

        foreach ($rules as $rule) {
            $position = strpos($rule, ':');
            $name     = $position === false ? $rule : substr($rule, 0, $position);
            $param    = $position === false ? null : substr($rule, $position + 1);

            // Later occurrences (e.g. min:1 followed by min:10) are kept as-is;
            // the first declared type-style rule is the one that matters here.
            if (! array_key_exists($name, $indexed)) {
                $indexed[$name] = $param;
            }
        }

        return $indexed;
    }

    /**
     * Human-readable rule list used for Postman parameter descriptions.
     *
     * @param  list<string>  $rules
     */
    public static function describe(array $rules): string
    {
        $noise = ['required', 'nullable', 'sometimes', 'present', 'filled', 'bail'];

        return implode(', ', array_values(array_filter(
            $rules,
            fn ($rule) => ! in_array(strtolower(strtok($rule, ':')), $noise, true)
        )));
    }

    public static function isRegexRule(string $rule): bool
    {
        $lower = strtolower($rule);

        return str_starts_with($lower, 'regex:') || str_starts_with($lower, 'not_regex:');
    }

    /**
     * A regex pattern is complete once its closing delimiter is present:
     * bracket-style delimiters close with their partner, everything else
     * repeats itself (skipping escaped occurrences).
     */
    private static function patternIsComplete(string $pattern): bool
    {
        if ($pattern === '') {
            return true;
        }

        $delimiter = $pattern[0];

        if (preg_match('/[A-Za-z0-9]/', $delimiter) === 1) {
            return true;
        }

        $closing = match ($delimiter) {
            '(' => ')', '[' => ']', '{' => '}', '<' => '>', default => $delimiter,
        };

        return preg_match('/(?<!\\\\)' . preg_quote($closing, '/') . '/', substr($pattern, 1)) === 1;
    }

    private static function describeObject(object $rule): string
    {
        try {
            if ($rule instanceof Closure) {
                return '';
            }

            $described = method_exists($rule, '__toString') ? (string) $rule : null;

            if ($described !== null && $described !== '') {
                // Laravel quotes rule arguments ("sales","users"); the snapshot
                // only needs the bare values.
                return str_replace('"', '', $described);
            }

            $short = self::shortName($rule);
            $args  = self::constructorArguments($rule);

            return $args === [] ? strtolower($short) : strtolower($short) . ':' . implode(',', $args);
        } catch (Throwable) {
            return '';
        }
    }

    private static function shortName(object $rule): string
    {
        $class = get_class($rule);
        $parts = explode('\\', $class);

        return end($parts) ?: $class;
    }

    /**
     * @return list<string>
     */
    private static function constructorArguments(object $rule): array
    {
        try {
            $reflection = new \ReflectionClass($rule);
            $constructor = $reflection->getConstructor();

            if ($constructor === null) {
                return [];
            }

            $values = [];

            foreach ($constructor->getParameters() as $parameter) {
                if (! $parameter->isPromoted()) {
                    continue;
                }

                $value = $parameter->getValue($rule);

                if (is_string($value) || is_int($value) || is_float($value)) {
                    $values[] = (string) $value;
                } elseif (is_array($value)) {
                    $flat = array_filter($value, static fn ($v) => is_string($v) || is_int($v) || is_float($v));

                    if ($flat !== []) {
                        $values[] = implode(',', array_map(static fn ($v) => (string) $v, $flat));
                    }
                }
            }

            return $values;
        } catch (Throwable) {
            return [];
        }
    }
}
