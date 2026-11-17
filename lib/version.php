<?php
/**
 * The kit's version number, read from the VERSION file at the root of the site.
 * tools/update.php, the System screen and the control panel footer all use it.
 */

declare(strict_types=1);

/** The installed version of the kit, such as "1.0.0"; "unknown" if the VERSION file is missing. */
function cms_version(): string
{
    static $version = null;
    if ($version === null) {
        $read = trim((string) @file_get_contents(ROOT . '/VERSION'));
        $version = preg_match('/^\d+\.\d+\.\d+$/', $read) ? $read : 'unknown';
    }
    return $version;
}

/**
 * The CHANGELOG.md entries newer than $since (a version such as "1.0.0"), newest first,
 * as [version => text]. Entries are the "## X.Y.Z" sections of the file.
 */
function changelog_since(string $since, ?string $file = null): array
{
    $text = (string) @file_get_contents($file ?? ROOT . '/CHANGELOG.md');
    preg_match_all('/^## (\d+\.\d+\.\d+)[^\n]*\n(.*?)(?=^## \d|\z)/ms', $text, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $entry) {
        if (version_compare($entry[1], $since, '>')) {
            $out[$entry[1]] = trim($entry[2]);
        }
    }
    return $out;
}
