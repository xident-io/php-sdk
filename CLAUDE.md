# CLAUDE.md — Xident PHP SDK

## Overview
Server-side PHP 8.1+ SDK for Xident age verification. Zero external dependencies (native cURL only). Works with Laravel, Symfony, WordPress, and plain PHP.

## Architecture
```
Client → Resources (Verification, Webhooks, Face2FA, Blacklist)
       → HttpClient (cURL wrapper, accepts mock transport for testing)
       → Config (readonly, immutable)
       → Responses (readonly value objects)
       → Exceptions (typed hierarchy mapping HTTP status codes)
```

## File Structure
- `src/Xident/SDK/Client.php` — Main entry point
- `src/Xident/SDK/Config.php` — Configuration
- `src/Xident/SDK/HttpClient.php` — cURL transport (internal)
- `src/Xident/SDK/Resources/` — API resource classes
- `src/Xident/SDK/Responses/` — Readonly response objects
- `src/Xident/SDK/Exceptions/` — Exception hierarchy
- `src/Xident/SDK/Enums/` — PHP enums (SessionStatus)
- `tests/` — PHPUnit tests (133 tests)
- `examples/` — Framework-specific integration examples

## API Endpoints Used

| Endpoint | SDK Method | Auth |
|----------|-----------|------|
| `POST /verify/v1/init` | `verification()->init()` | X-API-Key |
| `GET /verify/v1/result/{token}` | `verification()->getResult()` | X-API-Key |
| `POST /verify/v1/2fa/register` | `face2fa()->register()` | X-API-Key |
| `POST /verify/v1/2fa/verify` | `face2fa()->verify()` | X-API-Key |
| `GET /verify/v1/2fa/status/{id}` | `face2fa()->getStatus()` | X-API-Key |
| `GET /verify/v1/2fa/users/{user_id}` | `face2fa()->getUser()` | X-API-Key |
| `DELETE /verify/v1/2fa/users/{user_id}` | `face2fa()->deleteUser()` | X-API-Key |
| `GET /verify/v1/blacklist` | `blacklist()->list()` | X-API-Key |
| `POST /verify/v1/blacklist/session` | `blacklist()->addBySession()` | X-API-Key |
| `POST /verify/v1/blacklist/image` | `blacklist()->addByImage()` | X-API-Key |
| `DELETE /verify/v1/blacklist/{id}` | `blacklist()->remove()` | X-API-Key |

## Commands
```bash
composer install          # Install dependencies
composer test             # Run PHPUnit (133 tests)
composer test:coverage    # Coverage report
php examples/basic.php    # Run basic example
```

## Key Patterns
- `HttpClient` accepts optional `callable $transport` for test injection
- All responses are `readonly` classes with `fromArray()` factory methods
- Exception hierarchy maps HTTP codes: 400→Validation, 401→Auth, 404→NotFound, 429→RateLimit, 5xx→Server
- Retry only on 5xx + network errors, exponential backoff (1s, 2s, 4s)
- Webhook signatures match Go backend: `hash_hmac('sha256', "{timestamp}.{payload}", secret)`
