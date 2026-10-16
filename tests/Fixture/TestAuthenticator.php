<?php

declare(strict_types=1);

namespace SwanFlutter\LaravelApiSnapshot\Tests\Fixture;

use Illuminate\Foundation\Auth\User;
use SwanFlutter\LaravelApiSnapshot\Testing\SnapshotAuthenticator;
use SwanFlutter\LaravelApiSnapshot\Testing\SnapshotEndpoint;

/**
 * Signs the test in the way a host application would: in place, no headers.
 */
class TestAuthenticator implements SnapshotAuthenticator
{
    public function authenticate(object $test, SnapshotEndpoint $endpoint): ?array
    {
        if ($endpoint->surface !== 'report') {
            return null;
        }

        $test->be(new class extends User {
            public function getAuthIdentifier(): int
            {
                return 1;
            }
        });

        return [];
    }
}
