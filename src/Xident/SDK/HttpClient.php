<?php

declare(strict_types=1);

namespace Xident\SDK;

use Xident\SDK\Exceptions\AuthenticationException;
use Xident\SDK\Exceptions\NetworkException;
use Xident\SDK\Exceptions\NotFoundException;
use Xident\SDK\Exceptions\RateLimitException;
use Xident\SDK\Exceptions\ServerException;
use Xident\SDK\Exceptions\ValidationException;
use Xident\SDK\Exceptions\XidentException;
use Xident\SDK\Responses\ApiResponse;

/**
 * HTTP transport layer using native cURL.
 *
 * @internal Not part of the public API. Use Client instead.
 */
final class HttpClient
{
    /**
     * @var callable|null Optional transport override for testing.
     *                    Signature: fn(string $method, string $url, ?string $body, array $headers): array{status: int, body: string, headers: array}
     */
    private $transport;

    public function __construct(
        private readonly Config $config,
        ?callable $transport = null,
    ) {
        $this->transport = $transport;
    }

    /**
     * @param array<string, string|int> $queryParams
     * @throws XidentException
     */
    public function get(string $path, array $queryParams = []): ApiResponse
    {
        return $this->request('GET', $path, null, $queryParams);
    }

    /**
     * @param array<string, mixed> $body
     * @throws XidentException
     */
    public function post(string $path, array $body = []): ApiResponse
    {
        return $this->request('POST', $path, json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $body
     * @throws XidentException
     */
    public function patch(string $path, array $body = []): ApiResponse
    {
        return $this->request('PATCH', $path, json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** @throws XidentException */
    public function delete(string $path): ApiResponse
    {
        return $this->request('DELETE', $path);
    }

    /**
     * @param array<string, string|int> $queryParams
     * @throws XidentException
     */
    private function request(string $method, string $path, ?string $body = null, array $queryParams = []): ApiResponse
    {
        $url = $this->buildUrl($path, $queryParams);
        $headers = $this->buildHeaders($body);

        $raw = $this->executeWithRetry($method, $url, $body, $headers);

        $response = ApiResponse::fromJson($raw['body'], $raw['status']);

        if (!$response->success) {
            $this->throwException($response);
        }

        return $response;
    }

    /**
     * @param array<string, string|int> $queryParams
     */
    private function buildUrl(string $path, array $queryParams = []): string
    {
        $url = $this->config->apiUrl() . '/' . ltrim($path, '/');

        if ($queryParams !== []) {
            $url .= '?' . http_build_query($queryParams);
        }

        return $url;
    }

    /** @return list<string> */
    private function buildHeaders(?string $body): array
    {
        $headers = [
            'X-API-Key: ' . $this->config->apiKey,
            'User-Agent: ' . $this->config->userAgent(),
            'Accept: application/json',
        ];

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        foreach ($this->config->headers as $key => $value) {
            $headers[] = $key . ': ' . $value;
        }

        return $headers;
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, body: string, headers: array<string, string>}
     * @throws NetworkException
     */
    private function executeWithRetry(string $method, string $url, ?string $body, array $headers): array
    {
        $lastException = null;

        for ($attempt = 0; $attempt <= $this->config->maxRetries; $attempt++) {
            if ($attempt > 0) {
                usleep($this->retryDelayMs($attempt) * 1000);
            }

            try {
                $result = $this->doExecute($method, $url, $body, $headers);
                $status = $result['status'];

                // Only retry on 5xx (server errors)
                if ($status >= 500 && $attempt < $this->config->maxRetries) {
                    $lastException = new ServerException(
                        "Server error ({$status})",
                        '',
                        null,
                        $status,
                    );
                    continue;
                }

                return $result;
            } catch (NetworkException $e) {
                $lastException = $e;
                if ($attempt >= $this->config->maxRetries) {
                    throw $e;
                }
            }
        }

        throw $lastException ?? new NetworkException('Request failed after retries');
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, body: string, headers: array<string, string>}
     * @throws NetworkException
     */
    private function doExecute(string $method, string $url, ?string $body, array $headers): array
    {
        // Use injected transport for testing
        if ($this->transport !== null) {
            return ($this->transport)($method, $url, $body, $headers);
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS      => 0,
            CURLOPT_TIMEOUT        => $this->config->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_ENCODING       => '',
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $rawResponse = curl_exec($ch);

        if ($rawResponse === false) {
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            unset($ch);
            throw new NetworkException(
                "cURL error ({$errno}): {$error}",
                'NETWORK_ERROR',
                null,
                0,
            );
        }

        $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        unset($ch);

        $responseHeaders = $this->parseResponseHeaders(substr((string)$rawResponse, 0, $headerSize));
        $responseBody = substr((string)$rawResponse, $headerSize);

        return [
            'status'  => $httpStatus,
            'body'    => $responseBody,
            'headers' => $responseHeaders,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function parseResponseHeaders(string $raw): array
    {
        $headers = [];
        foreach (explode("\r\n", $raw) as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }
        return $headers;
    }

    /** Exponential backoff: 1000ms, 2000ms, 4000ms */
    private function retryDelayMs(int $attempt): int
    {
        return (int)(1000 * pow(2, $attempt - 1));
    }

    /** @throws XidentException */
    private function throwException(ApiResponse $response): never
    {
        $message = $response->errorMessage();
        $errorCode = $response->errorCode();
        $requestId = $response->requestId();
        $status = $response->httpStatus;

        $exception = match (true) {
            $status === 401, $status === 403 => new AuthenticationException($message, $errorCode, $requestId, $status),
            $status === 404                  => new NotFoundException($message, $errorCode, $requestId, $status),
            $status === 429                  => (new RateLimitException($message, $errorCode, $requestId, $status)),
            $status >= 500                   => new ServerException($message, $errorCode, $requestId, $status),
            default                          => new ValidationException($message, $errorCode, $requestId, $status),
        };

        throw $exception;
    }
}
