<?php

declare(strict_types=1);

namespace Xident\SDK\Responses;

use Xident\SDK\Enums\SessionStatus;

/**
 * Verification session result.
 *
 * Contains the full session state including liveness, age, and OCR results.
 * Use the helper methods to check the verification outcome.
 */
final readonly class SessionResult
{
    /**
     * @param array<string, mixed>|null $livenessResult
     * @param array<string, mixed>|null $ageResult
     * @param array<string, mixed>|null $ocrResult
     * @param array<string, mixed>|null $faceMatchResult
     * @param list<string>|null $requiredMethods
     *
     * Fields mirror the `GET /verify/v1/result/{token}` response DTO. The
     * `$token` here is the RESULT token (`xtk_` prefixed) — the same one the
     * widget appends to your callback URL, NOT the short-lived `xit_` init token.
     */
    public function __construct(
        public string $token,
        public SessionStatus $status,
        public ?array $livenessResult = null,
        public ?array $ageResult = null,
        public ?array $ocrResult = null,
        public ?array $faceMatchResult = null,
        public ?string $ocrTaskId = null,
        public ?string $countryCode = null,
        public ?string $regime = null,
        public ?array $requiredMethods = null,
        public ?int $remainingAttempts = null,
        public string $createdAt = '',
        public ?string $expiresAt = null,
        /**
         * Why a non-success terminal status came out that way. Empty when
         * $status is Success.
         *
         * Known values: age_below_threshold, dob_unreadable, face_mismatch,
         * face_not_detected, docverify_reject, blacklist_match. Treat the set
         * as open — new reasons may be added, so always handle a default.
         *
         * Declared last so existing positional construction keeps working.
         */
        public string $reason = '',
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

    /** The verified age bracket (12, 15, 18, 21, 25) or null if not yet determined. */
    public function ageBracket(): ?int
    {
        return isset($this->ageResult['verified_bracket'])
            ? (int)$this->ageResult['verified_bracket']
            : (isset($this->ageResult['estimated_age']) ? (int)$this->ageResult['estimated_age'] : null);
    }

    /** How the age was verified (e.g. "ml_fast", "ocr", "self_declaration"). */
    public function method(): ?string
    {
        return $this->ageResult['method'] ?? null;
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
            livenessResult: $data['liveness_result'] ?? null,
            ageResult: $data['age_result'] ?? null,
            ocrResult: $data['ocr_result'] ?? null,
            faceMatchResult: $data['face_match_result'] ?? null,
            ocrTaskId: $data['ocr_task_id'] ?? null,
            countryCode: $data['country_code'] ?? null,
            regime: $data['regime'] ?? null,
            requiredMethods: $data['required_methods'] ?? null,
            remainingAttempts: isset($data['remaining_attempts']) ? (int)$data['remaining_attempts'] : null,
            createdAt: (string)($data['created_at'] ?? ''),
            expiresAt: $data['expires_at'] ?? null,
            reason: (string)($data['reason'] ?? ''),
        );
    }
}
