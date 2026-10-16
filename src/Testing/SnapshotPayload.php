<?php

namespace SwanFlutter\LaravelApiSnapshot\Testing;

use SwanFlutter\LaravelApiSnapshot\Generators\FakeValueGenerator;
use SwanFlutter\LaravelApiSnapshot\Support\ParamTree;

/**
 * Builds a request payload from the documented validation rules, with upload
 * fields turned into {@see SnapshotRequests::FILE_MARKER} strings so the test
 * runner can swap them for fake uploaded files.
 */
final class SnapshotPayload
{
    private const FILE_RULES = ['file', 'image', 'mimes', 'mimetypes', 'dimensions'];

    /** @return array<string, mixed> */
    public static function fromTree(ParamTree $tree, FakeValueGenerator $generator): array
    {
        if ($tree->isEmpty()) {
            return [];
        }

        $value = self::node($tree, $generator, $tree->key());

        return is_array($value) && ! array_is_list($value) ? $value : [];
    }

    /**
     * @param  list<string>  $rules
     */
    public static function isFileField(array $rules): bool
    {
        foreach ($rules as $rule) {
            if (in_array(strtolower(strtok($rule, ':') ?: $rule), self::FILE_RULES, true)) {
                return true;
            }
        }

        return false;
    }

    private static function node(ParamTree $node, FakeValueGenerator $generator, string $key): mixed
    {
        if (self::isFileField($node->rules())) {
            return $node->isList()
                ? [SnapshotRequests::FILE_MARKER . self::filename($key)]
                : SnapshotRequests::FILE_MARKER . self::filename($key);
        }

        $children = $node->children();

        if ($children === []) {
            $value = $generator->generate($node->key(), $node->rules());

            return $node->isList() ? [$value] : $value;
        }

        $object = [];

        foreach ($children as $name => $child) {
            $value = self::node($child, $generator, (string) $name);

            $object[$name] = $value;

            if ($child->hasRule('confirmed')) {
                $object[$name . '_confirmation'] = $value;
            }
        }

        return $node->isList() ? [$object] : $object;
    }

    private static function filename(string $key): string
    {
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '-', trim($key, '.[]')) ?: 'upload';

        return self::isImageField($key) ? $slug . '.jpg' : $slug . '.txt';
    }

    private static function isImageField(string $key): bool
    {
        foreach (['image', 'avatar', 'photo', 'picture', 'logo', 'thumbnail', 'cover', 'gallery', 'file'] as $hint) {
            if (str_contains(strtolower($key), $hint)) {
                return $hint !== 'file';
            }
        }

        return false;
    }
}
