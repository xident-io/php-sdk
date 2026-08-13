<?php

declare(strict_types=1);

namespace Xident\SDK\Tests\Unit\Responses;

use PHPUnit\Framework\TestCase;
use Xident\SDK\Responses\SessionResult;

/**
 * Every field the API sends must have somewhere to land.
 *
 * The test this SDK needed and did not have.
 *
 * tests/Fixtures/tenant_result_v1.golden.json is a copy of the API's own frozen
 * fixture, and this suite's docblock calls it "the byte-for-byte fixture". For a
 * week it was not: the API's was 836 bytes and all four SDK copies were 659,
 * missing `risk`, `checks.eu_wallet` and `checks.aml`. Nothing caught it,
 * because:
 *
 *  - fromArray only reads the keys it names, so an unnamed key is ignored (which
 *    is what keeps this SDK working against an older deployment, and is
 *    therefore not the bug); and
 *  - the existing tests parse the fixture and assert individual properties, so a
 *    field that neither the fixture nor the class contained was invisible.
 *
 * The fixture and the classes agreed with each other perfectly while both
 * disagreed with the API.
 *
 * This SDK renames wire keys to camelCase, so comparing decoded arrays would
 * flag every snake_case key as dropped. The mapping is therefore declared
 * explicitly: a new wire key fails until someone adds it here AND adds the
 * property, which is exactly the pair of edits that was missed in August.
 */
final class GoldenRoundTripTest extends TestCase
{
    private const GOLDEN_FIXTURE = __DIR__ . '/../../Fixtures/tenant_result_v1.golden.json';

    /** wire key => property on SessionResult */
    private const WIRE_TO_PROP = [
        'token' => 'token',
        'status' => 'status',
        'verified' => 'verified',
        'reason' => 'reason',
        'verification_type' => 'verificationType',
        'ip_country' => 'ipCountry',
        'external_user_id' => 'externalUserId',
        'checks' => 'checks',
        'risk' => 'risk',
        'created_at' => 'createdAt',
        'completed_at' => 'completedAt',
        'expires_at' => 'expiresAt',
    ];

    /** wire key under `checks` => property on ResultChecks */
    private const CHECK_WIRE_TO_PROP = [
        'liveness' => 'liveness',
        'age' => 'age',
        'document' => 'document',
        'face_match' => 'faceMatch',
        'eu_wallet' => 'euWallet',
        'aml' => 'aml',
    ];

    /**
     * @return array<string, mixed>
     */
    private function golden(): array
    {
        $raw = file_get_contents(self::GOLDEN_FIXTURE);
        self::assertIsString($raw, 'golden fixture is unreadable');
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded, 'golden fixture is not valid JSON');

        return $decoded;
    }

    public function testEveryTopLevelWireKeyHasAProperty(): void
    {
        $golden = $this->golden();
        $result = SessionResult::fromArray($golden);

        $unmapped = [];
        foreach (array_keys($golden) as $wireKey) {
            if (!isset(self::WIRE_TO_PROP[$wireKey])) {
                $unmapped[] = $wireKey;
                continue;
            }
            self::assertTrue(
                property_exists($result, self::WIRE_TO_PROP[$wireKey]),
                sprintf(
                    'SessionResult has no $%s for wire key "%s" — the API sends it and this SDK '
                    . 'discards it. Add the property; do not trim the fixture.',
                    self::WIRE_TO_PROP[$wireKey],
                    $wireKey,
                ),
            );
        }

        self::assertSame([], $unmapped, 'wire keys with no declared property mapping: ' . implode(', ', $unmapped));
    }

    public function testEveryCheckWireKeyHasAProperty(): void
    {
        $golden = $this->golden();
        $checks = SessionResult::fromArray($golden)->checks;

        $problems = [];
        foreach (array_keys($golden['checks']) as $wireKey) {
            if (!isset(self::CHECK_WIRE_TO_PROP[$wireKey])) {
                $problems[] = sprintf('checks.%s (no declared mapping)', $wireKey);
                continue;
            }
            if (!property_exists($checks, self::CHECK_WIRE_TO_PROP[$wireKey])) {
                $problems[] = sprintf('checks.%s (no property)', $wireKey);
            }
        }

        self::assertSame([], $problems, 'dropped check results: ' . implode(', ', $problems));
    }

    public function testFixtureStaysInStepWithTheApiCopy(): void
    {
        // A future sync that silently trims the fixture fails here rather than in
        // a customer's integration.
        $golden = $this->golden();
        foreach (['liveness', 'age', 'document', 'face_match', 'eu_wallet', 'aml'] as $name) {
            self::assertArrayHasKey(
                $name,
                $golden['checks'],
                sprintf('fixture is missing checks.%s — out of sync with the API copy', $name),
            );
        }
        self::assertArrayHasKey('risk', $golden, 'fixture is missing the risk object');
    }

    public function testRecoveredFieldsAreReadable(): void
    {
        $golden = $this->golden();
        $result = SessionResult::fromArray($golden);

        self::assertNotNull($result->risk, 'risk is null after parsing a fixture that sets it');
        self::assertSame($golden['risk']['band'], $result->risk->band);

        // eu_wallet and aml are both performed:false in the fixture, so asserting
        // on `passed` would prove nothing — what matters is the wire value arrives.
        self::assertSame($golden['checks']['eu_wallet']['performed'], $result->checks->euWallet->performed);
        self::assertSame($golden['checks']['aml']['performed'], $result->checks->aml->performed);
    }

    public function testAbsentRiskStaysNull(): void
    {
        // `risk` is omitempty on the wire, so absent means "no risk signals",
        // which is materially different from a band of "". Callers branch on
        // `$result->risk === null`, so collapsing the two would change their
        // control flow.
        self::assertNull(SessionResult::fromArray(['token' => 'xtk_x', 'status' => 'success'])->risk);
        self::assertNull(SessionResult::fromArray(['token' => 'xtk_x', 'status' => 'success', 'risk' => []])->risk);
    }
}
