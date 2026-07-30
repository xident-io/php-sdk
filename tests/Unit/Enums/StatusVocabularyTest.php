<?php

declare(strict_types=1);

namespace Xident\SDK\Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use Xident\SDK\Enums\SessionStatus;
use Xident\SDK\Responses\SessionResult;

/**
 * The API renamed the pass verdict from `completed` to `success` and dropped
 * the British `cancelled` from the browser callback. This SDK kept the old
 * vocabulary and so reported every verified user as unverified.
 *
 * The api repo has a guard for this, but it walks only the api module and is
 * structurally incapable of seeing a sibling repo — which is exactly how all
 * seven SDKs missed the rename. There is no CI spanning the eight repos, so
 * each one needs its own copy.
 */
final class StatusVocabularyTest extends TestCase
{
    /** The value the pass verdict carried before July 2026. */
    private const LEGACY_SUCCESS = 'completed';

    public function testWireValues(): void
    {
        // These strings are the API contract. Changing one here without
        // changing the API is how an SDK stops recognising a real verdict.
        $this->assertSame('pending', SessionStatus::Pending->value);
        $this->assertSame('in_progress', SessionStatus::InProgress->value);
        $this->assertSame('success', SessionStatus::Success->value);
        $this->assertSame('failed', SessionStatus::Failed->value);
        $this->assertSame('canceled', SessionStatus::Canceled->value);
        $this->assertSame('claimed', SessionStatus::Claimed->value);

        $values = array_map(static fn (SessionStatus $c): string => $c->value, SessionStatus::cases());
        $this->assertNotContains(self::LEGACY_SUCCESS, $values, 'the retired literal must not be a case');
    }

    /**
     * The deprecated alias must resolve to the CURRENT case. Existing consumer
     * code comparing against it has to keep being correct, not merely keep
     * parsing.
     */
    public function testDeprecatedCompletedAliasResolvesToSuccess(): void
    {
        $this->assertSame(SessionStatus::Success, SessionStatus::Completed);

        $result = SessionResult::fromArray(['token' => 'a', 'status' => 'success', 'created_at' => '']);
        $this->assertSame(
            SessionStatus::Completed,
            $result->status,
            'pre-rename consumer code comparing against SessionStatus::Completed no longer matches a verified session',
        );
    }

    public function testNormalizeMapsLegacyValueForward(): void
    {
        $this->assertSame(SessionStatus::Success, SessionStatus::normalize(self::LEGACY_SUCCESS));
        $this->assertSame(SessionStatus::Success, SessionStatus::normalize('success'));
        $this->assertSame(SessionStatus::Canceled, SessionStatus::normalize('canceled'));
        $this->assertNull(SessionStatus::normalize('nonsense'));
    }

    /**
     * A deployment older than the July 2026 rename still sends `completed`.
     * An SDK outlives a rollout window, so it must keep understanding it.
     */
    public function testLegacyStatusNormalisesThroughSessionResult(): void
    {
        $result = SessionResult::fromArray([
            'token' => 'xtk_a',
            'status' => self::LEGACY_SUCCESS,
            'created_at' => '',
        ]);

        $this->assertSame(SessionStatus::Success, $result->status);
        $this->assertTrue($result->isVerified());
        $this->assertTrue($result->isTerminal());
        $this->assertFalse($result->isPending());
    }

    public function testUnknownStatusIsNeitherVerifiedNorTerminal(): void
    {
        $result = SessionResult::fromArray([
            'token' => 'xtk_a',
            'status' => 'quarantined',
            'created_at' => '',
        ]);

        $this->assertFalse($result->isVerified());
        $this->assertFalse($result->isTerminal());
    }

    public function testReasonIsCarriedForNonSuccessTerminalStatus(): void
    {
        $result = SessionResult::fromArray([
            'token' => 'xtk_a',
            'status' => 'failed',
            'reason' => 'age_below_threshold',
            'created_at' => '',
        ]);

        $this->assertFalse($result->isVerified());
        $this->assertSame('age_below_threshold', $result->reason);
    }

    /**
     * examples/ is shipped documentation — an integrator copies it verbatim,
     * so a stale comparison there is as harmful as one in the library.
     */
    public function testNoStaleVocabularyInSourceOrExamples(): void
    {
        $scanned = 0;
        foreach ([__DIR__ . '/../../../src', __DIR__ . '/../../../examples'] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            /** @var \SplFileInfo $file */
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $scanned++;
                $raw = (string)file_get_contents($file->getPathname());
                $name = $file->getPathname();

                // The British spelling is gone from every surface including
                // the callback, so it is stale in prose as well as in code.
                $this->assertStringNotContainsString(
                    'cancelled',
                    $raw,
                    "$name uses the British \"cancelled\"; the API uses \"canceled\" everywhere",
                );

                // "completed" is different: a docblock may legitimately
                // explain the rename, so strip comments before checking for a
                // hardcoded literal.
                $code = (string)preg_replace(['~/\*[\s\S]*?\*/~', '~//.*$~m'], '', $raw);
                $code = (string)preg_replace("~private const LEGACY_SUCCESS = '[^']*';~", '', $code);
                $this->assertDoesNotMatchRegularExpression(
                    "~['\"]completed['\"]~",
                    $code,
                    "$name hardcodes the retired \"completed\" literal; the pass verdict is \"success\"",
                );
            }
        }

        $this->assertGreaterThan(0, $scanned, 'scanned no files — the guard would pass vacuously');
    }
}
