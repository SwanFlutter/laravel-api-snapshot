<?php

namespace SwanFlutter\LaravelApiSnapshot\Testing;

/**
 * Signs a test in for one snapshot endpoint. Implement this in the host
 * application and point api-snapshot.tests.authenticator at it.
 */
interface SnapshotAuthenticator
{
    /**
     * @return array<string, string>|null  headers to send, [] when the test was
     *                                     authenticated another way (e.g.
     *                                     Sanctum::actingAs()), null when this
     *                                     surface cannot be signed in yet.
     */
    public function authenticate(object $test, SnapshotEndpoint $endpoint): ?array;
}
