<?php

namespace SwanFlutter\LaravelApiSnapshot\Generators;

use SwanFlutter\LaravelApiSnapshot\Support\RuleParser;

/**
 * Produces sample values that satisfy the validation rules declared for a
 * field, so an imported Postman request can be sent without editing the body.
 */
class FakeValueGenerator
{
    private const SAMPLE_TIMESTAMP = '2026-05-29 08:09:35';

    /** @var list<string> */
    private const TYPE_RULES = [
        'boolean', 'array', 'file', 'image', 'mimes', 'mimetypes', 'json',
        'integer', 'numeric', 'decimal', 'digits', 'digits_between', 'email',
        'date', 'date_format', 'after', 'before', 'after_or_equal', 'before_or_equal',
        'url', 'active_url', 'uuid', 'ulid', 'ip', 'ipv4', 'ipv6', 'in', 'string',
        'alpha', 'alpha_num', 'regex',
    ];

    /** @var array<string, list<string>> */
    private const FIELD_HINTS = [
        'email'    => ['email', 'mail'],
        'phone'    => ['phone', 'mobile', 'tel', 'fax'],
        'password' => ['password', 'passwd', 'secret', 'token'],
        'uuid'     => ['uuid'],
        'slug'     => ['slug'],
        'url'      => ['url', 'link', 'website', 'domain', 'avatar', 'cover', 'thumbnail', 'photo', 'image'],
        'counter'  => ['count', 'quantity', 'qty', 'amount', 'price', 'cost', 'fee', 'balance', 'total', 'age', 'duration', 'priority', 'position', 'sort_order', 'year', 'month', 'day'],
        'page'     => ['page', 'limit', 'offset', 'per_page', 'take', 'skip'],
        'id'       => ['_id', 'id_'],
    ];

    /** @var array<string, string> */
    private const FIXED_SAMPLES = [
        'email'    => 'example@example.com',
        'url'      => 'https://example.com',
        'uuid'     => '550e8400-e29b-41d4-a716-446655440000',
        'ulid'     => '01FJ8Y2Q6K3TQZ8XWVNRGMB5TS',
        'ip'       => '192.168.1.1',
        'phone'    => '+989123456789',
        'password' => 'Password@123',
        'slug'     => 'sample-slug',
    ];

    /** @param list<mixed> $rules */
    public function generate(string $field, array $rules): mixed
    {
        $indexed = RuleParser::index(RuleParser::normalise($rules));
        $type    = $this->resolveType($field, $indexed);

        // A plain "string" rule says nothing about the format, so a field name
        // such as "per_page" is still allowed to pick a more precise sample.
        if ($type === 'string' && $this->isBareString($indexed)) {
            $type = $this->typeFromFieldName($field) ?? $type;
        } elseif ($type === 'string') {
            // The rules do fix a length: only a fixed-format sample that still
            // fits may replace the value derived from the field name.
            $hint = $this->typeFromFieldName($field);

            if ($hint !== null && isset(self::FIXED_SAMPLES[$hint]) && $this->fitsLength($hint, $indexed)) {
                $type = $hint;
            }
        }

        if ($type === null) {
            return $this->isRequired($indexed) ? $this->humanString($field) : null;
        }

        if (isset(self::FIXED_SAMPLES[$type])) {
            return self::FIXED_SAMPLES[$type];
        }

        return match ($type) {
            'boolean' => $this->boolean($indexed),
            'integer' => $this->integer($indexed, $field),
            'numeric' => $this->numeric($indexed),
            'array'   => [],
            'date'    => $this->date($indexed),
            'file'    => $this->file($indexed),
            'json'    => '{"key":"value"}',
            'in'      => $this->choice($indexed),
            default   => $this->string($indexed, $field),
        };
    }

    /**
     * @param  array<string, string|null>  $indexed
     */
    private function resolveType(string $field, array $indexed): ?string
    {
        foreach (self::TYPE_RULES as $rule) {
            if (! array_key_exists($rule, $indexed)) {
                continue;
            }

            return match ($rule) {
                'image', 'mimes', 'mimetypes' => 'file',
                'date_format', 'after', 'before', 'after_or_equal', 'before_or_equal' => 'date',
                'active_url' => 'url',
                'ipv4', 'ipv6' => 'ip',
                'decimal' => 'numeric',
                'digits', 'digits_between' => 'integer',
                'alpha', 'alpha_num', 'regex' => 'string',
                default => $rule,
            };
        }

        return $this->typeFromFieldName($field);
    }

