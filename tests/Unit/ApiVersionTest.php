<?php

declare(strict_types=1);

namespace Xident\SDK\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Xident\SDK\Config;

/**
 * Every request must carry the dated API version this SDK was built against.
 *
 * Pinning in the SDK rather than relying on the project's dashboard setting is
 * what guarantees this release's response classes match the payload the server
 * sends: a customer pinned to an older version still receives the shape this SDK
 * parses. Without the header, a newer SDK reading an older shape would silently
 * leave fields empty — the same failure class as the three fields this SDK
 * dropped for a week in August.
 */
final class ApiVersionTest extends TestCase
{
    public function testDefaultsToThePinnedVersion(): void
    {
        self::assertSame(Config::PINNED_API_VERSION, (new Config('sk_test_x'))->apiVersion);
    }

    public function testCanBeOverriddenToTrialANewerVersion(): void
    {
        self::assertSame('2027-01-01', (new Config('sk_test_x', apiVersion: '2027-01-01'))->apiVersion);
    }

    public function testEmptyOverrideFallsBackToTheDefault(): void
    {
        // The server rejects an empty X-API-Version as invalid, so honouring ''
        // would break every request rather than doing nothing.
        self::assertSame(Config::PINNED_API_VERSION, (new Config('sk_test_x', apiVersion: ''))->apiVersion);
    }

    public function testPinnedVersionIsADateAndNotThePathPrefix(): void
    {
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', Config::PINNED_API_VERSION);
        // API_VERSION is the URL path segment (verify/v1). Naming these alike is
        // how the verification_mode / verification_type confusion started.
        self::assertNotSame(Config::API_VERSION, Config::PINNED_API_VERSION);
        self::assertNotSame(Config::SDK_VERSION, Config::PINNED_API_VERSION);
    }
}
