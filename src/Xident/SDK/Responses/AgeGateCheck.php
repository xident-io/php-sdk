<?php

declare(strict_types=1);

namespace Xident\SDK\Responses;

/**
 * The `checks.age` entry of the v1 result contract
 * (`GET /verify/v1/result/{token}`).
 */
final readonly class AgeGateCheck
{
    public function __construct(
        /** Whether an age check actually ran for the session. */
        public bool $performed,
        /** Whether the user met the gate. Always false when `$performed` is false. */
        public bool $passed,
        /**
         * The age threshold the session was configured with (the `min_age`
         * that started it). Set from the session's own configuration, so it
         * is meaningful even when `$performed` is false — the API always
         * knows the gate a session was created against, whether or not the
         * age check ever ran.
         */
        public int $gate,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            performed: (bool)($data['performed'] ?? false),
            passed: (bool)($data['passed'] ?? false),
            gate: (int)($data['gate'] ?? 0),
        );
    }
}
