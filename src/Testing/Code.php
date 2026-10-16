<?php

namespace SwanFlutter\LaravelApiSnapshot\Testing;

/**
 * Renders PHP values as readable literals for the generated test files.
 */
final class Code
{
    /**
     * @param  array<array-key, mixed>  $value
     */
    public static function export(array $value, int $indentLevel = 0): string
    {
        if ($value === []) {
            return '[]';
        }

        $pad  = str_repeat('    ', $indentLevel + 1);
        $tail = str_repeat('    ', $indentLevel);
        $isList = array_is_list($value);
        $lines  = [];

        foreach ($value as $key => $item) {
            $prefix  = $isList ? '' : var_export((string) $key, true) . ' => ';
            $lines[] = sprintf('%s%s%s,', $pad, $prefix, self::value($item, $indentLevel + 1));
        }

        return "[\n" . implode("\n", $lines) . "\n" . $tail . ']';
    }

    public static function value(mixed $value, int $indentLevel = 0): string
    {
        if (is_array($value)) {
            return self::export($value, $indentLevel);
        }

        return var_export($value, true);
    }
}
