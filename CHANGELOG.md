# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed
- **BREAKING** `SessionResult::$id` renamed to `$token`, now populated from the
  `/verify/v1/result/{token}` DTO's `token` field (the `xtk_` result token). The
  old `$id` read a non-existent `id` key and was always empty.

### Removed
- `SessionResult` properties `minAge`, `externalUserId`, `startedAt`,
  `completedAt` — the `/result` DTO never returns these, so they were always null.

### Documentation
- `theme`: corrected invalid `auto` value to `system` (README, Laravel example).
- `min_age`: documented as required (1–99) for age verification; omitting it or
  sending `0` returns HTTP 400.
- `locale`: aligned to the backend-supported set (en, es, fr, de, pt, ar, zh, ja,
  hi, nl); removed unsupported it/pl/tr.
- Documented the `metadata` param (opaque, echoed-back string) and the `purpose`
  param (`age_verification` default / `id_verification`).
- Documented the callback query params (`status` uses British `cancelled`; `token`
  is the `xtk_` result token, distinct from the `xit_` init token).

## [1.0.0] - 2026-03-23

### Added
- `Client` — Main SDK entry point with resource-based API
- `verification()->init()` — Create init tokens for verification sessions
- `verification()->getResult()` — Retrieve verification session results
- `tokens()->verify()` — Verify Xident verification tokens (cheap path)
- `webhooks()->verifySignature()` — HMAC-SHA256 webhook signature verification
- `webhooks()->constructEvent()` — Verify + parse webhook events
- Typed response objects: `InitResult`, `SessionResult`, `TokenResult`
- Exception hierarchy: Authentication, Validation, NotFound, RateLimit, Server, Network
- Automatic retry with exponential backoff on 5xx errors
- TLS 1.2+ enforcement
- Zero external dependencies (native cURL)
- PHP 8.1+ with strict types, readonly classes, enums
- 96 unit tests with 100% code coverage
- Framework examples: Laravel, Symfony, WordPress
- Manual autoloader for non-Composer environments
