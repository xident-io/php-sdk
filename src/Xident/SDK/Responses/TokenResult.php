<?php

declare(strict_types=1);

namespace Xident\SDK\Responses;

/**
 * Result of verifying a Xident verification token.
 */
final readonly class TokenResult
{
    public function __construct(
        public bool $valid,
        /** Verified age bracket (12, 15, 18, 21, 25) or null if invalid. */
        public ?int $ageBracket = null,
        /** How the age was verified (e.g. "ml_fast", "ocr", "token"). */
        public ?string $method = null,
        /** When this token expires (ISO 8601). */
        public ?string $expiresAt = null,
    ) {}

    /** Whether the token is valid and not expired. */
    public function isValid(): bool
    {
        return $this->valid;
    }

    /** Whether the token is valid AND the age bracket meets the minimum age requirement. */
    public function meetsMinAge(int $minAge): bool
    {
        return $this->valid && $this->ageBracket !== null && $this->ageBracket >= $minAge;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            valid: (bool)($data['valid'] ?? false),
            ageBracket: isset($data['age_bracket']) ? (int)$data['age_bracket'] : null,
            method: $data['method'] ?? null,
            expiresAt: $data['expires_at'] ?? null,
        );
    }
}
