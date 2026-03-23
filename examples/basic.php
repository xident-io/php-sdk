<?php
/**
 * Xident PHP SDK — Basic Integration Example
 *
 * This shows the full verification flow:
 * 1. Create init token (backend)
 * 2. Redirect user to verification widget
 * 3. Handle callback and verify result (backend)
 *
 * Run: php examples/basic.php
 */

require_once __DIR__ . '/../vendor/autoload.php';
// Or without Composer: require_once __DIR__ . '/../autoload.php';

use Xident\SDK\Client;
use Xident\SDK\Exceptions\XidentException;

/**
 * Helper: safely read a query parameter (sanitize for CLI demo).
 * In production, use your framework's request object instead.
 */
function safeParam(string $key, ?string $default = null): ?string
{
    $raw = filter_input(INPUT_GET, $key, FILTER_SANITIZE_SPECIAL_CHARS);
    return is_string($raw) && $raw !== '' ? $raw : $default;
}

// Initialize the client with your secret API key
$xident = new Client(
    apiKey: getenv('XIDENT_SECRET_KEY') ?: 'sk_test_xxx',
);

// ─────────────────────────────────────────────────
// Step 1: Create Init Token (call this from your "verify age" endpoint)
// ─────────────────────────────────────────────────

try {
    $session = $xident->verification()->init([
        'callback_url' => 'https://yoursite.com/verify-callback',
        'min_age'      => 18,
        'success_url'  => 'https://yoursite.com/welcome',
        'failed_url'   => 'https://yoursite.com/sorry',
        'user_id'      => 'user_123',          // Your internal user ID (optional)
        'theme'        => 'auto',              // light, dark, auto
        'locale'       => 'en',               // Language
    ]);

    echo "Token: " . $session->token . "\n";
    echo "Redirect user to: " . $session->verifyUrl . "\n";

    // In a real app:
    // header('Location: ' . $session->verifyUrl);
    // exit;

} catch (XidentException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Code: " . $e->getErrorCode() . "\n";
    echo "Request ID: " . ($e->getRequestId() ?? 'n/a') . "\n";
}

// ─────────────────────────────────────────────────
// Step 3: Verify Result (handle the callback)
// ─────────────────────────────────────────────────

// User returns to: https://yoursite.com/verify-callback?session_id=xxx
// NEVER trust the URL alone — always verify server-side:

$sessionId = safeParam('session_id', 'demo_session_id');

try {
    $result = $xident->verification()->getResult($sessionId);

    if ($result->isVerified()) {
        $bracket = $result->ageBracket();
        $method  = $result->method();
        $country = $result->countryCode;
        echo "Verified! Age bracket: {$bracket}, Method: {$method}, Country: {$country}\n";
    } elseif ($result->isFailed()) {
        echo "Verification failed\n";
    } elseif ($result->isPending()) {
        echo "Still in progress\n";
    }
} catch (XidentException $e) {
    echo "Error checking result: " . $e->getMessage() . "\n";
}

// ─────────────────────────────────────────────────
// Token verification (for returning Xident users — cheap path)
// ─────────────────────────────────────────────────

$token = safeParam('xident_token');
if ($token !== null) {
    try {
        $tokenResult = $xident->tokens()->verify($token);
        if ($tokenResult->isValid() && $tokenResult->meetsMinAge(18)) {
            echo "Returning user verified (cheap path)\n";
        }
    } catch (XidentException $e) {
        echo "Token verification error: " . $e->getMessage() . "\n";
    }
}
