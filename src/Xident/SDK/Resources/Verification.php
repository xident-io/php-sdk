<?php

declare(strict_types=1);

namespace Xident\SDK\Resources;

use Xident\SDK\HttpClient;
use Xident\SDK\Responses\InitResult;
use Xident\SDK\Responses\SessionResult;

/**
 * Verification resource — create init tokens and retrieve session results.
 */
final class Verification
{
    public function __construct(
        private readonly HttpClient $http,
    ) {}

    /**
     * Create an init token for starting a verification session.
     *
     * Returns a token and the full URL to redirect the user to.
     * The token is valid for 10 minutes.
     *
     * @param array{
     *   callback_url: string,
     *   min_age?: int,
     *   success_url?: string,
     *   failed_url?: string,
     *   user_id?: string,
     *   theme?: string,
     *   locale?: string,
     *   metadata?: string,
     *   liveness_difficulty?: string,
     *   purpose?: string,
     * } $params
     *
     * @throws \Xident\SDK\Exceptions\ValidationException If required params are missing
     * @throws \Xident\SDK\Exceptions\AuthenticationException If API key is invalid
     */
    public function init(array $params): InitResult
    {
        $response = $this->http->post('/init', $params);
        return InitResult::fromArray($response->data ?? []);
    }

    /**
     * Get the verification result for a token.
     *
     * Call this after the user returns from the verification widget.
     * NEVER trust URL parameters alone — always re-verify server-side.
     *
     * @throws \Xident\SDK\Exceptions\NotFoundException If token does not exist
     * @throws \Xident\SDK\Exceptions\AuthenticationException If API key is invalid
     */
    public function getResult(string $token): SessionResult
    {
        if ($token === '') {
            throw new \InvalidArgumentException('Token cannot be empty');
        }

        $response = $this->http->get('/status/' . urlencode($token));
        return SessionResult::fromArray($response->data ?? []);
    }
}
