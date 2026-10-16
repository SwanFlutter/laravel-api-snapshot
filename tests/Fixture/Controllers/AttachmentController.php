<?php

declare(strict_types=1);

namespace SwanFlutter\LaravelApiSnapshot\Tests\Fixture\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

class AttachmentController extends Controller
{
    /**
     * Upload a document with an optional note.
     */
    public function store(Request $request): array
    {
        return Validator::make($request->all(), [
            'document' => 'required|file|mimes:pdf|max:4096',
            'note'     => 'nullable|string',
            'tags'     => 'nullable|array',
            'reviewed' => ['boolean', function ($attribute, $value, $fail) {
                // A dynamic rule the snapshot must skip rather than choke on.
            }],
        ])->validate();
    }
}
