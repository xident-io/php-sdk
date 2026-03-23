<?php

declare(strict_types=1);

namespace Xident\SDK\Responses;

/**
 * Parsed API response envelope.
 *
 * The Xident API wraps all responses in:
 * { success: bool, data: T, error?: { code, message }, meta?: { request_id, timestamp } }
 *
 * @internal Not part of the public API — used by HttpClient.
 */
final readonly class ApiResponse
{
    /**
     * @param array<string, mixed>|null $data
     * @param array{code: string, message: string}|null $error
     * @param array<string, mixed>|null $meta
     */
    public function __construct(
        public bool $success,
        public int $httpStatus,
        public ?array $data = null,
        public ?array $error = null,
        public ?array $meta = null,
    ) {}

    public function requestId(): ?string
    {
        return $this->meta['request_id'] ?? null;
    }

    public function errorCode(): string
    {
        return $this->error['code'] ?? '';
    }

    public function errorMessage(): string
    {
        return $this->error['message'] ?? 'Unknown error';
    }

    public static function fromJson(string $json, int $httpStatus): self
    {
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            return new self(
                success: false,
                httpStatus: $httpStatus,
                error: ['code' => 'PARSE_ERROR', 'message' => 'Failed to parse API response'],
            );
        }

        return new self(
            success: (bool)($decoded['success'] ?? false),
            httpStatus: $httpStatus,
            data: $decoded['data'] ?? null,
            error: $decoded['error'] ?? null,
            meta: $decoded['meta'] ?? null,
        );
    }
}
