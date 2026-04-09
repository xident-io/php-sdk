<?php

declare(strict_types=1);

namespace Xident\SDK\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Xident\SDK\Config;
use Xident\SDK\Exceptions\AuthenticationException;
use Xident\SDK\Exceptions\NetworkException;
use Xident\SDK\Exceptions\NotFoundException;
use Xident\SDK\Exceptions\RateLimitException;
use Xident\SDK\Exceptions\ServerException;
use Xident\SDK\Exceptions\ValidationException;
use Xident\SDK\HttpClient;
use Xident\SDK\Tests\Helpers\MockTransport;

final class HttpClientTest extends TestCase
{
    private function makeClient(?MockTransport $transport = null, ?Config $config = null): HttpClient
    {
        return new HttpClient(
            $config ?? new Config(apiKey: 'sk_test_123'),
            $transport,
        );
    }

    // --- Request building ---

    public function testGetRequestSendsCorrectMethod(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess(['ok' => true]);
        $client = $this->makeClient($transport);

        $client->get('/result/abc');

        $req = $transport->getLastRequest();
        $this->assertSame('GET', $req['method']);
        $this->assertStringContainsString('/verify/v1/result/abc', $req['url']);
    }

    public function testPostRequestSendsCorrectMethodAndBody(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess(['token' => 'xit_abc']);
        $client = $this->makeClient($transport);

        $client->post('/init', ['callback_url' => 'https://example.com']);

        $req = $transport->getLastRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertStringContainsString('callback_url', $req['body']);
    }

    public function testPatchRequestSendsCorrectMethod(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess([]);
        $client = $this->makeClient($transport);

        $client->patch('/sessions/abc/liveness', ['passed' => true]);

        $this->assertSame('PATCH', $transport->getLastRequest()['method']);
    }

    public function testDeleteRequestSendsCorrectMethod(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess([]);
        $client = $this->makeClient($transport);

        $client->delete('/tokens/abc');

        $this->assertSame('DELETE', $transport->getLastRequest()['method']);
    }

    public function testQueryParamsAppendedToUrl(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess([]);
        $client = $this->makeClient($transport);

        $client->get('/search', ['q' => 'test', 'page' => 1]);

        $url = $transport->getLastRequest()['url'];
        $this->assertStringContainsString('q=test', $url);
        $this->assertStringContainsString('page=1', $url);
    }

    // --- Headers ---

    public function testApiKeyHeaderIncluded(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess([]);
        $client = $this->makeClient($transport);

        $client->get('/test');

        $headers = $transport->getLastRequest()['headers'];
        $this->assertContains('X-API-Key: sk_test_123', $headers);
    }

    public function testUserAgentHeaderIncluded(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess([]);
        $client = $this->makeClient($transport);

        $client->get('/test');

        $headers = implode(' ', $transport->getLastRequest()['headers']);
        $this->assertStringContainsString('Xident-PHP/', $headers);
    }

    public function testContentTypeOnPost(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess([]);
        $client = $this->makeClient($transport);

        $client->post('/test', ['key' => 'value']);

        $this->assertContains('Content-Type: application/json', $transport->getLastRequest()['headers']);
    }

    public function testNoContentTypeOnGet(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess([]);
        $client = $this->makeClient($transport);

        $client->get('/test');

        $this->assertNotContains('Content-Type: application/json', $transport->getLastRequest()['headers']);
    }

    public function testCustomHeadersIncluded(): void
    {
        $config = new Config(apiKey: 'sk_test_x', headers: ['X-Custom' => 'hello']);
        $transport = new MockTransport();
        $transport->queueSuccess([]);
        $client = $this->makeClient($transport, $config);

        $client->get('/test');

        $this->assertContains('X-Custom: hello', $transport->getLastRequest()['headers']);
    }

    // --- Error mapping ---

    public function testMaps400ToValidationException(): void
    {
        $transport = new MockTransport();
        $transport->queueError(400, 'INVALID_REQUEST', 'Bad params');
        $client = $this->makeClient($transport);

        $this->expectException(ValidationException::class);
        $client->get('/test');
    }

    public function testMaps401ToAuthenticationException(): void
    {
        $transport = new MockTransport();
        $transport->queueError(401, 'UNAUTHORIZED', 'Invalid key');
        $client = $this->makeClient($transport);

        $this->expectException(AuthenticationException::class);
        $client->get('/test');
    }

