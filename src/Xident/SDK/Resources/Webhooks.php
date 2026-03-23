<?php

declare(strict_types=1);

namespace Xident\SDK\Resources;

use Xident\SDK\Exceptions\ValidationException;

/**
 * Webhooks resource — verify webhook signatures and parse events.
 *
 * Xident sends webhook events to your configured callback URL when
 * verification sessions are completed, failed, or expire.
 *
 * Signature format (Stripe-style):
 *   X-Xident-Signature: t=1710345600,v1=5257a869abcdef...
 */
final class Webhooks
{
    /**
     * Verify an incoming webhook signature and parse the event.
     *
     * This is a convenience method that combines verifySignature() + parseEvent().
     *
     * @param string $payload   Raw JSON body (file_get_contents('php://input'))
     * @param string $signature Value of X-Xident-Signature header
     * @param string $secret    Webhook signing secret from dashboard (whsec_xxx)
     * @param int    $tolerance Maximum age in seconds (default 300 = 5 minutes)
     *
     * @return array{type: string, data: array<string, mixed>, id?: string, created?: int}
     *
     * @throws ValidationException If signature is invalid or too old
     */
    public function constructEvent(
        string $payload,
        string $signature,
        string $secret,
        int $tolerance = 300,
    ): array {
        $this->verifySignature($payload, $signature, $secret, $tolerance);
        return $this->parseEvent($payload);
    }

    /**
     * Verify a webhook signature using HMAC-SHA256.
     *
     * @param string $payload   Raw JSON body
     * @param string $signature Value of X-Xident-Signature header
     * @param string $secret    Webhook signing secret
     * @param int    $tolerance Maximum age in seconds (0 = no replay protection)
     *
     * @throws ValidationException If signature is invalid, malformed, or too old
     */
    public function verifySignature(
        string $payload,
        string $signature,
        string $secret,
        int $tolerance = 300,
    ): bool {
        if ($signature === '') {
            throw new ValidationException('Missing webhook signature', 'MISSING_SIGNATURE');
        }
        if ($secret === '') {
            throw new ValidationException('Missing webhook secret', 'MISSING_SECRET');
        }

        // Parse "t=TIMESTAMP,v1=HMAC_HEX"
        $parts = [];
        foreach (explode(',', $signature) as $pair) {
            $kv = explode('=', $pair, 2);
            if (count($kv) === 2) {
                $parts[$kv[0]] = $kv[1];
            }
        }

        if (!isset($parts['t'], $parts['v1'])) {
            throw new ValidationException(
                'Invalid signature format — expected t=TIMESTAMP,v1=HMAC',
                'INVALID_SIGNATURE_FORMAT',
            );
        }

        $timestamp = (int)$parts['t'];
        $expectedSig = $parts['v1'];

        // Replay protection
        if ($tolerance > 0) {
            $age = time() - $timestamp;
            if ($age > $tolerance) {
                throw new ValidationException(
                    sprintf('Webhook timestamp too old (%d seconds, tolerance %d)', $age, $tolerance),
                    'SIGNATURE_EXPIRED',
                );
            }
        }

        // Compute expected HMAC — matches Go: timestamp + "." + payload
        $signedPayload = $timestamp . '.' . $payload;
        $computed = hash_hmac('sha256', $signedPayload, $secret);

        // Constant-time comparison to prevent timing attacks
        if (!hash_equals($computed, $expectedSig)) {
            throw new ValidationException(
                'Webhook signature verification failed',
                'INVALID_SIGNATURE',
            );
        }

        return true;
    }

    /**
     * Parse a webhook event body.
     *
     * @return array{type: string, data: array<string, mixed>, id?: string, created?: int}
     *
     * @throws ValidationException If payload is not valid JSON
     */
    public function parseEvent(string $payload): array
    {
        $decoded = json_decode($payload, true);

        if (!is_array($decoded)) {
            throw new ValidationException('Invalid webhook payload — not valid JSON', 'INVALID_PAYLOAD');
        }

        return [
            'type'    => (string)($decoded['type'] ?? $decoded['event_type'] ?? ''),
            'data'    => (array)($decoded['data'] ?? $decoded),
            'id'      => $decoded['id'] ?? $decoded['event_id'] ?? null,
            'created' => isset($decoded['created']) ? (int)$decoded['created'] : null,
        ];
    }
}
