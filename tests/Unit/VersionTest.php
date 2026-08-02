<?php

declare(strict_types=1);

namespace Xident\SDK\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Xident\SDK\Client;
use Xident\SDK\Config;

/**
 * Guards the one place this package records its own version.
 *
 * The package intentionally has a single in-repo version — Config::SDK_VERSION
 * — and no `version` field in composer.json, because Packagist reads the git
 * tag and a second copy only drifts. These tests keep both halves of that
 * decision honest; scripts/check-version.php adds the third half by comparing
 * the constant to the tag at release time.
 */
final class VersionTest extends TestCase
{
    private const REPO_ROOT = __DIR__ . '/../..';

    /**
     * composer.json must NOT carry a `version` field.
     *
     * This is the recommended layout for a Packagist package: the tag is the
     * version, full stop. The sibling Xident SDKs that duplicated it all went
     * stale (go-sdk shipped 1.0.0 under tag v1.2.0; python-sdk 1.0.1 against a
     * 1.2.0 manifest; ios-sdk 1.0.0). If someone adds the field back "for
     * clarity", this fails and explains why.
     */
    public function testComposerJsonDeclaresNoVersionField(): void
    {
        $manifest = $this->composerManifest();

        $this->assertArrayNotHasKey(
            'version',
            $manifest,
            'composer.json must not declare a version — Packagist derives it from the git tag, '
            . 'and a duplicated field goes stale.',
        );
    }

    /** The manifest still has to say who it is; only `version` is delegated to the tag. */
    public function testComposerJsonStillIdentifiesThePackage(): void
    {
        $manifest = $this->composerManifest();

        $this->assertSame('xident-io/php-sdk', $manifest['name']);
        $this->assertSame('library', $manifest['type']);
        $this->assertSame(
            ['Xident\\SDK\\' => 'src/Xident/SDK/'],
            $manifest['autoload']['psr-4'],
            'PSR-4 prefix and path must stay in step with the src/ layout.',
        );
    }

    /**
     * The declared PHP floor must be at least 8.2.
     *
     * Config and every class in Responses/ are `final readonly class`, which is
     * PHP 8.2 syntax. Under 8.1 those files do not parse, so the SDK cannot
     * load at all — yet composer.json claimed `^8.1` through v1.2.0 and
     * Composer happily installed it into 8.1 projects that then fatalled. If
     * someone widens the constraint again without changing the syntax, this
     * fails first.
     */
    public function testComposerJsonRequiresAtLeastPhp82(): void
    {
        $constraint = $this->composerManifest()['require']['php'];

        $this->assertMatchesRegularExpression(
            '/^\^8\.(?:[2-9]|\d{2,})/',
            $constraint,
            "require.php is \"{$constraint}\" — but the source uses `readonly class` (PHP 8.2+), "
            . 'so anything below 8.2 is a parse error, not a degraded experience.',
        );
    }

    /** The readonly-class syntax that sets the floor is really still in use. */
    public function testResponseObjectsAreReadonlyClasses(): void
    {
        $readonly = array_filter(
            glob(self::REPO_ROOT . '/src/Xident/SDK/Responses/*.php') ?: [],
            static fn (string $file): bool => str_contains((string)file_get_contents($file), 'readonly class'),
        );

        $this->assertNotEmpty(
            $readonly,
            'No readonly classes left in Responses/ — if that is deliberate, the PHP floor can drop.',
        );
    }

    public function testSdkVersionIsSemver(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/',
            Config::SDK_VERSION,
            'SDK_VERSION must be a bare semver string — the release guard compares it to a git tag.',
        );
    }

    /** One source of truth: the wire version is derived, never re-typed. */
    public function testUserAgentIsBuiltFromTheVersionConstant(): void
    {
        $userAgent = (new Config('sk_test_useragent'))->userAgent();

        $this->assertSame(
            sprintf('Xident-PHP/%s PHP/%s', Config::SDK_VERSION, PHP_VERSION),
            $userAgent,
        );
        $this->assertStringContainsString(Config::SDK_VERSION, $userAgent);
    }

    public function testClientVersionReportsTheConstant(): void
    {
        $this->assertSame(Config::SDK_VERSION, Client::version());
    }

    /** The guard passes when the constant and the release version agree. */
    public function testReleaseGuardAcceptsTheCurrentVersion(): void
    {
        $result = $this->runReleaseGuard(Config::SDK_VERSION);

        $this->assertSame(0, $result['exit'], 'Guard rejected the current version: ' . $result['stderr']);
        $this->assertStringContainsString('OK: SDK_VERSION ' . Config::SDK_VERSION, $result['stdout']);
    }

    /** …and fails loudly when they do not. This is the drift that shipped in three sibling SDKs. */
    public function testReleaseGuardRejectsVersionDrift(): void
    {
        $result = $this->runReleaseGuard('v9.9.9');

        $this->assertSame(1, $result['exit'], 'Guard accepted a tag that disagrees with SDK_VERSION');
        $this->assertStringContainsString('Version drift', $result['stderr']);
        $this->assertStringContainsString('the tag says 9.9.9', $result['stderr']);
        $this->assertStringContainsString('Config::SDK_VERSION says ' . Config::SDK_VERSION, $result['stderr']);
        $this->assertSame('', $result['stdout']);
    }

    /** A leading `v` on the tag is normalised away rather than treated as drift. */
    public function testReleaseGuardAcceptsTheTagWithItsLeadingV(): void
    {
        $result = $this->runReleaseGuard('v' . Config::SDK_VERSION);

        $this->assertSame(0, $result['exit'], $result['stderr']);
    }

    /**
     * @return array<string, mixed>
     */
    private function composerManifest(): array
    {
        $path = realpath(self::REPO_ROOT . '/composer.json');
        $this->assertIsString($path, 'composer.json not found at the repository root');

        $manifest = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($manifest);

        return $manifest;
    }

    /**
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runReleaseGuard(string $version): array
    {
        $script = realpath(self::REPO_ROOT . '/scripts/check-version.php');
        $this->assertIsString($script, 'scripts/check-version.php not found');

        $process = proc_open(
            [PHP_BINARY, $script, $version],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process, 'Could not run the release guard');

        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
