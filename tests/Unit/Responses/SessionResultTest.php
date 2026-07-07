<?php

declare(strict_types=1);

namespace Xident\SDK\Tests\Unit\Responses;

use PHPUnit\Framework\TestCase;
use Xident\SDK\Enums\SessionStatus;
use Xident\SDK\Responses\SessionResult;

final class SessionResultTest extends TestCase
{
    public function testFromArrayCompletedSession(): void
    {
        // Payload mirrors the GET /verify/v1/result/{token} DTO: it echoes the
        // `xtk_` result token as `token` and does NOT include min_age/completed_at.
        $result = SessionResult::fromArray([
            'token' => 'xtk_001',
            'status' => 'completed',
            'age_result' => ['verified_bracket' => 18, 'method' => 'ml_fast'],
            'liveness_result' => ['passed' => true],
            'country_code' => 'DE',
            'created_at' => '2026-03-23T12:00:00Z',
            'expires_at' => '2026-03-23T12:10:00Z',
        ]);

        $this->assertSame('xtk_001', $result->token);
        $this->assertSame(SessionStatus::Completed, $result->status);
        $this->assertSame(18, $result->ageBracket());
        $this->assertSame('ml_fast', $result->method());
        $this->assertSame('DE', $result->countryCode);
        $this->assertSame('2026-03-23T12:10:00Z', $result->expiresAt);
    }

    public function testIsVerifiedOnlyForCompleted(): void
    {
        $completed = SessionResult::fromArray(['token' => 'a', 'status' => 'completed', 'created_at' => '']);
        $failed = SessionResult::fromArray(['token' => 'b', 'status' => 'failed', 'created_at' => '']);
        $pending = SessionResult::fromArray(['token' => 'c', 'status' => 'pending', 'created_at' => '']);

        $this->assertTrue($completed->isVerified());
        $this->assertFalse($failed->isVerified());
        $this->assertFalse($pending->isVerified());
    }

    public function testIsPendingIncludesInProgress(): void
    {
        $pending = SessionResult::fromArray(['token' => 'a', 'status' => 'pending', 'created_at' => '']);
        $inProgress = SessionResult::fromArray(['token' => 'b', 'status' => 'in_progress', 'created_at' => '']);
        $completed = SessionResult::fromArray(['token' => 'c', 'status' => 'completed', 'created_at' => '']);

        $this->assertTrue($pending->isPending());
        $this->assertTrue($inProgress->isPending());
        $this->assertFalse($completed->isPending());
    }

    public function testIsTerminalStates(): void
    {
        $terminal = ['completed', 'failed', 'canceled', 'claimed'];
        $nonTerminal = ['pending', 'in_progress'];

        foreach ($terminal as $status) {
            $r = SessionResult::fromArray(['token' => 'x', 'status' => $status, 'created_at' => '']);
            $this->assertTrue($r->isTerminal(), "$status should be terminal");
        }

        foreach ($nonTerminal as $status) {
            $r = SessionResult::fromArray(['token' => 'x', 'status' => $status, 'created_at' => '']);
            $this->assertFalse($r->isTerminal(), "$status should not be terminal");
        }
    }

    public function testAgeBracketFromEstimatedAge(): void
    {
        $result = SessionResult::fromArray([
            'token' => 'x', 'status' => 'completed', 'created_at' => '',
            'age_result' => ['estimated_age' => 25],
        ]);
        $this->assertSame(25, $result->ageBracket());
    }

    public function testAgeBracketNullWhenNoAgeResult(): void
    {
        $result = SessionResult::fromArray(['token' => 'x', 'status' => 'pending', 'created_at' => '']);
        $this->assertNull($result->ageBracket());
    }

    public function testMethodNullWhenNoAgeResult(): void
    {
        $result = SessionResult::fromArray(['token' => 'x', 'status' => 'pending', 'created_at' => '']);
        $this->assertNull($result->method());
    }

    public function testUnknownStatusFallsToPending(): void
    {
        $result = SessionResult::fromArray(['token' => 'x', 'status' => 'unknown_status', 'created_at' => '']);
        $this->assertSame(SessionStatus::Pending, $result->status);
    }
}
