# Xident PHP SDK

Server-side PHP SDK for [Xident](https://xident.io) age and identity verification. Zero external dependencies. Works with Laravel, Symfony, WordPress, and any PHP 8.2+ application.

> **v2.0.0 is a breaking release.** `SessionResult` was rewritten around the
> frozen v1 result contract (`GET /verify/v1/result/{token}`). Removed:
> `$ageResult`, `$livenessResult`, `$ocrResult`, `$faceMatchResult`,
> `$ocrTaskId`, `$countryCode`, `$regime`, `$requiredMethods`,
> `$remainingAttempts` — none of these were ever part of the actual `/result`
> response. Added: `$verified`, `$reason`, `$verificationMode`,
> `$externalUserId`, and `$checks` (per-step `liveness`/`age`/`document`/`face_match`
> detail — see below). `ageBracket()` and `method()` keep their signatures but
> now read from `$checks` and `$verificationMode` respectively. See
> `CHANGELOG.md` for the full list.

## Requirements

- PHP 8.2+ (the response objects are `readonly` classes, which are 8.2 syntax)
- cURL extension (bundled with PHP)
- JSON extension (bundled with PHP)

## Installation

```bash
composer require xident-io/php-sdk
```

Without Composer: `require_once '/path/to/xident-php/autoload.php';`

## Quick Start

```php
use Xident\SDK\Client;

$xident = new Client(apiKey: $_ENV['XIDENT_SECRET_KEY']);

// 1. Create init token (your backend, with your secret key)
const REQUIRED_MIN_AGE = 18; // your site's rule, decided on the server (12 to 25)

$session = $xident->verification()->init([
    'callback_url' => 'https://yoursite.com/verify-callback',
    'user_id'      => $currentUserId,    // required: your own id for this person
    'min_age'      => REQUIRED_MIN_AGE,  // 12 to 25, rounded up to 12, 15, 18, 21 or 25
]);
// Redirect user to $session->verifyUrl

// 2. After user returns, verify server-side (NEVER trust URL params)
$result = $xident->verification()->getResult($token);

// Grant only when the result belongs to this user AND proves your age.
if ($result->externalUserId === $currentUserId && $result->provesAge(REQUIRED_MIN_AGE)) {
    echo $result->ageBracket(); // 18
}
```

Two checks on top of "verified", both on your server:

- **Whose result is it?** Compare `$result->externalUserId` with the user id
  your server sent to `init`, never with the `user_id` in the callback URL. A
  result token copied from someone else's callback is a real success, for
  somebody else.
- **Which age does it prove?** `provesAge($minAge)` is true only when the
  session passed and its age gate (`checks.age.gate`) is `$minAge` or higher.
  It does not need `checks.age.passed`: a returning user who reused their
  Xident ID passes with the gate but without a new age check in that session. An 18+ result does
  not open a 21+ page, and an `id_verification` result (no gate) proves no age.
  Pass the age your server requires, never one from the request.

## How It Works

1. Your backend calls `POST /verify/v1/init` with your secret key
2. SDK returns an init token (`xit_`) + verify URL. You redirect the user there.
3. User completes verification on `verify.xident.io` (liveness + age check)
4. Widget redirects the browser back to your `callback_url` with query params:
   `status` (`success`, `failed`, or `canceled`), `token`
   (the **result** token, `xtk_` prefixed — a different token from the `xit_`
   init token), and `user_id` (if you supplied one).
5. Your backend calls `GET /verify/v1/result/{token}` with the `xtk_` result
   token to get the result
6. You make the authorization decision based on the verified result

## API Reference

### Client

```php
$xident = new \Xident\SDK\Client(
    apiKey:     'sk_live_xxx',            // Required: sk_live_, sk_test_, ak_live_ or ak_test_
    baseUrl:    'https://api.xident.io',  // Optional (default)
    timeout:    30,                        // Optional seconds
    maxRetries: 3,                         // Optional (retries on 5xx)
);
```

### verification()->init(params): InitResult

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `callback_url` | string | Yes | HTTPS URL for callback (localhost OK for dev) |
| `user_id` | string | Yes | Your own identifier for the person being verified. It comes back on the callback and in the result. |
| `min_age` | int | Yes* | Required for `age_verification`: a whole number from 12 to 25. Xident rounds it up to the next of 12, 15, 18, 21 or 25 and enforces that band, so 19 is enforced as 21. An `id_verification` takes no `min_age` (leave it out, or send 0). |
| `theme` | string | No | `light`, `dark`, or `system`. Unknown values coerce to `system`. |
| `locale` | string | No | `en`, `es`, `fr`, `de`, `pt`, `ar`, `zh`, `ja`, `hi`, `nl`. Unknown → `en`. |
| `purpose` | string | No | `age_verification` (default) or `id_verification`. An ID verification requires liveness, a document and a face match. |
| `verification_mode` | string | No | `auto` (default), `document` (a document and a face match are always required) or `facial` (on-device age estimation only). `facial` cannot be combined with purpose `id_verification`, which always needs a document. |
| `liveness_difficulty` | string | No | `easy` (default), `medium` or `hard`. |
| `expected` | array | No | Identity data you already hold about the user, checked against the document (data match). Any subset of `first_name`, `last_name`, `date_of_birth` (YYYY-MM-DD), `document_number`, `nationality` (ISO alpha-2). Needs a document: pair with `purpose: id_verification` or `verification_mode: document`. The values never reach the browser. |
| `mismatch_policy` | string | No | `report` (default): mismatches are reported, the outcome is unchanged. `review`: any mismatch sends the session to your review queue with reason `data_mismatch`. Only with `expected`. |

Returns: `$result->token` (init token, `xit_` prefixed), `$result->verifyUrl`

`init` needs a server key (`sk_live_`, `sk_test_`, `ak_live_` or `ak_test_`). A
public key (`pk_`) gets 403 `SECRET_KEY_REQUIRED`; the client refuses one when
it is built. Never put a server key in a browser or a mobile app.

Before it sends anything, the SDK checks `user_id`, `min_age` and the
`id_verification` rules, and throws a `ValidationException` with the same code
the API would answer with. A local refusal has HTTP status 0 and no request ID,
because no request was sent. The SDK sends `min_age` as given; the API rounds it.
A whole-number float such as `18.0` is sent as the integer `18`; `18.5` is refused.

| Code | When |
|------|------|
| `MISSING_USER_ID` (400) | `user_id` is missing or blank. |
| `INVALID_USER_ID` (400) | `user_id` is not a string, or (API only) it is a Xident key or token. |
| `INVALID_MIN_AGE` (400) | `min_age` is missing or outside 12 to 25 for `age_verification`, or is set (not 0) for `id_verification`. |
| `INVALID_VERIFICATION_MODE` (400) | `verification_mode` is `facial` with purpose `id_verification`. The API also refuses a mode other than `auto`, `document` or `facial`. |
| `INVALID_LIVENESS_DIFFICULTY` (400, API only) | `liveness_difficulty` is not `easy`, `medium` or `hard`. |
| `SECRET_KEY_REQUIRED` (403, API only) | The client was built with a public key. |
| `IDEMPOTENCY_KEY_MISMATCH` (422, API only) | An `Idempotency-Key` was reused with a different body. |

To get a failed session in test mode, use a test key and a `user_id` that ends
in `+fail`.

### verification()->getResult(token): SessionResult

Pass the **result** token (`xtk_`) from the callback — not the `xit_` init token.

Properties: `$result->token` (the `xtk_` result token), `$result->status`, `$result->verified`, `$result->reason`, `$result->verificationType`, `$result->externalUserId`, `$result->checks`, `$result->createdAt`, `$result->completedAt`, `$result->expiresAt`.

`$result->checks` is a `ResultChecks` object with one entry per verification
step — always present, `performed: false` when a step didn't run for this
session:

| Check | Type | Fields |
|-------|------|--------|
| `checks->liveness` | `CheckResult` | `performed`, `passed` |
| `checks->age` | `AgeGateCheck` | `performed`, `passed`, `gate` (the age threshold; 0 for an `id_verification`, which has none) |
| `checks->document` | `DocumentCheck` | `performed`, `passed`, `documentType`, `country` |
| `checks->faceMatch` | `CheckResult` | `performed`, `passed` |
| `checks->dataMatch` | `?DataMatchCheck` | `performed`, `passed`, `fields` — `null` unless you sent `expected` and a document was read. Each of `fields->firstName`, `lastName`, `dateOfBirth`, `documentNumber`, `nationality` is `match`, `mismatch`, `not_on_document` or `null`. Gate on `$checks->dataMatch?->passed === true`. |

Helpers: `isVerified()`, `isFailed()`, `isPending()`, `isTerminal()`, `provesAge(int $minAge)` (⇒ the session passed and `checks->age->gate` is `$minAge` or higher; false for an `id_verification` result, which has no gate), `ageBracket()` (⇒ `checks->age->gate` when that age was proven, by a passed age check or by a passed session with a gate, else `null`), `method()` (⇒ `$result->verificationType`)

### webhooks()->constructEvent(payload, signature, secret): array

Verify HMAC-SHA256 webhook signature and parse event.

```php
$event = $xident->webhooks()->constructEvent(
    payload:   file_get_contents('php://input'),
    signature: $_SERVER['HTTP_X_XIDENT_SIGNATURE'],
    secret:    'whsec_xxx',
);
// $event['type'], $event['data']
```

## Error Handling

```php
use Xident\SDK\Exceptions\XidentException;
use Xident\SDK\Exceptions\AuthenticationException;
use Xident\SDK\Exceptions\NotFoundException;

try {
    $result = $xident->verification()->getResult($token);
} catch (AuthenticationException $e) {
    // 401 - Invalid API key
} catch (NotFoundException $e) {
    // 404 - Session not found
} catch (XidentException $e) {
    echo $e->getErrorCode();   // API error code
    echo $e->getRequestId();   // For support tickets
    echo $e->getHttpStatus();  // HTTP status
}
```

Exception hierarchy: `AuthenticationException` (401), `ValidationException` (400), `NotFoundException` (404), `RateLimitException` (429), `ServerException` (5xx), `NetworkException` (cURL errors).

## Retry Behavior

Automatic retry with exponential backoff (1s, 2s, 4s) on 5xx and network errors only. Never retries 4xx.

## Laravel Example

```php
class VerificationController extends Controller
{
    private const REQUIRED_MIN_AGE = 18; // decided on the server, never from the request

    public function start(Request $request)
    {
        $xident = new \Xident\SDK\Client(apiKey: config('services.xident.secret_key'));
        $session = $xident->verification()->init([
            'callback_url' => route('verify.callback'),
            'min_age' => self::REQUIRED_MIN_AGE,
            'user_id' => (string) $request->user()->id,
        ]);
        return redirect($session->verifyUrl);
    }

    public function callback(Request $request)
    {
        $xident = new \Xident\SDK\Client(apiKey: config('services.xident.secret_key'));
        $result = $xident->verification()->getResult($request->input('token'));
        if ($result->externalUserId === (string) $request->user()->id
            && $result->provesAge(self::REQUIRED_MIN_AGE)
        ) {
            $request->user()->update(['age_verified' => true]);
            return redirect()->route('dashboard');
        }
        return redirect()->route('verify.failed');
    }
}
```

See `examples/` for Symfony, WordPress, and webhook examples.

## Security

- **Server keys**: Never expose `sk_*` or `ak_*` in frontend code
- **TLS 1.2+**: Enforced on all API calls
- **Webhooks**: Always verify signatures (`hash_equals` for timing-attack resistance)
- **Verification tokens**: Always re-verify server-side. Never trust URL params alone.
- **SSRF**: HTTP client does not follow redirects

## Testing

```bash
composer test              # 228 tests, 711 assertions
composer test:coverage     # With HTML coverage report
```

Mock the client in your tests:

```php
$transport = new \Xident\SDK\Tests\Helpers\MockTransport();
$transport->queueSuccess(['token' => 'xit_test', 'verify_url' => 'https://verify.xident.io?t=xit_test']);
$client = new \Xident\SDK\Client('sk_test_xxx', transport: $transport);
```

## Links

- [Try it live](https://demo.xident.io)
- [Documentation](https://docs.xident.io/sdks/php)
- [API Reference](https://docs.xident.io/api-reference)
- [JavaScript SDK](https://docs.xident.io/sdks/javascript) (client-side counterpart)
- [Dashboard](https://dashboard.xident.io) (get your API key)

## License

MIT
