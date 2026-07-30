# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
- `isVerified()` returned **false for every verified user**. The API renamed the
  pass verdict from `completed` to `success` (July 2026) and this SDK still
  compared against the old literal.

### Changed
- `SessionStatus::Success` is the pass verdict. `SessionStatus::Completed` is
  now a **deprecated constant aliasing `Success`**, not a case — a backed enum
  cannot have two cases with the same value, and the alias has to resolve to
  the new value so existing `=== SessionStatus::Completed` comparisons keep
  being *correct*, not merely keep parsing.
- `SessionStatus::normalize()` maps a legacy `completed` off the wire, so this
  SDK still works against a deployment older than the rename.
- `SessionResult::$reason` added — why a non-success terminal status came out
  that way (`age_below_threshold`, `dob_unreadable`, `face_mismatch`,
  `face_not_detected`, `docverify_reject`, `blacklist_match`). Declared last in
  the constructor so positional construction is unaffected.
- The browser callback's `?status=` uses `success | failed | canceled` — the
  same three words as the result endpoint. The earlier note in this file
  claiming it uses the British `cancelled` was correct at the time and is no
  longer true.
- `isCompleted()` deprecated in favour of `isVerified()`. Its docblock claimed
  "any outcome", which the code never did — it has always returned the pass
  verdict only. For "reached any terminal state" use `isTerminal()`.

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
