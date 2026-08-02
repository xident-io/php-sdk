<?php

declare(strict_types=1);

namespace Xident\SDK\Resources;

use Xident\SDK\HttpClient;
use Xident\SDK\Responses\BlacklistEntryList;

/**
 * Blacklist resource — manage your face blacklist.
 *
 * Entries are added by SESSION or IMAGE, never by raw embedding — the face
 * embedding is derived server-side and never leaves the server. Both add
 * calls are asynchronous: they return `processing` and the entry appears in
 * list() once the worker has extracted the embedding.
 */
final class Blacklist
{
    public function __construct(
        private readonly HttpClient $http,
    ) {}

    /**
     * List your active blacklist entries (paginated).
     *
     * @param int $page    Page number (>= 1)
     * @param int $perPage Items per page (1-100)
     *
     * @throws \InvalidArgumentException If page or perPage is out of range
     * @throws \Xident\SDK\Exceptions\AuthenticationException If API key is invalid
     */
    public function list(int $page = 1, int $perPage = 20): BlacklistEntryList
    {
        if ($page < 1) {
            throw new \InvalidArgumentException('Page must be >= 1');
        }
        if ($perPage < 1 || $perPage > 100) {
            throw new \InvalidArgumentException('Per page must be between 1 and 100');
        }

        $response = $this->http->get('/blacklist', [
            'page'     => $page,
            'per_page' => $perPage,
        ]);

        return BlacklistEntryList::fromResponse($response->data, $response->meta);
    }

    /**
     * Blacklist the person from one of YOUR verification sessions.
     *
     * The server lifts the selfie from the session and blacklists that face.
     * Only works for sessions that captured a selfie, within the 24-hour
     * document-retention window, and only after the session has reached a
     * terminal state (a still-running session is rejected with HTTP 409).
     *
     * Async — returns `processing`; the entry appears in list() once done.
     *
     * @param string $sessionToken The session's result token (max 100 chars)
     * @param string $reason       Why the face is being blacklisted (max 500 chars)
     *
     * @return string Queue status — `processing`
     *
     * @throws \InvalidArgumentException If sessionToken or reason is empty
     * @throws \Xident\SDK\Exceptions\NotFoundException If the session does not exist or is not yours
     * @throws \Xident\SDK\Exceptions\ValidationException If the session is still in progress (HTTP 409)
     * @throws \Xident\SDK\Exceptions\AuthenticationException If API key is invalid
     */
    public function addBySession(string $sessionToken, string $reason): string
    {
        if ($sessionToken === '') {
            throw new \InvalidArgumentException('Session token cannot be empty');
        }
        if ($reason === '') {
            throw new \InvalidArgumentException('Reason cannot be empty');
        }

        $response = $this->http->post('/blacklist/session', [
            'session_token' => $sessionToken,
            'reason'        => $reason,
        ]);

        return (string)($response->data['status'] ?? '');
    }

    /**
     * Blacklist the face in an image.
     *
     * The face embedding is extracted server-side. Async — returns
     * `processing`; the entry appears in list() once done.
     *
     * @param string $image  Base64-encoded image (max ~10 MB of base64)
     * @param string $reason Why the face is being blacklisted (max 500 chars)
     *
     * @return string Queue status — `processing`
     *
     * @throws \InvalidArgumentException If image or reason is empty
     * @throws \Xident\SDK\Exceptions\ValidationException If the request is invalid
     * @throws \Xident\SDK\Exceptions\AuthenticationException If API key is invalid
     */
    public function addByImage(string $image, string $reason): string
    {
        if ($image === '') {
            throw new \InvalidArgumentException('Image cannot be empty');
        }
        if ($reason === '') {
            throw new \InvalidArgumentException('Reason cannot be empty');
        }

        $response = $this->http->post('/blacklist/image', [
            'image'  => $image,
            'reason' => $reason,
        ]);

        return (string)($response->data['status'] ?? '');
    }

    /**
     * Remove (deactivate) a blacklist entry — un-ban.
     *
     * The audit record survives server-side; retention hard-deletes the
     * deactivated biometric data later.
     *
     * @param int $id Blacklist entry ID (from list())
     *
     * @return bool True when the entry was removed
     *
     * @throws \InvalidArgumentException If id is not positive
     * @throws \Xident\SDK\Exceptions\NotFoundException If the entry does not exist or is not yours
     * @throws \Xident\SDK\Exceptions\AuthenticationException If API key is invalid
     */
    public function remove(int $id): bool
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('Blacklist entry ID must be a positive integer');
        }

        $this->http->delete('/blacklist/' . $id);
        return true;
    }
}
