<?php

declare(strict_types=1);

namespace SwanFlutter\LaravelApiSnapshot\Tests;

use PHPUnit\Framework\Attributes\Test;
use SwanFlutter\LaravelApiSnapshot\Generators\FakeValueGenerator;
use SwanFlutter\LaravelApiSnapshot\Support\RuleParser;

final class ValidationRulesTest extends TestCase
{
    #[Test]
    public function it_reads_rules_from_a_form_request_and_builds_a_nested_body(): void
    {
        $fields = $this->formFields('POST', '/api/v1/patients');

        // The fixture declares an image upload, so the body must be multipart.
        $this->assertArrayHasKey('email', $fields);
        $this->assertArrayHasKey('meta[weight]', $fields, 'Dotted rules must nest');
        $this->assertArrayHasKey('visits[][doctor_id]', $fields, 'Wildcard rules must nest into a list');
        $this->assertSame('file', $fields['avatar']['type']);
        $this->assertSame([], $fields['avatar']['src']);
        $this->assertSame('example@example.com', $fields['email']['value']);
    }

    #[Test]
    public function it_expands_a_confirmed_rule_into_its_sibling_field(): void
    {
        $fields = $this->formFields('POST', '/api/v1/patients');

        $this->assertSame($fields['password']['value'], $fields['password_confirmation']['value']);
    }

    #[Test]
    public function it_uses_the_first_allowed_value_of_an_in_rule(): void
    {
        $fields = $this->formFields('POST', '/api/v1/patients');

        $this->assertSame('admin', $fields['role']['value']);
    }

    #[Test]
    public function it_resolves_route_parameters_inside_form_request_rules(): void
    {
        $item    = $this->findRequest($this->collection(), 'PUT', '/api/v1/patients/:id');
        $decoded = json_decode($item['request']['body']['raw'], true);

        // UpdatePatientRequest builds "max:" from $this->route('id'), which is 1 here.
        $this->assertSame(5, $decoded['score']);
        $this->assertIsString($decoded['name']);
    }

    #[Test]
    public function it_reads_inline_validation_arrays_from_the_controller_source(): void
    {
        $item    = $this->findRequest($this->collection(), 'POST', '/api/v1/reports/generate');
        $decoded = json_decode($item['request']['body']['raw'], true);

        $this->assertSame('sales', $decoded['kind'], 'Rule::in([...]) must be read from the controller source');
        $this->assertSame(['period' => 6, 'tag' => 'Tag'], $decoded['filters']);
        $this->assertSame(250, $decoded['limit']);
        $this->assertSame(false, $decoded['include_total']);
        $this->assertArrayNotHasKey('include_total_confirmation', $decoded);
    }

    #[Test]
    public function it_reads_rules_from_validator_make_calls(): void
    {
        $fields = $this->formFields('POST', '/api/v1/attachments');

        $this->assertSame('file', $fields['document']['type']);
        $this->assertStringContainsString('pdf', $fields['document']['description']);
        $this->assertArrayHasKey('note', $fields);
    }

    #[Test]
    public function it_uses_query_parameters_for_read_endpoints(): void
    {
        $item  = $this->findRequest($this->collection(), 'GET', '/api/v1/patients');
        $query = collect($item['request']['url']['query'])->keyBy('key');

        foreach (['page', 'per_page', 'search', 'active'] as $key) {
            $this->assertTrue($query->has($key), "Expected query parameter {$key}");
        }

        $this->assertStringContainsString('/api/v1/patients?', $item['request']['url']['raw']);
    }

    #[Test]
    public function it_does_not_send_a_body_for_get_requests(): void
    {
        $item = $this->findRequest($this->collection(), 'GET', '/api/v1/health');

        $this->assertArrayNotHasKey('body', $item['request']);
    }

