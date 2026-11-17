<?php
/**
 * Keep a site up to date with the kit, from the command line:
 *   php tools/update.php           show the installed version and what a newer kit would bring
 *   php tools/update.php --apply   pull the update (git pull --ff-only), refresh Composer packages
 *                                  if vendor/ exists, run any new migrations, and print the changelog
 * The site must be a git checkout whose "origin" is the kit repository (see README: create sites
 * with git clone, not by copying). Migrations are tools/migrations/NNN-name.php; each is run once
 * and noted in data/migrations.json. Every migration must be safe to run twice.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this from the command line.');
}
require __DIR__ . '/../lib/bootstrap.php';

$apply = in_array('--apply', $argv, true);
$ref = update_ref();
[$remote] = explode('/', $ref, 2) + [''];
$before = cms_version();

echo "Full Circle CMS $before in " . realpath(ROOT) . "\n";

if (!git_available()) {
    fwrite(STDERR, "Git is not available here, so this site cannot be updated this way.\n");
    exit(1);
}
if (!git_checkout()) {
    fwrite(STDERR, "This folder is not a git checkout. Sites are updated by git: see docs on creating a site with git clone.\n");
    exit(1);
}
echo 'Branch ' . git_branch() . ", updates come from $ref\n";

echo "Fetching $remote...\n";
if (git_run(['fetch', '--quiet', $remote], 300) === null) {
    fwrite(STDERR, "Could not fetch from $remote. Check the network and the repository address (git remote -v).\n");
    exit(1);
}
$log = trim((string) git_run(['log', '--oneline', "HEAD..$ref"]));
$remoteVersion = trim((string) git_run(['show', "$ref:VERSION"]));
if ($log === '') {
    echo "Up to date: nothing new on $ref.\n";
} else {
    echo "New on $ref" . ($remoteVersion !== '' ? " (version $remoteVersion)" : '') . ":\n" . preg_replace('/^/m', '  ', $log) . "\n";
}

if (!$apply) {
    $pending = pending_migrations();
    if ($pending) {
        echo 'Migrations waiting to run: ' . implode(', ', array_map('basename', $pending)) . "\n";
    }
    if ($log !== '' || $pending) {
        echo "Run  php tools/update.php --apply  to update.\n";
    }
    check_for_updates();
    exit(0);
}

if ($log !== '') {
    $dirty = trim((string) git_run(['status', '--porcelain', '--untracked-files=no']));
    if ($dirty !== '') {
        fwrite(STDERR, "There are local changes to the kit's files, so a fast-forward pull would fail:\n$dirty\nCommit or stash them first (site/ and content/ are never involved).\n");
        exit(1);
    }
    echo "Pulling...\n";
    if (git_run(['pull', '--ff-only', '--quiet', $remote], 300) === null) {
        fwrite(STDERR, "git pull --ff-only failed. The branch may have diverged from $ref; run git status to see.\n");
        exit(1);
    }
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
}

if (is_dir(ROOT . '/vendor') && is_file(ROOT . '/composer.json')) {
    echo "Refreshing Composer packages (composer install --no-dev)...\n";
    $composer = trim((string) shell_exec('command -v composer 2>/dev/null'));
    if ($composer === '' && is_file(ROOT . '/composer.phar')) {
        $composer = PHP_BINARY . ' ' . escapeshellarg(ROOT . '/composer.phar');
    }
    if ($composer === '') {
        echo "  Composer is not installed here; skipped. Run composer install --no-dev yourself if the assistant is on.\n";
    } else {
        passthru('cd ' . escapeshellarg(ROOT) . ' && ' . $composer . ' install --no-dev --no-interaction --quiet 2>&1', $code);
        echo $code === 0 ? "  Done.\n" : "  Composer reported a problem (exit $code); the site still works without the assistant.\n";
    }
}

foreach (pending_migrations() as $file) {
    echo 'Running migration ' . basename($file) . "...\n";
    try {
        require $file;
        record_migration(basename($file));
        echo "  Done.\n";
    } catch (Throwable $e) {
        fwrite(STDERR, '  Failed: ' . $e->getMessage() . "\nFix the cause and run php tools/update.php --apply again; finished migrations are not repeated.\n");
        exit(1);
    }
}

$after = trim((string) @file_get_contents(ROOT . '/VERSION')) ?: $before;
if ($after !== $before) {
    echo "Updated from $before to $after.\n";
    foreach (changelog_since($before) as $version => $text) {
        echo "\n== $version ==\n$text\n";
    }
} else {
    echo "Still at version $after.\n";
}
check_for_updates();
exit(0);

/** Migration files that have not run on this site yet, in order. */
function pending_migrations(): array
{
    $done = read_json(MIGRATIONS_FILE)['applied'] ?? [];
    $files = glob(ROOT . '/tools/migrations/[0-9][0-9][0-9]-*.php') ?: [];
    sort($files);
    return array_values(array_filter($files, fn($f) => !isset($done[basename($f)])));
}

function record_migration(string $name): void
{
    $state = read_json(MIGRATIONS_FILE) ?? ['applied' => []];
    $state['applied'][$name] = date('c');
    write_json(MIGRATIONS_FILE, $state);
}
