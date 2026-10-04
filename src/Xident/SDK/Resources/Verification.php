<?php

declare(strict_types=1);

namespace Xident\SDK\Resources;

use Xident\SDK\Exceptions\ValidationException;
use Xident\SDK\HttpClient;
use Xident\SDK\Responses\InitResult;
use Xident\SDK\Responses\SessionResult;

/**
 * Verification resource — create init tokens and retrieve session results.
 */
final class Verification
{
    /** The lowest and highest `min_age` an age verification accepts. */
    private const MIN_AGE_FLOOR = 12;
    private const MIN_AGE_CEILING = 25;

    private const PURPOSE_ID = 'id_verification';

    private const MISSING_USER_ID_MESSAGE =
        'user_id is required: pass your own identifier for the person being verified';

    private const INVALID_MIN_AGE_MESSAGE =
        'min_age must be between 12 and 25; it is rounded up to the next of 12, 15, 18, 21 or 25 '
        . '(19 is enforced as 21). An id_verification takes no min_age.';

    private const FACIAL_WITH_ID_MESSAGE =
        'verification_mode facial cannot be combined with purpose id_verification, '
        . 'which always requires a document';

    public function __construct(
        private readonly HttpClient $http,
    ) {}

    /**
     * Create an init token for starting a verification session.
     *
     * Returns an init token (`xit_` prefixed) and the full URL to redirect the
     * user to. The init token is valid for 10 minutes.
     *
     * Needs a server key (`sk_live_`, `sk_test_`, `ak_live_` or `ak_test_`). A
     * public key (`pk_`) gets 403 `SECRET_KEY_REQUIRED`. Never put a server key
     * in a browser or a mobile app.
     *
     * Required params:
     * - `callback_url`: HTTPS URL (http://localhost allowed for dev) the widget
     *   redirects the browser back to when done.
     * - `user_id`: required. Your own identifier for the person being
     *   verified. It comes back on the callback and in the result.
     * - `min_age`: required for `age_verification`: a whole number from 12 to
     *   25. Xident rounds it up to the next of 12, 15, 18, 21 or 25 and
     *   enforces that band, so 19 is enforced as 21. An `id_verification`
     *   takes no `min_age` (leave it out, or send 0).
     *
     * Optional params:
     * - `success_url` / `failed_url`: redirect targets for each outcome.
     * - `theme`: `light`, `dark`, or `system`. Unknown values coerce to `system`.
     * - `locale`: one of en, es, fr, de, pt, ar, zh, ja, hi, nl. Unknown → `en`.
     * - `metadata`: an OPAQUE string echoed back to you (e.g. a JSON blob or plan
     *   ID). Xident stores it verbatim and never parses it.
     * - `purpose`: `age_verification` (default) or `id_verification`. An ID
     *   verification requires liveness, a document and a face match.
     * - `verification_mode`: `auto` (default), `document` to force document +
     *   face match, or `facial` to force on-device age estimation. Composes
     *   with `min_age` rather than replacing it. `facial` cannot be combined
     *   with purpose `id_verification`, which always needs a document.
     * - `liveness_difficulty`: `easy` (default), `medium` or `hard`. Any other
     *   value gets 400 `INVALID_LIVENESS_DIFFICULTY` from the API.
     * - `expected`: identity data you already hold about the user, checked
     *   against the document they present (data match, since 2026-09-05). Any
     *   subset of `first_name`, `last_name`, `date_of_birth` (YYYY-MM-DD),
     *   `document_number`, `nationality` (ISO alpha-2). Needs a document, so
     *   pair it with `purpose: id_verification` or `verification_mode: document`.
     *   The values never reach the browser; the result carries only verdicts,
     *   per field, in `checks.data_match`.
     * - `mismatch_policy`: `report` (default) reports mismatches in the result
     *   with the outcome unchanged; `review` sends any mismatch to your review
     *   queue with reason `data_mismatch`. Only meaningful with `expected`.
     *
     * The SDK checks `user_id`, `min_age` and the `id_verification` rules
     * before it sends anything, and throws the same error codes the API
     * answers with: `MISSING_USER_ID`, `INVALID_USER_ID`, `INVALID_MIN_AGE`,
     * `INVALID_VERIFICATION_MODE`. It sends `min_age` as given; the API is the
     * one place that rounds it to the band.
     *
     * @param array{
     *   callback_url: string,
     *   user_id: string,
     *   min_age?: int,
     *   success_url?: string,
     *   failed_url?: string,
     *   theme?: string,
     *   locale?: string,
     *   metadata?: string,
     *   purpose?: string,
     *   verification_mode?: string,
     *   liveness_difficulty?: string,
     *   expected?: array{first_name?: string, last_name?: string, date_of_birth?: string, document_number?: string, nationality?: string},
     *   mismatch_policy?: string,
     * } $params
     *
     * @throws \Xident\SDK\Exceptions\ValidationException If a parameter is
     *         missing or invalid. The local checks throw it with HTTP status 0
     *         and no request ID, because no request was sent.
     * @throws \Xident\SDK\Exceptions\AuthenticationException If API key is invalid
     */
    public function init(array $params): InitResult
    {
        self::validateInitParams($params);

        $response = $this->http->post('/init', $params);
        return InitResult::fromArray($response->data ?? []);
    }

    /**
     * The API's own rules for `user_id`, `min_age` and an ID verification,
     * checked before any request is sent. Codes and messages match the API's
     * 400 answers, so a caller handles one set of codes whichever side
     * refused. The checks run in the API's order: `user_id`, then `min_age`,
     * then the verification mode.
     *
     * @param array<string, mixed> $params
     *
     * @throws ValidationException
     */
    private static function validateInitParams(array $params): void
    {
        $userId = $params['user_id'] ?? null;
        if ($userId !== null && !is_string($userId)) {
            throw new ValidationException('user_id must be a string', 'INVALID_USER_ID');
        }
        if ($userId === null || trim($userId) === '') {
            throw new ValidationException(self::MISSING_USER_ID_MESSAGE, 'MISSING_USER_ID');
        }

        $minAge = $params['min_age'] ?? null;
        if (($params['purpose'] ?? null) === self::PURPOSE_ID) {
            if ($minAge !== null && $minAge !== 0) {
                throw new ValidationException(self::INVALID_MIN_AGE_MESSAGE, 'INVALID_MIN_AGE');
            }
            if (($params['verification_mode'] ?? null) === 'facial') {
                throw new ValidationException(self::FACIAL_WITH_ID_MESSAGE, 'INVALID_VERIFICATION_MODE');
            }
            return;
        }

        // Any other purpose is an age verification, the strict reading the
        // API uses too. A string such as "18" is refused: the API reads
        // min_age as a JSON number and would not accept it either.
        if (!is_int($minAge) || $minAge < self::MIN_AGE_FLOOR || $minAge > self::MIN_AGE_CEILING) {
            throw new ValidationException(self::INVALID_MIN_AGE_MESSAGE, 'INVALID_MIN_AGE');
        }
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
