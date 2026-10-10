<?php

declare(strict_types=1);

namespace Xident\SDK\Tests\Unit\Responses;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Xident\SDK\Client;
use Xident\SDK\Responses\SessionResult;

/**
 * provesAge(): the check a callback needs on top of "verified".
 *
 * Fixtures (tests/Fixtures/):
 *  - tenant_result_v1.golden.json: the API's golden tenant result
 *    (api internal/domain/services/testdata/tenant_result_v1.golden.json),
 *    a passed session with `checks.age.gate` 21.
 *  - tenant_result_v1_id_no_gate.json: the same result as an
 *    `id_verification` returns it since api#44: the document proved a date of
 *    birth, so `checks.age.passed` is true, but the session had no age
 *    threshold, so `checks.age.gate` is absent.
 *  - tenant_result_v1_xident_id_reuse.json: a passed Xident ID reuse, built
 *    from the API code (api PR #45 at c2d6890: account_reuse.go completes the
 *    session with kind xident_id; session_result_view.go buildResultChecks
 *    sets the gate from the session's min_age and performed/passed only from
 *    an age result or a document date of birth). `checks.age.gate` is 21,
 *    `checks.age.performed` and `passed` are false: this session captured no
 *    new evidence.
 *  - tenant_result_v1_eu_wallet.json: an EU Digital Identity Wallet
 *    presentation (api c2d6890, wallet.go stores only the wallet result):
 *    verification_type eu_wallet, checks.eu_wallet passed, checks.age gate 21
 *    with performed and passed false.
 *  - tenant_result_v1_test_mode.json: a test-key settle (api c2d6890,
 *    requirements.go settleTestSession): reason test_mode, every check
 *    performed and passed false, gate 21, `test: true`.
 */
final class ProvesAgeTest extends TestCase
{
    private const AGE_FIXTURE = __DIR__ . '/../../Fixtures/tenant_result_v1.golden.json';
    private const ID_FIXTURE = __DIR__ . '/../../Fixtures/tenant_result_v1_id_no_gate.json';
    private const REUSE_FIXTURE = __DIR__ . '/../../Fixtures/tenant_result_v1_xident_id_reuse.json';
    private const WALLET_FIXTURE = __DIR__ . '/../../Fixtures/tenant_result_v1_eu_wallet.json';
    private const TEST_FIXTURE = __DIR__ . '/../../Fixtures/tenant_result_v1_test_mode.json';

