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
     * Returns an init token (`xit_` prefixed) and the full URL to redirect the
     * user to. The init token is one-time-use and valid for 10 minutes.
     *
     * Required params:
     * - `callback_url`: HTTPS URL (http://localhost allowed for dev) the widget
     *   redirects the browser back to when done.
     * - `min_age`: 1-99. REQUIRED for age verification — omitting it (or sending 0)
     *   returns HTTP 400. Only optional (0-99) when `purpose` is `id_verification`.
     *
     * Optional params:
     * - `success_url` / `failed_url`: redirect targets for each outcome.
     * - `user_id`: your internal user ID, echoed back on the callback.
     * - `theme`: `light`, `dark`, or `system`. Unknown values coerce to `system`.
     * - `locale`: one of en, es, fr, de, pt, ar, zh, ja, hi, nl. Unknown → `en`.
     * - `metadata`: an OPAQUE string echoed back to you (e.g. a JSON blob or plan
     *   ID). Xident stores it verbatim and never parses it.
     * - `purpose`: `age_verification` (default) or `id_verification`.
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

        $response = $this->http->get('/result/' . urlencode($token));
        return SessionResult::fromArray($response->data ?? []);
    }
}
