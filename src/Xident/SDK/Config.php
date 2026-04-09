<?php

declare(strict_types=1);

namespace Xident\SDK;

/**
 * SDK configuration. Immutable after construction.
 */
final readonly class Config
{
    public const DEFAULT_BASE_URL = 'https://api.xident.io';
    public const DEFAULT_TIMEOUT = 30;
    public const DEFAULT_MAX_RETRIES = 3;
    public const API_VERSION = 'verify/v1';
    public const SDK_VERSION = '1.0.0';

    public string $apiKey;
    public string $baseUrl;
    public int $timeout;
    public int $maxRetries;
    /** @var array<string, string> */
    public array $headers;

    /**
     * @param array<string, string> $headers Extra headers to send with every request
     */
    public function __construct(
        string $apiKey,
        string $baseUrl = self::DEFAULT_BASE_URL,
        int $timeout = self::DEFAULT_TIMEOUT,
        int $maxRetries = self::DEFAULT_MAX_RETRIES,
        array $headers = [],
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('API key cannot be empty');
        }

        if (str_starts_with($apiKey, 'pk_')) {
            throw new \InvalidArgumentException(
                'Public keys (pk_*) cannot be used with the server SDK. Use your secret key (sk_live_* or sk_test_*).'
            );
        }
        if (!str_starts_with($apiKey, 'sk_live_') && !str_starts_with($apiKey, 'sk_test_')) {
            throw new \InvalidArgumentException(
                'Invalid API key format. Must start with "sk_live_" or "sk_test_".'
            );
        }

        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = max(1, $timeout);
        $this->maxRetries = max(0, $maxRetries);
        $this->headers = $headers;
    }

    /** Full API URL (base + version prefix). */
    public function apiUrl(): string
    {
        return $this->baseUrl . '/' . self::API_VERSION;
    }

    /** User-Agent header value. */
    public function userAgent(): string
    {
        return sprintf('Xident-PHP/%s PHP/%s', self::SDK_VERSION, PHP_VERSION);
    }
}
