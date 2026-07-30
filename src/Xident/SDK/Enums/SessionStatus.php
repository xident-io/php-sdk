<?php

declare(strict_types=1);

namespace Xident\SDK\Enums;

/**
 * Verification session states.
 *
 * The API uses ONE vocabulary for the outcome across every surface — the
 * result endpoint, the webhook payload and the browser callback's `?status=`
 * query parameter all use these same words. Earlier versions of this SDK
 * documented a spelling difference between the callback and the result
 * endpoint; that is no longer true.
 */
enum SessionStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';

    /**
     * The PASS verdict: the user met the age threshold and every required
     * check passed.
     *
     * This is a verdict, not a lifecycle marker. A session that ran to the
     * end of the flow but did not meet the threshold is `Failed`, not this.
     */
    case Success = 'success';

    case Failed = 'failed';
    case Canceled = 'canceled';
    case Claimed = 'claimed';

    /**
     * @deprecated The API renamed this verdict from `completed` to `success`
     * in July 2026, because "completed" described a lifecycle and was read by
     * at least one integrator as "the flow finished" regardless of the age
     * result. Use {@see SessionStatus::Success}.
     *
     * This is a CONSTANT, not a case: a backed enum cannot have two cases
     * with the same value, and the alias has to resolve to the new value
     * rather than the old one. Enum cases are singletons, so an existing
     * `$status === SessionStatus::Completed` comparison still works and still
     * returns true for a verified user. Had this stayed a case backed by
     * `'completed'` it would keep working syntactically and quietly report
     * every verified user as unverified.
     */
    public const Completed = self::Success;

    /**
     * The value the pass verdict carried before July 2026.
     *
     * Kept only so an SDK pointed at a deployment older than that rename
     * still behaves — an SDK outlives a rollout window, and gets aimed at
     * self-hosted and lagging installs long after our own migration finished.
     */
    private const LEGACY_SUCCESS = 'completed';

    /**
     * Map a raw wire status onto the current vocabulary.
     *
     * Returns null for anything unrecognised, so the caller decides what to
     * do with it rather than this method inventing a verdict.
     */
    public static function normalize(string $raw): ?self
    {
        return self::tryFrom($raw === self::LEGACY_SUCCESS ? self::Success->value : $raw);
    }

    /** Whether the session has reached a terminal state. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Success, self::Failed, self::Canceled, self::Claimed => true,
            default => false,
        };
    }
}
