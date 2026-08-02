<?php

declare(strict_types=1);

namespace Xident\SDK\Responses;

/**
 * A user's face 2FA enrollment state.
 *
 * Mirrors the `GET /verify/v1/2fa/users/{user_id}` DTO.
 */
final readonly class Face2FAEnrollment
{
    public function __construct(
        /** Whether the user has an active face enrollment. */
        public bool $enrolled,
        /** RFC 3339 timestamp of the enrollment. Null when not enrolled. */
        public ?string $enrolledAt = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            enrolled: (bool)($data['enrolled'] ?? false),
            enrolledAt: $data['enrolled_at'] ?? null,
        );
    }
}
