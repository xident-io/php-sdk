<?php

declare(strict_types=1);

namespace Xident\SDK\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;
use Xident\SDK\Client;
use Xident\SDK\Exceptions\AuthenticationException;
use Xident\SDK\Exceptions\NotFoundException;
use Xident\SDK\Exceptions\ValidationException;
use Xident\SDK\Responses\Face2FAChallenge;
use Xident\SDK\Responses\Face2FAEnrollment;
use Xident\SDK\Responses\Face2FAStatus;
use Xident\SDK\Tests\Helpers\MockTransport;

final class Face2FATest extends TestCase
{
    private const IMAGE = 'aGVsbG8='; // any base64 payload

    private function client(MockTransport $transport): Client
    {
        return new Client('sk_test_123', transport: $transport);
    }

    // --- register() ---

    public function testRegisterReturnsChallenge(): void
    {
        $transport = new MockTransport();
        // The API returns 201 Created for submissions — the SDK keys off the
        // envelope's `success`, not the 2xx code, so pin that behavior here.
        $transport->queueResponse(201, [
            'success' => true,
            'data' => ['challenge_id' => 'ch_abc123', 'status' => 'processing'],
        ]);

        $challenge = $this->client($transport)->face2fa()->register('usr_1', self::IMAGE);

        $this->assertInstanceOf(Face2FAChallenge::class, $challenge);
        $this->assertSame('ch_abc123', $challenge->challengeId);
        $this->assertSame('processing', $challenge->status);
        $this->assertTrue($challenge->isProcessing());
    }

    public function testRegisterSendsPostRequestWithBody(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess(['challenge_id' => 'ch_x', 'status' => 'processing']);

        $this->client($transport)->face2fa()->register('usr_42', self::IMAGE);

        $req = $transport->getLastRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertStringContainsString('/verify/v1/2fa/register', $req['url']);

        $body = json_decode($req['body'], true);
        $this->assertSame(['user_id' => 'usr_42', 'image' => self::IMAGE], $body);
    }

    public function testRegisterEmptyUserIdThrows(): void
    {
        $client = $this->client(new MockTransport());

        $this->expectException(\InvalidArgumentException::class);
        $client->face2fa()->register('', self::IMAGE);
    }

    public function testRegisterEmptyImageThrows(): void
    {
        $client = $this->client(new MockTransport());

        $this->expectException(\InvalidArgumentException::class);
        $client->face2fa()->register('usr_1', '');
    }

    public function testRegisterValidationError(): void
    {
        $transport = new MockTransport();
        $transport->queueError(400, 'VALIDATION_FAILED', 'image exceeds maximum size');

        $this->expectException(ValidationException::class);
        $this->client($transport)->face2fa()->register('usr_1', self::IMAGE);
    }

    public function testRegisterUnauthorized(): void
    {
        $transport = new MockTransport();
        $transport->queueError(401, 'UNAUTHORIZED', 'not authenticated');

        $this->expectException(AuthenticationException::class);
        $this->client($transport)->face2fa()->register('usr_1', self::IMAGE);
    }

    // --- verify() ---

    public function testVerifySendsPostToVerifyPath(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess(['challenge_id' => 'ch_v', 'status' => 'processing']);

        $challenge = $this->client($transport)->face2fa()->verify('usr_42', self::IMAGE);

        $req = $transport->getLastRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertStringContainsString('/verify/v1/2fa/verify', $req['url']);

        $body = json_decode($req['body'], true);
        $this->assertSame(['user_id' => 'usr_42', 'image' => self::IMAGE], $body);

        $this->assertSame('ch_v', $challenge->challengeId);
        $this->assertTrue($challenge->isProcessing());
    }

    public function testVerifyEmptyUserIdThrows(): void
    {
        $client = $this->client(new MockTransport());

        $this->expectException(\InvalidArgumentException::class);
        $client->face2fa()->verify('', self::IMAGE);
    }

    public function testVerifyEmptyImageThrows(): void
    {
        $client = $this->client(new MockTransport());

        $this->expectException(\InvalidArgumentException::class);
        $client->face2fa()->verify('usr_1', '');
    }

    // --- getStatus() ---

    public function testGetStatusProcessing(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess([
            'challenge_id' => 'ch_p',
            'kind' => 'verify',
            'status' => 'processing',
            'passed' => null,
            'expires_at' => '2026-08-02T12:05:00Z',
        ]);

        $status = $this->client($transport)->face2fa()->getStatus('ch_p');

        $this->assertInstanceOf(Face2FAStatus::class, $status);
        $this->assertSame('ch_p', $status->challengeId);
        $this->assertSame('verify', $status->kind);
        $this->assertNull($status->passed);
        $this->assertTrue($status->isProcessing());
        $this->assertFalse($status->isTerminal());
        $this->assertFalse($status->hasPassed());
        $this->assertNull($status->completedAt);
    }

