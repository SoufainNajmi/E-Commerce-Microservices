# Phase 2: Auth Service

## Scope and architecture

`services/auth-service` is an independent Laravel 12 application requiring PHP 8.4+ and PostgreSQL. Controllers handle HTTP responses, Form Requests validate input, `AuthService` handles credentials and token lifecycle, `UserResource` exposes safe fields, and middleware disables response caching. Roles are an enum: `ADMIN`, `CUSTOMER`, `SELLER`.

The existing Phase 1 files were empty placeholders. Phase 2 adds only Auth, its PostgreSQL instance, and an Nginx ingress. Other service folders and the planned gateway, Redis, RabbitMQ and frontend remain placeholders. Existing Domain/Application/Infrastructure/Interfaces folders remain available, but this phase uses the requested simpler Laravel structure.

## Start with Docker

From the repository root:

```bash
cp .env.example .env
# Fill AUTH_APP_KEY, POSTGRES_ADMIN_PASSWORD and AUTH_DB_PASSWORD in .env.
# Generate an application key:
php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
# Generate each database password independently:
openssl rand -hex 32
# Optional: set ADMIN_EMAIL and ADMIN_PASSWORD for a local development admin.
docker compose up -d --build
docker compose exec auth-service php artisan migrate --force
docker compose exec auth-service php artisan db:seed --force
curl http://localhost:8080/health
```

Keep `.env` private; credentials have no committed defaults. `ADMIN_PASSWORD` must meet registration password requirements. Seeding runs only in `local`/`testing`; it does not promote or overwrite an existing account. Default ingress is localhost port 8080 (`AUTH_PORT` changes it). Only Nginx publishes a port. PHP-FPM and PostgreSQL are reachable inside `microservices-network`. This image includes development dependencies to support the requested test workflow; prepare a separate `--no-dev` release image and HTTPS ingress before a production deployment.

The database bootstrap creates `auth_db` and a non-superuser `auth_app` role with no create-database or create-role privileges. Auth receives only that role's password. Access to PostgreSQL's other connectable default databases is revoked. Initialization runs once for a new volume; changing `.env` does not rotate existing PostgreSQL credentials.

## Run independently without Docker

Provide an existing PostgreSQL `auth_db` and a dedicated owner account, then:

```bash
cd services/auth-service
composer install
cp .env.example .env
# Set DB_HOST, DB_USERNAME and DB_PASSWORD for your PostgreSQL instance.
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan serve --host=127.0.0.1 --port=8000
```

Only a `pgsql` database connection is configured. File cache, synchronous queues and array sessions keep the application independent of other services.

## API contract

Send `Content-Type: application/json`. Protected requests require `Authorization: Bearer <token>`. No browser session or CSRF-cookie flow is enabled.

| Method | Endpoint | Body | Authentication | Success | Errors |
| --- | --- | --- | --- | --- | --- |
| POST | `/api/auth/register` | `name`, `email`, `password`, `password_confirmation` | Public | 201, user + token | 422 invalid/duplicate data or supplied role; 429 |
| POST | `/api/auth/login` | `email`, `password` | Public | 200, user + token | 401 invalid credentials; 422 invalid input; 429 |
| GET | `/api/auth/me` | None | Bearer | 200, user | 401; 429 |
| POST | `/api/auth/logout` | None | Bearer | 200, empty object | 401; 429 |
| POST | `/api/auth/refresh` | None | Unexpired bearer | 200, user + replacement token | 401 expired/revoked token; 429 |
| GET | `/health` | None | Public | 200, health object | 500 application error |

Registration example:

```json
{
  "name": "Test Customer",
  "email": "customer@example.test",
  "password": "ExamplePassword123!",
  "password_confirmation": "ExamplePassword123!"
}
```

Name: required string, at most 255 characters. Email: valid, at most 255 characters, normalized to lowercase and unique. Password: 12–72 characters, at most 72 bytes (bcrypt limit), uppercase, lowercase, number, symbol, and matching confirmation. Registration rejects nonempty `role` values; all new accounts receive `CUSTOMER` from the database default. No public role management endpoint exists.

Register/login/refresh response (`201` for register, otherwise `200`):

```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "user": {
      "id": 1,
      "name": "Test Customer",
      "email": "customer@example.test",
      "role": "CUSTOMER",
      "created_at": "2026-09-27T12:00:00.000000Z",
      "updated_at": "2026-09-27T12:00:00.000000Z"
    },
    "token": "<opaque Sanctum bearer token>",
    "token_type": "Bearer",
    "expires_at": "2026-09-27T13:00:00+00:00"
  }
}
```

Register message: `Registration successful`. Refresh message: `Token refreshed`. Me returns `message: Authenticated user` and `data.user` with the same safe user fields. Logout returns `{"success":true,"message":"Logout successful","data":{}}`.

Validation failure:

```json
{"success":false,"message":"Validation failed","errors":{"email":["The email has already been taken."]}}
```

