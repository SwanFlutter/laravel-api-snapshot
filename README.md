# Laravel API Snapshot

[![PHP Version](https://img.shields.io/badge/php-%5E8.1-8892BF.svg?style=flat-square)](https://php.net/)
[![Laravel](https://img.shields.io/badge/laravel-10%20%7C%2011%20%7C%2012-FF2D20.svg?style=flat-square)](https://laravel.com)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg?style=flat-square)](https://opensource.org/licenses/MIT)
[![Packagist](https://img.shields.io/packagist/v/swanflutter/laravel-api-snapshot.svg?style=flat-square)](https://packagist.org/packages/swanflutter/laravel-api-snapshot)

**Laravel API Snapshot** scans all your registered API routes and generates a **Postman Collection v2.1 JSON** file automatically — similar to Scribe but focused purely on Postman export.

---

## Features

- Zero-config — works out of the box
- Scans all routes filtered by middleware group (default: `api`)
- Groups endpoints by URI prefix: `api/v1/admin/users` → **Admin › User**
- Extracts `FormRequest` validation rules via PHP Reflection (no HTTP lifecycle needed)
- Generates realistic sample/fake values for every parameter type
- Produces path variables, query params, and JSON body in correct Postman format
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

  --output=      Output filename          (default: postman_collection.json)
  --name=        Collection name          (default: APP_NAME from .env)
  --base-url=    Base URL for requests    (default: APP_URL from .env)
  --exclude=     Comma-separated URI patterns to exclude
  --group-by=    Grouping: prefix | controller
  --no-persian   Use English descriptions instead of Farsi
  --force        Overwrite without prompting
```

### Examples

```bash
# Basic
php artisan api-snapshot:generate

# Custom output and name
php artisan api-snapshot:generate --output=storage/api.json --name="My API"

# Production base URL
php artisan api-snapshot:generate --base-url=https://api.example.com

# Group by controller class
php artisan api-snapshot:generate --group-by=controller

# Exclude specific routes
php artisan api-snapshot:generate --exclude=admin,webhook

# English descriptions
php artisan api-snapshot:generate --no-persian
```

---

## Configuration

After publishing, edit `config/api-snapshot.php`:

```php
return [
    'name'                => env('APP_NAME') . ' API Documentation',
    'base_url'            => env('APP_URL', 'http://localhost'),
    'output_file'         => 'postman_collection.json',
    'include_middleware'  => ['api'],           // Only scan routes with this middleware
    'exclude_patterns'    => ['telescope', 'horizon', '_debugbar', 'sanctum', 'livewire'],
    'auth'                => ['type' => 'noauth', 'token' => null], // noauth | bearer | basic
    'group_by'            => 'prefix',          // prefix | controller
    'generate_fake_values'=> true,
    'persian_descriptions'=> true,
    'default_headers'     => [
        ['key' => 'Content-Type', 'value' => 'application/json'],
        ['key' => 'Accept',       'value' => 'application/json'],
    ],
];
```

---

## How It Works

### Route Grouping (prefix mode)

```
api/v1/admin/users/{id}/status
       │     │
       │     └── sub-group → "User"
       └── top group   → "Admin"
```

### FormRequest Extraction

For each controller method, the package reflects on its type-hinted parameters to find a `FormRequest`. The `rules()` method is called on an instance created without the HTTP lifecycle, so no real request is needed.

### Parameter Location

| HTTP Method     | Rules location  |
|-----------------|-----------------|
| GET, DELETE     | Query string    |
| POST, PUT, PATCH| JSON body       |
| `{param}` in URI| Path variable   |

### Fake Value Generation

| Rule             | Sample value                             |
|------------------|------------------------------------------|
| `email`          | `example@example.com`                   |
| `integer/numeric`| `16` (respects `min`/`max`)             |
| `date/datetime`  | `2026-05-29T08:09:35`                   |
| `boolean`        | `false`                                  |
| `in:a,b,c`       | `a` (first value)                        |
| `url`            | `https://example.com`                   |
| `uuid`           | `550e8400-e29b-41d4-a716-446655440000`  |
| `nullable` only  | `null`                                   |

---

## Output Format

The generated JSON is a valid **Postman Collection v2.1**:

```json
{
  "variable": [
    {"id": "baseUrl", "key": "baseUrl", "type": "string", "name": "string", "value": "http://localhost"}
  ],
  "info": {
    "name": "My API Documentation",
    "_postman_id": "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx",
    "description": "",
    "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json"
  },
  "item": [
    {
      "name": "Admin",
      "description": "",
      "item": [
        {
          "name": "User",
          "description": "",
          "item": [
            {
              "name": "store User",
              "request": {
                "url": {
                  "host": "{{baseUrl}}",
                  "path": "api/v1/admin/users",
                  "query": [],
                  "raw": "{{baseUrl}}/api/v1/admin/users"
                },
                "method": "POST",
                "header": [
                  {"key": "Content-Type", "value": "application/json"},
                  {"key": "Accept",       "value": "application/json"}
                ],
                "body": {
                  "mode": "raw",
                  "raw": {"email": "example@example.com", "password": "Password@123"}
                },
                "description": "این endpoint عملیات «store» را انجام می‌دهد.\n...",
                "auth": {"type": "noauth"}
              },
              "response": []
            }
          ]
        }
      ]
    }
  ]
}
```

---

## Requirements

- PHP ^8.1
- Laravel 10, 11, or 12

---

## License

MIT — see [LICENSE](LICENSE) for details.