    private function typeFromFieldName(string $field): ?string
    {
        $lower = strtolower($field);

        if (str_ends_with($lower, '_at') || str_contains($lower, 'date') || str_contains($lower, '_time')) {
            return 'date';
        }

        // Only unambiguous hints fall back to a type; a bare "note" or "title"
        // tells us nothing about the format, so it keeps no sample value.
        foreach (['email', 'phone', 'password', 'uuid', 'slug', 'url', 'counter', 'page'] as $hint) {
            if ($this->matchesHint($lower, $hint)) {
                return in_array($hint, ['counter', 'page'], true) ? 'integer' : $hint;
            }
        }

        if ($lower === 'id' || $this->matchesHint($lower, 'id')) {
            return 'integer';
        }

        return null;
    }

    /** @param array<string, string|null> $indexed */
    private function isRequired(array $indexed): bool
    {
        foreach (['required', 'required_if', 'required_with', 'required_unless', 'prohibited_unless', 'present', 'filled'] as $rule) {
            if (array_key_exists($rule, $indexed)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, string|null> $indexed */
    private function boolean(array $indexed): bool
    {
        if ($this->has($indexed, 'in')) {
            return filter_var($this->firstOption((string) $indexed['in']), FILTER_VALIDATE_BOOLEAN);
        }

        return false;
    }

    /**
     * True when the only type rule is a plain "string", with nothing that
     * constrains the shape of the value.
     *
     * @param array<string, string|null> $indexed
     */
    private function isBareString(array $indexed): bool
    {
        foreach (['size', 'min', 'max', 'regex', 'alpha', 'alpha_num', 'in', 'digits', 'digits_between'] as $rule) {
            if ($this->has($indexed, $rule)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a fixed-format sample satisfies the length the rules declare.
     * A rule that fixes the shape itself (regex, in, alpha …) always wins.
     *
     * @param  array<string, string|null>  $indexed
     */
    private function fitsLength(string $type, array $indexed): bool
    {
        foreach (['regex', 'alpha', 'alpha_num', 'in', 'not_in', 'digits', 'digits_between', 'size'] as $rule) {
            if ($this->has($indexed, $rule)) {
                return false;
            }
        }

        $sample = strlen(self::FIXED_SAMPLES[$type]);
        $min    = $this->number($indexed, 'min');
        $max    = $this->number($indexed, 'max');

        return ($min === null || $sample >= $min) && ($max === null || $sample <= $max);
    }

    /** @param array<string, string|null> $indexed */
    private function integer(array $indexed, string $field = ''): int
    {
        if ($this->has($indexed, 'in')) {
            return (int) $this->firstOption((string) $indexed['in']);
        }

        if ($this->has($indexed, 'size')) {
            return (int) trim((string) $indexed['size']);
        }

        if ($this->has($indexed, 'digits')) {
            $digits = max(1, (int) trim((string) $indexed['digits']));

            return (int) substr(str_repeat('1', $digits), 0, 9);
        }

        $min = $this->number($indexed, 'min');
        $max = $this->number($indexed, 'max');

        if ($min === null && $max === null && $field !== '' && $this->matchesHint(strtolower($field), 'page')) {
            return 1;
        }

        return match (true) {
            $min !== null && $max !== null => intdiv((int) $min + (int) $max, 2),
            $min !== null                  => (int) $min,
            $max !== null                  => max(1, min((int) $max, 16)),
            default                         => 16,
        };
    }

    /** @param array<string, string|null> $indexed */
    private function numeric(array $indexed): float|int
    {
        if ($this->has($indexed, 'in')) {
            return $this->toNumber($this->firstOption((string) $indexed['in']));
        }

        $decimals = $this->number($indexed, 'decimal');
        $min      = $this->number($indexed, 'min');
        $max      = $this->number($indexed, 'max');

        $value = match (true) {
            $min !== null && $max !== null => ($min + $max) / 2,
            $min !== null                  => $min,
            $max !== null                  => $max,
            default                         => 1500.5,
        };

        if ($decimals !== null) {
            $places = (int) $decimals;

            return $places === 0 ? (int) round($value) : round($value, $places);
        }

        return $value === floor($value) ? (int) $value : $value;
    }

    /** @param array<string, string|null> $indexed */
    private function string(array $indexed, string $field): string
    {
        if ($this->has($indexed, 'in')) {
            return $this->firstOption((string) $indexed['in']);
        }

        if ($this->has($indexed, 'regex')) {
            return $this->sampleFromRegex((string) $indexed['regex'], $field);
        }

        $base = $this->humanString($field);
        $size = $this->number($indexed, 'size');
        $min  = $this->number($indexed, 'min');
        $max  = $this->number($indexed, 'max');

        // The sample keeps its readable length unless a rule forces it shorter
        // or longer; only then is it cut or padded.
        $length = match (true) {
            $size !== null => max(1, (int) $size),
            $min !== null  => max(strlen($base), (int) $min),
            $max !== null  => min(strlen($base), max(1, (int) $max)),
            default         => strlen($base),
        };

        if ($this->has($indexed, 'alpha_num')) {
            $letters = max(1, (int) ceil($length * 2 / 3));

            return substr(str_repeat('abcdefgh', 8), 0, $letters)
                . substr(str_repeat('12345678', 8), 0, max(0, $length - $letters));
        }

        if ($this->has($indexed, 'alpha')) {
            return substr(str_repeat('abcdefghijklmnopqrstuvwxyz', 8), 0, $length);
        }

        if ($length <= strlen($base)) {
            return substr($base, 0, $length);
        }

        return substr(str_repeat($base, (int) ceil($length / strlen($base))), 0, $length);
    }

    /** @param array<string, string|null> $indexed */
    private function date(array $indexed): string
    {
        $moment = new \DateTimeImmutable(self::SAMPLE_TIMESTAMP);

        if ($this->has($indexed, 'date_format')) {
            return $moment->format(str_replace('\\|', '|', (string) $indexed['date_format']))
                ?: $moment->format('Y-m-d');
        }

        foreach (['after', 'before', 'after_or_equal', 'before_or_equal'] as $rule) {
            if ($this->has($indexed, $rule)) {
                return $moment->format('Y-m-d');
            }
        }

        return $moment->format('Y-m-d\TH:i:s');
    }

    /** @param array<string, string|null> $indexed */
    private function file(array $indexed): string
    {
        if ($this->has($indexed, 'mimes')) {
            $extension = $this->firstOption((string) $indexed['mimes']);

            if ($extension !== '') {
                return 'sample.' . $extension;
            }
        }

        return $this->has($indexed, 'image') ? 'image.jpg' : 'document.pdf';
    }

    /** @param array<string, string|null> $indexed */
    private function choice(array $indexed): string
    {
        $option = $this->firstOption((string) ($indexed['in'] ?? ''));

        return $option === '' ? 'option' : $option;
    }

    /**
     * Rule presence, including rules declared without an argument ("image").
     *
     * @param array<string, string|null> $indexed
     */
    private function has(array $indexed, string $rule): bool
    {
        return array_key_exists($rule, $indexed);
    }

    /**
     * @param array<string, string|null> $indexed
     */
    private function number(array $indexed, string $rule): ?float
    {
        if (! $this->has($indexed, $rule)) {
            return null;
        }

        $value = trim((string) $indexed[$rule]);

        return is_numeric($value) ? (float) $value : null;
    }

    private function firstOption(string $parameter): string
    {
        foreach (explode(',', $parameter) as $option) {
            $option = trim($option);

            if ($option !== '') {
                return $option;
            }
        }

        return '';
    }

    private function toNumber(string $value): float|int
    {
        if (! is_numeric($value)) {
            return 1;
        }

        return str_contains($value, '.') ? (float) $value : (int) $value;
    }

    private function humanString(string $field): string
    {
        $clean = trim(str_replace(['_', '-'], ' ', preg_replace('/[^A-Za-z0-9_\-]/', '', $field) ?? $field));

        return $clean === '' ? 'sample' : ucwords($clean);
    }

    private function matchesHint(string $lowerField, string $hint): bool
    {
        foreach (self::FIELD_HINTS[$hint] ?? [] as $needle) {
            if (str_starts_with($needle, '_') || str_ends_with($needle, '_')) {
                if (str_contains($lowerField, $needle)) {
                    return true;
                }

                continue;
            }

            if ($lowerField === $needle || str_contains($lowerField, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Readable sample for a regex-constrained field, so a request can be sent
     * as-is instead of failing validation on e.g. a phone number.
     */
    private function sampleFromRegex(string $pattern, string $field): string
    {
        $sample = $this->walkRegex($this->regexBody($pattern));

        return $sample === '' ? $this->humanString($field) : $sample;
    }

    /**
     * Drop the delimiter and its modifiers ("/^09\d{9}$/i" -> "^09\d{9}$").
     */
    private function regexBody(string $pattern): string
    {
        if (preg_match('/^(?<open>[^\w\s])(?<body>.*)(?<close>\k<open>)[a-z]*$/i', $pattern, $matches) === 1) {
            $pattern = $matches['body'];
        }

        // "(?:", "(?P<name>" and friends behave like a plain group.
        return preg_replace('/\(\?(?:P?<[^>]*>|[:=#!]|<[=!?])/', '(', $pattern) ?? $pattern;
    }

    private function walkRegex(string $pattern, int $depth = 0): string
    {
        if ($pattern === '' || $depth > 6) {
            return '';
        }

        $units  = [];
        $length = strlen($pattern);
        $index  = 0;

        while ($index < $length) {
            $char = $pattern[$index];

            if ($char === '^' || $char === '$') {
                $index++;
                continue;
            }

            // Only the first alternative of "a|b" can appear in one sample.
            if ($char === '|') {
                break;
            }

            if ($char === '\\') {
                $unit  = $this->escapedUnit($pattern[$index + 1] ?? '');
                $index += 2;
            } elseif ($char === '[') {
                $closing = $this->findClassEnd($pattern, $index);
                $unit    = $this->classUnit(substr($pattern, $index + 1, $closing - $index - 1));
                $index   = $closing + 1;
            } elseif ($char === '(') {
                $closing = $this->findGroupEnd($pattern, $index);
                $unit    = $this->walkRegex(substr($pattern, $index + 1, $closing - $index - 1), $depth + 1);
                $index   = $closing + 1;
            } else {
                $unit  = $char === '.' ? 'a' : $char;
                $index++;
            }

            $quantifier = $this->quantifierAt($pattern, $index);

            if ($quantifier === null) {
                $units[] = $unit;
                continue;
            }

            $index += $quantifier[0];

            // A "?" right after a quantifier is the lazy marker, not a new atom.
            if (($pattern[$index] ?? '') === '?') {
                $index++;
            }

            $units[] = $quantifier[1] === 0 ? '' : str_repeat($unit, min($quantifier[1], 24));
        }

        return substr(implode('', $units), 0, 64);
    }

    /**
     * "\d" -> "1", "\w" -> "a", anything else escaped is taken literally.
     */
    private function escapedUnit(string $escape): string
    {
        return match ($escape) {
            'd' => '1',
            'D' => 'a',
            'w' => 'a',
            'W' => '-',
            's' => ' ',
            'S' => 'a',
            'n', 'r', 't' => ' ',
            default => $escape,
        };
    }

    /**
     * The first value a bracket expression accepts ("[a-z3-9]" -> "a").
     */
    private function classUnit(string $class): string
    {
        $class = ltrim($class, '^');

        foreach (['a-z' => 'a', 'A-Z' => 'A', '0-9' => '1'] as $range => $sample) {
            if (str_contains($class, $range)) {
                return $sample;
            }
        }

        foreach (mb_str_split($class) as $char) {
            if ($char !== '\\' && $char !== ']') {
                return $char;
            }
        }

        return 'a';
    }

    /**
     * Position of the "]" that closes the class opened at $open.
     */
    private function findClassEnd(string $pattern, int $open): int
    {
        for ($i = $open + 1, $length = strlen($pattern); $i < $length; $i++) {
            if ($pattern[$i] === '\\') {
                $i++;
                continue;
            }

            if ($pattern[$i] === ']') {
                return $i;
            }
        }

        return strlen($pattern);
    }

    /**
     * Position of the ")" that closes the group opened at $open.
     */
    private function findGroupEnd(string $pattern, int $open): int
    {
        $depth = 0;

        for ($i = $open, $length = strlen($pattern); $i < $length; $i++) {
            if ($pattern[$i] === '\\') {
                $i++;
                continue;
            }

            if ($pattern[$i] === '(') {
                $depth++;
            } elseif ($pattern[$i] === ')' && --$depth === 0) {
                return $i;
            }
        }

        return strlen($pattern);
    }

    /**
     * [skip characters, repetitions] for a quantifier at $index, if any.
     *
     * @return array{0: int, 1: int}|null
     */
    private function quantifierAt(string $pattern, int $index): ?array
    {
        $char = $pattern[$index] ?? '';

        return match (true) {
            $char === '?', $char === '+', $char === '*' => [1, 1],
            preg_match('/^\{(\d+)(?:,\d*)?\}/', substr($pattern, $index), $matches) === 1
                => [strlen($matches[0]), min(24, (int) $matches[1])],
            default => null,
        };
    }
}
