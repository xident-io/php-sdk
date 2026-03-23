<?php

declare(strict_types=1);

namespace Xident\SDK\Tests\Helpers;

/**
 * Mock HTTP transport for unit tests.
 *
 * Queue responses with queueResponse(), then inject as a callable transport.
 * Records all requests for assertion.
 */
final class MockTransport
{
    /** @var list<array{status: int, body: string, headers: array<string, string>}> */
    private array $responses = [];

    /** @var list<array{method: string, url: string, body: ?string, headers: list<string>}> */
    private array $requests = [];

    /**
     * @param array<string, string> $headers
     */
    public function queueResponse(int $status, array|string $body, array $headers = []): self
    {
        $bodyStr = is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body;
        $this->responses[] = ['status' => $status, 'body' => $bodyStr, 'headers' => $headers];
        return $this;
    }

    /** Queue a successful API response with the standard envelope. */
    public function queueSuccess(mixed $data, ?array $meta = null): self
    {
        $envelope = ['success' => true, 'data' => $data];
        if ($meta !== null) {
            $envelope['meta'] = $meta;
        }
        return $this->queueResponse(200, $envelope);
    }

    /** Queue an error API response with the standard envelope. */
    public function queueError(int $status, string $code, string $message): self
    {
        return $this->queueResponse($status, [
            'success' => false,
            'error' => ['code' => $code, 'message' => $message],
            'meta' => ['request_id' => 'req_test_' . time()],
        ]);
    }

    /**
     * @return array{method: string, url: string, body: ?string, headers: list<string>}|null
     */
    public function getLastRequest(): ?array
    {
        return $this->requests[count($this->requests) - 1] ?? null;
    }

    /**
     * @return array{method: string, url: string, body: ?string, headers: list<string>}|null
     */
    public function getRequest(int $index): ?array
    {
        return $this->requests[$index] ?? null;
    }

    public function getRequestCount(): int
    {
        return count($this->requests);
    }

    /**
     * Callable invocation — this is passed to HttpClient as the transport.
     *
     * @param list<string> $headers
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    public function __invoke(string $method, string $url, ?string $body, array $headers): array
    {
        $this->requests[] = [
            'method'  => $method,
            'url'     => $url,
            'body'    => $body,
            'headers' => $headers,
        ];

        if ($this->responses === []) {
            throw new \RuntimeException('MockTransport: no more queued responses');
        }

        return array_shift($this->responses);
    }
}
