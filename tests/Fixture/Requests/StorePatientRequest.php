<?php

declare(strict_types=1);

namespace SwanFlutter\LaravelApiSnapshot\Tests\Fixture\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePatientRequest extends FormRequest
{
    /**
     * Create a patient with a nested visit schedule.
     */
    public function rules(): array
    {
        return [
            'phone'               => 'required|string|regex:/^09\d{9}$/',
            'email'               => 'required|email|max:255',
            'password'            => 'required|min:8|confirmed',
            'role'                => ['required', Rule::in(['admin', 'patient'])],
            'status'              => ['sometimes', 'regex:/^(active|pending|blocked)$/'],
            'birth_date'          => 'nullable|date',
            'is_active'           => 'boolean',
            'meta.weight'         => ['nullable', 'numeric', 'min:0', 'max:200'],
            'visits.*.doctor_id'  => ['required', 'integer', 'exists:users,id'],
            'visits.*.hour'       => ['required', 'integer', 'min:8', 'max:20'],
            'avatar'              => 'nullable|image|mimes:jpg,png|max:2048',
            'referral_code'       => ['nullable', 'alpha_num', 'size:6'],
            'specialty_id'        => ['nullable', 'integer'],
        ];
    }
}
