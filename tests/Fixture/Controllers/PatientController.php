<?php

declare(strict_types=1);

namespace SwanFlutter\LaravelApiSnapshot\Tests\Fixture\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use SwanFlutter\LaravelApiSnapshot\Tests\Fixture\Requests\StorePatientRequest;
use SwanFlutter\LaravelApiSnapshot\Tests\Fixture\Requests\UpdatePatientRequest;

class PatientController extends Controller
{
    /**
     * Paginated patient list.
     */
    public function index(Request $request): array
    {
        return [
            'page'     => $request->integer('page'),
            'per_page' => $request->input('per_page'),
            'search'   => $request->query('search'),
            'active'   => $request->boolean('active'),
        ];
    }

    public function show(Request $request, string $id): array
    {
        return ['id' => $id];
    }

    public function store(StorePatientRequest $request): array
    {
        return $request->validated();
    }

    public function update(UpdatePatientRequest $request, string $id): array
    {
        return $request->validated();
    }
}
