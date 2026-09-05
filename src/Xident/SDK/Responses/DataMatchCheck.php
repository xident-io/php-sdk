<?php

declare(strict_types=1);

namespace Xident\SDK\Responses;

/**
 * Whether the identity data you supplied at init (`expected`) agrees with the
 * presented document, field by field.
 *
 * Sent by the API since 2026-09-05 and OPTIONAL on the wire:
 * `ResultChecks::$dataMatch` is null when the check was not performed (no
 * `expected` supplied, no document read, or the reference never reached the
 * session). Gate on `$checks->dataMatch?->passed === true`, which fails
 * closed. The values themselves are never returned.
 */
final readonly class DataMatchCheck
{
    public function __construct(
        /** Whether the comparison ran. */
        public bool $performed,
        /** True only when every requested field matched. */
        public bool $passed,
        /** Per-field outcomes. */
        public DataMatchFields $fields,
    ) {}

    /**
     * Returns null for an absent (or non-array) `data_match`.
     *
     * @param mixed $data
     */
    public static function fromMixed(mixed $data): ?self
    {
        if (!is_array($data)) {
            return null;
        }
        $fields = is_array($data['fields'] ?? null) ? $data['fields'] : [];
        return new self(
            performed: (bool)($data['performed'] ?? false),
            passed: (bool)($data['passed'] ?? false),
            fields: DataMatchFields::fromArray($fields),
        );
    }
}
