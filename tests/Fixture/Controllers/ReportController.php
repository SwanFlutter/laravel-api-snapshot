<?php

declare(strict_types=1);

namespace SwanFlutter\LaravelApiSnapshot\Tests\Fixture\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    /**
     * ساخت گزارش برای بازه‌ی زمانی
     *
     * Build a report for a date range.
     */
    public function generate(Request $request): array
    {
        return $request->validate([
            'from'          => 'required|date',
            'to'            => ['required', 'date'],
            'kind'          => ['required', Rule::in(['sales', 'users'])],
            'filters.period' => ['nullable', 'integer', 'min:1', 'max:12'],
            'filters.tag'   => ['nullable', 'string', 'max:40'],
            'limit'         => 'integer|min:1|max:500',
            'include_total' => 'boolean',
        ]);
    }

    /**
     * روزهای باز یک بازه.
     *
     * A date field whose rules live in a shared helper the scanner has to follow.
     */
    public function days(Request $request): array
    {
        return $request->validate([
            'day'      => ['required', ...$this->gregorianDateRules()],
            'position' => ['required', 'string'],
            'period'   => ['nullable', 'string'],
        ]);
    }

    /** @return list<string> */
    protected function gregorianDateRules(): array
    {
        return [
            'date_format:Y-m-d',
            'regex:/^(19|20)\d{2}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/',
        ];
    }
}