    public function testGetStatusCompletedPassed(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess([
            'challenge_id' => 'ch_ok',
            'kind' => 'enroll',
            'status' => 'completed',
            'passed' => true,
            'expires_at' => '2026-08-02T12:05:00Z',
            'completed_at' => '2026-08-02T12:01:30Z',
        ]);

        $status = $this->client($transport)->face2fa()->getStatus('ch_ok');

        $this->assertTrue($status->passed);
        $this->assertTrue($status->hasPassed());
        $this->assertTrue($status->isTerminal());
        $this->assertFalse($status->isProcessing());
        $this->assertNull($status->failureReason);
        $this->assertSame('2026-08-02T12:01:30Z', $status->completedAt);
    }

    public function testGetStatusFailedWithReason(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess([
            'challenge_id' => 'ch_f',
            'kind' => 'verify',
            'status' => 'failed',
            'passed' => false,
            'failure_reason' => 'face_mismatch',
            'expires_at' => '2026-08-02T12:05:00Z',
            'completed_at' => '2026-08-02T12:01:30Z',
        ]);

        $status = $this->client($transport)->face2fa()->getStatus('ch_f');

        $this->assertFalse($status->passed);
        $this->assertFalse($status->hasPassed());
        $this->assertTrue($status->isTerminal());
        $this->assertSame('face_mismatch', $status->failureReason);
    }

    public function testGetStatusSendsGetRequest(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess([
            'challenge_id' => 'ch_g',
            'kind' => 'verify',
            'status' => 'processing',
            'passed' => null,
            'expires_at' => '2026-08-02T12:05:00Z',
        ]);

        $this->client($transport)->face2fa()->getStatus('ch_g');

        $req = $transport->getLastRequest();
        $this->assertSame('GET', $req['method']);
        $this->assertStringContainsString('/verify/v1/2fa/status/ch_g', $req['url']);
    }

    public function testGetStatusNotFound(): void
    {
        $transport = new MockTransport();
        $transport->queueError(404, 'NOT_FOUND', 'challenge not found');

        $this->expectException(NotFoundException::class);
        $this->client($transport)->face2fa()->getStatus('ch_missing');
    }

    public function testGetStatusEmptyIdThrows(): void
    {
        $client = $this->client(new MockTransport());

        $this->expectException(\InvalidArgumentException::class);
        $client->face2fa()->getStatus('');
    }

    // --- getUser() ---

    public function testGetUserEnrolled(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess([
            'enrolled' => true,
            'enrolled_at' => '2026-07-01T09:00:00Z',
        ]);

        $enrollment = $this->client($transport)->face2fa()->getUser('usr_1');

        $this->assertInstanceOf(Face2FAEnrollment::class, $enrollment);
        $this->assertTrue($enrollment->enrolled);
        $this->assertSame('2026-07-01T09:00:00Z', $enrollment->enrolledAt);
    }

    public function testGetUserNotEnrolled(): void
    {
        $transport = new MockTransport();
        // enrolled_at is omitted entirely when not enrolled
        $transport->queueSuccess(['enrolled' => false]);

        $enrollment = $this->client($transport)->face2fa()->getUser('usr_2');

        $this->assertFalse($enrollment->enrolled);
        $this->assertNull($enrollment->enrolledAt);
    }

    public function testGetUserUrlEncodesUserId(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess(['enrolled' => false]);

        $this->client($transport)->face2fa()->getUser('user/4 2');

        $req = $transport->getLastRequest();
        $this->assertSame('GET', $req['method']);
        $this->assertStringContainsString('/verify/v1/2fa/users/user%2F4+2', $req['url']);
    }

    public function testGetUserEmptyThrows(): void
    {
        $client = $this->client(new MockTransport());

        $this->expectException(\InvalidArgumentException::class);
        $client->face2fa()->getUser('');
    }

    // --- deleteUser() ---

    public function testDeleteUserReturnsTrue(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess(['deleted' => true]);

        $deleted = $this->client($transport)->face2fa()->deleteUser('usr_1');

        $this->assertTrue($deleted);

        $req = $transport->getLastRequest();
        $this->assertSame('DELETE', $req['method']);
        $this->assertStringContainsString('/verify/v1/2fa/users/usr_1', $req['url']);
        $this->assertNull($req['body']);
    }

    public function testDeleteUserEmptyThrows(): void
    {
        $client = $this->client(new MockTransport());

        $this->expectException(\InvalidArgumentException::class);
        $client->face2fa()->deleteUser('');
    }
}
