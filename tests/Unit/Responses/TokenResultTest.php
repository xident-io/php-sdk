<?php

declare(strict_types=1);

namespace Xident\SDK\Tests\Unit\Responses;

use PHPUnit\Framework\TestCase;
use Xident\SDK\Responses\TokenResult;

final class TokenResultTest extends TestCase
{
    public function testValidTokenFromArray(): void
    {
        $result = TokenResult::fromArray([
            'valid' => true,
            'age_bracket' => 21,
            'method' => 'ocr',
            'expires_at' => '2026-04-23T00:00:00Z',
        ]);

        $this->assertTrue($result->isValid());
        $this->assertSame(21, $result->ageBracket);
        $this->assertSame('ocr', $result->method);
        $this->assertSame('2026-04-23T00:00:00Z', $result->expiresAt);
    }

    public function testInvalidTokenFromArray(): void
    {
        $result = TokenResult::fromArray(['valid' => false]);

        $this->assertFalse($result->isValid());
        $this->assertNull($result->ageBracket);
        $this->assertNull($result->method);
        $this->assertNull($result->expiresAt);
    }

    public function testMeetsMinAgeCombinations(): void
    {
        $bracket18 = TokenResult::fromArray(['valid' => true, 'age_bracket' => 18]);

        $this->assertTrue($bracket18->meetsMinAge(12));
        $this->assertTrue($bracket18->meetsMinAge(15));
        $this->assertTrue($bracket18->meetsMinAge(18));
        $this->assertFalse($bracket18->meetsMinAge(21));
        $this->assertFalse($bracket18->meetsMinAge(25));
    }

    public function testMeetsMinAgeInvalidTokenAlwaysFalse(): void
    {
        $invalid = TokenResult::fromArray(['valid' => false, 'age_bracket' => 25]);
        $this->assertFalse($invalid->meetsMinAge(12));
    }

    public function testEmptyArrayDefaults(): void
    {
        $result = TokenResult::fromArray([]);
        $this->assertFalse($result->isValid());
        $this->assertNull($result->ageBracket);
    }
}
