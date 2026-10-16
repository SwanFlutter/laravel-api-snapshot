# Laravel API Snapshot

[![PHP Version](https://img.shields.io/badge/php-%5E8.1-8892BF.svg?style=flat-square)](https://php.net/)
[![Laravel](https://img.shields.io/badge/laravel-10%20%7C%2011%20%7C%2012-FF2D20.svg?style=flat-square)](https://laravel.com)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg?style=flat-square)](https://opensource.org/licenses/MIT)
[![Packagist](https://img.shields.io/packagist/v/swanflutter/laravel-api-snapshot.svg?style=flat-square)](https://packagist.org/packages/swanflutter/laravel-api-snapshot)

**Laravel API Snapshot** scans all your registered API routes and generates a **Postman Collection v2.1 JSON** file automatically — similar to Scribe but focused purely on Postman export.

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
];
```

The cache key is a fingerprint of the effective config plus the route list, so changing
`--base-url`, `--paths` or `--group-by` never serves a stale collection. If the app's cache
store is unreachable (a database cache with the database down, for example), the command
warns and generates without it rather than failing.

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
end to end: Postman format, route discovery, rule extraction, grouping and caching.

---

## Requirements

- PHP ^8.1
- Laravel 10, 11, or 12

---

## License

MIT — see [LICENSE](LICENSE) for details.