    /** @return array<string, mixed> */
    private static function fixture(string $path): array
    {
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /** @return array<string, array{0: int, 1: bool}> */
    public static function requiredAgeProvider(): array
    {
        return [
            'a 12+ page' => [12, true],
            'an 18+ page' => [18, true],
            'a 19+ page (the gate 21 covers it)' => [19, true],
            'a 21+ page' => [21, true],
            'a 25+ page: the 21 result is not enough' => [25, false],
        ];
    }

    #[DataProvider('requiredAgeProvider')]
    public function testAgeResultProvesOnlyAgesUpToItsGate(int $required, bool $proves): void
    {
        $result = SessionResult::fromArray(self::fixture(self::AGE_FIXTURE));

        $this->assertSame(21, $result->checks->age->gate);
        $this->assertSame($proves, $result->provesAge($required));
    }

    #[DataProvider('requiredAgeProvider')]
    public function testXidentIdReuseProvesItsGateWithoutAnAgeCheckInThisSession(int $required, bool $proves): void
    {
        $result = SessionResult::fromArray(self::fixture(self::REUSE_FIXTURE));

        $this->assertSame('xident_id', $result->verificationType);
        $this->assertFalse($result->checks->age->performed);
        $this->assertFalse($result->checks->age->passed);
        $this->assertSame(21, $result->checks->age->gate);
        $this->assertSame($proves, $result->provesAge($required));
        $this->assertSame(21, $result->ageBracket());
    }

    public function testIdOnlyResultProvesNoAge(): void
    {
        $result = SessionResult::fromArray(self::fixture(self::ID_FIXTURE));

        // The ID verification passed, and its age check passed from the
        // document. Without a gate it is still not an age verification.
        $this->assertTrue($result->isVerified());
        $this->assertTrue($result->checks->age->passed);
        $this->assertSame(0, $result->checks->age->gate);
        $this->assertFalse($result->provesAge(12));
        $this->assertFalse($result->provesAge(0));
        $this->assertNull($result->ageBracket());
    }

    /** @return array<string, array{0: callable(array<string, mixed>): array<string, mixed>}> */
    public static function notProvenProvider(): array
    {
        return [
            'status failed' => [static function (array $d): array {
                $d['status'] = 'failed';
                $d['verified'] = false;
                return $d;
            }],
            'status success but verified false' => [static function (array $d): array {
                $d['verified'] = false;
                return $d;
            }],
            'verified but the gate is 0' => [static function (array $d): array {
                $d['checks']['age']['gate'] = 0;
                return $d;
            }],
            'verified but no gate at all' => [static function (array $d): array {
                unset($d['checks']['age']['gate']);
                return $d;
            }],
            'pending' => [static function (array $d): array {
                $d['status'] = 'pending';
                $d['verified'] = false;
                return $d;
            }],
        ];
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $change
     */
    #[DataProvider('notProvenProvider')]
    public function testOnlyAPassedSessionWithAGateProvesAnAge(callable $change): void
    {
        $result = SessionResult::fromArray($change(self::fixture(self::AGE_FIXTURE)));

        $this->assertFalse($result->provesAge(18));
    }

    public function testTestKeyResultIsRefusedUnlessTheCallerOptsIn(): void
    {
        $result = SessionResult::fromArray(self::fixture(self::TEST_FIXTURE));

        $this->assertTrue($result->test);
        $this->assertTrue($result->isVerified());
        $this->assertSame(21, $result->checks->age->gate);
        $this->assertFalse($result->provesAge(18));
        $this->assertFalse($result->provesAge(18, false));
        $this->assertTrue($result->provesAge(18, allowTest: true));
        $this->assertTrue($result->provesAge(21, allowTest: true));
        $this->assertFalse($result->provesAge(25, allowTest: true));
        $this->assertNull($result->ageBracket());

        // Even with a passed age check, a test-key result gives no bracket.
        $passed = self::fixture(self::AGE_FIXTURE);
        $passed['test'] = true;
        $this->assertNull(SessionResult::fromArray($passed)->ageBracket());
        $this->assertFalse(SessionResult::fromArray($passed)->provesAge(18));
    }

    public function testTestIsReadOnlyFromABooleanTrue(): void
    {
        $this->assertFalse(SessionResult::fromArray(self::fixture(self::AGE_FIXTURE))->test);
        $notBool = self::fixture(self::AGE_FIXTURE);
        $notBool['test'] = 'true';
        $this->assertFalse(SessionResult::fromArray($notBool)->test);
    }

    public function testOptInNeverMakesAnIdOnlyResultProveAnAge(): void
    {
        $id = self::fixture(self::ID_FIXTURE);
        $id['test'] = true;

        $this->assertFalse(SessionResult::fromArray($id)->provesAge(12, allowTest: true));
        $this->assertFalse(SessionResult::fromArray(self::fixture(self::ID_FIXTURE))->provesAge(12, allowTest: true));
    }

    /**
     * The five result shapes the API returns, side by side: what provesAge
     * answers by default and with the test opt-in, for 18, 21 and 25.
     *
     * @return array<string, array{0: string, 1: array<int, bool>, 2: array<int, bool>}>
     */
    public static function shapeProvider(): array
    {
        $upTo21 = [18 => true, 21 => true, 25 => false];
        $never = [18 => false, 21 => false, 25 => false];

        return [
            'document (golden)' => [self::AGE_FIXTURE, $upTo21, $upTo21],
            'ID only, no gate' => [self::ID_FIXTURE, $never, $never],
            'Xident ID reuse' => [self::REUSE_FIXTURE, $upTo21, $upTo21],
            'EU wallet' => [self::WALLET_FIXTURE, $upTo21, $upTo21],
            'test key' => [self::TEST_FIXTURE, $never, $upTo21],
        ];
    }

    /**
     * @param array<int, bool> $byDefault
     * @param array<int, bool> $withOptIn
     */
    #[DataProvider('shapeProvider')]
    public function testEveryResultShapeAsResultAndAsWebhookData(string $path, array $byDefault, array $withOptIn): void
    {
        $secret = 'whsec_shapes';
        $client = new Client('sk_test_123', transport: static fn () => throw new \LogicException('no request expected'));
        $payload = json_encode([
            'id' => 'evt_0001',
            'type' => 'session.success',
            'api_version' => '2026-08-13',
            'created' => 1785751350,
            'data' => self::fixture($path),
        ], JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        $event = $client->webhooks()->constructEvent($payload, $signature, $secret);

        foreach ([SessionResult::fromArray(self::fixture($path)), SessionResult::fromArray($event['data'])] as $result) {
            $this->assertSame('cust-4711', $result->externalUserId);
            foreach ($byDefault as $age => $want) {
                $this->assertSame($want, $result->provesAge($age), basename($path) . " {$age}");
            }
            foreach ($withOptIn as $age => $want) {
                $this->assertSame($want, $result->provesAge($age, allowTest: true), basename($path) . " {$age} allowTest");
            }
        }
    }

    /**
     * The same rule on a real webhook: the envelope the API sends
     * (models.WebhookEventPayload: id, type, api_version, created, data),
     * whose `data` is the same tenant result.
     */
    public function testWebhookDataProvesAgeLikeTheResult(): void
    {
        $secret = 'whsec_test';
        $client = new Client('sk_test_123', transport: static fn () => throw new \LogicException('no request expected'));

        foreach ([[self::AGE_FIXTURE, true], [self::ID_FIXTURE, false], [self::REUSE_FIXTURE, true]] as [$path, $proves]) {
            $payload = json_encode([
                'id' => 'evt_0001',
                'type' => 'session.success',
                'api_version' => '2026-08-13',
                'created' => 1785751350,
                'data' => self::fixture($path),
            ], JSON_THROW_ON_ERROR);
            $timestamp = time();
            $signature = 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

            $event = $client->webhooks()->constructEvent($payload, $signature, $secret);
            $result = SessionResult::fromArray($event['data']);

            $this->assertSame('cust-4711', $result->externalUserId);
            $this->assertSame($proves, $result->provesAge(18), basename($path));
        }
    }
}
