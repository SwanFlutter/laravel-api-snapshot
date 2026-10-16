<?php

namespace SwanFlutter\LaravelApiSnapshot\Extractors;

use ReflectionMethod;
use SwanFlutter\LaravelApiSnapshot\Support\RuleParser;
use Throwable;

/**
 * Reads validation rules that a controller declares inline instead of in a
 * FormRequest — $request->validate([...]), $this->validate(...) and
 * Validator::make(...) — by scanning the method source. Nothing is executed.
 */
class InlineRuleExtractor
{
    private const VALIDATION_PATTERN = '/(?:(?:\$request|\$this->request|\$this)\s*->\s*(?:validate|validateWith)|Validator\s*::\s*(?:make|validate))\s*(?=\()/';


    /** @var array<string, string> request getter method => implied rule */
    private const GETTERS = [
        'file'    => 'file',
        'boolean' => 'boolean',
        'integer' => 'integer',
        'float'   => 'numeric',
        'number'  => 'numeric',
        'string'  => 'string',
        'query'   => 'string',
        'input'   => 'string',
        'get'     => 'string',
    ];

    /** @return array<string, list<string>> */
    public function extract(string $className, string $methodName): array
    {
        $body = $this->methodBody($className, $methodName);

        if ($body === null) {
            return [];
        }

        if (! preg_match_all(self::VALIDATION_PATTERN, $body, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        foreach ($matches[0] as $match) {
            $arguments = $this->arguments(substr($body, $match[1] + strlen($match[0])));

            foreach ($arguments as $argument) {
                $rules = $this->rulesFromArray($argument);

                if ($rules !== []) {
                    return $rules;
                }
            }
        }

        return [];
    }

    /**
     * Fields read directly from the request, used as query parameters for GET
     * endpoints that declare no validation rules at all.
     *
     * @return array<string, list<string>>
     */
    public function inferredInputs(string $className, string $methodName): array
    {
        $body = $this->methodBody($className, $methodName);

        if ($body === null) {
            return [];
        }

        $pattern = '/\$this->request|\$request\s*->\s*(' . implode('|', array_keys(self::GETTERS)) . ')\s*\(\s*[\'"]([A-Za-z0-9_.\-\[\]]+)[\'"]/';
        $rules   = [];

        preg_match_all($pattern, $body, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $rules[$match[2]] ??= ['nullable', self::GETTERS[$match[1]]];
        }

        return $rules;
    }

    private function methodBody(string $className, string $methodName): ?string
    {
        try {
            $method = new ReflectionMethod($className, $methodName);
            $file   = $method->getFileName();

            if ($file === false || $method->isInternal()) {
                return null;
            }

            $lines = file($file);

            if ($lines === false) {
                return null;
            }

            $body = implode('', array_slice(
                $lines,
                $method->getStartLine() - 1,
                max(1, $method->getEndLine() - $method->getStartLine() + 1)
            ));

            return $this->stripComments($body);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    private function arguments(string $afterOpenParen): array
    {
        $list = $this->balanced($afterOpenParen, '(');

        return $list === null ? [] : $this->split($list, ',');
    }

    /** @return array<string, list<string>> */
    private function rulesFromArray(string $expression): array
    {
        $expression = trim($expression);

        if (! str_starts_with($expression, '[') && ! preg_match('/^array\s*\(/i', $expression)) {
            return [];
        }

        $inner = $this->balanced($expression, $expression[0] === '[' ? '[' : '(');
        $inner ??= preg_replace('/^array\s*\((.*)\)$/is', '$1', $expression) ?? '';

        $rules = [];

        foreach ($this->split($inner, ',') as $entry) {
            [$key, $value] = $this->splitOnce($entry, '=>');

            if ($key === null || $value === null) {
                continue;
            }

            $field = $this->scalar($key);

            if ($field === null || $field === '') {
                continue;
            }

            $parsed = $this->rules($value);

            if ($parsed !== []) {
                $rules[$field] = $parsed;
            }
        }

        return $rules;
    }

    /**
     * @return list<string>
     */
    private function rules(string $expression): array
    {
        $expression = trim($expression);

        if (str_starts_with($expression, '[') || preg_match('/^array\s*\(/i', $expression)) {
            $result = [];
            $inner  = $this->balanced($expression, $expression[0] === '[' ? '[' : '(')
                ?? preg_replace('/^array\s*\((.*)\)$/is', '$1', $expression) ?? '';

            foreach ($this->split($inner, ',') as $item) {
                if ($this->topLevelPosition($item, '=>') !== null) {
                    return [];
                }

                foreach ($this->rules($item) as $rule) {
                    $result[] = $rule;
                }
            }

            return $result;
        }

        $scalar = $this->scalar($expression);

        if ($scalar !== null) {
            return RuleParser::split($scalar);
        }

        return $this->describeBuilder($expression);
    }

    /**
     * "Rule::unique('users')->ignore($id)" => "unique:users",
     * "new Min(8)" => "min:8".
     *
     * @return list<string>
     */
    private function describeBuilder(string $expression): array
    {
        $expression = trim($expression);

        $isRuleFacade = preg_match('/^\\\\?Rule\s*::\s*([A-Za-z_][A-Za-z0-9_]*)\s*(?=\(|$)/', $expression, $m) === 1;

        if (! $isRuleFacade && ! preg_match('/^new\s+\\\\?([A-Za-z0-9_\\\\]+)\s*(?=\(|$)/', $expression, $m)) {
            return [];
        }

        $tail = trim(substr($expression, strlen($m[0])));
        $args = $this->split($this->balanced($tail, '(') ?? '', ',');

        $values = [];

        foreach ($args as $arg) {
            $scalar = $this->scalar($arg);

            if ($scalar !== null) {
                $values[] = $scalar;
                continue;
            }

            if (is_numeric(trim($arg))) {
                $values[] = trim($arg);
                continue;
            }

            $nested = $this->arrayScalars($arg);

            if ($nested === []) {
                break;
            }

            foreach ($nested as $item) {
                $values[] = $item;
            }
        }

        $name = strtolower($this->shortName($m[1]));

        if ($name === '') {
            return [];
        }

        return $values === [] ? [$name] : [$name . ':' . implode(',', $values)];
    }

    /**
     * Scalars held in an array argument, e.g. Rule::in(['pending', 'done']).
     *
     * @return list<string>
     */
    private function arrayScalars(string $expression): array
    {
        $expression = trim($expression);

        if (! str_starts_with($expression, '[') && ! preg_match('/^array\s*\(/i', $expression)) {
            return [];
        }

        $inner = $this->balanced($expression, $expression[0] === '[' ? '[' : '(')
            ?? preg_replace('/^array\s*\((.*)\)$/is', '$1', $expression) ?? '';

        $values = [];

        foreach ($this->split($inner, ',') as $item) {
            $scalar = $this->scalar($item);

            if ($scalar !== null) {
                $values[] = $scalar;
            } elseif (is_numeric(trim($item))) {
                $values[] = trim($item);
            }
        }

        return $values;
    }

    private function shortName(string $classOrName): string
    {
        $parts = explode('\\', trim($classOrName, '\\'));

        return (string) end($parts);
    }

    /**
     * A quoted literal, or a class constant that resolves to one.
     */
    private function scalar(string $expression): ?string
    {
        $expression = trim($expression);

        if (preg_match("/^'((?:\\\\.|[^'])*)'$/s", $expression, $m)) {
            return str_replace(["\\'", "\\\\"], ["'", '\\'], $m[1]);
        }

        if (preg_match('/^"((?:\\\\.|[^"])*)"$/s', $expression, $m)) {
            return stripcslashes($m[1]);
        }

        if (is_numeric($expression)) {
            return $expression;
        }

        if (preg_match('/^(\w+)::([A-Z][A-Z0-9_]*)$/', $expression, $m)) {
            $class = $m[1] === 'self' || $m[1] === 'static' || $m[1] === 'parent'
                ? null
                : $m[1];

            try {
                if ($class !== null && class_exists($class) && defined($class . '::' . $m[2])) {
                    $value = constant($class . '::' . $m[2]);

                    return is_scalar($value) ? (string) $value : null;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * The text inside the balanced brackets that open at the start of $text.
     */
    private function balanced(string $text, string $open): ?string
    {
        $close  = match ($open) {
            '['    => ']',
            '('    => ')',
            default => ']',
        };

        $text = ltrim($text);

        if ($text === '' || $text[0] !== $open) {
            return null;
        }

        $depth   = 0;
        $quote   = null;
        $length  = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                continue;
            }

            if ($char === $open) {
                $depth++;
            } elseif ($char === $close) {
                $depth--;

                if ($depth === 0) {
                    return substr($text, 1, $i - 1);
                }
            } elseif (($char === '[' && $open !== '[') || ($char === '(' && $open !== '(')) {
                // Nested other-bracket pairs (e.g. a call inside an array) still
                // need depth tracking so a ")" of an inner call does not close us.
                $nested = $char === '[' ? ']' : ')';
                $skip   = $this->nestedClose($text, $i, $char, $nested);

                if ($skip === null) {
                    continue;
                }

                $i = $skip;
            }
        }

        return null;
    }

    /**
     * Index of the character closing a nested bracket pair that opens at $start.
     */
    private function nestedClose(string $text, int $start, string $open, string $close): ?int
    {
        $depth  = 0;
        $quote  = null;
        $length = strlen($text);

        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                continue;
            }

            if ($char === $open) {
                $depth++;
            } elseif ($char === $close) {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Split on a delimiter occurring at depth 0, outside quotes.
     *
     * @return list<string>
     */
    private function split(string $text, string $needle): array
    {
        $pieces = [];
        $buffer = '';
        $depth  = 0;
        $quote  = null;
        $bracket = ['[' => 1, '(' => 1, '{' => 1, ']' => -1, ')' => -1, '}' => -1];
        $length = strlen($text);
        $size   = strlen($needle);

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];

            if ($quote !== null) {
                $buffer .= $char;

                if ($char === '\\') {
                    if ($i + 1 < $length) {
                        $buffer .= $text[$i + 1];
                        $i++;
                    }

                    continue;
                }

                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote   = $char;
                $buffer .= $char;
                continue;
            }

            if ($depth === 0 && substr_compare($text, $needle, $i, $size) === 0) {
                $pieces[] = $buffer;
                $buffer   = '';
                $i       += $size - 1;
                continue;
            }

            $buffer .= $char;

            if (isset($bracket[$char])) {
                $depth = max(0, $depth + $bracket[$char]);
            }
        }

        if (trim($buffer) !== '') {
            $pieces[] = $buffer;
        }

        return $pieces;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function splitOnce(string $text, string $needle): array
    {
        $position = $this->topLevelPosition($text, $needle);

        if ($position === null) {
            return [trim($text), null];
        }

        return [
            trim(substr($text, 0, $position)),
            trim(substr($text, $position + strlen($needle))),
        ];
    }

    private function topLevelPosition(string $text, string $needle): ?int
    {
        $depth   = 0;
        $quote   = null;
        $bracket = ['[' => 1, '(' => 1, '{' => 1, ']' => -1, ')' => -1, '}' => -1];
        $length  = strlen($text);
        $size    = strlen($needle);

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                continue;
            }

            if ($depth === 0 && substr_compare($text, $needle, $i, $size) === 0) {
                return $i;
            }

            if (isset($bracket[$char])) {
                $depth = max(0, $depth + $bracket[$char]);
            }
        }

        return null;
    }

    /**
     * Comments can contain quotes and brackets, which would confuse the
     * scanners above, so they are removed from the method body first.
     */
    private function stripComments(string $code): string
    {
        try {
            $tokens  = token_get_all('<?php ' . $code);
            $rebuilt = '';

            foreach ($tokens as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $rebuilt .= is_array($token) ? $token[1] : $token;
            }

            return substr($rebuilt, strlen('<?php '));
        } catch (Throwable) {
            return $code;
        }
    }
}
