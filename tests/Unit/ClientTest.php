<?php

declare(strict_types=1);

namespace Xident\SDK\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Xident\SDK\Client;
use Xident\SDK\Config;
use Xident\SDK\Resources\Verification;
use Xident\SDK\Resources\Webhooks;
use Xident\SDK\Tests\Helpers\MockTransport;

final class ClientTest extends TestCase
{
    public function testConstructorWithApiKey(): void
    {
        $client = new Client('sk_test_123');
        $this->assertInstanceOf(Client::class, $client);
    }

    public function testConstructorWithAllOptions(): void
    {
        $client = new Client(
            apiKey: 'sk_test_123',
            baseUrl: 'https://custom.api.io',
            timeout: 60,
            maxRetries: 5,
            headers: ['X-Custom' => 'value'],
        );
        $this->assertInstanceOf(Client::class, $client);
    }

    public function testEmptyApiKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client('');
    }

    public function testVerificationReturnsResource(): void
    {
        $client = new Client('sk_test_123');
        $this->assertInstanceOf(Verification::class, $client->verification());
    }

    public function testWebhooksReturnsResource(): void
    {
        $client = new Client('sk_test_123');
        $this->assertInstanceOf(Webhooks::class, $client->webhooks());
    }

    public function testResourcesAreCached(): void
    {
        $client = new Client('sk_test_123');
        $v1 = $client->verification();
        $v2 = $client->verification();
        $this->assertSame($v1, $v2);
    }

    public function testVersion(): void
    {
        $this->assertSame(Config::SDK_VERSION, Client::version());
    }

    public function testGetConfig(): void
    {
        $client = new Client('sk_test_123', baseUrl: 'https://staging.api.io');
        $config = $client->getConfig();
        $this->assertSame('sk_test_123', $config->apiKey);
        $this->assertSame('https://staging.api.io', $config->baseUrl);
    }

    public function testTransportInjection(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess(['token' => 'xit_abc', 'verify_url' => 'https://verify.xident.io?t=xit_abc']);

        $client = new Client('sk_test_123', transport: $transport);
        $result = $client->verification()->init(['callback_url' => 'https://example.com/cb']);

        $this->assertSame('xit_abc', $result->token);
        $this->assertSame(1, $transport->getRequestCount());
    }
}
