<?php

declare(strict_types=1);

namespace Xident\SDK\Responses;

/**
 * Status of a face 2FA challenge (register or verify).
 *
 * Mirrors the `GET /verify/v1/2fa/status/{id}` DTO. Deliberately contains
 * pass/fail only — the API never returns confidence scores or biometric data.
 */
final readonly class Face2FAStatus
{
    public function __construct(
        /** Challenge ID. */
        public string $challengeId,
        /** What the challenge tracks: `enroll` or `verify`. */
        public string $kind,
        /** `processing`, `completed`, `failed`, or `expired`. */
        public string $status,
        /**
         * Pass verdict. Null while the challenge is still processing;
         * true when completed, false when failed or expired.
         */
        public ?bool $passed = null,
        /**
         * Why the challenge failed. Known values: invalid_image,
         * no_face_detected, not_enrolled, face_mismatch, blacklist_match,
         * expired, internal_error. Treat the set as open — new reasons may
         * be added, so always handle a default.
         */
        public ?string $failureReason = null,
        /** RFC 3339 timestamp after which the challenge expires. */
        public string $expiresAt = '',
        /** RFC 3339 timestamp when the challenge reached a terminal state. */
        public ?string $completedAt = null,
    ) {}

    /** The challenge is still being processed — keep polling. */
    public function isProcessing(): bool
    {
        return $this->passed === null;
    }

    /** The challenge has reached a terminal state (no more changes possible). */
    public function isTerminal(): bool
    {
        return $this->passed !== null;
    }

    /**
     * The challenge PASSED. This is the check to gate on.
     *
     * False both while processing and after a failure — check
     * {@see self::isTerminal()} first when you need to distinguish
     * "still running" from "failed".
     */
    public function hasPassed(): bool
    {
        return $this->passed === true;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            challengeId: (string)($data['challenge_id'] ?? ''),
            kind: (string)($data['kind'] ?? ''),
            status: (string)($data['status'] ?? ''),
            passed: isset($data['passed']) ? (bool)$data['passed'] : null,
            failureReason: $data['failure_reason'] ?? null,
            expiresAt: (string)($data['expires_at'] ?? ''),
            completedAt: $data['completed_at'] ?? null,
        );
    }
}
