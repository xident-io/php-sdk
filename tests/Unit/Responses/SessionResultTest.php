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
        $result = SessionResult::fromArray([
            'id' => 'sess_001',
            'status' => 'completed',
            'age_result' => ['verified_bracket' => 18, 'method' => 'ml_fast'],
            'liveness_result' => ['passed' => true],
            'country_code' => 'DE',
            'min_age' => 18,
            'created_at' => '2026-03-23T12:00:00Z',
            'completed_at' => '2026-03-23T12:01:00Z',
        ]);

        $this->assertSame('sess_001', $result->id);
        $this->assertSame(SessionStatus::Completed, $result->status);
        $this->assertSame(18, $result->ageBracket());
        $this->assertSame('ml_fast', $result->method());
        $this->assertSame('DE', $result->countryCode);
        $this->assertNotNull($result->completedAt);
    }

    public function testIsVerifiedOnlyForCompleted(): void
    {
        $completed = SessionResult::fromArray(['id' => 'a', 'status' => 'completed', 'created_at' => '']);
        $failed = SessionResult::fromArray(['id' => 'b', 'status' => 'failed', 'created_at' => '']);
        $pending = SessionResult::fromArray(['id' => 'c', 'status' => 'pending', 'created_at' => '']);

        $this->assertTrue($completed->isVerified());
        $this->assertFalse($failed->isVerified());
        $this->assertFalse($pending->isVerified());
    }

    public function testIsPendingIncludesInProgress(): void
    {
        $pending = SessionResult::fromArray(['id' => 'a', 'status' => 'pending', 'created_at' => '']);
        $inProgress = SessionResult::fromArray(['id' => 'b', 'status' => 'in_progress', 'created_at' => '']);
        $completed = SessionResult::fromArray(['id' => 'c', 'status' => 'completed', 'created_at' => '']);

        $this->assertTrue($pending->isPending());
        $this->assertTrue($inProgress->isPending());
        $this->assertFalse($completed->isPending());
    }

    public function testIsTerminalStates(): void
    {
        $terminal = ['completed', 'failed', 'canceled', 'claimed'];
        $nonTerminal = ['pending', 'in_progress'];

        foreach ($terminal as $status) {
            $r = SessionResult::fromArray(['id' => 'x', 'status' => $status, 'created_at' => '']);
            $this->assertTrue($r->isTerminal(), "$status should be terminal");
        }

        foreach ($nonTerminal as $status) {
            $r = SessionResult::fromArray(['id' => 'x', 'status' => $status, 'created_at' => '']);
            $this->assertFalse($r->isTerminal(), "$status should not be terminal");
        }
    }

    public function testAgeBracketFromEstimatedAge(): void
    {
        $result = SessionResult::fromArray([
            'id' => 'x', 'status' => 'completed', 'created_at' => '',
            'age_result' => ['estimated_age' => 25],
        ]);
        $this->assertSame(25, $result->ageBracket());
    }

    public function testAgeBracketNullWhenNoAgeResult(): void
    {
        $result = SessionResult::fromArray(['id' => 'x', 'status' => 'pending', 'created_at' => '']);
        $this->assertNull($result->ageBracket());
    }

    public function testMethodNullWhenNoAgeResult(): void
    {
        $result = SessionResult::fromArray(['id' => 'x', 'status' => 'pending', 'created_at' => '']);
        $this->assertNull($result->method());
    }

    public function testUnknownStatusFallsToPending(): void
    {
        $result = SessionResult::fromArray(['id' => 'x', 'status' => 'unknown_status', 'created_at' => '']);
        $this->assertSame(SessionStatus::Pending, $result->status);
    }
}
