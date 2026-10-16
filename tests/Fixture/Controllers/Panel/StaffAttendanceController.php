<?php

declare(strict_types=1);

namespace SwanFlutter\LaravelApiSnapshot\Tests\Fixture\Controllers\Panel;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class StaffAttendanceController extends Controller
{
    /**
     * Attendance sheet for one staff member.
     */
    public function show(Request $request, string $staff): array
    {
        return ['staff' => $staff];
    }
}
