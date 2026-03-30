# Xident PHP SDK

Server-side PHP SDK for [Xident](https://xident.io) age and identity verification. Zero external dependencies. Works with Laravel, Symfony, WordPress, and any PHP 8.1+ application.

## Requirements

- PHP 8.1+
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

// 1. Create init token (your backend)
$session = $xident->verification()->init([
    'callback_url' => 'https://yoursite.com/verify-callback',
    'min_age'      => 18,
]);
// Redirect user to $session->verifyUrl

// 2. After user returns, verify server-side (NEVER trust URL params)
$result = $xident->verification()->getResult($token);

if ($result->isVerified()) {
    echo $result->ageBracket(); // 18
}
```

## How It Works

1. Your backend calls `POST /verify/v1/init` with your secret key
2. SDK returns a token + verify URL. You redirect the user there.
3. User completes verification on `verify.xident.io` (liveness + age check)
4. User redirected back to your `success_url` with `?token=xxx`
5. Your backend calls `GET /verify/v1/status/{token}` to get the result
6. You make the authorization decision based on the verified result

## API Reference

### Client

```php
$xident = new \Xident\SDK\Client(
    apiKey:     'sk_live_xxx',            // Required
    baseUrl:    'https://api.xident.io',  // Optional (default)
    timeout:    30,                        // Optional seconds
    maxRetries: 3,                         // Optional (retries on 5xx)
);
```

### verification()->init(params): InitResult

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `callback_url` | string | Yes | HTTPS URL for callback (localhost OK for dev) |
| `min_age` | int | No | 12, 15, 18, 21, or 25. Default: 18 |
| `success_url` | string | No | Redirect on success |
| `failed_url` | string | No | Redirect on failure |
| `user_id` | string | No | Your internal user ID |
| `theme` | string | No | `light`, `dark`, `auto` |
| `locale` | string | No | `en`, `de`, `es`, `fr`, `it`, `pt`, `nl`, `pl`, `tr`, `ar`, `ja` |
| `metadata` | string | No | Custom JSON (max 1KB) |

Returns: `$result->token`, `$result->verifyUrl`

### verification()->getResult(token): SessionResult

Helpers: `isVerified()`, `isFailed()`, `isPending()`, `isTerminal()`, `ageBracket()`, `method()`

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
    public function start(Request $request)
    {
        $xident = new \Xident\SDK\Client(apiKey: config('services.xident.secret_key'));
        $session = $xident->verification()->init([
            'callback_url' => route('verify.callback'),
            'min_age' => 18,
            'user_id' => (string) $request->user()->id,
        ]);
        return redirect($session->verifyUrl);
    }

    public function callback(Request $request)
    {
        $xident = new \Xident\SDK\Client(apiKey: config('services.xident.secret_key'));
        $result = $xident->verification()->getResult($request->input('token'));
        if ($result->isVerified()) {
            $request->user()->update(['age_verified' => true]);
            return redirect()->route('dashboard');
        }
        return redirect()->route('verify.failed');
    }
}
```

See `examples/` for Symfony, WordPress, and webhook examples.

## Security

- **Secret key**: Never expose `sk_*` in frontend code
- **TLS 1.2+**: Enforced on all API calls
- **Webhooks**: Always verify signatures (`hash_equals` for timing-attack resistance)
- **Verification tokens**: Always re-verify server-side. Never trust URL params alone.
- **SSRF**: HTTP client does not follow redirects

## Testing

```bash
composer test              # 96 tests, 200 assertions
composer test:coverage     # With HTML coverage report
```

Mock the client in your tests:

```php
$transport = new \Xident\SDK\Tests\Helpers\MockTransport();
$transport->queueSuccess(['token' => 'xit_test', 'verify_url' => 'https://verify.xident.io?t=xit_test']);
$client = new \Xident\SDK\Client('sk_test_xxx', transport: $transport);
```

## Links

- [Documentation](https://docs.xident.io/sdks/php)
- [API Reference](https://docs.xident.io/api-reference)
- [JavaScript SDK](https://docs.xident.io/sdks/javascript) (client-side counterpart)
- [Dashboard](https://dashboard.xident.io) (get your API key)

## License

MIT
