<?php

declare(strict_types=1);

namespace Xident\SDK\Tests\Unit\Responses;

use PHPUnit\Framework\TestCase;
use Xident\SDK\Responses\AgeGateCheck;
use Xident\SDK\Responses\CheckResult;
use Xident\SDK\Responses\DocumentCheck;
use Xident\SDK\Responses\ResultChecks;

/**
 * The `checks` value objects nested inside SessionResult — one class per file,
 * matching how every other Responses/ type in this SDK is organized (see
 * BlacklistEntry, Face2FAStatus). Each mirrors one entry of the v1 result
 * contract's `checks` object (golden fixture: tests/Fixtures/tenant_result_v1.golden.json).
 */
final class ResultChecksTest extends TestCase
{
    // --- CheckResult (liveness, face_match) ---

    public function testCheckResultFromArray(): void
    {
        $check = CheckResult::fromArray(['performed' => true, 'passed' => false]);

        $this->assertTrue($check->performed);
        $this->assertFalse($check->passed);
    }

    public function testCheckResultDefaultsWhenKeysMissing(): void
    {
        $check = CheckResult::fromArray([]);

        $this->assertFalse($check->performed);
        $this->assertFalse($check->passed);
    }

    // --- AgeGateCheck ---

    public function testAgeGateCheckFromArray(): void
    {
        $age = AgeGateCheck::fromArray(['performed' => true, 'passed' => true, 'gate' => 21]);

        $this->assertTrue($age->performed);
        $this->assertTrue($age->passed);
        $this->assertSame(21, $age->gate);
    }

    /** `gate` mirrors the session's configured min_age and is meaningful even when the check didn't run. */
    public function testAgeGateCheckGateSurvivesWhenNotPerformed(): void
    {
        $age = AgeGateCheck::fromArray(['performed' => false, 'passed' => false, 'gate' => 18]);

        $this->assertFalse($age->performed);
        $this->assertSame(18, $age->gate);
    }

    public function testAgeGateCheckDefaultsWhenKeysMissing(): void
    {
        $age = AgeGateCheck::fromArray([]);

        $this->assertFalse($age->performed);
        $this->assertFalse($age->passed);
        $this->assertSame(0, $age->gate);
    }

    // --- DocumentCheck ---

    public function testDocumentCheckFromArray(): void
    {
        $document = DocumentCheck::fromArray([
            'performed' => true,
            'passed' => true,
            'document_type' => 'passport',
            'country' => 'DE',
        ]);

        $this->assertTrue($document->performed);
        $this->assertTrue($document->passed);
        $this->assertSame('passport', $document->documentType);
        $this->assertSame('DE', $document->country);
    }

    public function testDocumentCheckDefaultsWhenKeysMissing(): void
    {
        $document = DocumentCheck::fromArray([]);

        $this->assertFalse($document->performed);
        $this->assertFalse($document->passed);
        $this->assertNull($document->documentType);
        $this->assertNull($document->country);
    }

    // --- ResultChecks (the container) ---

    public function testResultChecksFromArrayBuildsAllFourChecks(): void
    {
        $checks = ResultChecks::fromArray([
            'liveness' => ['performed' => true, 'passed' => true],
            'age' => ['performed' => true, 'passed' => true, 'gate' => 21],
            'document' => ['performed' => true, 'passed' => true, 'document_type' => 'passport', 'country' => 'DE'],
            'face_match' => ['performed' => true, 'passed' => true],
        ]);

        $this->assertTrue($checks->liveness->passed);
        $this->assertSame(21, $checks->age->gate);
        $this->assertSame('passport', $checks->document->documentType);
        $this->assertTrue($checks->faceMatch->passed);
    }

    /** Every sub-check defaults safely when `checks` itself is `[]` (no TypeError). */
    public function testResultChecksFromArrayDefaultsWhenEmpty(): void
    {
        $checks = ResultChecks::fromArray([]);

        $this->assertFalse($checks->liveness->performed);
        $this->assertFalse($checks->age->performed);
        $this->assertFalse($checks->document->performed);
        $this->assertFalse($checks->faceMatch->performed);
    }

    /** A sub-key present but not itself an array (malformed payload) must not TypeError. */
    public function testResultChecksFromArrayTreatsNonArraySubkeyAsMissing(): void
    {
        $checks = ResultChecks::fromArray(['age' => 'not-an-array', 'document' => null]);

        $this->assertFalse($checks->age->performed);
        $this->assertFalse($checks->document->performed);
    }
}
