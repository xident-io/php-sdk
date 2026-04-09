<?php

declare(strict_types=1);

namespace Xident\SDK\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Xident\SDK\Config;

final class ConfigTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $config = new Config(apiKey: 'sk_test_123');

        $this->assertSame('sk_test_123', $config->apiKey);
        $this->assertSame(Config::DEFAULT_BASE_URL, $config->baseUrl);
        $this->assertSame(Config::DEFAULT_TIMEOUT, $config->timeout);
        $this->assertSame(Config::DEFAULT_MAX_RETRIES, $config->maxRetries);
        $this->assertSame([], $config->headers);
    }

    public function testCustomValues(): void
    {
        $config = new Config(
            apiKey: 'sk_live_abc',
            baseUrl: 'https://custom.api.io',
            timeout: 60,
            maxRetries: 5,
            headers: ['X-Custom' => 'value'],
        );

        $this->assertSame('sk_live_abc', $config->apiKey);
        $this->assertSame('https://custom.api.io', $config->baseUrl);
        $this->assertSame(60, $config->timeout);
        $this->assertSame(5, $config->maxRetries);
        $this->assertSame(['X-Custom' => 'value'], $config->headers);
    }

    public function testEmptyApiKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('API key cannot be empty');
        new Config(apiKey: '');
    }

    public function testBaseUrlTrailingSlashTrimmed(): void
    {
        $config = new Config(apiKey: 'sk_test_x', baseUrl: 'https://api.example.com/');
        $this->assertSame('https://api.example.com', $config->baseUrl);
    }

    public function testApiUrl(): void
    {
        $config = new Config(apiKey: 'sk_test_x', baseUrl: 'https://api.xident.io');
        $this->assertSame('https://api.xident.io/verify/v1', $config->apiUrl());
    }

    public function testUserAgent(): void
    {
        $config = new Config(apiKey: 'sk_test_x');
        $ua = $config->userAgent();

        $this->assertStringContainsString('Xident-PHP/', $ua);
        $this->assertStringContainsString('PHP/', $ua);
        $this->assertStringContainsString(Config::SDK_VERSION, $ua);
    }

    public function testTimeoutMinimumClamped(): void
    {
        $config = new Config(apiKey: 'sk_test_x', timeout: -5);
        $this->assertSame(1, $config->timeout);
    }

    public function testMaxRetriesMinimumClamped(): void
    {
        $config = new Config(apiKey: 'sk_test_x', maxRetries: -1);
        $this->assertSame(0, $config->maxRetries);
    }

    public function testRejectsPublicKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Public keys (pk_*)');
        new Config(apiKey: 'pk_live_abc123');
    }

    public function testRejectsInvalidFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid API key format');
        new Config(apiKey: 'some_random_key');
    }

    public function testAcceptsSkLiveKey(): void
    {
        $config = new Config(apiKey: 'sk_live_abc123');
        $this->assertSame('sk_live_abc123', $config->apiKey);
    }

    public function testAcceptsSkTestKey(): void
    {
        $config = new Config(apiKey: 'sk_test_abc123');
        $this->assertSame('sk_test_abc123', $config->apiKey);
    }
}
