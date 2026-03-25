<?php
/**
 * Xident PHP SDK — Webhook Handler Example
 *
 * Receives and verifies webhook events from Xident.
 * Configure your webhook URL in the Xident dashboard.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Xident\SDK\Client;
use Xident\SDK\Exceptions\ValidationException;

$xident = new Client(
    apiKey: getenv('XIDENT_SECRET_KEY') ?: 'sk_test_xxx',
);

// Read raw request body and signature header
$payload   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_XIDENT_SIGNATURE'] ?? '';

try {
    // Verify signature and parse event in one call
    $event = $xident->webhooks()->constructEvent(
        payload:   $payload,
        signature: $signature,
        secret:    getenv('XIDENT_WEBHOOK_SECRET') ?: 'whsec_xxx',
        tolerance: 300, // Reject events older than 5 minutes
    );

    // Handle the event
    switch ($event['type']) {
        case 'session.completed':
            $token  = $event['data']['token'] ?? '';
            $status = $event['data']['status'] ?? '';
            // Update your database, grant access, etc.
            error_log("Verification {$token} completed with status: {$status}");
            break;

        case 'session.failed':
            $token = $event['data']['token'] ?? '';
            error_log("Verification {$token} failed");
            break;

        case 'session.expired':
            // Session timed out before user completed verification
            break;

        default:
            error_log("Unhandled webhook event type: " . $event['type']);
    }

    // Always return 200 to acknowledge receipt
    http_response_code(200);
    echo json_encode(['received' => true]);

} catch (ValidationException $e) {
    // Signature verification failed — reject the request
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
