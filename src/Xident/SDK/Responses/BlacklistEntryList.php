<?php

declare(strict_types=1);

namespace Xident\SDK\Responses;

/**
 * One page of face blacklist entries plus pagination metadata.
 *
 * The rows come from the envelope's `data` array; pagination comes from
 * `meta.pagination` ({page, per_page, total, total_pages}).
 */
final readonly class BlacklistEntryList
{
    /**
     * @param list<BlacklistEntry> $entries
     */
    public function __construct(
        public array $entries,
        public int $page,
        public int $perPage,
        /** Total entries across all pages. */
        public int $total,
        public int $totalPages,
    ) {}

    /** Number of entries on THIS page (not the overall total). */
    public function count(): int
    {
        return count($this->entries);
    }

    /** Whether another page exists after this one. */
    public function hasMore(): bool
    {
        return $this->page < $this->totalPages;
    }

    /**
     * @param array<int, array<string, mixed>>|null $data Envelope `data` (the rows)
     * @param array<string, mixed>|null $meta Envelope `meta` (holds `pagination`)
     */
    public static function fromResponse(?array $data, ?array $meta): self
    {
        $entries = [];
        foreach ($data ?? [] as $row) {
            if (is_array($row)) {
                $entries[] = BlacklistEntry::fromArray($row);
            }
        }

        $pagination = $meta['pagination'] ?? [];

        return new self(
            entries: $entries,
            page: (int)($pagination['page'] ?? 1),
            perPage: (int)($pagination['per_page'] ?? count($entries)),
            total: (int)($pagination['total'] ?? count($entries)),
            totalPages: (int)($pagination['total_pages'] ?? 1),
        );
    }
}
