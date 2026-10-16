# Changelog

All notable changes to `swanflutter/laravel-api-snapshot` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.4.0] - 2026-10-16

The route scan that produced the Postman collection can now also be written out as a runnable
integration test suite, so every endpoint is called at least once on each `php artisan test`.

### Added

- **`api-snapshot:tests`** command — generates a Pest or PHPUnit suite from the scanned routes.
  Each generated file holds one `SnapshotEndpoint` case per route (concrete URI, the sample payload
  built from the validation rules, the auth contract found in the middleware) plus the tests that
  call it. Options: `--driver=pest|phpunit|auto`, `--output=`, `--file-depth=`,
  `--expect=handled|success`, `--namespace=`, `--no-auth-tests`, `--no-refresh-db`, `--no-prune`,
  `--dry-run`, `--force`, and the same route-selection options as `api-snapshot:generate`
  (`--include`, `--paths`, `--exclude`, `--all`, `--group-by`, `--depth`, `--no-fakes`).
- **`api-snapshot:install-testing`** command — prepares what a generated suite needs:
  `.env.testing` (`APP_ENV=testing`, the test database, `CACHE_STORE=array`, `SESSION_DRIVER=array`,
  `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`, `APP_KEY` copied from the environment), the matching
  `<env>` entries in `phpunit.xml`, a SQLite test database file, and the `seeders` / `authenticator`
  values inside `config/api-snapshot.php`. `--with-authenticator-stub` scaffolds a starter
  authenticator. Nothing that is already configured is overwritten without `--force`.
- `SwanFlutter\LaravelApiSnapshot\Testing` runtime for the generated files: `SnapshotEndpoint`
  (one route + payload + expectation), `SnapshotPayload`, `SnapshotRequests`
  (`run()`, `runUnauthenticated()`, `send()`, `assertHandled()`, `assertDenied()`, `prepare()`),
  `SnapshotTestGenerator`, `TestEnvironment`, `Code`, the `SnapshotAuthenticator` contract and its
  `NullAuthenticator`.
- `ApiSnapshotGenerator::scanEndpoints()` — the public endpoint list the test generator reads,
  sorted by group, URI and method.
- Config `tests` block: `driver`, `output`, `namespace`, `base_class`, `file_group_depth`, `expect`,
  `auth_denial_tests`, `refresh_database`, `seeders`, `authenticator`.
- Auth-denial coverage: every protected endpoint also gets a test that sends no credentials and
  expects 401/403.
- `surface` on each endpoint, taken from the `role:` / `permission:` / guard argument of the route
  middleware (`role:admin|super_admin` → `admin`), so an authenticator can sign in per audience.
- `README.md`: an "Integration Tests" section covering generation, assertions, signing requests in,
  the test environment and regeneration.
- Tests for the new path: endpoint scanner, request runtime, file renderer, test environment and the
  two commands (91 tests / 433 assertions in total).

### Changed

- Generated files are written idempotently: `_snapshot-manifest.json` records them, and a later run
  prunes only generated files (recognised by their `api-snapshot:tests` header) whose endpoint no
  longer exists. Hand-written tests are never touched.

## [1.3.0] - 2026-10-16

Correctness work driven by running the package against a real Laravel application (≈360 routes,
70+ FormRequest classes, split route files, an old published config file).

### Fixed

- A Persian or Arabic docblock could make the whole collection fail to encode: `preg_split('/\R/')`
  without the `u` modifier treats the raw `0x85` byte inside a multi-byte character as a line break
  and cut the character in half, so `json_encode` rejected every description. Docblocks are now
  split on the byte literals, and any invalid byte read from application source is scrubbed instead
  of aborting the run.
- A bearer token written as a variable reference (`{{access_token}}`) produced requests that
  referenced a variable the collection never defined, so Postman sent an empty `Authorization`
  header. The collection now defines the variable under exactly that name.
- `--paths` / `include_paths` were OR-ed with `include_middleware`, which meant a path filter could
  never narrow anything. Both options now act as filters together.
- With `group_by => 'controller'`, `group_depth` dropped the controller name and left hundreds of
  requests called `index`, `store` and `update` in a handful of folders. The controller name is now
  kept at any depth.
- `isset()` instead of `array_key_exists()` made argument-less rules (`alpha_num`, `image`) invisible,
  so those fields got the wrong sample.
- A regex rule with a `|` inside (`regex:/^(active|pending|blocked)$/`) swallowed the rules after it.
- `Rule::in(['sales', 'users'])` was described as `in:"sales","users"` and sampled `"sales"`.
- A `confirmed` rule did not produce its `_confirmation` field in `formdata` bodies.

### Changed

- Sample values respect what the rules declare: length rules no longer pad a value that was never
  asked to be long, `page`-like names start at `1`, `regex` patterns are sampled from the pattern
  itself (`/^09\d{9}$/` → `09111111111`), and a field name that implies a format (`phone`,
  `password`, `url`) is used when its sample still fits the declared length.
- Nullable fields with no other rule get an empty value instead of a made-up one.
- Request item names are capitalised (`index` → `Index`), as are controller folder names, which no
  longer carry the shared namespace.
- An unreachable cache store (a `database` cache with the database down) now warns and generates
  without caching instead of failing the command.
- `README.md` rewritten around the actual options, config keys and output shape.
- The test suite is real again: 52 PHPUnit tests on a Testbench app exercising `src/` end to end —
  Postman format, route discovery, rule extraction, grouping, encoding and caching — replacing the
  old self-contained script that re-implemented the generator instead of testing it.

## [1.2.0] - 2026-06-05

### Added

- `api-snapshot:clear-cache` command, so cached snapshots can be dropped without generating.

## [1.1.0] - 2026-06-05

### Added

- The generated collection is cached, keyed by a fingerprint of the effective config and route list,
  with `--no-cache` / `--clear-cache` to bypass it.

### Changed

- Classes and the service provider were renamed from `Snapshot*` to `ApiSnapshot*`, so the package
  no longer collides with other snapshot libraries. Published names, namespace
  `SwanFlutter\LaravelApiSnapshot\` and the public API have stayed backwards compatible since.

## [1.0.0] - 2026-06-05

Initial release: `php artisan api-snapshot:generate` scans the application routes and writes a
Postman Collection v2.1 file with folders per route group, validation-derived sample payloads,
Persian or English endpoint descriptions, and configurable auth and headers.

[1.4.0]: https://github.com/SwanFlutter/laravel-api-snapshot/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/SwanFlutter/laravel-api-snapshot/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/SwanFlutter/laravel-api-snapshot/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/SwanFlutter/laravel-api-snapshot/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/SwanFlutter/laravel-api-snapshot/commit/50004d9