    public function testMaps403ToAuthenticationException(): void
    {
        $transport = new MockTransport();
        $transport->queueError(403, 'FORBIDDEN', 'Access denied');
        $client = $this->makeClient($transport);

        $this->expectException(AuthenticationException::class);
        $client->get('/test');
    }

    public function testMaps404ToNotFoundException(): void
    {
        $transport = new MockTransport();
        $transport->queueError(404, 'NOT_FOUND', 'Session not found');
        $client = $this->makeClient($transport);

        $this->expectException(NotFoundException::class);
        $client->get('/test');
    }

    public function testMaps429ToRateLimitException(): void
    {
        $transport = new MockTransport();
        $transport->queueError(429, 'TOO_MANY_REQUESTS', 'Rate limited');
        $client = $this->makeClient($transport);

        $this->expectException(RateLimitException::class);
        $client->get('/test');
    }

    public function testMaps500ToServerException(): void
    {
        $transport = new MockTransport();
        $transport->queueError(500, 'INTERNAL_ERROR', 'Server error');
        // No retries to test immediate exception
        $config = new Config(apiKey: 'sk_test_x', maxRetries: 0);
        $client = $this->makeClient($transport, $config);

        $this->expectException(ServerException::class);
        $client->get('/test');
    }

    public function testNetworkErrorThrowsNetworkException(): void
    {
        $transport = function () {
            throw new NetworkException('cURL error (28): Connection timed out', 'NETWORK_ERROR');
        };
        $config = new Config(apiKey: 'sk_test_x', maxRetries: 0);
        $client = new HttpClient($config, $transport);

        $this->expectException(NetworkException::class);
        $client->get('/test');
    }

    // --- Retry logic ---

    public function testRetriesOn500(): void
    {
        $transport = new MockTransport();
        $transport->queueError(500, 'INTERNAL_ERROR', 'Error 1');
        $transport->queueSuccess(['ok' => true]);

        $config = new Config(apiKey: 'sk_test_x', maxRetries: 1);
        $client = $this->makeClient($transport, $config);

        $response = $client->get('/test');

        $this->assertTrue($response->success);
        $this->assertSame(2, $transport->getRequestCount());
    }

    public function testDoesNotRetryOn400(): void
    {
        $transport = new MockTransport();
        $transport->queueError(400, 'BAD_REQUEST', 'Invalid');

        $config = new Config(apiKey: 'sk_test_x', maxRetries: 3);
        $client = $this->makeClient($transport, $config);

        try {
            $client->get('/test');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(1, $transport->getRequestCount());
    }

    public function testExhaustsRetriesOn500(): void
    {
        $transport = new MockTransport();
        $transport->queueError(500, 'ERROR', 'Fail 1');
        $transport->queueError(500, 'ERROR', 'Fail 2');

        $config = new Config(apiKey: 'sk_test_x', maxRetries: 1);
        $client = $this->makeClient($transport, $config);

        $this->expectException(ServerException::class);
        $client->get('/test');
    }

    // --- Envelope parsing ---

    public function testParsesSuccessEnvelope(): void
    {
        $transport = new MockTransport();
        $transport->queueSuccess(['token' => 'abc'], ['request_id' => 'req_123']);
        $client = $this->makeClient($transport);

        $response = $client->get('/test');

        $this->assertTrue($response->success);
        $this->assertSame('abc', $response->data['token']);
        $this->assertSame('req_123', $response->requestId());
    }

    public function testErrorEnvelopeCarriesRequestId(): void
    {
        $transport = new MockTransport();
        $transport->queueError(400, 'BAD', 'Bad request');
        $client = $this->makeClient($transport);

        try {
            $client->get('/test');
            $this->fail('Expected exception');
        } catch (ValidationException $e) {
            $this->assertSame('BAD', $e->getErrorCode());
            $this->assertNotNull($e->getRequestId());
        }
    }

    public function testInvalidJsonReturnsParseError(): void
    {
        $transport = function () {
            return ['status' => 200, 'body' => 'not json', 'headers' => []];
        };
        $client = new HttpClient(new Config(apiKey: 'sk_test_x'), $transport);

        // Non-JSON 200 response should be treated as error
        $this->expectException(ValidationException::class);
        $client->get('/test');
    }
}
