<?php

declare(strict_types=1);

namespace Xident\SDK\Responses;

/**
 * One face blacklist entry.
 *
 * Mirrors the row DTO of `GET /verify/v1/blacklist`. Face embeddings never
 * leave the server — an entry is metadata only.
 */
final readonly class BlacklistEntry
{
    public function __construct(
        /** Entry ID — pass to remove() to un-ban. */
        public int $id,
        /** Why the face was blacklisted. */
        public string $reason,
        /** How the entry was created (e.g. "session", "image"). */
        public string $source,
        /** Source verification session ID, when created from a session. */
        public ?int $sessionId = null,
        /** RFC 3339 creation timestamp. */
        public string $createdAt = '',
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int)($data['id'] ?? 0),
            reason: (string)($data['reason'] ?? ''),
            source: (string)($data['source'] ?? ''),
            sessionId: isset($data['session_id']) ? (int)$data['session_id'] : null,
            createdAt: (string)($data['created_at'] ?? ''),
        );
    }
}
