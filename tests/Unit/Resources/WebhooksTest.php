<?php

declare(strict_types=1);

namespace Xident\SDK\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;
use Xident\SDK\Exceptions\ValidationException;
use Xident\SDK\Resources\Webhooks;

final class WebhooksTest extends TestCase
{
    private Webhooks $webhooks;
    private string $secret = 'whsec_test_secret_key';

    protected function setUp(): void
    {
        $this->webhooks = new Webhooks();
    }

    private function makeSignature(string $payload, string $secret, ?int $timestamp = null): string
    {
        $ts = $timestamp ?? time();
        $signed = $ts . '.' . $payload;
        $hmac = hash_hmac('sha256', $signed, $secret);
        return "t={$ts},v1={$hmac}";
    }

    // --- verifySignature() ---

    public function testValidSignature(): void
    {
        $payload = '{"type":"session.completed","data":{"session_id":"abc"}}';
        $signature = $this->makeSignature($payload, $this->secret);

        $result = $this->webhooks->verifySignature($payload, $signature, $this->secret);
        $this->assertTrue($result);
    }

    public function testInvalidSignatureThrows(): void
    {
        $payload = '{"type":"session.completed"}';
        $signature = 't=' . time() . ',v1=invalid_hmac_hex';

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('signature verification failed');
        $this->webhooks->verifySignature($payload, $signature, $this->secret);
    }

    public function testExpiredTimestampThrows(): void
    {
        $payload = '{"type":"session.completed"}';
        $oldTimestamp = time() - 600; // 10 minutes ago
        $signature = $this->makeSignature($payload, $this->secret, $oldTimestamp);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('too old');
        $this->webhooks->verifySignature($payload, $signature, $this->secret, tolerance: 300);
    }

    public function testToleranceZeroDisablesReplayProtection(): void
    {
        $payload = '{"type":"session.completed"}';
        $oldTimestamp = time() - 86400; // 24 hours ago
        $signature = $this->makeSignature($payload, $this->secret, $oldTimestamp);

        $result = $this->webhooks->verifySignature($payload, $signature, $this->secret, tolerance: 0);
        $this->assertTrue($result);
    }

    public function testMissingSignatureThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Missing webhook signature');
        $this->webhooks->verifySignature('{}', '', $this->secret);
    }

    public function testMissingSecretThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Missing webhook secret');
        $this->webhooks->verifySignature('{}', 't=1,v1=abc', '');
    }

    public function testMalformedSignatureThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid signature format');
        $this->webhooks->verifySignature('{}', 'not-a-valid-sig', $this->secret);
    }

    public function testMissingTimestampComponent(): void
    {
        $this->expectException(ValidationException::class);
        $this->webhooks->verifySignature('{}', 'v1=abc123', $this->secret);
    }

    public function testMissingHmacComponent(): void
    {
        $this->expectException(ValidationException::class);
        $this->webhooks->verifySignature('{}', 't=12345', $this->secret);
    }

    // --- constructEvent() ---

    public function testConstructEventReturnsEvent(): void
    {
        $payload = json_encode([
            'type' => 'session.completed',
            'data' => ['session_id' => 'sess_abc', 'status' => 'completed'],
            'id' => 'evt_001',
            'created' => 1710345600,
        ]);
        $signature = $this->makeSignature($payload, $this->secret);

        $event = $this->webhooks->constructEvent($payload, $signature, $this->secret);

        $this->assertSame('session.completed', $event['type']);
        $this->assertSame('sess_abc', $event['data']['session_id']);
        $this->assertSame('evt_001', $event['id']);
        $this->assertSame(1710345600, $event['created']);
    }

    // --- parseEvent() ---

    public function testParseEventValidJson(): void
    {
        $event = $this->webhooks->parseEvent('{"type":"session.failed","data":{"reason":"timeout"}}');

        $this->assertSame('session.failed', $event['type']);
        $this->assertSame(['reason' => 'timeout'], $event['data']);
    }

    public function testParseEventInvalidJsonThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('not valid JSON');
        $this->webhooks->parseEvent('not json');
    }

    public function testParseEventWithEventTypeField(): void
    {
        $event = $this->webhooks->parseEvent('{"event_type":"session.expired","data":{}}');
        $this->assertSame('session.expired', $event['type']);
    }
}
