<?php

declare(strict_types=1);

namespace Xident\SDK\Tests\Unit\Responses;

use PHPUnit\Framework\TestCase;
use Xident\SDK\Responses\DataMatchCheck;
use Xident\SDK\Responses\DataMatchFields;
use Xident\SDK\Responses\SessionResult;

/**
 * checks.data_match is OPTIONAL on the wire (sent since 2026-09-05). Two
 * fixtures pin both states: the base golden has no data_match key and must
 * parse to null; the data_match golden has it and must parse in full.
 */
final class DataMatchTest extends TestCase
{
    private const BASE = __DIR__ . '/../../Fixtures/tenant_result_v1.golden.json';
    private const WITH_DATA_MATCH = __DIR__ . '/../../Fixtures/tenant_result_v1_data_match.golden.json';

    /** @return array<string, mixed> */
    private static function load(string $path): array
    {
        $raw = file_get_contents($path);
        self::assertNotFalse($raw);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        return $decoded;
    }

    public function testAbsentOnTheBaseGolden(): void
    {
        $result = SessionResult::fromArray(self::load(self::BASE));
        self::assertNull($result->checks->dataMatch);
    }

    public function testParsedFromItsGolden(): void
    {
        $result = SessionResult::fromArray(self::load(self::WITH_DATA_MATCH));
        $dm = $result->checks->dataMatch;
        self::assertInstanceOf(DataMatchCheck::class, $dm);
        self::assertTrue($dm->performed);
        self::assertFalse($dm->passed);
        self::assertSame(DataMatchFields::MATCH, $dm->fields->firstName);
        self::assertSame(DataMatchFields::MISMATCH, $dm->fields->dateOfBirth);
        self::assertNull($dm->fields->lastName);
        self::assertNull($dm->fields->documentNumber);
        self::assertNull($dm->fields->nationality);
        self::assertTrue($result->verified, 'report policy: a mismatch and a success coexist');
    }

    public function testUnknownOutcomeIsNull(): void
    {
        $dm = DataMatchCheck::fromMixed(['performed' => true, 'passed' => false, 'fields' => ['first_name' => 'maybe', 'last_name' => 'match']]);
        self::assertNotNull($dm);
        self::assertNull($dm->fields->firstName);
        self::assertSame('match', $dm->fields->lastName);
        self::assertNull(DataMatchCheck::fromMixed(null));
        self::assertNull(DataMatchCheck::fromMixed('nope'));
    }
}
