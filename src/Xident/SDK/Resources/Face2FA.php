<?php

declare(strict_types=1);

namespace Xident\SDK\Resources;

use Xident\SDK\HttpClient;
use Xident\SDK\Responses\Face2FAChallenge;
use Xident\SDK\Responses\Face2FAEnrollment;
use Xident\SDK\Responses\Face2FAStatus;

/**
 * Face 2FA resource — enroll and verify faces as a second factor.
 *
 * Register stores (or replaces) a face for one of YOUR users; verify runs a
 * 1:1 comparison against the enrolled face. Both are asynchronous: they
 * return a challenge in `processing` status which you poll via getStatus()
 * until it reaches a terminal state. The API returns pass/fail only — never
 * confidence scores or biometric data.
 *
 * User IDs are your own opaque identifiers (max 255 chars), scoped to your
 * tenant. Images are base64-encoded (max ~10 MB of base64, ≈7.5 MB decoded).
 */
final class Face2FA
{
    public function __construct(
        private readonly HttpClient $http,
    ) {}

    /**
     * Register (enroll) a face for a user.
     *
     * Stores or replaces the user's face enrollment. Registration is free of
     * charge. Async — poll getStatus() with the returned challenge ID.
     *
     * @param string $userId Your user identifier (max 255 chars)
     * @param string $image  Base64-encoded face image
     *
     * @throws \InvalidArgumentException If userId or image is empty
     * @throws \Xident\SDK\Exceptions\ValidationException If the request is invalid
     * @throws \Xident\SDK\Exceptions\AuthenticationException If API key is invalid
     */
    public function register(string $userId, string $image): Face2FAChallenge
    {
        return $this->submit('/2fa/register', $userId, $image);
    }

    /**
     * Verify a face against the user's enrolled face.
     *
     * Async — poll getStatus() with the returned challenge ID for pass/fail.
     * Fails with reason `not_enrolled` when the user has no enrollment.
     *
     * @param string $userId Your user identifier (max 255 chars)
     * @param string $image  Base64-encoded face image
     *
     * @throws \InvalidArgumentException If userId or image is empty
     * @throws \Xident\SDK\Exceptions\ValidationException If the request is invalid
     * @throws \Xident\SDK\Exceptions\AuthenticationException If API key is invalid
     */
    public function verify(string $userId, string $image): Face2FAChallenge
    {
        return $this->submit('/2fa/verify', $userId, $image);
    }

    /**
     * Get the status of a register or verify challenge.
     *
     * Poll until {@see Face2FAStatus::isTerminal()} — `passed` stays null
     * while the challenge is processing.
     *
     * @throws \InvalidArgumentException If challengeId is empty
     * @throws \Xident\SDK\Exceptions\NotFoundException If the challenge does not exist
     * @throws \Xident\SDK\Exceptions\AuthenticationException If API key is invalid
     */
    public function getStatus(string $challengeId): Face2FAStatus
    {
        if ($challengeId === '') {
            throw new \InvalidArgumentException('Challenge ID cannot be empty');
        }

        $response = $this->http->get('/2fa/status/' . urlencode($challengeId));
        return Face2FAStatus::fromArray($response->data ?? []);
    }

    /**
     * Check whether a user has a face enrolled for 2FA.
     *
     * @throws \InvalidArgumentException If userId is empty
     * @throws \Xident\SDK\Exceptions\AuthenticationException If API key is invalid
     */
    public function getUser(string $userId): Face2FAEnrollment
    {
        if ($userId === '') {
            throw new \InvalidArgumentException('User ID cannot be empty');
        }

        $response = $this->http->get('/2fa/users/' . urlencode($userId));
        return Face2FAEnrollment::fromArray($response->data ?? []);
    }

    /**
     * Delete a user's face enrollment (GDPR hard delete).
     *
     * Idempotent — succeeds whether or not an enrollment existed.
     *
     * @return bool True when the deletion was processed
     *
     * @throws \InvalidArgumentException If userId is empty
     * @throws \Xident\SDK\Exceptions\AuthenticationException If API key is invalid
     */
    public function deleteUser(string $userId): bool
    {
        if ($userId === '') {
            throw new \InvalidArgumentException('User ID cannot be empty');
        }

        $response = $this->http->delete('/2fa/users/' . urlencode($userId));
        return (bool)($response->data['deleted'] ?? false);
    }

    /** Shared by register() and verify() — same request shape, different path. */
    private function submit(string $path, string $userId, string $image): Face2FAChallenge
    {
        if ($userId === '') {
            throw new \InvalidArgumentException('User ID cannot be empty');
        }
        if ($image === '') {
            throw new \InvalidArgumentException('Image cannot be empty');
        }

        $response = $this->http->post($path, [
            'user_id' => $userId,
            'image'   => $image,
        ]);

        return Face2FAChallenge::fromArray($response->data ?? []);
    }
}
