<?php

namespace SwanFlutter\LaravelApiSnapshot\Support;

class Utf8
{
    /**
     * Anything read from application source — docblocks, rule strings, class
     * names — can hold bytes that are not valid UTF-8, and json_encode then
     * rejects the entire collection. Scrub those bytes instead of failing.
     */
    public static function sanitize(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_scrub($value, 'UTF-8');
    }

    /**
     * @template T
     *
     * @param  T  $value
     * @return T
     */
    public static function cleanTree(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::sanitize($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        $clean = [];

        foreach ($value as $key => $item) {
            $clean[is_string($key) ? self::sanitize($key) : $key] = self::cleanTree($item);
        }

        return $clean;
    }
}
