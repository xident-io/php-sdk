<?php

declare(strict_types=1);

namespace Xident\SDK\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Xident\SDK\Config;
use Xident\SDK\Exceptions\NetworkException;
use Xident\SDK\Exceptions\NotFoundException;
use Xident\SDK\Exceptions\RateLimitException;
use Xident\SDK\Exceptions\ValidationException;
use Xident\SDK\HttpClient;
use Xident\SDK\Tests\Helpers\LocalHttpServer;

/**
 * Exercises the SDK's REAL cURL transport against a local HTTP server.
 *
 * Every other HttpClient test injects a fake transport, which short-circuits
 * doExecute() before a single cURL call. These tests take the other branch:
 * they hand HttpClient no transport at all, so it builds a cURL handle, talks
 * over a real socket, and splits the raw response into headers and body.
 */
final class HttpClientCurlTest extends TestCase
{
    private static ?LocalHttpServer $server = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = LocalHttpServer::start(__DIR__ . '/../Fixtures/test-server.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    private function client(?int $maxRetries = null, ?string $baseUrl = null): HttpClient
    {
        return new HttpClient(new Config(
            apiKey: 'sk_test_curl',
            baseUrl: $baseUrl ?? self::$server->baseUrl,
            timeout: 5,
            maxRetries: $maxRetries ?? 0,
        ));
    }

    public function testGetOverRealCurlParsesEnvelope(): void
    {
        $response = $this->client()->get('/ok');

        $this->assertTrue($response->success);
        $this->assertSame(200, $response->httpStatus);
        $this->assertSame('xtk_from_wire', $response->data['token']);
        $this->assertSame('req_wire_1', $response->requestId());
    }

    public function testRealCurlSendsTheRequestedMethodAndQueryString(): void
    {
        // The fixture echoes back what the server actually received, so this
        // asserts the URL that went over the socket, not the one we built.
        $response = $this->client()->get('/echo', ['page' => 2, 'q' => 'a b']);

        $this->assertSame('GET', $response->data['method']);
        $this->assertSame('page=2&q=a+b', $response->data['query']);
    }

    public function testPostOverRealCurlSendsTheJsonBody(): void
    {
        $response = $this->client()->post('/echo', ['callback_url' => 'https://example.com/cb']);

        $this->assertSame('POST', $response->data['method']);
        $this->assertSame('{"callback_url":"https:\/\/example.com\/cb"}', $response->data['body']);
    }

    public function testDeleteOverRealCurlSendsNoBody(): void
    {
        // An empty PHP array json_encodes to `[]`, not `{}` — no production
        // call site posts an empty body, but this pins the actual wire bytes.
        $response = $this->client()->post('/echo', []);
        $this->assertSame('[]', $response->data['body']);

        $deleted = $this->client()->delete('/echo');
        $this->assertSame('DELETE', $deleted->data['method']);
        $this->assertSame('', $deleted->data['body']);
    }

    public function testPatchOverRealCurlSendsTheJsonBody(): void
    {
        $response = $this->client()->patch('/echo', ['passed' => true]);

        $this->assertSame('PATCH', $response->data['method']);
        $this->assertSame('{"passed":true}', $response->data['body']);
    }

    /**
     * The response-header parser is only observable through Retry-After, and
     * only a real response carries real header bytes: a status line with no
     * colon at all, plus header values that contain colons of their own.
     */
    public function testRetryAfterHeaderSurvivesTheRealHeaderParser(): void
    {
        try {
            $this->client()->get('/limited');
            $this->fail('Expected a RateLimitException for the 429 fixture');
        } catch (RateLimitException $e) {
            $this->assertSame('Slow down', $e->getMessage());
            $this->assertSame('RATE_LIMITED', $e->getErrorCode());
            $this->assertSame(429, $e->getHttpStatus());
            $this->assertSame(42, $e->getRetryAfter());
        }
    }

    public function testRealCurlMapsA404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('No such fixture route');

        $this->client()->get('/no-such-route');
    }

    public function testRealCurlNonJsonBodyBecomesAParseError(): void
    {
        try {
            $this->client()->get('/garbage');
            $this->fail('Expected a ValidationException for a non-JSON body');
        } catch (ValidationException $e) {
            $this->assertSame('Failed to parse API response', $e->getMessage());
            $this->assertSame('PARSE_ERROR', $e->getErrorCode());
        }
    }

    public function testConnectionRefusedBecomesANetworkException(): void
    {
        $client = $this->client(
            maxRetries: 0,
            baseUrl: 'http://127.0.0.1:' . LocalHttpServer::closedPort(),
        );

        try {
            $client->get('/ok');
            $this->fail('Expected a NetworkException when nothing is listening');
        } catch (NetworkException $e) {
            $this->assertMatchesRegularExpression('/^cURL error \(\d+\): .+/', $e->getMessage());
            $this->assertSame('NETWORK_ERROR', $e->getErrorCode());
            $this->assertSame(0, $e->getHttpStatus());
        }
    }
}
