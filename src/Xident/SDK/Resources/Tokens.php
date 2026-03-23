<?php

declare(strict_types=1);

namespace Xident\SDK\Resources;

use Xident\SDK\HttpClient;
use Xident\SDK\Responses\TokenResult;

/**
 * Tokens resource — verify Xident verification tokens.
 *
 * Verification tokens are the "cheap path" for returning Xident users.
 * Instead of running full verification again, validate their existing token.
 */
final class Tokens
{
    public function __construct(
        private readonly HttpClient $http,
    ) {}

    /**
     * Verify a Xident verification token.
     *
     * Returns whether the token is valid and the verified age bracket.
     * This is the cheap verification path — 80% less than full verification.
     *
     * @throws \Xident\SDK\Exceptions\AuthenticationException If API key is invalid
     */
    public function verify(string $token): TokenResult
    {
        if ($token === '') {
            throw new \InvalidArgumentException('Token cannot be empty');
        }

        $response = $this->http->post('/verification-tokens/verify', [
            'token' => $token,
        ]);

        return TokenResult::fromArray($response->data ?? []);
    }
}
