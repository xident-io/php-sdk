<?php

declare(strict_types=1);

namespace Xident\SDK\Responses;

/**
 * The `checks.document` entry of the v1 result contract
 * (`GET /verify/v1/result/{token}`) — the OCR/document-verification path
 * (liveness + age bracket recognition failing over to document upload, or a
 * restricted country requiring it outright).
 */
final readonly class DocumentCheck
{
    public function __construct(
        /** Whether document verification actually ran for the session. */
        public bool $performed,
        /** Whether it passed. Always false when `$performed` is false. */
        public bool $passed,
        /** e.g. "passport", "drivers_license", "national_id". Null until determined. */
        public ?string $documentType = null,
        /**
         * ISO 3166-1 alpha-2 country code. This is IP-derived and known for
         * the session regardless of whether the document check ran — it is
         * not something the document itself establishes.
         */
        public ?string $country = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            performed: (bool)($data['performed'] ?? false),
            passed: (bool)($data['passed'] ?? false),
            documentType: isset($data['document_type']) ? (string)$data['document_type'] : null,
            country: isset($data['country']) ? (string)$data['country'] : null,
        );
    }
}
