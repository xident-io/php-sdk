<?php
/**
 * Xident PHP SDK — Basic Integration Example
 *
 * This shows the full verification flow:
 * 1. Create init token using your SECRET key (server-side only)
 * 2. Redirect user to verification widget
 * 3. Handle callback and verify result using your SECRET key
 *
 * IMPORTANT: Use your SECRET key (sk_live_... or sk_test_...) for server-side SDK calls.
 * A public key (pk_live_... or pk_test_...) cannot create a session: init answers
 * 403 SECRET_KEY_REQUIRED. Never put a secret key in a browser or a mobile app.
 *
 * Run with PHP built-in server:
 *   XIDENT_SECRET_KEY=sk_test_adult_secret_key_1234567890 php -S localhost:8888 -t examples
 *   Then open: http://localhost:8888/basic.php
 */

require_once __DIR__ . '/../vendor/autoload.php';
// Or without Composer: require_once __DIR__ . '/../autoload.php';

use Xident\SDK\Client;
use Xident\SDK\Exceptions\XidentException;

/**
 * Helper: safely read a query parameter.
 * In production, use your framework's request object instead.
 */
function safeParam(string $key, ?string $default = null): ?string
{
    $raw = filter_input(INPUT_GET, $key, FILTER_SANITIZE_SPECIAL_CHARS);
    return is_string($raw) && $raw !== '' ? $raw : $default;
}

// ─────────────────────────────────────────────────
// Initialize the client with your SECRET API key
// ─────────────────────────────────────────────────
// The secret key (sk_live_... or sk_test_...) is required for:
//   - Creating init tokens (POST /verify/v1/init)
//   - Reading verification results (GET /verify/v1/result/{token})
// A public key (pk_live_... or pk_test_...) gets 403 SECRET_KEY_REQUIRED here.

$secretKey = getenv('XIDENT_SECRET_KEY');
if (!$secretKey) {
    echo "ERROR: Set XIDENT_SECRET_KEY environment variable\n";
    echo "Example: XIDENT_SECRET_KEY=sk_test_adult_secret_key_1234567890 php -S localhost:8888 -t examples\n";
    exit(1);
}

$xident = new Client(apiKey: $secretKey);

// The minimum age this site requires. Decided here, on the server: never take
// it from the request, or the browser could ask for a lower age.
const REQUIRED_MIN_AGE = 18; // 12 to 25, rounded up to 12, 15, 18, 21 or 25

// The id of the person being verified, as your app knows them. In your app
// this is the signed-in user's id. This demo has no login, so it keeps a
// random id in the PHP session.
session_start();
$_SESSION['xident_user_id'] ??= 'php_demo_' . bin2hex(random_bytes(8));
$userId = $_SESSION['xident_user_id'];

// ─────────────────────────────────────────────────
// Handle callback — check if user returned from verification
// ─────────────────────────────────────────────────

$callbackToken = safeParam('token');
if ($callbackToken) {
    // User returned from verification widget with ?token=xtk_xxx
    // ALWAYS verify server-side — never trust URL params alone
    try {
        $result = $xident->verification()->getResult($callbackToken);

        // Grant only when both hold:
        // 1. The result belongs to this visitor. Compare with the id this
        //    server sent as user_id, never with the user_id in the URL: a
        //    result token copied from someone else's callback is a real
        //    success, for somebody else.
        // 2. It proves the age this site requires. An 18+ result does not
        //    open a 21+ page, and an ID-only result proves no age.
        if ($result->externalUserId !== $userId) {
            echo "<h2 style='color:red'>This result belongs to someone else</h2>";
        } elseif ($result->provesAge(REQUIRED_MIN_AGE)) {
            $bracket = $result->ageBracket();
            $method  = $result->method();
            // Country is only known when the document check ran (Path B/C) —
            // null on a Path A (on-device) or Path D (token reuse) pass.
            $country = $result->checks->document->country ?? 'n/a';
            echo "<h2 style='color:green'>Verified!</h2>";
            echo "<p>Age bracket: {$bracket}+, Method: {$method}, Country: {$country}</p>";
        } elseif ($result->isVerified()) {
            echo "<h2 style='color:red'>Verified, but not for the age this site requires</h2>";
        } elseif ($result->isFailed()) {
            echo "<h2 style='color:red'>Verification failed</h2>";
        } elseif ($result->isPending()) {
            echo "<h2 style='color:orange'>Still in progress...</h2>";
        }
    } catch (XidentException $e) {
        echo "<p style='color:red'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    exit;
}

// ─────────────────────────────────────────────────
// Step 1: Create Init Token and redirect user
// ─────────────────────────────────────────────────

$callbackUrl = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost:8888') . '/basic.php';

try {
    $session = $xident->verification()->init([
        'callback_url' => $callbackUrl,
        'user_id'      => $userId,           // required: your own identifier for this person
        'min_age'      => REQUIRED_MIN_AGE,
    ]);

    // Show link to verification widget
    $verifyUrl = htmlspecialchars($session->verifyUrl);
    echo "<h1>Xident PHP SDK Demo</h1>";
    echo "<p>Init token created: <code>{$session->token}</code></p>";
    echo "<p><a href='{$verifyUrl}' style='display:inline-block;padding:12px 24px;background:#4f46e5;color:white;border-radius:8px;text-decoration:none;font-weight:bold'>Start Age Verification</a></p>";
    echo "<p style='color:#666;font-size:13px'>After verification, you'll be redirected back here with the result.</p>";

} catch (XidentException $e) {
    echo "<h2 style='color:red'>Error creating init token</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Code: " . htmlspecialchars($e->getErrorCode() ?? 'unknown') . "</p>";
    echo "<p>Request ID: " . htmlspecialchars($e->getRequestId() ?? 'n/a') . "</p>";

    if ($e->getErrorCode() === 'SECRET_KEY_REQUIRED') {
        echo "<p style='color:#b91c1c'><strong>Hint:</strong> You're using a public key (pk_...). ";
        echo "The init endpoint requires a secret key (sk_...). Check your dashboard for the secret key.</p>";
    }
}
