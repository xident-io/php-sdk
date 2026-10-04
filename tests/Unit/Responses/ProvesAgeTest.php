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
 */
final class ProvesAgeTest extends TestCase
{
    private const AGE_FIXTURE = __DIR__ . '/../../Fixtures/tenant_result_v1.golden.json';
    private const ID_FIXTURE = __DIR__ . '/../../Fixtures/tenant_result_v1_id_no_gate.json';

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
            'verified but the age check did not pass' => [static function (array $d): array {
                $d['checks']['age']['passed'] = false;
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
    public function testOnlyAPassedSessionWithAPassedAgeCheckProvesAnAge(callable $change): void
    {
        $result = SessionResult::fromArray($change(self::fixture(self::AGE_FIXTURE)));

        $this->assertFalse($result->provesAge(18));
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

        foreach ([[self::AGE_FIXTURE, true], [self::ID_FIXTURE, false]] as [$path, $proves]) {
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