Other errors use the same envelope with `errors: {}`. Messages include `Invalid credentials`, `Unauthenticated`, `Forbidden`, `Not found`, `Method not allowed`, `Too many requests`, and `Request failed`. Server exception details are never returned. Health is the specified exception to the envelope: `{"service":"auth-service","status":"healthy"}`. It is a liveness check, not a database readiness probe.

### Token lifecycle and throttling

Tokens expire after `AUTH_TOKEN_TTL` minutes (default 60, minimum 1). Only their hashes are stored. Refresh requires a still-valid token, atomically revokes it and issues a replacement; after expiry the client must log in again. Logout revokes the current token only; other devices remain signed in. Clients must store bearer tokens securely and use HTTPS outside local development.

Registration allows 5 requests/minute/IP. Login allows 5/minute/email+IP and 20/minute/IP. Protected routes allow 60/minute/user. Rate limits use file cache in this single-instance phase. Schedule `php artisan schedule:run` once per minute to prune tokens expired for over 24 hours, or run `php artisan sanctum:prune-expired --hours=24` manually. Expired tokens are rejected even before pruning.

[Sanctum token expiry and revocation reference](https://laravel.com/docs/12.x/sanctum#token-expiration).

## Database structure

All tables live exclusively in `auth_db`:

- `users`: bigint primary key `id`; `name`, unique `email`, hashed `password`; constrained `role` default `CUSTOMER`; timestamps.
- `personal_access_tokens`: bigint primary key, polymorphic user reference, name, unique SHA-256 token hash, abilities, last-use time, indexed expiry and timestamps.
- `migrations`: Laravel migration history.

There are no shared tables, cross-service connections, profile tables, or business-service schemas.

## Tests (isolated PostgreSQL)

Feature tests use real PostgreSQL and reset tables. Use the separate project below: it has its own volume and network, no host ports, and a required `AUTH_TEST_DATABASE` safety flag. Never set this flag on a database containing data you need.

```bash
docker compose -p najmi-auth-tests -f docker-compose.yml -f compose.test.yml up -d --build
docker compose -p najmi-auth-tests -f docker-compose.yml -f compose.test.yml exec auth-service php artisan test
docker compose -p najmi-auth-tests -f docker-compose.yml -f compose.test.yml down -v
```

`compose.test.yml` requires Docker Compose 2.24.4+ for `!override`. The normal development stack intentionally refuses to run this resetting suite. For an independent test PostgreSQL instance also named `auth_db`, configure its credentials and run `AUTH_TEST_DATABASE=true php artisan test` within the service.

Tests cover registration, duplicate/case-normalized emails, malformed input, confirmation, role escalation, login, invalid credentials, real bearer authentication, missing authentication, logout isolation, refresh/replay, expiry, rate limiting, health, connection configuration and development seeding.

For a live end-to-end check after migrations:

```bash
python3 services/auth-service/tests/smoke.py http://localhost:8080
```

The smoke test creates a uniquely named customer, checks register/login/me/refresh/logout and rejection of revoked tokens. It leaves that customer in the local database and never prints passwords or tokens.

## Verification results

Verified on 2026-09-27:

- `php artisan test`: **20 passed, 82 assertions**, both local PHP against isolated PostgreSQL and inside the isolated Docker stack.
- Docker build, development migrations and environment-driven admin seeding completed successfully.
- Live HTTP checks passed with standalone `artisan serve` and through Docker Nginx: health, register, login, me, refresh, rejection of the old token, logout and rejection of the logged-out token.
- The seeded development admin logged in and out successfully through Nginx.
- PostgreSQL reported `current_database() = auth_db`, `current_user = auth_app`; access to `postgres` and `template1` was denied. Feature tests also confirmed no superuser/create-database/create-role privileges.
- Laravel Pint, strict Composer manifest validation, Compose configuration validation and `git diff --check` passed.

The test override explicitly selects the array cache, because Docker environment variables otherwise override PHPUnit's cache setting and make rate-limit counters persist between test cases.

## Main files added

- `services/auth-service/`: Laravel scaffold, `composer.json` / lockfile, `artisan`, bootstrap/configuration, public entry point and storage directories.
- `app/Enums/Role.php`, `app/Models/User.php`, `app/Services/AuthService.php`.
- `app/Http/Controllers/AuthController.php`, `Requests/LoginRequest.php`, `Requests/RegisterRequest.php`, `Resources/UserResource.php`, `Middleware/PreventAuthCaching.php`.
- `routes/api.php`, `routes/web.php`, `routes/console.php`, `app/Providers/AppServiceProvider.php`.
- `database/migrations/`: users and Sanctum token tables; `database/seeders/DevelopmentAdminSeeder.php`, `DatabaseSeeder.php`; user factory.
- `tests/Feature/AuthTest.php`, `tests/TestCase.php`, `tests/smoke.py`, `phpunit.xml`.
- Auth `Dockerfile`, `.dockerignore`, environment example and service README.
- Root `compose.test.yml`, `databases/auth-db/init.sh`, `infrastructure/nginx/auth.conf`, and this document.

Existing root Compose/environment placeholders and architecture documentation were updated for the Auth runtime. Other services remain unimplemented.
