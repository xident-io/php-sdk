# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Ship in the same release as the API change (xident-io/api#44, #45 and #46).
Plan: `plans/requirements-check-trusted-params.md` version 4 in xident-io/projects.

### Changed
- `verification()->init()` requires `user_id`. Without it, or with a blank
  value, the SDK throws a `ValidationException` with code `MISSING_USER_ID`
  and sends nothing. A non-string `user_id` gets `INVALID_USER_ID`. From this
  release the API refuses a missing `user_id` too.
- `min_age` for `age_verification` must be a whole number from 12 to 25
  (`INVALID_MIN_AGE` otherwise, including a numeric string or a fraction such
  as 18.5). A whole-number float such as `18.0` is accepted and sent as the
  integer 18. The API rounds it up to the next of 12, 15, 18, 21 or 25 and
  enforces that band, so 19 is enforced as 21. The SDK sends the value as
  given. The old range was 1 to 99.
- `id_verification` takes no `min_age` (absent, `null` or 0; anything else is
  `INVALID_MIN_AGE`) and cannot use `verification_mode: facial`
  (`INVALID_VERIFICATION_MODE`). An ID verification now always requires
  liveness, a document and a face match.
- The local checks throw with the API's own codes and messages, HTTP status 0
  and no request ID.
- `SessionResult::ageBracket()` returns `null` when the session had no age
  threshold. An `id_verification` result carries no `checks.age.gate` (the
  API no longer reports a false 18 for it), which parses as `gate` 0; the
  bracket used to come back as that 0 whenever the age check passed. It
  now also returns the gate of a passed Xident ID reuse, whose age check in
  that session did not run.

### Added
- The client accepts agent keys (`ak_live_`, `ak_test_`) as well as secret
  keys. The API accepts both on `POST /verify/v1/init`.
- `SessionResult::provesAge(int $minAge)`: true only when the session passed
  and its age gate (`checks.age.gate`) is `$minAge` or higher. It does not
  need `checks.age.passed`, which is false for a passed Xident ID reuse. An
  `id_verification` result has no gate and proves no age.

### Documentation
- Every callback example (README, Laravel, Symfony, WordPress, `basic.php`,
  `webhook.php`) now grants access only when the result's `externalUserId` is
  the user the server started the verification for, and `provesAge()` holds
  for the age the server requires. Before, they trusted any successful result
  token, so a token from another user's callback, an 18+ result on a 21+
  page, or an ID-only result was accepted. The required age is a constant or
  an option on the server, never a request value.
- `init` needs a server key (`sk_live_`, `sk_test_`, `ak_live_`, `ak_test_`);
  a public key gets 403 `SECRET_KEY_REQUIRED`. The README lists the new error
  codes, `verification_mode` and `liveness_difficulty`.
- Every example passes a `user_id`. `examples/basic.php` now reads the
  `SECRET_KEY_REQUIRED` hint from the error code, not the message.

## [3.2.0] - 2026-09-06

### Added
- Data match. `verification()->init()` accepts `expected` (any subset of
  `first_name`, `last_name`, `date_of_birth`, `document_number`,
  `nationality`) and `mismatch_policy` (`report` | `review`); the values are
  checked against the presented document and never reach the browser.
  `SessionResult::$checks->dataMatch` (`?DataMatchCheck`, added in 3.1.1's
  result parsing) carries the per-field verdicts. Additive: nothing is sent
  unless you pass the parameters, and the API version pin is unchanged.
- `Config::SDK_VERSION` bumped to `3.2.0`.

## [2.1.0] - 2026-08-04

### Added
- `SessionResult::$ipCountry` (`?string`) — the ISO 3166-1 alpha-2 country the
  end user connected from, IP-derived. The v1 tenant result contract's first
  additive field since the 2.0.0 freeze. Null on sessions created before
  2026-08-04 or where IP geolocation failed. Distinct from
  `$checks->document->country`, which is the document's issuing country.
- `Config::SDK_VERSION` bumped to `2.1.0`.

## [2.0.0] - 2026-08-03

### Changed
- **BREAKING** `SessionResult` rewritten around the v1 tenant result contract
  (the `data` of `GET /verify/v1/result/{token}`), which is FROZEN
  (additive-only) from this release forward:
  - **Removed** (never reflected the actual `/result` response — the DTO does
    not return these keys): `$livenessResult`, `$ageResult`, `$ocrResult`,
    `$faceMatchResult`, `$ocrTaskId`, `$countryCode`, `$regime`,
    `$requiredMethods`, `$remainingAttempts`.
  - **Added**: `$verified` (bool, the pass verdict as data, not just a
    derived helper), `$verificationMode` (`"full"` or `"token"`),
    `$externalUserId` (your own user ID, echoed back when supplied at init),
    `$completedAt` (RFC 3339 timestamp, null until the session reaches a
    terminal state), and `$checks` — a new `ResultChecks` value object with
    one entry per verification step (`liveness`, `age`, `document`,
    `face_match`), each always present even when that step never ran for the
    session (`performed: false`). `$externalUserId` and `$completedAt` were
    both listed as permanently removed further down in this same
    `[Unreleased]`-turned-`2.0.0` entry — that call was correct against the
    contract at the time, and is superseded now that the v1 contract ratified
    for this release does return both; see the annotated note under
    `### Removed` below.
  - `$reason` (added earlier in this entry, unchanged): last-declared
    constructor parameter, empty string on success.
  - New value objects, one per file per this SDK's existing `Responses/`
    convention: `CheckResult` (`performed`, `passed` — used for `liveness` and
    `face_match`), `AgeGateCheck` (`performed`, `passed`, `gate` — the age
    threshold the session was configured with, meaningful even when the check
    never ran), `DocumentCheck` (`performed`, `passed`, `documentType`,
    `country`).
  - `ageBracket()` now reads `$checks->age->passed ? $checks->age->gate : null`
    instead of spelunking a raw `age_result` array. Same signature (`?int`),
    different source.
  - `method()` now returns `$verificationMode` instead of a value that used to
    live inside `$ageResult['method']`. Same signature (`?string`).
  - `isVerified()`, `isCompleted()` (deprecated alias), `isFailed()`,
    `isPending()`, `isTerminal()` are unchanged in behavior — all four still
    key off `$status` alone.
  - **Tolerant both directions**: the v1 payload parses fully; a pre-2.0
    verbose payload (the removed `*_result` blobs, `country_code`, `regime`,
    no `checks` key at all) still constructs without a `TypeError` and
    `isVerified()` still reports correctly — `$checks` just degrades to
    `performed: false` on every step, since that shape carries no per-check
    detail to read.
- `Config::SDK_VERSION` bumped to `2.0.0`.

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
  (`externalUserId` and `completedAt` came back in `2.0.0` above: the v1
  contract ratified for that release does return both. `minAge` and
  `startedAt` remain gone — the v1 contract has no equivalent field for
  either.)

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
