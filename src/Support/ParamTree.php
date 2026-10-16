<?php

namespace SwanFlutter\LaravelApiSnapshot\Support;

use SwanFlutter\LaravelApiSnapshot\Generators\FakeValueGenerator;

/**
 * Expands dotted validation keys ("user.email", "items.*.name") into a tree
 * so the generated request body matches the payload Laravel actually expects.
 */
class ParamTree
{
    /** @var array<string, self> */
    private array $children = [];

    /** @var list<string> */
    private array $rules = [];

    private bool $isList = false;

    public function __construct(private readonly string $key = '') {}

    /**
     * @param  array<string, list<string>>  $rulesByPath
     */
    public static function fromRules(array $rulesByPath): self
    {
        $root = new self();

        foreach ($rulesByPath as $path => $rules) {
            $root->insert((string) $path, $rules);
        }

        return $root;
    }

    /**
     * @param  list<string>  $rules
     */
    public function insert(string $path, array $rules): void
    {
        $node     = $this;
        $segments = explode('.', $path);
        $leaf     = null;

        foreach ($segments as $segment) {
            if ($segment === '*') {
                $node->isList = true;
                continue;
            }

            if (! isset($node->children[$segment])) {
                $node->children[$segment] = new self($segment);
            }

            $node = $node->children[$segment];
            $leaf = $segment;
        }

        if ($leaf !== null) {
            $node->rules = $rules;
        } else {
            // Path was only wildcards ("*"); rules belong to the list itself.
            $this->rules = $rules;
        }
    }

    public function key(): string
    {
        return $this->key;
    }

    /** @return array<string, self> */
    public function children(): array
    {
        return $this->children;
    }

    /** @return list<string> */
    public function rules(): array
    {
        return $this->rules;
    }

    public function isList(): bool
    {
        return $this->isList;
    }

    public function isEmpty(): bool
    {
        return $this->children === [] && $this->rules === [];
    }

    public function hasRule(string $name): bool
    {
        foreach ($this->rules as $rule) {
            if (strtolower(strtok($rule, ':') ?: $rule) === strtolower($name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when any node in the subtree declares a file upload rule, which
     * forces the request body into multipart/form-data.
     */
    public function containsFile(): bool
    {
        foreach (['file', 'image', 'mimes', 'mimetypes', 'dimensions'] as $name) {
            if ($this->hasRule($name)) {
                return true;
            }
        }

        foreach ($this->children as $child) {
            if ($child->containsFile()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  (callable(string, list<string>): mixed)|null  $leafOverride
     * @return array<string, mixed>|list<mixed>|mixed
     */
    public function sample(FakeValueGenerator $generator, ?callable $leafOverride = null): mixed
    {
        $element = $this->sampleElement($generator, $leafOverride);

        return $this->isList ? [$element] : $element;
    }

    /**
     * @param  (callable(string, list<string>): mixed)|null  $leafOverride
     * @return array<string, mixed>|mixed
     */
    private function sampleElement(FakeValueGenerator $generator, ?callable $leafOverride): mixed
    {
        if ($this->children === []) {
            return $leafOverride !== null
                ? $leafOverride($this->key, $this->rules)
                : $generator->generate($this->key, $this->rules);
        }

        $object = [];

        foreach ($this->children as $name => $child) {
            $value = $child->sample($generator, $leafOverride);

            $object[$name] = $value;

            if ($child->hasRule('confirmed')) {
                $object[$name . '_confirmation'] = $value;
            }
        }

        if ($object === []) {
            return $leafOverride !== null
                ? $leafOverride($this->key, $this->rules)
                : $generator->generate($this->key, $this->rules);
        }

        return $object;
    }

    /**
     * Flatten the tree into PHP-style query string keys ("user[name]", "items[][name]").
     *
     * @param  (callable(string, list<string>): mixed)|null  $leafOverride
     * @return list<array<string, mixed>>
     */
    public function queryParameters(FakeValueGenerator $generator, ?callable $leafOverride = null): array
    {
        $params = [];
        $this->collectQuery($generator, $leafOverride, '', $params);

        return $params;
    }

    /**
     * @param  (callable(string, list<string>): mixed)|null  $leafOverride
     * @param  list<array<string, mixed>>  $params
     */
    private function collectQuery(FakeValueGenerator $generator, ?callable $leafOverride, string $prefix, array &$params): void
    {
        if ($this->children === []) {
            if ($prefix === '') {
                return;
            }

            $value = $leafOverride !== null
                ? $leafOverride($this->key, $this->rules)
                : $generator->generate($this->key, $this->rules);

            $params[] = [
                'key'         => $prefix,
                'value'       => self::stringify($value),
                'description' => RuleParser::describe($this->rules),
                'disabled'    => false,
            ];

            return;
        }

        $suffix = $this->isList ? '[]' : '';

        foreach ($this->children as $name => $child) {
            $child->collectQuery($generator, $leafOverride, $prefix === '' ? $name : $prefix . $suffix . '[' . $name . ']', $params);
        }
    }

    /**
     * Fields for a multipart/form-data body: upload rules become file inputs,
     * everything else a text field keyed by its bracket path.
     *
     * @param  (callable(string, list<string>): mixed)|null  $leafOverride
     * @return list<array{key: string, type: string, value: string, description: string}>
     */
    public function formFields(FakeValueGenerator $generator, ?callable $leafOverride = null): array
    {
        $fields = [];
        $this->collectForm($generator, $leafOverride, '', $fields);

        return $fields;
    }

    /**
     * @param  (callable(string, list<string>): mixed)|null  $leafOverride
     * @param  list<array{key: string, type: string, value: string, description: string}>  $fields
     */
    private function collectForm(FakeValueGenerator $generator, ?callable $leafOverride, string $prefix, array &$fields): void
    {
        if ($this->children === []) {
            if ($prefix === '') {
                return;
            }

            $isFile = $this->hasRule('file') || $this->hasRule('image')
                || $this->hasRule('mimes') || $this->hasRule('mimetypes');

            $value = $leafOverride !== null
                ? $leafOverride($this->key, $this->rules)
                : $generator->generate($this->key, $this->rules);

            $fields[] = [
                'key'         => $prefix,
                'type'        => $isFile ? 'file' : 'text',
                'value'       => $isFile ? '' : self::stringify($value),
                'description' => $isFile && !$this->hasRule('required')
                    ? RuleParser::describe($this->rules) . ' (upload a file in Postman)'
                    : RuleParser::describe($this->rules),
            ];

            if ($this->hasRule('confirmed')) {
                $fields[] = [
                    'key'         => $prefix . '_confirmation',
                    'type'        => 'text',
                    'value'       => self::stringify($value),
                    'description' => 'Must match ' . $prefix,
                ];
            }

            return;
        }

        $suffix = $this->isList ? '[]' : '';

        foreach ($this->children as $name => $child) {
            $child->collectForm($generator, $leafOverride, $prefix === '' ? $name : $prefix . $suffix . '[' . $name . ']', $fields);
        }
    }

    private static function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return '';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        return (string) $value;
    }
}
