<?php

declare(strict_types=1);

namespace SwanFlutter\LaravelApiSnapshot\Tests\Fixture\Controllers;

use Illuminate\Routing\Controller;

class HealthController extends Controller
{
    public function __invoke(): array
    {
        return ['status' => 'ok'];
    }
}
