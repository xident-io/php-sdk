# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- `Face2FA` resource (`$client->face2fa()`) — face-based second factor:
  - `register($userId, $image)` / `verify($userId, $image)` — both async;
    they return a `Face2FAChallenge` in `processing` status.
  - `getStatus($challengeId)` — poll for the pass/fail verdict
    (`Face2FAStatus`; `passed` is null while processing, `failure_reason`
    carries the taxonomy: invalid_image, no_face_detected, not_enrolled,
    face_mismatch, blacklist_match, expired, internal_error).
  - `getUser($userId)` — enrollment state (`Face2FAEnrollment`).
  - `deleteUser($userId)` — GDPR hard delete, idempotent.
- `Blacklist` resource (`$client->blacklist()`) — manage your face blacklist:
  - `list($page, $perPage)` — paginated entries (`BlacklistEntryList` of
    `BlacklistEntry`; pagination read from the envelope's `meta.pagination`).
  - `addBySession($sessionToken, $reason)` — blacklist the person from one of
    your terminal verification sessions (async, returns `processing`; a
    still-running session is rejected with HTTP 409 → `ValidationException`).
  - `addByImage($image, $reason)` — blacklist the face in a base64 image
    (async, returns `processing`).
  - `remove($id)` — deactivate an entry (un-ban).
- Response objects: `Face2FAChallenge`, `Face2FAStatus`, `Face2FAEnrollment`,
  `BlacklistEntry`, `BlacklistEntryList`.

### Fixed
- `isVerified()` returned **false for every verified user**. The API renamed the
  pass verdict from `completed` to `success` (July 2026) and this SDK still
  compared against the old literal.
- `RateLimitException::getRetryAfter()` **always returned null**. The setter
  existed and was never called, so the `Retry-After` header the API sends on a
  429 was parsed off the wire and then thrown away. It is now read from the
  response and attached to the exception. Only the delta-seconds form is
  honoured; an HTTP-date still yields null rather than a wait derived from a
  clock we do not control.
- `Webhooks::parseEvent()` returned the event `id` uncast, so a numeric id
  leaked as an int despite the documented type being `string` since 1.0.0. It
  is now cast like every sibling field.

### Changed
- **`require.php` raised from `^8.1` to `^8.2`.** The SDK never actually ran on
  8.1: `Config` and every class in `Responses/` are declared
  `final readonly class`, which is PHP 8.2 syntax, so nine of the twenty-three
  source files were parse errors on 8.1 and the client could not be loaded at
  all. The manifest, README and this file all claimed 8.1+. Composer will now
  refuse the install instead of letting it fatal at runtime. PHP 8.1 reached
  end of life in December 2025.
- `Webhooks::parseEvent()` / `constructEvent()` return-type docs corrected: the
  `id` and `created` keys are ALWAYS present (null when the payload omits them),
  never absent. The old `id?: string` annotation implied otherwise.
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
