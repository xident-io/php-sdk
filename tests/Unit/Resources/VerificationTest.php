<?php

declare(strict_types=1);

namespace Xident\SDK\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;
use Xident\SDK\Client;
use Xident\SDK\Exceptions\NotFoundException;
use Xident\SDK\Exceptions\ValidationException;
use Xident\SDK\Responses\InitResult;
use Xident\SDK\Responses\SessionResult;
use Xident\SDK\Tests\Helpers\MockTransport;

final class VerificationTest extends TestCase
{
    private function client(MockTransport $transport): Client
    {
        return new Client('sk_test_123', transport: $transport);
    }

    // --- init() ---

    public function testInitReturnsInitResult(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess([
            'token' => 'xit_abc123',
            'verify_url' => 'https://verify.xident.io?t=xit_abc123',
        ]);

        $result = $this->client($transport)->verification()->init([
            'callback_url' => 'https://example.com/cb',
            'min_age' => 18,
        ]);

        $this->assertInstanceOf(InitResult::class, $result);
        $this->assertSame('xit_abc123', $result->token);
        $this->assertSame('https://verify.xident.io?t=xit_abc123', $result->verifyUrl);
    }

    public function testInitSendsPostRequest(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess(['token' => 'xit_x', 'verify_url' => 'https://v.io?t=xit_x']);

        $this->client($transport)->verification()->init([
            'callback_url' => 'https://example.com/cb',
            'min_age' => 21,
            'success_url' => 'https://example.com/ok',
            'user_id' => 'usr_123',
            'theme' => 'dark',
            'locale' => 'de',
        ]);

        $req = $transport->getLastRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertStringContainsString('/init', $req['url']);

        $body = json_decode($req['body'], true);
        $this->assertSame('https://example.com/cb', $body['callback_url']);
        $this->assertSame(21, $body['min_age']);
        $this->assertSame('dark', $body['theme']);
    }

    public function testInitWithMinimalParams(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess(['token' => 'xit_min', 'verify_url' => 'https://v.io?t=xit_min']);

        $result = $this->client($transport)->verification()->init([
            'callback_url' => 'https://example.com/cb',
        ]);

        $this->assertSame('xit_min', $result->token);
    }

    public function testInitValidationError(): void
    {
        $transport = new MockTransport();
        $transport->queueError(400, 'MISSING_CALLBACK_URL', 'callback_url is required');

        $this->expectException(ValidationException::class);
        $this->client($transport)->verification()->init([]);
    }

    public function testInitWithAllParams(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess(['token' => 'xit_all', 'verify_url' => 'https://v.io?t=xit_all']);

        $this->client($transport)->verification()->init([
            'callback_url' => 'https://example.com/cb',
            'min_age' => 18,
            'success_url' => 'https://example.com/success',
            'failed_url' => 'https://example.com/failed',
            'user_id' => 'usr_456',
            'theme' => 'system',
            'locale' => 'en',
            'metadata' => '{"plan":"pro"}',
            'purpose' => 'age_verification',
        ]);

        $body = json_decode($transport->getLastRequest()['body'], true);
        $this->assertSame('https://example.com/success', $body['success_url']);
        $this->assertSame('https://example.com/failed', $body['failed_url']);
        $this->assertSame('system', $body['theme']);
        $this->assertSame('{"plan":"pro"}', $body['metadata']);
        $this->assertSame('age_verification', $body['purpose']);
    }

    // --- getResult() ---

    public function testGetResultReturnsSessionResult(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess([
            'token' => 'xtk_abc',
            'status' => 'completed',
            'age_result' => ['verified_bracket' => 18, 'method' => 'ml_fast', 'confidence' => 0.95],
            'liveness_result' => ['passed' => true],
            'country_code' => 'US',
            'created_at' => '2026-03-23T12:00:00Z',
            'expires_at' => '2026-03-23T12:10:00Z',
        ]);

        $result = $this->client($transport)->verification()->getResult('xtk_abc');

        $this->assertInstanceOf(SessionResult::class, $result);
        $this->assertSame('xtk_abc', $result->token);
        $this->assertTrue($result->isVerified());
        $this->assertTrue($result->isCompleted());
        $this->assertFalse($result->isPending());
        $this->assertTrue($result->isTerminal());
        $this->assertSame(18, $result->ageBracket());
        $this->assertSame('ml_fast', $result->method());
    }

    public function testGetResultSendsGetRequest(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess(['token' => 'xtk_x', 'status' => 'pending', 'created_at' => '2026-01-01']);

        $this->client($transport)->verification()->getResult('xtk_x');

        $req = $transport->getLastRequest();
        $this->assertSame('GET', $req['method']);
        $this->assertStringContainsString('/result/xtk_x', $req['url']);
    }

    public function testGetResultNotFound(): void
    {
        $transport = new MockTransport();
        $transport->queueError(404, 'NOT_FOUND', 'Session not found');

        $this->expectException(NotFoundException::class);
        $this->client($transport)->verification()->getResult('nonexistent');
    }

    public function testGetResultEmptyTokenThrows(): void
    {
        $transport = new MockTransport();
        $client = $this->client($transport);

        $this->expectException(\InvalidArgumentException::class);
        $client->verification()->getResult('');
    }

    public function testGetResultPendingSession(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess([
            'token' => 'xtk_p',
            'status' => 'in_progress',
            'created_at' => '2026-03-23T12:00:00Z',
            'remaining_attempts' => 3,
        ]);

        $result = $this->client($transport)->verification()->getResult('xtk_p');

        $this->assertTrue($result->isPending());
        $this->assertFalse($result->isVerified());
        $this->assertFalse($result->isTerminal());
        $this->assertSame(3, $result->remainingAttempts);
    }

    public function testGetResultFailedSession(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess([
            'id' => 'sess_f',
            'status' => 'failed',
            'created_at' => '2026-03-23T12:00:00Z',
        ]);

        $result = $this->client($transport)->verification()->getResult('sess_f');

        $this->assertTrue($result->isFailed());
        $this->assertFalse($result->isVerified());
        $this->assertTrue($result->isTerminal());
    }

    public function testGetResultCanceledSession(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess([
            'id' => 'sess_c',
            'status' => 'canceled',
            'created_at' => '2026-03-23T12:00:00Z',
        ]);

        $result = $this->client($transport)->verification()->getResult('sess_c');

        $this->assertFalse($result->isVerified());
        $this->assertTrue($result->isTerminal());
    }
}
