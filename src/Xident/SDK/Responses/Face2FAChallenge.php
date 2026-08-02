<?php

declare(strict_types=1);

namespace Xident\SDK\Responses;

/**
 * Result of submitting a face 2FA register or verify call.
 *
 * Both calls are asynchronous: the API returns a challenge in `processing`
 * status. Poll {@see \Xident\SDK\Resources\Face2FA::getStatus()} with the
 * challenge ID until the status is terminal.
 */
final readonly class Face2FAChallenge
{
    public function __construct(
        /** Challenge ID — poll getStatus() with this. */
        public string $challengeId,
        /** Challenge status — `processing` right after submission. */
        public string $status,
    ) {}

    /** The challenge is still being processed by the worker. */
    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            challengeId: (string)($data['challenge_id'] ?? ''),
            status: (string)($data['status'] ?? ''),
        );
    }
}
