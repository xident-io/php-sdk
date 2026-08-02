<?php

declare(strict_types=1);

namespace Xident\SDK\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
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

    // --- Retry-After on 429 ---

    /**
     * @return iterable<string, array{0: array<string, string>, 1: ?int}>
     */
    public static function retryAfterHeaderProvider(): iterable
    {
        yield 'delta-seconds'              => [['Retry-After' => '30'], 30];
        yield 'lower-case header name'     => [['retry-after' => '30'], 30];
        yield 'surrounding whitespace'     => [['Retry-After' => ' 30 '], 30];
        yield 'zero is a real answer'      => [['Retry-After' => '0'], 0];
        yield 'header absent'              => [[], null];
        yield 'empty value'                => [['Retry-After' => ''], null];
        // The HTTP-date form is deliberately not parsed — see
        // HttpClient::retryAfterSeconds(). Null means "use your own backoff",
        // which is safer than a wait derived from a clock we do not control.
        yield 'http-date is not guessed at' => [['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT'], null];
        yield 'non-numeric junk'           => [['Retry-After' => 'soon'], null];
        yield 'negative is not a delay'    => [['Retry-After' => '-5'], null];
    }

    /**
     * @param array<string, string> $responseHeaders
     */
    #[DataProvider('retryAfterHeaderProvider')]
    public function testRateLimitExceptionCarriesRetryAfterFromTheResponse(
        array $responseHeaders,
        ?int $expected,
    ): void {
        $transport = new MockTransport();
        $transport->queueResponse(
            429,
            ['success' => false, 'error' => ['code' => 'TOO_MANY_REQUESTS', 'message' => 'Rate limited']],
            $responseHeaders,
        );
        $client = $this->makeClient($transport);

        try {
            $client->get('/test');
            $this->fail('Expected a RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertSame('Rate limited', $e->getMessage());
            $this->assertSame($expected, $e->getRetryAfter());
        }
    }

    /** Retry-After is meaningless on other statuses and must not leak onto them. */
    public function testRetryAfterOnANon429IsIgnored(): void
    {
        $transport = new MockTransport();
        $transport->queueResponse(
            400,
            ['success' => false, 'error' => ['code' => 'BAD', 'message' => 'Nope']],
            ['Retry-After' => '30'],
        );
        $client = $this->makeClient($transport);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Nope');
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

    /**
     * The retry loop's last line of defence: if the loop ever ends without a
     * result it must raise, never return null into a caller expecting an
     * ApiResponse.
     *
     * Config::__construct clamps maxRetries with max(0, …), so the loop always
     * runs at least once and this line cannot be reached by any supported
     * construction — it is a guard, not a code path. The only way to prove the
     * guard holds is to hand HttpClient the state Config forbids, which is why
     * the Config here is built through Reflection instead of `new`. That
     * mismatch is the point of the test: if someone later relaxes the clamp,
     * this asserts the failure mode is still a typed SDK exception.
     */
    public function testRetryLoopThatNeverRunsStillRaises(): void
    {
        $reflection = new \ReflectionClass(Config::class);
        $config = $reflection->newInstanceWithoutConstructor();
        foreach ([
            'apiKey'     => 'sk_test_x',
            'baseUrl'    => 'https://api.xident.io',
            'timeout'    => 30,
            'maxRetries' => -1,
            'headers'    => [],
        ] as $property => $value) {
            $reflection->getProperty($property)->setValue($config, $value);
        }

        $transport = new MockTransport();
        $client = new HttpClient($config, $transport);

        try {
            $client->get('/test');
            $this->fail('Expected a NetworkException when the retry loop cannot run');
        } catch (NetworkException $e) {
            $this->assertSame('Request failed after retries', $e->getMessage());
            $this->assertSame(0, $e->getHttpStatus());
        }

        // The transport was never reached, which is what made the loop fall
        // through in the first place.
        $this->assertSame(0, $transport->getRequestCount());
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
