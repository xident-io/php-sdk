<?php

declare(strict_types=1);

namespace Xident\SDK\Responses;

/**
 * The session's risk assessment.
 *
 * An object rather than a bare string, mirroring the wire, so future signals
 * get a home without a breaking change.
 *
 * Sent by the API since 2026-08-06 and silently discarded by this SDK until
 * 3.1.0: `fromArray` only reads the keys it names, and the golden fixture was a
 * stale copy that did not contain this one either — so the fixture and the class
 * agreed with each other while both disagreed with the API.
 */
final readonly class Risk
{
    public function __construct(
        /** Coarse risk bucket: "low", "medium" or "high". Treat the set as open. */
        public string $band,
    ) {}

    /**
     * Returns null for an absent OR empty object.
     *
     * `risk` is omitempty on the wire, so absent means "no risk signals", which
     * is materially different from a band of "". Callers branch on
     * `$result->risk === null`, so collapsing the two would silently change
     * their control flow.
     *
     * @param mixed $data
     */
    public static function fromMixed(mixed $data): ?self
    {
        if (!is_array($data) || $data === []) {
            return null;
        }

        return new self(band: is_string($data['band'] ?? null) ? $data['band'] : '');
    }
}
