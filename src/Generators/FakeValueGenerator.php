<?php

namespace SwanFlutter\LaravelApiSnapshot\Generators;

class FakeValueGenerator
{
    /** @param string[] $rules */
    public function generate(string $field, array $rules): mixed
    {
        // Strip exists/unique rules before processing to avoid false positive
        // matches on table names (e.g. "exists:doctor_profiles,id" contains
        // the substring "file" from "profiles", triggering the file rule).
        $cleanRules = array_filter($rules, function ($r) {
            if (! is_string($r)) {
                return true;
            }
            $lower = strtolower($r);
            return ! str_starts_with($lower, 'exists:')
                && ! str_starts_with($lower, 'unique:');
        });

        $rulesStr = strtolower(implode('|', $cleanRules));

        $hasTypeRule = $this->has($rulesStr, 'integer') || $this->has($rulesStr, 'numeric')
            || $this->has($rulesStr, 'boolean') || $this->has($rulesStr, 'string')
            || $this->has($rulesStr, 'email')   || $this->has($rulesStr, 'date')
            || $this->has($rulesStr, 'url')     || $this->has($rulesStr, 'uuid')
            || $this->has($rulesStr, 'array')   || (bool) preg_match('/\bin:/', $rulesStr);

        if ($this->has($rulesStr, 'nullable') && ! $hasTypeRule) {
            return null;
        }

        if ($this->has($rulesStr, 'boolean') || $this->has($rulesStr, 'bool')) {
            return false;
        }

        if ($this->has($rulesStr, 'date') || $this->has($rulesStr, 'date_format')
            || $this->has($rulesStr, 'datetime')
            || $this->hint($field, ['_date', 'date_', '_at', '_time', 'datetime'])) {
            return '2026-05-29T08:09:35';
        }

        if ($this->has($rulesStr, 'email') || $this->hint($field, ['email', 'mail'])) {
            return 'example@example.com';
        }

        if ($this->has($rulesStr, 'url') || $this->hint($field, ['url', 'link', 'website'])) {
            return 'https://example.com';
        }

        if ($this->has($rulesStr, 'uuid')) {
            return '550e8400-e29b-41d4-a716-446655440000';
        }

        if ($this->has($rulesStr, 'ip') || $this->has($rulesStr, 'ipv4') || $this->has($rulesStr, 'ipv6')) {
            return '192.168.1.1';
        }

        if ($this->has($rulesStr, 'file') || $this->has($rulesStr, 'image') || $this->has($rulesStr, 'mimes')) {
            return 'file.jpg';
        }

        if ($this->has($rulesStr, 'json')) {
            return '{}';
        }

        if (preg_match('/\bin:([^|]+)/', $rulesStr, $m)) {
            return trim(explode(',', $m[1])[0]);
        }

        if ($this->has($rulesStr, 'integer') || $this->has($rulesStr, 'numeric')
            || $this->has($rulesStr, 'digits')
            || $this->hint($field, ['_id', 'id_', 'count', 'number', 'amount', 'price', 'qty', 'quantity', 'age', 'page', 'limit', 'offset', 'per_page'])) {
            return $this->numericSample($rulesStr);
        }

        if ($this->has($rulesStr, 'array')) {
            return [];
        }

        if ($this->hint($field, ['password', 'passwd', 'secret']))  return 'Password@123';
        if ($this->hint($field, ['phone', 'mobile', 'tel', 'fax'])) return '+989123456789';
        if ($this->hint($field, ['name', 'title', 'label']))        return 'Sample ' . ucfirst($field);
        if ($this->hint($field, ['description', 'body', 'content', 'message', 'text', 'note', 'comment', 'bio'])) {
            return 'This is a sample ' . $field . '.';
        }

        if ($this->has($rulesStr, 'string') || $this->has($rulesStr, 'alpha') || $this->has($rulesStr, 'alpha_num')) {
            $min = $this->numericParam($rulesStr, 'min');
            return ($min !== null && $min > 1) ? str_repeat('a', min($min, 10)) : 'n';
        }

        if ($this->has($rulesStr, 'required')) {
            return $field . '_value';
        }

        return null;
    }

    private function has(string $rulesStr, string $keyword): bool
    {
        return str_contains($rulesStr, $keyword);
    }

    /** @param string[] $hints */
    private function hint(string $field, array $hints): bool
    {
        $lower = strtolower($field);
        foreach ($hints as $h) {
            if (str_contains($lower, $h)) {
                return true;
            }
        }
        return false;
    }

    private function numericParam(string $rulesStr, string $rule): ?int
    {
        if (preg_match('/\b' . preg_quote($rule, '/') . ':(\d+)/', $rulesStr, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    private function numericSample(string $rulesStr): int
    {
        $min    = $this->numericParam($rulesStr, 'min');
        $max    = $this->numericParam($rulesStr, 'max');
        $digits = $this->numericParam($rulesStr, 'digits');

        if ($min !== null && $max !== null) return (int) round(($min + $max) / 2);
        if ($min !== null) return $min;
        if ($digits !== null && $digits > 0) return (int) (10 ** ($digits - 1));
        return 16;
    }
}