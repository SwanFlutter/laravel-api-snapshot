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
}
