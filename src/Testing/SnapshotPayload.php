<?php

namespace SwanFlutter\LaravelApiSnapshot\Testing;

use SwanFlutter\LaravelApiSnapshot\Generators\FakeValueGenerator;
use SwanFlutter\LaravelApiSnapshot\Support\ParamTree;
use SwanFlutter\LaravelApiSnapshot\Support\RuleParser;

/**
 * Builds a request payload from the documented validation rules, with upload
 * fields turned into {@see SnapshotRequests::FILE_MARKER} strings so the test
 * runner can swap them for fake uploaded files.
 */
final class SnapshotPayload
{
    private const FILE_RULES = ['file', 'image', 'mimes', 'mimetypes', 'dimensions'];

    /** Rules that compare a date with a clock anchor or another field. */
    private const COMPARISON_RULES = ['after', 'after_or_equal', 'before', 'before_or_equal'];

    /** Words a comparison rule may use for "this moment" instead of a fixed date. */
    private const CLOCK_ANCHORS = ['now', 'today', 'tomorrow', 'yesterday'];

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

    /**
     * The table and column an `exists` rule points at, as
     * "snapshot-ref://table/column". No sample value can satisfy an `exists`
     * rule, so the test runner looks the value up in the seeded database
     * instead of inventing one.
     *
     * @param  list<string>  $rules
     */
    public static function reference(array $rules): ?string
    {
        foreach ($rules as $rule) {
            if (! is_string($rule) || ! str_starts_with(strtolower($rule), 'exists:')) {
                continue;
            }

            $parts = explode(',', substr($rule, strlen('exists:')));
            $table = trim($parts[0] ?? '');
            $column = trim($parts[1] ?? 'id');

            if ($table !== '' && preg_match('/^[A-Za-z0-9_]+$/', $table) === 1 && preg_match('/^[A-Za-z0-9_]+$/', $column) === 1) {
                return SnapshotRequests::REFERENCE_MARKER . $table . '/' . $column;
            }
        }

        return null;
    }

    /**
     * A date pinned to the clock — "new_date after:today" passes tomorrow, never
     * a sample frozen in the test file. "snapshot-dynamic://<modifier>/<format>"
     * is turned into the real date when the request is sent.
     *
     * @param  list<string>  $rules
     */
    public static function dynamicDate(array $rules, string $key = ''): ?string
    {
        $indexed  = RuleParser::index(RuleParser::normalise($rules));
        $modifier = self::clockModifier($indexed);

        return $modifier === null ? null : self::marker($modifier, self::dateFormat($indexed, $key));
    }

    /**
     * Keeps a field the rules compare with a sibling ("date_to after date_from")
     * on the correct side of it, resolved against whatever the sibling ends up
     * holding at request time.
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, ParamTree>  $children
     * @return array<string, mixed>
     */
    private static function orderSiblings(array $object, array $children): array
    {
        foreach ($children as $name => $child) {
            if (! array_key_exists($name, $object)) {
                continue;
            }

            $indexed = RuleParser::index(RuleParser::normalise($child->rules()));

            foreach (self::COMPARISON_RULES as $rule) {
                $sibling = trim((string) ($indexed[$rule] ?? ''));

                if ($sibling === '' || $sibling === $name || ! array_key_exists($sibling, $object)) {
                    continue;
                }

                $object[$name] = self::marker(
                    self::shift($rule),
                    self::dateFormat($indexed, (string) $name),
                    $sibling,
                );

                break;
            }
        }

        return $object;
    }

    /**
     * @param  array<string, string|null>  $indexed
     */
    private static function clockModifier(array $indexed): ?string
    {
        foreach (self::COMPARISON_RULES as $rule) {
            $anchor = strtolower(trim((string) ($indexed[$rule] ?? '')));

            if (! self::isClockAnchor($anchor)) {
                continue;
            }

            return $anchor . ' ' . self::shift($rule);
        }

        return null;
    }

    private static function isClockAnchor(string $anchor): bool
    {
        return in_array($anchor, self::CLOCK_ANCHORS, true)
            // "+2 days" and "-1 week" name a moment relative to now too.
            || ($anchor !== '' && (str_starts_with($anchor, '+') || str_starts_with($anchor, '-')));
    }

    /**
     * One day clear of the anchor, in whichever direction the rule demands. A
     * non-strict rule takes it too: "or equal" is satisfied by a later date, and
     * guessing the sibling's exact string shape is not.
     */
    private static function shift(string $rule): string
    {
        return str_starts_with($rule, 'before') ? '-1 day' : '+1 day';
    }

    /**
     * @param  array<string, string|null>  $indexed
     */
    private static function dateFormat(array $indexed, string $key = ''): string
    {
        $declared = trim((string) ($indexed['date_format'] ?? ''));

        if ($declared !== '') {
            return trim(explode('|', $declared)[0]);
        }

        $lower = strtolower($key);

        return str_ends_with($lower, '_at') || str_contains($lower, 'time') ? 'Y-m-d H:i:s' : 'Y-m-d';
    }

    private static function marker(string $modifier, string $format, ?string $sibling = null): string
    {
        $segments = [rawurlencode($modifier), rawurlencode($format)];

        if ($sibling !== null) {
            $segments[] = rawurlencode($sibling);
        }

        // Each part is url-encoded, so the plain "/" between them never splits a
        // format such as "Y/m/d" by mistake.
        return SnapshotRequests::DYNAMIC_MARKER . implode('/', $segments);
    }

    private static function node(ParamTree $node, FakeValueGenerator $generator, string $key): mixed
    {
        if (self::isFileField($node->rules())) {
            $file = self::filename($key, $node->rules());

            return $node->isList() ? [SnapshotRequests::FILE_MARKER . $file] : SnapshotRequests::FILE_MARKER . $file;
        }

        if (($reference = self::reference($node->rules())) !== null) {
            return $node->isList() ? [$reference] : $reference;
        }

        if (($dynamic = self::dynamicDate($node->rules(), $key)) !== null) {
            return $node->isList() ? [$dynamic] : $dynamic;
        }

        $children = $node->children();

        if ($children === []) {
            $value = $generator->generate($node->key(), $node->rules());

            return $node->isList() ? [$value] : $value;
        }

        $object = [];

        foreach ($children as $name => $child) {
            $object[$name] = self::node($child, $generator, (string) $name);
        }

        $object = self::orderSiblings($object, $children);

        foreach ($children as $name => $child) {
            if ($child->hasRule('confirmed')) {
                $object[$name . '_confirmation'] = $object[$name] ?? null;
            }
        }

        return $node->isList() ? [$object] : $object;
    }

    private static function filename(string $key, array $rules): string
    {
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '-', trim($key, '.[]')) ?: 'upload';

        return $slug . '.' . self::extension($key, $rules);
    }

    /**
     * The extension the rules ask for. The validator guesses an upload's type
     * from its name, so a field declared `mimes:pdf,jpg` only accepts a sample
     * that ends in one of those — a `.txt` fails whatever the field is called.
     */
    private static function extension(string $key, array $rules): string
    {
        $indexed = RuleParser::index(RuleParser::normalise($rules));

        foreach (['mimes', 'file_extensions'] as $rule) {
            $declared = strtolower(trim(explode(',', explode('|', trim((string) ($indexed[$rule] ?? '')))[0])[0]));

            if ($declared !== '' && preg_match('/^[a-z0-9]{1,5}$/', $declared) === 1) {
                return $declared === 'jpeg' ? 'jpg' : $declared;
            }
        }

        return isset($indexed['image']) || self::isImageField($key) ? 'jpg' : 'txt';
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
