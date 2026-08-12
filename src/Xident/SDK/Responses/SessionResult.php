<?php

declare(strict_types=1);

namespace Xident\SDK\Responses;

use Xident\SDK\Enums\SessionStatus;

/**
 * Verification session result.
 *
 * Mirrors the v1 tenant result contract — the `data` of
 * `GET /verify/v1/result/{token}` — which is FROZEN as of 2026-08-03
 * (additive-only from here on; see `checks` for the per-step detail).
 * Use the helper methods to check the verification outcome.
 */
final readonly class SessionResult
{
    /**
     * Fields mirror the `GET /verify/v1/result/{token}` response DTO. The
     * `$token` here is the RESULT token (`xtk_` prefixed) — the same one the
     * widget appends to your callback URL, NOT the short-lived `xit_` init token.
     */
    public function __construct(
        public string $token,
        public SessionStatus $status,
        /** The pass verdict. Redundant with `$status === SessionStatus::Success` — use {@see self::isVerified()}. */
        public bool $verified,
        /**
         * Why a non-success terminal status came out that way. Empty when
         * $status is Success.
         *
         * Known values: age_below_threshold, dob_unreadable, face_mismatch,
         * face_not_detected, docverify_reject, blacklist_match. Treat the set
         * as open — new reasons may be added, so always handle a default.
         */
        public string $reason,
        /**
         * Which PATH produced the verdict: "full" (document + face match),
         * "age_check" (browser-only), "xident_id" (Xident-ID reuse) or
         * "eu_wallet". Treat the set as open. Null until known.
         */
        public ?string $verificationMode,
        /**
         * The ISO 3166-1 alpha-2 country the end user connected from,
         * IP-derived. Null on sessions created before 2026-08-04, or where
         * IP geolocation failed. Distinct from `$checks->document->country`,
         * which is the document's issuing country — the two can
         * legitimately differ.
         */
        public ?string $ipCountry,
        /** Your own user ID, echoed back if supplied at init. Null if you did not supply one. */
        public ?string $externalUserId,
        /** Per-step detail: liveness, age, document, face_match. */
        public ResultChecks $checks,
        public string $createdAt,
        /** RFC 3339 timestamp the session reached a terminal state. Null while still in progress. */
        public ?string $completedAt,
        public ?string $expiresAt,
    ) {}

    /**
     * The user PASSED verification. This is the check to gate on.
     *
     * False for a session that ran all the way through the flow but did not
     * meet the age threshold — that session is Failed with $reason
     * `age_below_threshold`.
     */
    public function isVerified(): bool
    {
        return $this->status === SessionStatus::Success;
    }

    /**
     * @deprecated Alias of {@see self::isVerified()}. Its docblock used to
     * claim "any outcome", which the code never did — it has always returned
     * the pass verdict only. For "reached any terminal state" use
     * {@see self::isTerminal()}.
     */
    public function isCompleted(): bool
    {
        return $this->isVerified();
    }

    /** Session failed verification. */
    public function isFailed(): bool
    {
        return $this->status === SessionStatus::Failed;
    }

    /** Session is still in progress (pending or in_progress). */
    public function isPending(): bool
    {
        return $this->status === SessionStatus::Pending
            || $this->status === SessionStatus::InProgress;
    }

    /** Session has reached a terminal state (no more changes possible). */
    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * The verified age bracket (12, 15, 18, 21, 25), or null when the age
     * check did not pass (including when it never ran).
     */
    public function ageBracket(): ?int
    {
        return $this->checks->age->passed ? $this->checks->age->gate : null;
    }

    /**
     * Which PATH produced the verdict: "full" (document + face match),
     * "age_check" (browser-only), "xident_id" (Xident-ID reuse) or
     * "eu_wallet". Treat the set as open. Alias of `$verificationMode`.
     */
    public function method(): ?string
    {
        return $this->verificationMode;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            token: (string)($data['token'] ?? ''),
            // normalize() maps a legacy `completed` from a pre-July-2026
            // deployment onto Success. An unrecognised value falls back to
            // Pending, which is neither terminal nor verified — so a caller
            // polling for an outcome keeps polling rather than treating
            // something it does not understand as a finished verification.
            status: SessionStatus::normalize((string)($data['status'] ?? 'pending')) ?? SessionStatus::Pending,
            verified: (bool)($data['verified'] ?? false),
            reason: (string)($data['reason'] ?? ''),
            verificationMode: isset($data['verification_mode']) ? (string)$data['verification_mode'] : null,
            ipCountry: isset($data['ip_country']) ? (string)$data['ip_country'] : null,
            externalUserId: isset($data['external_user_id']) ? (string)$data['external_user_id'] : null,
            // A pre-v1 payload (or anything malformed) has no usable `checks`
            // object at all — ResultChecks::fromArray([]) degrades every
            // sub-check to performed:false rather than throwing, which is
            // what keeps an old deployment's response constructible.
            checks: ResultChecks::fromArray(is_array($data['checks'] ?? null) ? $data['checks'] : []),
            createdAt: (string)($data['created_at'] ?? ''),
            completedAt: isset($data['completed_at']) ? (string)$data['completed_at'] : null,
            expiresAt: isset($data['expires_at']) ? (string)$data['expires_at'] : null,
        );
    }
}
