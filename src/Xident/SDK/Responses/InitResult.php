<?php

declare(strict_types=1);

namespace Xident\SDK\Responses;

/**
 * Result of creating an init token.
 *
 * Contains the token and the full URL to redirect the user to for verification.
 */
final readonly class InitResult
{
    public function __construct(
        /** Short-lived init token (xit_ prefixed, 10-minute TTL). */
        public string $token,
        /** Full verification URL — redirect the user here. */
        public string $verifyUrl,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            token: (string)($data['token'] ?? ''),
            verifyUrl: (string)($data['verify_url'] ?? ''),
        );
    }
}
