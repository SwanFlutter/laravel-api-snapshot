<?php

declare(strict_types=1);

namespace SwanFlutter\LaravelApiSnapshot\Tests\Fixture\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePatientRequest extends FormRequest
{
    public function rules(): array
    {
        $id = (int) $this->route('id');

        return [
            'name'  => 'required|string|min:3|max:120',
            'score' => 'required|integer|min:0|max:' . max(10, $id * 2),
            'notify'=> 'boolean',
        ];
    }
}
