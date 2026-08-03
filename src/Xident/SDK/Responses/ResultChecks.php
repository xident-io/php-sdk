<?php

declare(strict_types=1);

namespace Xident\SDK\Responses;

/**
 * The `checks` object of the v1 result contract (`GET /verify/v1/result/{token}`).
 *
 * One entry per verification step the "Verify Once" flow can run: liveness,
 * age bracket recognition, document verification, and face match. All four
 * are ALWAYS present — a step that never ran for a session still reports
 * `performed: false`, it is never an absent key.
 */
final readonly class ResultChecks
{
    public function __construct(
        public CheckResult $liveness,
        public AgeGateCheck $age,
        public DocumentCheck $document,
        public CheckResult $faceMatch,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            liveness: CheckResult::fromArray(self::subArray($data, 'liveness')),
            age: AgeGateCheck::fromArray(self::subArray($data, 'age')),
            document: DocumentCheck::fromArray(self::subArray($data, 'document')),
            faceMatch: CheckResult::fromArray(self::subArray($data, 'face_match')),
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function subArray(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : [];
    }
}
