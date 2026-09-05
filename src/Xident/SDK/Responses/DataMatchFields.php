<?php

declare(strict_types=1);

namespace Xident\SDK\Responses;

/**
 * One outcome per field you asked about in `expected` at init; fields you did
 * not supply are null. Each value is `match`, `mismatch` or `not_on_document`
 * (the document does not carry the field, so it could neither confirm nor
 * contradict it; blocks `passed`).
 */
final readonly class DataMatchFields
{
    public const MATCH = 'match';
    public const MISMATCH = 'mismatch';
    public const NOT_ON_DOCUMENT = 'not_on_document';

    public function __construct(
        public ?string $firstName,
        public ?string $lastName,
        public ?string $dateOfBirth,
        public ?string $documentNumber,
        public ?string $nationality,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            firstName: self::outcome($data, 'first_name'),
            lastName: self::outcome($data, 'last_name'),
            dateOfBirth: self::outcome($data, 'date_of_birth'),
            documentNumber: self::outcome($data, 'document_number'),
            nationality: self::outcome($data, 'nationality'),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function outcome(array $data, string $key): ?string
    {
        $v = $data[$key] ?? null;
        return in_array($v, [self::MATCH, self::MISMATCH, self::NOT_ON_DOCUMENT], true) ? $v : null;
    }
}