    #[Test]
    public function it_keeps_pipes_inside_regex_rules_intact(): void
    {
        $rules = RuleParser::split('sometimes|regex:/^(active|pending|blocked)$/|nullable');

        $this->assertSame(['sometimes', 'regex:/^(active|pending|blocked)$/', 'nullable'], $rules);

        $alternate = RuleParser::split('required|regex:#a|b#|max:10');

        $this->assertSame(['required', 'regex:#a|b#', 'max:10'], $alternate);
    }

    #[Test]
    public function it_stringifies_rule_objects_instead_of_printing_class_names(): void
    {
        $rules = RuleParser::normalise(['required', \Illuminate\Validation\Rule::in(['sales', 'users'])]);

        $this->assertContains('in:sales,users', $rules);
        $this->assertStringNotContainsString('Illuminate', implode(',', $rules));
    }

    #[Test]
    public function it_generates_values_that_match_the_declared_type(): void
    {
        $generator = new FakeValueGenerator();

        $this->assertSame(16, $generator->generate('doctor_id', ['required', 'integer']));
        $this->assertSame(4, $generator->generate('age', ['integer', 'min:1', 'max:7']));
        $this->assertSame('2026-05-29T08:09:35', $generator->generate('created_at', []));
        $this->assertSame('2026-05-29', $generator->generate('until', ['date', 'after:now']));
        $this->assertSame('08:09', $generator->generate('start', ['date_format:H:i']));
        $this->assertSame(false, $generator->generate('notify', ['boolean']));
        $this->assertSame('abcd12', $generator->generate('referral_code', ['alpha_num', 'size:6']));
        $this->assertNull($generator->generate('note', ['nullable']));
    }

    /**
     * "digits:10" counts digits instead of fixing a magnitude, and an integer
     * that long is past a 32-bit build, so the sample stays a string of digits.
     */
    #[Test]
    public function it_counts_digits_of_a_digits_rule(): void
    {
        $generator = new FakeValueGenerator();

        $this->assertSame(111111, $generator->generate('code', ['required', 'digits:6']));
        $this->assertSame('1111111111', $generator->generate('national_code', ['required', 'string', 'digits:10']));
        $this->assertSame(11111111, $generator->generate('card', ['digits_between:6,10']));
    }

    /**
     * A strength pattern is built from assertions, and an assertion consumes
     * nothing, so walking it cannot produce a passing value — the field's own
     * format can.
     */
    #[Test]
    public function it_uses_the_field_format_for_a_regex_built_from_assertions(): void
    {
        $generator = new FakeValueGenerator();

        $strong = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/';

        $this->assertSame(
            'Password@123',
            $generator->generate('password', ['required', 'string', 'min:8', 'regex:' . $strong]),
        );
        $this->assertSame(
            'aaaaaaaa',
            $generator->generate('code', ['regex:/^(?=.*[a-z])[a-z]{8}$/']),
        );
    }

    /**
     * "string|max:15" fixes a length, not a format, so a field called phone
     * still deserves a phone number — as long as the sample fits the rule.
     */
    #[Test]
    public function it_prefers_the_field_format_over_the_field_name_when_the_sample_fits(): void
    {
        $generator = new FakeValueGenerator();

        $this->assertSame('+989123456789', $generator->generate('phone', ['required', 'string', 'max:15']));
        $this->assertSame('Password@123', $generator->generate('password', ['required', 'string', 'min:8']));
        $this->assertSame('https://example.com', $generator->generate('avatar_url', ['nullable', 'string', 'max:255']));
        $this->assertSame('Phone', $generator->generate('phone', ['required', 'string', 'max:5']));
        $this->assertSame('active', $generator->generate('phone', ['string', 'in:active,blocked']));
        $this->assertSame('09111111111', $generator->generate('phone', ['string', 'regex:/^09\d{9}$/']));
    }

    /** @return array<string, array<string, mixed>> */
    private function formFields(string $method, string $path): array
    {
        $item = $this->findRequest($this->collection(), $method, $path);

        $this->assertSame('formdata', $item['request']['body']['mode']);

        return collect($item['request']['body']['formdata'])->keyBy('key')->all();
    }
}
