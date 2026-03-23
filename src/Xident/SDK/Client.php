<?php

declare(strict_types=1);

namespace Xident\SDK;

use Xident\SDK\Resources\Tokens;
use Xident\SDK\Resources\Verification;
use Xident\SDK\Resources\Webhooks;

/**
 * Xident SDK Client — main entry point.
 *
 * Usage:
 *   $xident = new \Xident\SDK\Client('sk_live_xxx');
 *   $session = $xident->verification()->init([...]);
 *   $result = $xident->verification()->getResult($sessionId);
 */
final class Client
{
    private Config $config;
    private HttpClient $http;
    private ?Verification $verification = null;
    private ?Tokens $tokens = null;
    private ?Webhooks $webhooks = null;

    /**
     * @param string      $apiKey     Your Xident secret API key (sk_live_xxx or sk_test_xxx)
     * @param string|null $baseUrl    API base URL (default: https://api.xident.io)
     * @param int|null    $timeout    Request timeout in seconds (default: 30)
     * @param int|null    $maxRetries Max retries on 5xx errors (default: 3)
     * @param array<string, string>|null $headers Extra headers for every request
     * @param callable|null $transport  Test transport (internal — do not use in production)
     */
    public function __construct(
        string $apiKey,
        ?string $baseUrl = null,
        ?int $timeout = null,
        ?int $maxRetries = null,
        ?array $headers = null,
        ?callable $transport = null,
    ) {
        $this->config = new Config(
            apiKey: $apiKey,
            baseUrl: $baseUrl ?? Config::DEFAULT_BASE_URL,
            timeout: $timeout ?? Config::DEFAULT_TIMEOUT,
            maxRetries: $maxRetries ?? Config::DEFAULT_MAX_RETRIES,
            headers: $headers ?? [],
        );

        $this->http = new HttpClient($this->config, $transport);
    }

    /** Verification resource — init tokens and session results. */
    public function verification(): Verification
    {
        return $this->verification ??= new Verification($this->http);
    }

    /** Tokens resource — verify Xident verification tokens. */
    public function tokens(): Tokens
    {
        return $this->tokens ??= new Tokens($this->http);
    }

    /** Webhooks resource — verify signatures and parse events. */
    public function webhooks(): Webhooks
    {
        return $this->webhooks ??= new Webhooks();
    }

    /** SDK version string. */
    public static function version(): string
    {
        return Config::SDK_VERSION;
    }

    /** Get the current configuration (read-only). */
    public function getConfig(): Config
    {
        return $this->config;
    }
}
