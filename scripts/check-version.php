<?php

/**
 * Release guard: the version this SDK reports must equal the tag it ships as.
 *
 * Why this exists
 * ---------------
 * composer.json deliberately carries NO `version` field — Packagist derives the
 * version from the git tag, and a hand-maintained copy is one more thing to
 * forget. But the SDK still needs its own version at runtime, because it goes
 * out in the User-Agent and that is what support reads off a request. So there
 * is exactly one in-repo copy, Config::SDK_VERSION, and exactly one thing that
 * can go wrong with it: someone tags a release without bumping it.
 *
 * That is not hypothetical. The sibling Xident SDKs all drifted this way —
 * go-sdk reported 1.0.0 at tag v1.2.0, python-sdk 1.0.1 against a 1.2.0
 * manifest, ios-sdk 1.0.0. Every one of them was a silent mismatch nobody
 * noticed because nothing checked.
 *
 * Usage
 * -----
 *   php scripts/check-version.php            # infer the tag on HEAD
 *   php scripts/check-version.php v1.3.0     # check against an explicit tag
 *   composer check-version
 *
 * In CI the tag arrives as GITHUB_REF_NAME on a tag push. Exits non-zero and
 * says what is wrong; exits 0 and says nothing surprising when things line up.
 */

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use Xident\SDK\Config;

const EXIT_OK = 0;
const EXIT_FAIL = 1;

/**
 * @param list<string> $argv
 */
function main(array $argv): int
{
    $root = dirname(__DIR__);
    $failures = [];

    $expected = resolveExpectedVersion($argv[1] ?? null);

    if ($expected === null) {
        fwrite(STDERR, <<<TXT
        FAIL: nothing to check the SDK version against.

          HEAD is not tagged and no version was passed. Either run this on a
          tagged commit, or pass the tag you are about to cut:

            php scripts/check-version.php v1.3.0

        TXT);

        return EXIT_FAIL;
    }

    if (Config::SDK_VERSION !== $expected) {
        $failures[] = sprintf(
            "Version drift: the tag says %s but Config::SDK_VERSION says %s.\n"
            . "  Fix src/Xident/SDK/Config.php (SDK_VERSION) so it matches the tag,\n"
            . "  then re-tag. The tag is authoritative; the constant follows it.",
            $expected,
            Config::SDK_VERSION,
        );
    }

    $failures = array_merge($failures, checkComposerJsonHasNoVersion($root));
    $failures = array_merge($failures, checkUserAgentUsesTheConstant());

    if ($failures !== []) {
        foreach ($failures as $failure) {
            fwrite(STDERR, "FAIL: {$failure}\n\n");
        }

        return EXIT_FAIL;
    }

    fwrite(STDOUT, sprintf(
        "OK: SDK_VERSION %s matches the release version, composer.json carries no version field,\n"
        . "    and the User-Agent is built from the constant (%s).\n",
        Config::SDK_VERSION,
        (new Config('sk_test_versioncheck'))->userAgent(),
    ));

    return EXIT_OK;
}

/**
 * Explicit argument, then the CI tag ref, then the tag on HEAD.
 *
 * Sources are tried lazily — each is a closure so that git is only shelled out
 * to when the cheaper sources came up empty. An eagerly-built list would run
 * `git describe` on every invocation, including the ones that already know
 * their answer.
 */
function resolveExpectedVersion(?string $argument): ?string
{
    $sources = [
        static fn (): ?string => $argument,
        static fn (): ?string => getenv('GITHUB_REF_NAME') ?: null,
        gitTagOnHead(...),
    ];

    foreach ($sources as $source) {
        $candidate = $source();
        if ($candidate === null || trim($candidate) === '') {
            continue;
        }

        $normalised = ltrim(trim($candidate), 'vV');
        if (preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $normalised) === 1) {
            return $normalised;
        }
    }

    return null;
}

function gitTagOnHead(): ?string
{
    if (!is_dir(dirname(__DIR__) . '/.git')) {
        return null; // Exported tarball, or a checkout without VCS metadata.
    }

    $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    // Silenced deliberately: a machine without git is a handled outcome ("no
    // tag available"), not something to print a warning about. PHP 8.4+ warns
    // on a failed spawn, which would otherwise land in this script's stdout.
    $process = @proc_open(
        ['git', 'describe', '--tags', '--exact-match', 'HEAD'],
        $descriptor,
        $pipes,
        dirname(__DIR__),
    );

    if (!is_resource($process)) {
        return null;
    }

    $tag = trim((string)stream_get_contents($pipes[1]));
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return proc_close($process) === 0 && $tag !== '' ? $tag : null;
}

/**
 * A `version` field in composer.json is the drift vector we are avoiding.
 * Packagist reads the tag; a duplicated field just goes stale.
 *
 * @return list<string>
 */
function checkComposerJsonHasNoVersion(string $root): array
{
    $path = $root . '/composer.json';
    $manifest = json_decode((string)file_get_contents($path), true);

    if (!is_array($manifest)) {
        return ["could not parse {$path}"];
    }

    if (array_key_exists('version', $manifest)) {
        return [
            "composer.json has a `version` field (\"{$manifest['version']}\").\n"
            . '  Remove it. Packagist takes the version from the git tag, and a second'
            . " copy only ever\n  drifts — that is exactly how go-sdk, python-sdk and"
            . ' ios-sdk ended up misreporting.',
        ];
    }

    return [];
}

/**
 * The User-Agent is the only place the version reaches a server, so it has to
 * be derived from the constant rather than written out a second time.
 *
 * @return list<string>
 */
function checkUserAgentUsesTheConstant(): array
{
    $userAgent = (new Config('sk_test_versioncheck'))->userAgent();

    if (!str_contains($userAgent, Config::SDK_VERSION)) {
        return [
            "the User-Agent (\"{$userAgent}\") does not contain SDK_VERSION ("
            . Config::SDK_VERSION . ').',
        ];
    }

    return [];
}

exit(main($argv));
