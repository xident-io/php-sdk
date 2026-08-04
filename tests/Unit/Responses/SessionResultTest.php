<?php

declare(strict_types=1);

namespace Xident\SDK\Tests\Unit\Responses;

use PHPUnit\Framework\TestCase;
use Xident\SDK\Enums\SessionStatus;
use Xident\SDK\Responses\SessionResult;

/**
 * SessionResult mirrors the v1 tenant result contract: `GET /verify/v1/result/{token}`
 * `data` — frozen, additive-only (see api/internal/domain/services/testdata/tenant_result_v1.golden.json,
 * the byte-for-byte fixture copied into tests/Fixtures/ for this suite).
 */
final class SessionResultTest extends TestCase
{
    private const GOLDEN_FIXTURE = __DIR__ . '/../../Fixtures/tenant_result_v1.golden.json';

    /**
     * @return array<string, mixed>
     */
    private function goldenFixture(): array
    {
        $json = file_get_contents(self::GOLDEN_FIXTURE);
        $this->assertIsString($json, 'golden fixture missing: ' . self::GOLDEN_FIXTURE);

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);

        return $data;
    }

    public function testFromArrayParsesTheGoldenFixture(): void
    {
        $result = SessionResult::fromArray($this->goldenFixture());

        $this->assertSame('xtk_golden0001', $result->token);
        $this->assertSame(SessionStatus::Success, $result->status);
        $this->assertTrue($result->verified);
        $this->assertSame('', $result->reason);
        $this->assertSame('full', $result->verificationMode);
        $this->assertSame('DE', $result->ipCountry);
        $this->assertSame('cust-4711', $result->externalUserId);

        $this->assertTrue($result->checks->liveness->performed);
        $this->assertTrue($result->checks->liveness->passed);

        $this->assertTrue($result->checks->age->performed);
        $this->assertTrue($result->checks->age->passed);
        $this->assertSame(21, $result->checks->age->gate);

        $this->assertTrue($result->checks->document->performed);
        $this->assertTrue($result->checks->document->passed);
        $this->assertSame('passport', $result->checks->document->documentType);
        $this->assertSame('DE', $result->checks->document->country);

        $this->assertTrue($result->checks->faceMatch->performed);
        $this->assertTrue($result->checks->faceMatch->passed);

        $this->assertSame('2026-08-03T10:00:00Z', $result->createdAt);
        $this->assertSame('2026-08-03T10:02:30Z', $result->completedAt);
        $this->assertSame('2026-08-03T10:15:00Z', $result->expiresAt);
    }

    public function testAgeBracketFromGoldenFixture(): void
    {
        $result = SessionResult::fromArray($this->goldenFixture());

        $this->assertSame(21, $result->ageBracket());
    }

    public function testMethodReturnsVerificationMode(): void
    {
        $result = SessionResult::fromArray($this->goldenFixture());

        $this->assertSame('full', $result->method());
    }

    public function testIsVerifiedOnlyForSuccess(): void
    {
        $completed = SessionResult::fromArray(['token' => 'a', 'status' => 'success']);
        $failed = SessionResult::fromArray(['token' => 'b', 'status' => 'failed']);
        $pending = SessionResult::fromArray(['token' => 'c', 'status' => 'pending']);

        $this->assertTrue($completed->isVerified());
        $this->assertFalse($failed->isVerified());
        $this->assertFalse($pending->isVerified());
    }

    public function testIsCompletedIsADeprecatedAliasOfIsVerified(): void
    {
        $success = SessionResult::fromArray(['token' => 'a', 'status' => 'success']);
        $failed = SessionResult::fromArray(['token' => 'b', 'status' => 'failed']);

        $this->assertSame($success->isVerified(), $success->isCompleted());
        $this->assertSame($failed->isVerified(), $failed->isCompleted());
    }

    public function testIsPendingIncludesInProgress(): void
    {
        $pending = SessionResult::fromArray(['token' => 'a', 'status' => 'pending']);
        $inProgress = SessionResult::fromArray(['token' => 'b', 'status' => 'in_progress']);
        $completed = SessionResult::fromArray(['token' => 'c', 'status' => 'success']);

        $this->assertTrue($pending->isPending());
        $this->assertTrue($inProgress->isPending());
        $this->assertFalse($completed->isPending());
    }

    public function testIsTerminalStates(): void
    {
        $terminal = ['success', 'failed', 'canceled', 'claimed'];
        $nonTerminal = ['pending', 'in_progress'];

        foreach ($terminal as $status) {
            $r = SessionResult::fromArray(['token' => 'x', 'status' => $status]);
            $this->assertTrue($r->isTerminal(), "$status should be terminal");
        }

        foreach ($nonTerminal as $status) {
            $r = SessionResult::fromArray(['token' => 'x', 'status' => $status]);
            $this->assertFalse($r->isTerminal(), "$status should not be terminal");
        }
    }

    public function testUnknownStatusFallsToPending(): void
    {
        $result = SessionResult::fromArray(['token' => 'x', 'status' => 'unknown_status']);
        $this->assertSame(SessionStatus::Pending, $result->status);
    }

    /** `checks.age.passed:false` (or absent) must null out the bracket even when `gate` is set. */
    public function testAgeBracketNullWhenAgeCheckNotPassed(): void
    {
        $result = SessionResult::fromArray([
            'token' => 'x',
            'status' => 'failed',
            'reason' => 'age_below_threshold',
            'checks' => ['age' => ['performed' => true, 'passed' => false, 'gate' => 21]],
        ]);

        $this->assertNull($result->ageBracket());
    }

    public function testAgeBracketNullWhenNoChecksProvided(): void
    {
        $result = SessionResult::fromArray(['token' => 'x', 'status' => 'pending']);

        $this->assertNull($result->ageBracket());
    }

    public function testMethodNullWhenNoVerificationMode(): void
    {
        $result = SessionResult::fromArray(['token' => 'x', 'status' => 'pending']);

        $this->assertNull($result->method());
    }

    public function testReasonPopulatedOnFailure(): void
    {
        $result = SessionResult::fromArray([
            'token' => 'x',
            'status' => 'failed',
            'verified' => false,
            'reason' => 'face_mismatch',
        ]);

        $this->assertSame('face_mismatch', $result->reason);
        $this->assertFalse($result->verified);
    }

    /**
     * Forward/backward safety: an SDK outlives any single deployment. A payload
     * shaped like the pre-v1 verbose DTO (age_result/liveness_result/ocr_result
     * blobs, country_code, regime, no `checks` key at all) must still construct
     * without a TypeError, and the pass/fail verdict must still be readable —
     * even though the per-check detail is unavailable from that shape.
     */
    public function testFromArrayToleratesTheOldVerbosePayload(): void
    {
        $result = SessionResult::fromArray([
            'id' => 'sess_old_001',
            'status' => 'success',
            'age_result' => ['verified_bracket' => 18, 'method' => 'ml_fast'],
            'liveness_result' => ['passed' => true],
            'ocr_result' => ['document_type' => 'passport'],
            'face_match_result' => ['matched' => true],
            'ocr_task_id' => 'task_1',
            'country_code' => 'DE',
            'regime' => 'strict',
            'required_methods' => ['liveness', 'age'],
            'remaining_attempts' => 2,
            'created_at' => '2026-03-23T12:00:00Z',
            'started_at' => '2026-03-23T11:59:00Z',
            'completed_at' => '2026-03-23T12:01:00Z',
            'expires_at' => '2026-03-23T12:10:00Z',
            'min_age' => 18,
        ]);

        $this->assertTrue($result->isVerified());
        $this->assertTrue($result->isTerminal());
        $this->assertSame(SessionStatus::Success, $result->status);
    }

    /** A `completed` (pre-July-2026) status inside the old payload shape also still normalizes. */
    public function testFromArrayToleratesTheOldVerbosePayloadWithLegacyStatus(): void
    {
        $result = SessionResult::fromArray([
            'id' => 'sess_old_002',
            'status' => 'completed',
            'age_result' => ['estimated_age' => 25],
            'created_at' => '2026-03-23T12:00:00Z',
        ]);

        $this->assertTrue($result->isVerified());
    }

    /** `checks` present but not an array (defensive: a malformed/partial payload) must not TypeError. */
    public function testFromArrayToleratesNonArrayChecksValue(): void
    {
        $result = SessionResult::fromArray(['token' => 'x', 'status' => 'pending', 'checks' => 'not-an-array']);

        $this->assertFalse($result->checks->age->performed);
        $this->assertNull($result->ageBracket());
    }
}
