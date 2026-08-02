<?php

/**
 * Coverage floor: fail the build when line coverage drops below a threshold.
 *
 * PHPUnit has no built-in minimum-coverage flag, so this reads the Clover
 * report it writes and enforces the number itself. The floor is a ratchet —
 * raise it, never lower it.
 *
 * Usage:
 *   php scripts/check-coverage.php build/clover.xml 100
 */

declare(strict_types=1);

/**
 * @param list<string> $argv
 */
function main(array $argv): int
{
    $report = $argv[1] ?? '';
    $floor = (float)($argv[2] ?? '100');

    if ($report === '' || !is_file($report)) {
        fwrite(STDERR, "FAIL: no Clover report at \"{$report}\".\n"
            . "  Usage: php scripts/check-coverage.php <clover.xml> <min-percent>\n");

        return 1;
    }

    $xml = simplexml_load_file($report);
    if ($xml === false) {
        fwrite(STDERR, "FAIL: could not parse the Clover report at \"{$report}\".\n");

        return 1;
    }

    $statements = 0;
    $covered = 0;
    /** @var list<string> $gaps */
    $gaps = [];

    foreach ($xml->xpath('//file') ?: [] as $file) {
        $uncovered = [];

        foreach ($file->line as $line) {
            if ((string)$line['type'] === 'method') {
                continue; // Method declarations are counted through their bodies.
            }

            $statements++;
            if ((int)$line['count'] > 0) {
                $covered++;
            } else {
                $uncovered[] = (int)$line['num'];
            }
        }

        if ($uncovered !== []) {
            $gaps[] = sprintf('  %s: %s', (string)$file['name'], implode(', ', $uncovered));
        }
    }

    if ($statements === 0) {
        fwrite(STDERR, "FAIL: the Clover report contains no measurable statements.\n");

        return 1;
    }

    $percent = $covered / $statements * 100;

    if ($percent + 0.0001 < $floor) {
        fwrite(STDERR, sprintf(
            "FAIL: line coverage %.2f%% (%d/%d) is below the %.2f%% floor.\nUncovered lines:\n%s\n",
            $percent,
            $covered,
            $statements,
            $floor,
            implode("\n", $gaps),
        ));

        return 1;
    }

    fwrite(STDOUT, sprintf(
        "OK: line coverage %.2f%% (%d/%d), floor %.2f%%.\n",
        $percent,
        $covered,
        $statements,
        $floor,
    ));

    return 0;
}

exit(main($argv));
