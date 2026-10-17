# Laravel API Snapshot

[![PHP Version](https://img.shields.io/badge/php-%5E8.1-8892BF.svg?style=flat-square)](https://php.net/)
[![Laravel](https://img.shields.io/badge/laravel-10%20%7C%2011%20%7C%2012-FF2D20.svg?style=flat-square)](https://laravel.com)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg?style=flat-square)](https://opensource.org/licenses/MIT)
[![Packagist](https://img.shields.io/packagist/v/swanflutter/laravel-api-snapshot.svg?style=flat-square)](https://packagist.org/packages/swanflutter/laravel-api-snapshot)

**Laravel API Snapshot** scans all your registered API routes and generates a **Postman Collection v2.1 JSON** file automatically — similar to Scribe but focused purely on Postman export.
The same scan can also be written out as a runnable **integration test suite**, so every route is called at least once on each `php artisan test`.

---

## Features

- Zero-config — works out of the box on any Laravel 10/11/12 app
- Selects routes by middleware group (default: `api`), by URI prefix/wildcard, or scans everything
- Groups endpoints into nested Postman folders: `api/v1/admin/users` → **Admin › User**
- Extracts validation rules from `FormRequest` classes, inline `$request->validate([...])`,
  `$this->validate(...)` and `Validator::make(...)` — including `Rule::in()` objects
- Understands nested (`meta.weight`) and wildcard (`visits.*.doctor_id`) rule keys and `confirmed`
- Generates realistic sample values for every rule type, so requests can be sent as soon as they are imported
- Correct Collection v2.1 output: JSON **or** `formdata` bodies (file uploads included), path variables,
  typed headers, and bearer auth on routes protected by `auth`/`role`/`permission` middleware
- Caches the collection and clears it with a dedicated command
- Persian/Farsi auto-descriptions (configurable)
- Artisan command with flexible CLI options
- Turns the same scan into an executable **integration test suite** (Pest or PHPUnit) that calls
  every route and asserts no endpoint answers with a server error
- Sets up the test environment a generated suite needs: `.env.testing`, `phpunit.xml`, a SQLite
  file, seeders and an authenticator stub

---

## Installation

```bash
composer require swanflutter/laravel-api-snapshot
```

The service provider is auto-discovered via Laravel's package discovery.

Optionally publish the config:

```bash
php artisan vendor:publish --tag=api-snapshot-config
```

---

## Quick Start

```bash
php artisan api-snapshot:generate
```

Writes `postman_collection.json` to your project root. Import it directly into Postman.

To turn the same routes into tests:

```bash
php artisan api-snapshot:install-testing   # prepare .env.testing, phpunit.xml, seeders
php artisan api-snapshot:tests             # write the test files
./vendor/bin/pest                          # run them
```

---

## Command Options

```
php artisan api-snapshot:generate [options]

  --output=       Output filename          (default: postman_collection.json)
  --name=         Collection name          (default: APP_NAME from .env)
  --base-url=     Base URL for requests    (default: APP_URL from .env)
  --include=      Comma-separated middleware names that select routes
  --paths=        Comma-separated URI prefixes or wildcards that select routes
  --exclude=      Comma-separated URI patterns to exclude
  --all           Scan every route, ignoring the include filters
  --group-by=     Grouping: prefix | controller
  --depth=        Number of folder levels in the collection
  --no-persian    Use English descriptions instead of Farsi
  --no-fakes      Leave parameter and body values empty
  --force         Overwrite without prompting
  --no-cache      Ignore the cached collection and regenerate
  --clear-cache   Drop cached snapshots before generating

php artisan api-snapshot:clear-cache   # remove every cached snapshot

php artisan api-snapshot:tests [options]        # generate the integration suite
  --driver=         pest | phpunit | auto       (auto detects Pest from composer.json)
  --output=         Directory for the test files (default: tests/Feature/ApiSnapshot)
  --file-depth=     Route-group levels that make one file (default: 1 → one file per folder)
  --expect=         handled (no 5xx) | success (2xx/3xx)
  --namespace=      Namespace of the generated PHPUnit classes
  --include= --paths= --exclude= --all --group-by= --depth= --no-fakes
                    Same route-selection options as api-snapshot:generate
  --no-auth-tests   Leave out the "rejects a request without credentials" cases
  --no-refresh-db   Do not add RefreshDatabase to the generated files
  --no-prune        Keep files whose endpoint no longer exists
  --dry-run         Report the plan without writing anything
  --force           Overwrite existing files without prompting

php artisan api-snapshot:install-testing [options]   # prepare the test environment
  --connection=     Test database connection  (default: sqlite)
  --database=       Test database name        (default: :memory:)
  --db-file=        Use this SQLite file instead of :memory: and create it when missing
  --seeders=        Comma-separated seeder classes to run before each test
  --authenticator=  Class that signs a test in for a protected endpoint
  --with-authenticator-stub   Write a starter authenticator into tests/Support
  --publish         Publish config/api-snapshot.php so the settings above can be stored
  --no-env          Do not write .env.testing
  --no-phpunit      Do not touch phpunit.xml
  --dry-run         Report the changes without writing anything
  --force           Replace values that are already set
```

### Examples

```bash
# Basic
php artisan api-snapshot:generate

# Custom output and name
php artisan api-snapshot:generate --output=storage/api.json --name="My API"

# Production base URL
php artisan api-snapshot:generate --base-url=https://api.example.com

# Only the public client API, one folder level
php artisan api-snapshot:generate --paths=api/v1/patient --depth=1

# Group by controller class (folders follow the namespace below App\Http\Controllers)
php artisan api-snapshot:generate --group-by=controller

# Everything, including web routes, minus internal tooling
php artisan api-snapshot:generate --all --exclude=admin,webhook

# Empty placeholders instead of generated sample values
php artisan api-snapshot:generate --no-fakes

# English descriptions
php artisan api-snapshot:generate --no-persian

# Integration tests for the patient API only, planned but not written
php artisan api-snapshot:tests --paths=api/v1/patient --dry-run

# One file per two group levels, and every endpoint must answer 2xx/3xx
php artisan api-snapshot:tests --file-depth=2 --expect=success

# PHPUnit classes instead of Pest files
php artisan api-snapshot:tests --driver=phpunit --namespace="Tests\\Feature\\Api"

# Prepare the test database, then generate against it
php artisan api-snapshot:install-testing --db-file=database/test.sqlite --seeders="RolePermissionSeeder" --authenticator="Tests\\Support\\SnapshotAuthenticator"
```

---

## Configuration

After publishing, edit `config/api-snapshot.php`:

```php
return [
    'name'                 => env('APP_NAME') . ' API Documentation',
    'base_url'             => env('APP_URL', 'http://localhost'),
    'output_file'          => 'postman_collection.json',

    // Route selection. Both options act as filters, so a path chosen on top of
    // a middleware narrows it down: "api" + "api/v1/admin" scans only the admin
    // routes. Wildcards match one segment ("api/*/reports"). Empty on both
    // sides means "everything", and a filter that matches nothing falls back to
    // scanning every route with a warning.
    'include_middleware'   => ['api'],
    'include_paths'        => [],
    'exclude_middleware'   => [],
    'exclude_paths'        => [],
    'exclude_patterns'     => ['telescope', 'horizon', '_debugbar', 'sanctum', 'livewire'],
    'scan_all_routes'      => false,

    // noauth | bearer | basic. With "noauth", routes behind auth/role/
    // permission/can middleware still get a bearer block on {{authToken}}.
    // A token such as "{{access_token}}" names the collection variable that
    // Postman fills in, so the variable is created under that exact name.
    'auth'                 => ['type' => 'noauth', 'token' => null, 'detect_middleware' => true],

    'group_by'             => 'prefix',   // prefix | controller
    'group_depth'          => 2,          // folder levels
    'strip_prefixes'       => ['api'],    // segments that never become folders

    'generate_fake_values' => true,
    'persian_descriptions' => true,

    'default_headers'      => [
        ['key' => 'Content-Type', 'value' => 'application/json'],
        ['key' => 'Accept',       'value' => 'application/json'],
    ],

    'cache' => [
        'enabled' => env('API_SNAPSHOT_CACHE_ENABLED', true),
        'ttl'     => env('API_SNAPSHOT_CACHE_TTL', 3600),
        'prefix'  => 'api_snapshot_',
    ],

    // Options used by api-snapshot:tests (see the Integration Tests section).
    'tests' => [
        'driver'            => 'auto',    // auto | pest | phpunit
        'output'            => 'tests/Feature/ApiSnapshot',
        'namespace'         => 'Tests\\Feature\\ApiSnapshot',
        'base_class'        => 'Tests\\TestCase',
        'file_group_depth'  => 1,
        'expect'            => 'handled', // handled | success
        'auth_denial_tests' => true,
        'refresh_database'  => true,
        'seeders'           => [],
        'authenticator'     => null,

        // What each endpoint is actually sent, called and expected to answer.
        // Keys are "METHOD /uri" or a bare "/uri" covering every verb, with "*"
        // standing for a path segment.
        'payload_overrides' => [],        // fields, or a Closure resolved at send time
        'auth_overrides'    => [],        // true | false | audience name
        'uri_overrides'     => [],        // call this URI instead
        'expect_overrides'  => [],        // handled | success for one endpoint
    ],
];
```

The cache key is a fingerprint of the effective config plus the route list, so changing
`--base-url`, `--paths` or `--group-by` never serves a stale collection. If the app's cache
store is unreachable (a database cache with the database down, for example), the command
warns and generates without it rather than failing.

---

## Integration Tests

`api-snapshot:tests` runs the same scanner as `api-snapshot:generate` and writes the endpoints it
finds out as ordinary test files. Each file holds one `SnapshotEndpoint` case per route — the
concrete URI, the sample payload built from the validation rules, the auth contract discovered
from the middleware — plus a test that calls it:

```php
use SwanFlutter\LaravelApiSnapshot\Testing\SnapshotEndpoint;
use SwanFlutter\LaravelApiSnapshot\Testing\SnapshotRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

$getApiV1AdminUsersStatus = new SnapshotEndpoint(
    method: 'GET',
    uri: '/api/v1/admin/users/1/status',
    query: [],
    body: [],
    multipart: false,
    authRequired: true,
    surface: 'admin',
    group: 'Admin › User',
    action: 'App\\Http\\Controllers\\Admin\\UserController@status',
    middleware: ['api', 'auth:sanctum', 'role:admin'],
    expectation: SnapshotEndpoint::EXPECT_HANDLED,
);

it('Admin › User › GET /api/v1/admin/users/1/status responds without a server error', function () use ($getApiV1AdminUsersStatus) {
    SnapshotRequests::run($this, $getApiV1AdminUsersStatus);
});

it('Admin › User › GET /api/v1/admin/users/1/status rejects a request without credentials', function () use ($getApiV1AdminUsersStatus) {
    SnapshotRequests::runUnauthenticated($this, $getApiV1AdminUsersStatus);
});
```

The files stay readable and diffable: nothing runs through eval, the generated code calls the
documented public API of the package, and every case keeps the payload the scanner produced.

### What the tests assert

- `expect => handled` (the default) fails only on a 5xx answer, so a made-up ID, an `exists` rule
  or a 404 from missing fixtures reports a real server bug rather than a fake failure.
- `expect => success` additionally demands 2xx/3xx and needs real data — use it once the
  authenticator and seeders make the endpoints answer properly.
- Protected endpoints always get a second test that sends the request with no credentials and
  expects 401/403 (`auth_denial_tests => false` drops it).
- A protected endpoint whose surface the authenticator cannot sign in is **skipped with a
  message**, never silently passed.

### Payload markers

Sample values alone cannot pass every rule, so the payload builder writes a marker instead and the
runtime resolves it when the request is sent:

| Marker | Written for | Resolved to |
| --- | --- | --- |
| `snapshot-file://avatar.jpg` | `file`, `image`, `mimes` rules | a fake uploaded file (multipart when any field carries one) |
| `snapshot-ref://users/id` | the table and column of an `exists:` rule | the lowest real value of that column in the test database |
| `snapshot-dynamic://today%20%2B1%20day/Y-m-d` | a rule compared with the clock (`after:today`) | that date, one day clear of the anchor, formatted as the rules ask |
| `snapshot-dynamic://%2B1%20day/Y-m-d/date_from` | a rule compared with a sibling (`date_to after date_from`) | the sibling's own sent value, shifted a day |

An empty table answers `1`, so in success mode a `422` names the field whose fixture is missing
instead of passing quietly.

### Telling the scanner what it cannot see

Four lists in `config/api-snapshot.php` describe the endpoints the rules alone do not cover. Keys
are `METHOD /uri` or a bare `/uri`, and `*` matches anything in the path:

```php
'tests' => [
    'payload_overrides' => [
        // Data no generator can invent: real credentials, a checksum, a code.
        'POST /api/v1/auth/login' => ['phone' => '09120000004', 'password' => 'Secret#2026'],

        // A value that only exists in the moment the request is sent: a one-time
        // code, an HMAC over a fresh body, tomorrow's weekday in a Jalali pair.
        'POST /api/v1/sponsorship/callback' => fn (SnapshotEndpoint $e) => [
            'signature' => hash_hmac('sha256', json_encode(['order' => 7]), 'shared-secret'),
        ],

        // A field that must be *absent* — a version key that turns an update into
        // a 409, a `days.*` the scanner read off `array: days` and invented `days.0`
        // for. FORGET removes the path instead of overwriting it.
        'PUT /api/v1/admin/site-settings' => ['version' => SnapshotOverrides::FORGET],
        'PUT /api/v1/doctor/schedule'     => ['days' => ['0' => SnapshotOverrides::FORGET]],
    ],

    // Auth the middleware does not declare because the controller reads the
    // session itself; false sends the request anonymously.
    'auth_overrides' => [
        'GET /api/v1/me/*'      => 'patient',
        'POST /api/v1/auth/logout' => true,
    ],

    // One operation often needs a different row than a read: a delete wants a
    // record nothing references.
    'uri_overrides' => [
        'DELETE /api/v1/doctor/workplaces/1' => '/api/v1/doctor/workplaces/2',
    ],

    // The suite default is right everywhere except the route that cannot succeed
    // without a live third party.
    'expect_overrides' => [
        'POST /api/v1/payments/webhook' => 'handled',
    ],
],
```

`payload_overrides` replaces fields and keeps the rest generated; the other three change only what
they name. Run `api-snapshot:tests` again after editing them — the declarations are baked into the
generated files, so a diff shows exactly which endpoint learned what.

### Signing requests in

The package does not know how your app issues credentials, so the generated tests ask a class of
yours:

```php
namespace Tests\Support;

use SwanFlutter\LaravelApiSnapshot\Testing\SnapshotAuthenticator as SnapshotAuthenticatorContract;
use SwanFlutter\LaravelApiSnapshot\Testing\SnapshotEndpoint;

class SnapshotAuthenticator implements SnapshotAuthenticatorContract
{
    public function authenticate(object $test, SnapshotEndpoint $endpoint): ?array
    {
        return match ($endpoint->surface) {
            'admin'  => $this->admin($test) ? [] : null,
            'doctor' => $this->doctor($test) ? [] : null,
            default  => null,   // null → the test is skipped, not faked
        };
    }
}
```

Return `[]` after signing the test in yourself (`$test->be($user)`, `Sanctum::actingAs($user)`),
or a header array such as `['Authorization' => 'Bearer ' . $token]` — the runtime merges it with
`Accept: application/json`. `surface` is the first name taken from the `role:`/`permission:`/guard argument of the route
middleware (`role:admin|super_admin` → `admin`), so it is what your app already calls the audience. Point
`config('api-snapshot.tests.authenticator')` at the class, or start from a scaffold:

```bash
php artisan api-snapshot:install-testing --authenticator="Tests\\Support\\SnapshotAuthenticator" --with-authenticator-stub
```

### Test environment

`api-snapshot:install-testing` writes what the generated suite needs and never overwrites a value
you already set unless you pass `--force`:

- `.env.testing` with `APP_ENV=testing`, `DB_CONNECTION`, `DB_DATABASE`, `CACHE_STORE=array`,
  `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`, and the `APP_KEY` copied
  from your environment. Because Laravel loads *only* `.env.testing` once it exists, a missing one
  starts as a copy of `.env`, so the other values the app reads never disappear. That copy carries
  your real secrets, so the command also adds `.env.testing` to `.gitignore` when the entry is
  missing.
- The matching `<env>` entries in `phpunit.xml`.
- `database/test.sqlite` (or `--db-file=`) when you choose a file instead of `:memory:`.
- `tests.seeders` and `tests.authenticator` inside `config/api-snapshot.php` (`--publish` writes
  the file first if it is not published).

`seeders` are the classes run before the first request of each test, inside the transaction
`RefreshDatabase` opens — put `RolePermissionSeeder` or any lookup-data seeder there so roles and
permissions exist when a `role:admin` route is called.

### Regenerating

Generation is idempotent: the command writes `_snapshot-manifest.json` next to the files and, on
the next run, deletes only generated files it recognises (their header contains
`api-snapshot:tests`) whose endpoints no longer exist. Hand-written tests are never touched, and
`--no-prune` keeps the stale files too. `--dry-run` prints the plan — file, endpoint count, test
count — without writing anything.

---

## How It Works

### Route Grouping (prefix mode)

```
api/v1/admin/users/{id}/status
       │     │
       │     └── level 2 folder → "User"
       └── level 1 folder   → "Admin"
```

`group_depth` adds levels (`Admin › User › Status`), and controller mode uses the namespace
below the folder every controller shares: `App\Http\Controllers\Admin\UserController` →
**Admin › User**. Controller mode never drops the controller name when `group_depth` is
smaller than the namespace — 360 requests called `index`, `store` and `update` are only
findable when each folder carries the controller it belongs to.

### Where validation rules come from

| Source                                    | How it is read                                        |
|-------------------------------------------|-------------------------------------------------------|
| `FormRequest::rules()`                    | Reflected and executed against a synthetic request, so `$this->route('id')` works |
| `$request->validate([...])`               | Parsed from the controller source, nothing is executed |
| `$this->validate([...])`, `Validator::make([...])` | Same source parsing                          |
| `$request->input('x')`, `->integer('y')`  | Untyped fields a GET endpoint reads, added as query parameters |
| Closure rules                             | Skipped — a dynamic rule never breaks the snapshot     |

Rule objects (`Rule::in([...])`, `Rule::unique(...)`) are stringified, dotted keys become
nested objects, `visits.*.field` becomes a one-element list, upload rules (`file`, `image`,
`mimes`) switch the body to `formdata`, and `confirmed` adds its `_confirmation` sibling.

### Parameter Location

| HTTP Method     | Rules location  |
|-----------------|-----------------|
| GET, DELETE     | Query string    |
| POST, PUT, PATCH| JSON body (or `formdata` when a file is declared) |
| `{param}` in URI| Path variable   |

### Fake Value Generation

| Rule             | Sample value                             |
|------------------|------------------------------------------|
| `email`          | `example@example.com`                   |
| `integer`        | `16`, or the middle of `min:1|max:7` → `4` |
| `page`/`limit`   | `1`                                      |
| `numeric`        | `1500.5`, `decimal:2` respected          |
| `date`           | `2026-05-29T08:09:35`, `date_format:H:i` → `08:09` |
| `boolean`        | `false`                                  |
| `in:a,b,c`       | `a` (first value)                        |
| `url`, `uuid`, `ulid`, `ip` | `https://example.com`, a real UUID, ULID, `192.168.1.1` |
| `image`, `mimes:jpg,png` | `sample.jpg` as a `file` form field |
| `regex:/^09\d{9}$/` | `09111111111` (a value that passes the pattern) |
| `alpha_num` + `size:6` | `abcd12`                            |
| `nullable` only  | empty                                    |

---

## Output Format

The generated JSON is a valid **Postman Collection v2.1**:

```json
{
  "variable": [
    { "id": "baseUrl", "key": "baseUrl", "type": "string", "value": "http://localhost" },
    { "id": "authToken", "key": "authToken", "type": "string", "value": "" }
  ],
  "info": {
    "name": "My API Documentation",
    "_postman_id": "bf62ce8d-afe8-484f-981b-3746c62d6833",
    "description": "Generated by swanflutter/laravel-api-snapshot on 2026-10-16 06:16 (8 endpoints).",
    "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json"
  },
  "item": [
    {
      "name": "Patient",
      "description": "",
      "item": [
        {
          "name": "update",
          "request": {
            "url": {
              "host": "{{baseUrl}}",
              "path": ["api", "v1", "patients", ":id"],
              "query": [],
              "raw": "{{baseUrl}}/api/v1/patients/:id",
              "variable": [
                { "id": "id", "key": "id", "value": "1", "description": "The unique identifier" }
              ]
            },
            "method": "PUT",
            "header": [
              { "key": "Content-Type", "value": "application/json", "type": "text" },
              { "key": "Accept", "value": "application/json", "type": "text" }
            ],
            "description": "این endpoint عملیات «update» را با متد PUT انجام می‌دهد.\n...",
            "auth": { "type": "noauth" },
            "body": {
              "mode": "raw",
              "raw": "{\n    \"name\": \"Name\",\n    \"score\": 5,\n    \"notify\": false\n}",
              "options": { "raw": { "language": "json" } }
            }
          },
          "response": []
        }
      ]
    }
  ]
}
```

Uploads use Postman's `formdata` mode instead, with `{"type": "file", "src": []}` entries for
every field that declares `file`, `image`, `mimes` or `mimetypes`.

---

## Development

The package ships no development requirements of its own (the published `composer.json`
constraints stay untouched). A harness in `devtools/` — only its `composer.json` is committed,
never the vendor install — provides them:

```bash
composer install --working-dir=devtools
devtools/vendor/bin/phpunit
```

`devtools/` is gitignored; `tests/` contains a real Testbench application (routes, FormRequests,
inline validation, uploads, closures and an invokable controller) that exercises the generator
end to end: Postman format, route discovery, rule extraction, grouping and caching. The test mode
is covered the same way — the scanner's endpoint list, every generated file checked with
`php -l`, the install command against a scratch project, and the generated cases actually sent
through `SnapshotRequests` against the Testbench app.

---

## Requirements

- PHP ^8.1
- Laravel 10, 11, or 12

---

## License

MIT — see [LICENSE](LICENSE) for details.
