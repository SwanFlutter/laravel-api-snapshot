<?php

return [

    'name' => env('APP_NAME', 'Laravel') . ' API Documentation',

    'base_url' => env('APP_URL', 'http://localhost'),

    'output_file' => 'postman_collection.json',

    // Routes are selected by middleware name first; include_paths is matched
    // against the URI and accepts a prefix ("api/v1") or a wildcard
    // ("api/*/reports"). When both are empty every route is scanned.
    'include_middleware' => ['api'],

    'include_paths' => [],

    // Anything listed here never reaches the collection.
    'exclude_middleware' => [],

    'exclude_patterns' => [
        'telescope',
        'horizon',
        '_debugbar',
        'sanctum',
        'livewire',
    ],

    'exclude_paths' => [],

    // Scan every route regardless of the include filters above.
    'scan_all_routes' => false,

    'auth' => [
        'type' => 'noauth', // noauth | bearer | basic
        'token' => null,
        // When type is "noauth", routes protected by auth/role/permission
        // middleware still get a bearer block bound to {{authToken}}.
        'detect_middleware' => true,
    ],

    'group_by' => 'prefix', // prefix | controller

    // How many URI (or namespace) levels become Postman folders.
    'group_depth' => 2,

    // Leading segments that carry no meaning for a folder name.
    'strip_prefixes' => ['api'],

    'generate_fake_values' => true,

    'persian_descriptions' => true,

    'default_headers' => [
        ['key' => 'Content-Type', 'value' => 'application/json'],
        ['key' => 'Accept',       'value' => 'application/json'],
    ],

    'cache' => [
        'enabled' => env('API_SNAPSHOT_CACHE_ENABLED', true),
        'ttl' => env('API_SNAPSHOT_CACHE_TTL', 3600), // seconds
        'prefix' => 'api_snapshot_',
    ],

    // Integration tests written by "php artisan api-snapshot:tests".
    'tests' => [
        // auto | pest | phpunit. "auto" looks for pestphp/pest in composer.json.
        'driver' => 'auto',

        'output' => 'tests/Feature/ApiSnapshot',

        // Only used by the PHPUnit driver.
        'namespace' => 'Tests\\Feature\\ApiSnapshot',

        'base_class' => 'Tests\\TestCase',

        // How many route-group levels share one generated file.
        'file_group_depth' => 1,

        // "handled" rejects only a 5xx answer, "success" demands 2xx/3xx.
        'expect' => 'handled',

        // Also assert that a protected endpoint answers 401/403 without credentials.
        'auth_denial_tests' => true,

        // Add RefreshDatabase to every generated test.
        'refresh_database' => true,

        // Seeder classes run once per test, inside the transaction it is wrapped in.
        'seeders' => [],

        // A class implementing SwanFlutter\LaravelApiSnapshot\Testing\SnapshotAuthenticator.
        // Without it every protected endpoint is skipped instead of faking a pass.
        'authenticator' => null,

        // Data the scanner cannot invent: real credentials, a signature, a code
        // the app checks with its own algorithm. A key is "METHOD /uri" — or a
        // bare "/uri" for every verb — and "*" stands for a whole segment, so
        // "POST /api/v1/tickets/*/reply" covers each ticket. The listed fields
        // replace only what they name; the rest of the payload stays generated.
        'payload_overrides' => [
            // 'POST /api/v1/auth/login' => [
            //     'email' => 'admin@example.com',
            //     'password' => 'Password@123',
            // ],
        ],

        // Endpoints that read the session inside the controller, so no middleware
        // tells the scanner they need credentials. true authenticates and leaves
        // the audience to the authenticator, a name sets that audience, and a
        // false really does mean anonymous.
        'auth_overrides' => [
            // 'GET /api/v1/me/profile' => 'patient',
            // 'GET /api/v1/public/*' => false,
        ],

        // A different row for one operation: the key is the URI the scanner
        // produced and the value the URI the generated test calls.
        'uri_overrides' => [
            // 'DELETE /api/v1/doctor/workplaces/1' => '/api/v1/doctor/workplaces/2',
        ],

        // Per-endpoint expectation for the endpoints whose 2xx answer depends on
        // something no fixture can provide. "handled" keeps the test in the
        // suite but only rejects a server error.
        'expect_overrides' => [
            // 'POST /api/v1/sponsorship/callback' => 'handled',
        ],
    ],

];
