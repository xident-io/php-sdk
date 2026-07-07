---
name: xident-php-sdk
description: Integrate Xident age verification into a PHP application using the official PHP SDK. Use when user asks to add age verification, implement Xident, or integrate identity verification in PHP, Laravel, Symfony, or WordPress.
---

# Xident PHP SDK Integration

## Install

```bash
composer require xident/xident-php
```

## Complete Integration (3 Steps)

### Step 1: Create init token (your backend)

```php
use Xident\SDK\Client;

$xident = new Client(apiKey: $_ENV['XIDENT_SECRET_KEY']);

$session = $xident->verification()->init([
    'callback_url' => 'https://yoursite.com/verify-callback',
    'min_age'      => 18,        // required, 1-99 (omitting or 0 → HTTP 400)
    'success_url'  => 'https://yoursite.com/welcome',
    'failed_url'   => 'https://yoursite.com/sorry',
    'user_id'      => $userId,   // optional
]);

// Redirect user to $session->verifyUrl
header('Location: ' . $session->verifyUrl);
```

### Step 2: User completes verification on verify.xident.io

The user is redirected back to your `success_url` or `failed_url` with `?token=xxx`.

### Step 3: Verify result server-side (CRITICAL)

```php
$token = $_GET['token']; // or $request->input('token') in Laravel

$result = $xident->verification()->getResult($token);

if ($result->isVerified()) {
    $ageBracket = $result->ageBracket(); // 18
    $method = $result->method();          // "ml_fast"
    // Grant access
} elseif ($result->isFailed()) {
    // Deny access
}
```

## Webhook Handling

```php
$event = $xident->webhooks()->constructEvent(
    payload: file_get_contents('php://input'),
    signature: $_SERVER['HTTP_X_XIDENT_SIGNATURE'],
    secret: $_ENV['XIDENT_WEBHOOK_SECRET'],
);

match ($event['type']) {
    'session.completed' => handleCompleted($event['data']),
    'session.failed' => handleFailed($event['data']),
    default => null,
};
```

## Error Handling

```php
try {
    $result = $xident->verification()->getResult($token);
} catch (\Xident\SDK\Exceptions\AuthenticationException $e) {
    // Invalid API key (401)
} catch (\Xident\SDK\Exceptions\NotFoundException $e) {
    // Token not found (404)
} catch (\Xident\SDK\Exceptions\XidentException $e) {
    // Any other error — $e->getErrorCode(), $e->getRequestId()
}
```

## Key Rules

1. NEVER trust URL parameters — always call `getResult()` server-side
2. Use `sk_*` secret key server-side only, never in frontend
3. Always verify webhook signatures before processing events
4. The SDK auto-retries on 5xx errors (3 times, exponential backoff)
