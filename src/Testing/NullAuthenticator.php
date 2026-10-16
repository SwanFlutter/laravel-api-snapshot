<?php

namespace SwanFlutter\LaravelApiSnapshot\Testing;

/**
 * The default: nothing can sign in, so every protected endpoint is skipped
 * instead of silently passing without credentials.
 */
final class NullAuthenticator implements SnapshotAuthenticator
{
    public function authenticate(object $test, SnapshotEndpoint $endpoint): ?array
    {
        return null;
    }
}
