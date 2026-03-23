<?php

declare(strict_types=1);

namespace Xident\SDK\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;
use Xident\SDK\Client;
use Xident\SDK\Responses\TokenResult;
use Xident\SDK\Tests\Helpers\MockTransport;

final class TokensTest extends TestCase
{
    private function client(MockTransport $transport): Client
    {
        return new Client('sk_test_123', transport: $transport);
    }

    public function testVerifyValidToken(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess([
            'valid' => true,
            'age_bracket' => 18,
            'method' => 'ml_fast',
            'expires_at' => '2026-04-23T12:00:00Z',
        ]);

        $result = $this->client($transport)->tokens()->verify('tok_abc');

        $this->assertInstanceOf(TokenResult::class, $result);
        $this->assertTrue($result->isValid());
        $this->assertSame(18, $result->ageBracket);
        $this->assertSame('ml_fast', $result->method);
        $this->assertTrue($result->meetsMinAge(18));
        $this->assertTrue($result->meetsMinAge(12));
        $this->assertFalse($result->meetsMinAge(21));
    }

    public function testVerifyInvalidToken(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess([
            'valid' => false,
            'age_bracket' => null,
            'method' => null,
            'expires_at' => null,
        ]);

        $result = $this->client($transport)->tokens()->verify('tok_expired');

        $this->assertFalse($result->isValid());
        $this->assertFalse($result->meetsMinAge(18));
        $this->assertNull($result->ageBracket);
    }

    public function testVerifySendsPostWithToken(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess(['valid' => true, 'age_bracket' => 25]);

        $this->client($transport)->tokens()->verify('tok_xyz');

        $req = $transport->getLastRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertStringContainsString('/verification-tokens/verify', $req['url']);

        $body = json_decode($req['body'], true);
        $this->assertSame('tok_xyz', $body['token']);
    }

    public function testVerifyEmptyTokenThrows(): void
    {
        $transport = new MockTransport();
        $this->expectException(\InvalidArgumentException::class);
        $this->client($transport)->tokens()->verify('');
    }

    public function testMeetsMinAgeWithExactMatch(): void
    {
        $result = TokenResult::fromArray(['valid' => true, 'age_bracket' => 18]);
        $this->assertTrue($result->meetsMinAge(18));
    }

    public function testMeetsMinAgeWithHigherBracket(): void
    {
        $result = TokenResult::fromArray(['valid' => true, 'age_bracket' => 25]);
        $this->assertTrue($result->meetsMinAge(18));
    }

    public function testMeetsMinAgeWithLowerBracket(): void
    {
        $result = TokenResult::fromArray(['valid' => true, 'age_bracket' => 12]);
        $this->assertFalse($result->meetsMinAge(18));
    }

    public function testMeetsMinAgeInvalidToken(): void
    {
        $result = TokenResult::fromArray(['valid' => false, 'age_bracket' => 25]);
        $this->assertFalse($result->meetsMinAge(18));
    }

    public function testMeetsMinAgeNullBracket(): void
    {
        $result = TokenResult::fromArray(['valid' => true]);
        $this->assertFalse($result->meetsMinAge(18));
    }
}
