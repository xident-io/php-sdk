<?php

declare(strict_types=1);

namespace Xident\SDK\Responses;

/**
 * Pass/fail verdict for one binary verification check — `liveness` or
 * `face_match` in the `checks` object of the v1 result contract
 * (`GET /verify/v1/result/{token}`). `age` and `document` carry extra fields
 * of their own; see {@see AgeGateCheck} and {@see DocumentCheck}.
 */
final readonly class CheckResult
{
    public function __construct(
        /** Whether this check actually ran for the session. */
        public bool $performed,
        /** Whether it passed. Always false when `$performed` is false. */
        public bool $passed,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            performed: (bool)($data['performed'] ?? false),
            passed: (bool)($data['passed'] ?? false),
        );
    }
}
