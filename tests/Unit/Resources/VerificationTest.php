<?php

declare(strict_types=1);

namespace Xident\SDK\Tests\Unit\Resources;

use PHPUnit\Framework\Attributes\DataProvider;
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
            'user_id' => 'usr_1',
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
            'user_id' => 'usr_1',
            'min_age' => 18,
        ]);

        $this->assertSame('xit_min', $result->token);
    }

    public function testInitValidationError(): void
    {
        $transport = new MockTransport();
        $transport->queueError(400, 'MISSING_CALLBACK_URL', 'callback_url is required');

        // Valid locally, so the request is sent and the API's 400 comes back.
        try {
            $this->client($transport)->verification()->init(['user_id' => 'usr_1', 'min_age' => 18]);
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('MISSING_CALLBACK_URL', $e->getErrorCode());
            $this->assertSame(400, $e->getHttpStatus());
            $this->assertSame(1, $transport->getRequestCount());
        }
    }

    /**
     * Data match (2026-09-05): `expected` goes on the wire as a nested object,
     * `mismatch_policy` beside it. The array is passed through as given, so an
     * integration that never heard of them sends exactly the body it sent before.
     */
    public function testInitSendsExpectedNested(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess(['token' => 'xit_dm', 'verify_url' => 'https://v.io']);

        $this->client($transport)->verification()->init([
            'callback_url' => 'https://example.com/cb',
            'user_id' => 'usr_1',
            'purpose' => 'id_verification',
            'expected' => ['first_name' => 'Jane', 'date_of_birth' => '1990-05-14', 'nationality' => 'GB'],
            'mismatch_policy' => 'review',
        ]);

        $body = json_decode($transport->getLastRequest()['body'], true);
        $this->assertSame(
            ['first_name' => 'Jane', 'date_of_birth' => '1990-05-14', 'nationality' => 'GB'],
            $body['expected']
        );
        $this->assertSame('review', $body['mismatch_policy']);
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

    // --- init() local validation: the API's rules, checked before sending ---

    /**
     * Calls init() with $params and asserts it threw a local
     * ValidationException with $code and sent nothing.
     *
     * @param array<string, mixed> $params
     */
    private function assertRefusedLocally(array $params, string $code): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess(['token' => 'xit_never', 'verify_url' => 'https://v.io?t=xit_never']);

        try {
            $this->client($transport)->verification()->init($params);
            $this->fail("expected a ValidationException with code {$code}");
        } catch (ValidationException $e) {
            $this->assertSame($code, $e->getErrorCode());
            // A local refusal: no HTTP answer, no request ID.
            $this->assertSame(0, $e->getHttpStatus());
            $this->assertNull($e->getRequestId());
        }

        $this->assertSame(0, $transport->getRequestCount(), 'no request may be sent');
    }

    /**
     * Calls init() with $params, asserts one request was sent, and returns
     * the decoded body.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function sentBody(array $params): array
    {
        return json_decode($this->sentBodyRaw($params), true);
    }

    /**
     * Calls init() with $params, asserts exactly one POST to /verify/v1/init
     * was sent, and returns the raw JSON body.
     *
     * @param array<string, mixed> $params
     */
    private function sentBodyRaw(array $params): string
    {
        $transport = new MockTransport();
        $transport->queueSuccess(['token' => 'xit_ok', 'verify_url' => 'https://v.io?t=xit_ok']);

        $this->client($transport)->verification()->init($params);

        $this->assertSame(1, $transport->getRequestCount());
        $request = $transport->getLastRequest();
        $this->assertSame('POST', $request['method']);
        $this->assertStringEndsWith('/verify/v1/init', $request['url']);

        return (string) $request['body'];
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function missingUserIdProvider(): array
    {
        return [
            'absent' => [[]],
            'null' => [['user_id' => null]],
            'empty' => [['user_id' => '']],
            'spaces only' => [['user_id' => "  \t "]],
        ];
    }

    /**
     * @param array<string, mixed> $userId
     */
    #[DataProvider('missingUserIdProvider')]
    public function testInitRefusesMissingUserId(array $userId): void
    {
        $this->assertRefusedLocally(
            ['callback_url' => 'https://example.com/cb', 'min_age' => 18] + $userId,
            'MISSING_USER_ID',
        );
    }

    public function testInitRefusesNonStringUserId(): void
    {
        $this->assertRefusedLocally(
            ['callback_url' => 'https://example.com/cb', 'min_age' => 18, 'user_id' => 42],
            'INVALID_USER_ID',
        );
    }

    public function testInitSendsUserIdExactlyAsGiven(): void
    {
        $body = $this->sentBody(['callback_url' => 'https://example.com/cb', 'user_id' => ' usr_1 ', 'min_age' => 18]);

        $this->assertSame(' usr_1 ', $body['user_id']);
    }

    /** @return array<string, array{0: mixed}> */
    public static function invalidAgeMinAgeProvider(): array
    {
        return [
            'absent' => [null],
            'zero' => [0],
            '11, below the range' => [11],
            '26, above the range' => [26],
            '99, the old maximum' => [99],
            'negative' => [-18],
            'a numeric string' => ['18'],
            'a fraction' => [18.5],
            'a whole-number float out of range' => [26.0],
            'not a number' => [NAN],
            'infinity' => [INF],
            'true' => [true],
        ];
    }

    #[DataProvider('invalidAgeMinAgeProvider')]
    public function testInitRefusesAgeVerificationMinAgeOutsideTwelveToTwentyFive(mixed $minAge): void
    {
        $params = ['callback_url' => 'https://example.com/cb', 'user_id' => 'usr_1'];
        if ($minAge !== null) {
            $params['min_age'] = $minAge;
        }

        $this->assertRefusedLocally($params, 'INVALID_MIN_AGE');
        // The explicit purpose takes the same path as the default.
        $this->assertRefusedLocally($params + ['purpose' => 'age_verification'], 'INVALID_MIN_AGE');
    }

    /** @return array<string, array{0: int}> */
    public static function validAgeMinAgeProvider(): array
    {
        return [
            '12, the floor' => [12],
            '18' => [18],
            '19, sent as 19 (the API rounds it to 21)' => [19],
            '25, the ceiling' => [25],
        ];
    }

    #[DataProvider('validAgeMinAgeProvider')]
    public function testInitSendsAgeVerificationMinAgeAsGiven(int $minAge): void
    {
        $body = $this->sentBody(['callback_url' => 'https://example.com/cb', 'user_id' => 'usr_1', 'min_age' => $minAge]);

        $this->assertSame($minAge, $body['min_age']);
    }

    /**
     * json_encode writes 18.0 as 18, which the API accepts, so a whole-number
     * float is accepted too and sent as the integer.
     */
    public function testInitSendsWholeNumberFloatMinAgeAsInteger(): void
    {
        $body = $this->sentBodyRaw(['callback_url' => 'https://example.com/cb', 'user_id' => 'usr_1', 'min_age' => 18.0]);

        $this->assertMatchesRegularExpression('/"min_age":18[,}]/', $body);
        $this->assertSame(18, json_decode($body, true)['min_age']);
    }

    public function testInitSendsIdVerificationWholeNumberFloatZeroAsInteger(): void
    {
        $body = $this->sentBodyRaw(
            ['callback_url' => 'https://example.com/cb', 'user_id' => 'usr_1', 'purpose' => 'id_verification', 'min_age' => 0.0],
        );

        $this->assertMatchesRegularExpression('/"min_age":0[,}]/', $body);
        $this->assertSame(0, json_decode($body, true)['min_age']);
    }

    /** An agent key is a server key: it builds the client and goes out as X-API-Key. */
    public function testInitWithAgentKeySendsIt(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess(['token' => 'xit_ak', 'verify_url' => 'https://verify.xident.io?t=xit_ak']);

        (new Client('ak_live_agent1', transport: $transport))->verification()->init(
            ['callback_url' => 'https://example.com/cb', 'user_id' => 'usr_1', 'min_age' => 18],
        );

        $request = $transport->getLastRequest();
        $this->assertStringEndsWith('/verify/v1/init', $request['url']);
        $this->assertContains('X-API-Key: ak_live_agent1', $request['headers']);
    }

    public function testInitRefusesIdVerificationWithMinAge(): void
    {
        $this->assertRefusedLocally(
            ['callback_url' => 'https://example.com/cb', 'user_id' => 'usr_1', 'purpose' => 'id_verification', 'min_age' => 18],
            'INVALID_MIN_AGE',
        );
    }

    public function testInitRefusesIdVerificationWithFacialMode(): void
    {
        $this->assertRefusedLocally(
            ['callback_url' => 'https://example.com/cb', 'user_id' => 'usr_1', 'purpose' => 'id_verification', 'verification_mode' => 'facial'],
            'INVALID_VERIFICATION_MODE',
        );
    }

    /** The API checks min_age before the mode, so this pair answers INVALID_MIN_AGE. */
    public function testInitIdVerificationChecksMinAgeBeforeMode(): void
    {
        $this->assertRefusedLocally(
            ['callback_url' => 'https://example.com/cb', 'user_id' => 'usr_1', 'purpose' => 'id_verification', 'min_age' => 18, 'verification_mode' => 'facial'],
            'INVALID_MIN_AGE',
        );
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function validIdVerificationProvider(): array
    {
        return [
            'no min_age' => [[]],
            'min_age 0' => [['min_age' => 0]],
            'min_age null' => [['min_age' => null]],
            'document mode' => [['verification_mode' => 'document']],
            'auto mode' => [['verification_mode' => 'auto']],
        ];
    }

    /**
     * @param array<string, mixed> $extra
     */
    #[DataProvider('validIdVerificationProvider')]
    public function testInitAcceptsIdVerificationWithoutAgeOrFacial(array $extra): void
    {
        $params = ['callback_url' => 'https://example.com/cb', 'user_id' => 'usr_1', 'purpose' => 'id_verification'] + $extra;
        $body = $this->sentBody($params);

        // The whole body survives as sent: the purpose, the user, the mode,
        // and no age the API would refuse for an ID verification.
        $this->assertSame('id_verification', $body['purpose']);
        $this->assertSame('usr_1', $body['user_id']);
        $this->assertSame('https://example.com/cb', $body['callback_url']);
        $this->assertSame($extra['verification_mode'] ?? null, $body['verification_mode'] ?? null);
        $this->assertContains($body['min_age'] ?? null, [null, 0], 'an ID verification sends no age');
        $this->assertSame(array_key_exists('min_age', $params), array_key_exists('min_age', $body));
    }

    /** facial stays allowed for an age verification. */
    public function testInitAcceptsFacialModeForAgeVerification(): void
    {
        $body = $this->sentBody(
            ['callback_url' => 'https://example.com/cb', 'user_id' => 'usr_1', 'min_age' => 21, 'verification_mode' => 'facial'],
        );

        $this->assertSame('facial', $body['verification_mode']);
        $this->assertSame(21, $body['min_age']);
        $this->assertSame('usr_1', $body['user_id']);
    }

    public function testInitSendsDocumentModeAndPurposeForAgeVerification(): void
    {
        $body = $this->sentBody([
            'callback_url' => 'https://example.com/cb',
            'user_id' => 'usr_1',
            'min_age' => 19,
            'purpose' => 'age_verification',
            'verification_mode' => 'document',
        ]);

        $this->assertSame(
            ['callback_url' => 'https://example.com/cb', 'user_id' => 'usr_1', 'min_age' => 19, 'purpose' => 'age_verification', 'verification_mode' => 'document'],
            $body,
        );
    }

    // --- getResult() ---

    public function testGetResultReturnsSessionResult(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess([
            'token' => 'xtk_abc',
            'status' => 'success',
            'verified' => true,
            'verification_type' => 'full',
            'checks' => [
                'liveness' => ['performed' => true, 'passed' => true],
                'age' => ['performed' => true, 'passed' => true, 'gate' => 18],
                'document' => ['performed' => false, 'passed' => false],
                'face_match' => ['performed' => false, 'passed' => false],
            ],
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
        $this->assertSame('full', $result->method());
    }

    /**
     * An id_verification session has no age threshold, so its result carries
     * no `checks.age.gate`. A passed document still proves the age check, so
     * `passed` is true with no gate. ageBracket() must not answer 0.
     */
    public function testGetResultIdVerificationWithoutGate(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess([
            'token' => 'xtk_id',
            'status' => 'success',
            'verified' => true,
            'verification_type' => 'full',
            'checks' => [
                'liveness' => ['performed' => true, 'passed' => true],
                'age' => ['performed' => true, 'passed' => true],
                'document' => ['performed' => true, 'passed' => true, 'document_type' => 'passport', 'country' => 'DE'],
                'face_match' => ['performed' => true, 'passed' => true],
            ],
            'created_at' => '2026-10-05T12:00:00Z',
        ]);

        $result = $this->client($transport)->verification()->getResult('xtk_id');

        $this->assertTrue($result->isVerified());
        $this->assertTrue($result->checks->age->performed);
        $this->assertTrue($result->checks->age->passed);
        $this->assertSame(0, $result->checks->age->gate);
        $this->assertNull($result->ageBracket());
        $this->assertTrue($result->checks->document->passed);
        $this->assertTrue($result->checks->faceMatch->passed);
    }

    public function testGetResultSendsGetRequest(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess(['token' => 'xtk_x', 'status' => 'pending']);

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
        ]);

        $result = $this->client($transport)->verification()->getResult('xtk_p');

        $this->assertTrue($result->isPending());
        $this->assertFalse($result->isVerified());
        $this->assertFalse($result->isTerminal());
    }

    public function testGetResultFailedSession(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess([
            'token' => 'xtk_f',
            'status' => 'failed',
            'reason' => 'age_below_threshold',
            'created_at' => '2026-03-23T12:00:00Z',
        ]);

        $result = $this->client($transport)->verification()->getResult('xtk_f');

        $this->assertTrue($result->isFailed());
        $this->assertFalse($result->isVerified());
        $this->assertTrue($result->isTerminal());
        $this->assertSame('age_below_threshold', $result->reason);
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
