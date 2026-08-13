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
    /** The API version path PREFIX — not the dated response contract. */
    public const API_VERSION = 'verify/v1';

    /**
     * The dated API version this SDK release was built against, sent as
     * X-API-Version on every request.
     *
     * Pinned in the SDK rather than relying on the project's dashboard setting so
     * this release's response classes always match the payload the server sends:
     * a customer pinned to an older version still receives the shape this SDK
     * parses. Sending nothing would let a newer SDK read an older shape and
     * silently leave fields empty.
     *
     * Upgrading the MAJOR version of this SDK is therefore an explicit opt-in to
     * a new API version. Pass $apiVersion to the constructor to override, e.g. to
     * trial a newer version before changing the dashboard pin.
     *
     * Deliberately NOT derived from SDK_VERSION: they move on different clocks,
     * and an SDK patch release must never change which shape a customer receives.
     */
    public const PINNED_API_VERSION = '2026-08-13';
    public const SDK_VERSION = '3.1.0';

    public string $apiKey;
    public string $baseUrl;
    public int $timeout;
    public int $maxRetries;
    /** @var array<string, string> */
    public array $headers;

    /**
     * The dated API version sent as X-API-Version.
     *
     * Defaults to PINNED_API_VERSION, the version this release was built against
     * — the right choice for almost everyone, because it guarantees the response
     * classes match the payload. Override it to trial a NEWER version before
     * changing your project's dashboard pin.
     *
     * A readonly property cannot carry a default, so a Config built WITHOUT
     * running the constructor (reflection, a test double, unserialize) leaves
     * this uninitialised — reading it then throws "must not be accessed before
     * initialization". HttpClient therefore reads it through isset() and falls
     * back to PINNED_API_VERSION: throwing at request time over a header would
     * be far worse than sending the pinned default.
     */
    public string $apiVersion;

    /**
     * @param array<string, string> $headers Extra headers to send with every request
     */
    public function __construct(
        string $apiKey,
        string $baseUrl = self::DEFAULT_BASE_URL,
        int $timeout = self::DEFAULT_TIMEOUT,
        int $maxRetries = self::DEFAULT_MAX_RETRIES,
        array $headers = [],
        string $apiVersion = '',
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
        // An empty string falls back to the pinned default: the server rejects an
        // empty X-API-Version as invalid, so honouring '' would break every request.
        $this->apiVersion = $apiVersion !== '' ? $apiVersion : self::PINNED_API_VERSION;
    }

    /**
     * Full API URL (base + version prefix).
     *
     * Never empty: the version prefix is appended unconditionally, so even a
     * baseUrl of "/" yields a usable path. cURL relies on that.
     *
     * @return non-empty-string
     */
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
