<?php
/**
 * Keep a site up to date with the kit, from the command line:
 *   php tools/update.php           show the installed version and what a newer kit would bring
 *   php tools/update.php --apply   pull the update (git pull --ff-only), refresh Composer packages
 *                                  if vendor/ exists, run any new migrations, and print the changelog
 *   php tools/update.php --apply --verify
 *                                  the same, then run tools/selftest.php; if it fails, put the code back
 *                                  exactly as it was (git reset to the previous commit) and exit with 2.
 *                                  Finished migrations are not undone; every migration is safe to run twice.
 *   php tools/update.php --migrate
 *                                  run only the migrations not yet run, with no git at all: for a site
 *                                  whose code arrives another way (rsync from a deploy pipeline).
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
$verify = in_array('--verify', $argv, true);
if (in_array('--migrate', $argv, true)) {
    echo 'Full Circle CMS ' . cms_version() . ' in ' . realpath(ROOT) . "\n";
    $pending = pending_migrations();
    if (!$pending) {
        echo "No migrations waiting to run.\n";
        exit(0);
    }
    foreach ($pending as $file) {
        echo 'Running migration ' . basename($file) . "...\n";
        try {
            require $file;
            record_migration(basename($file));
            echo "  Done.\n";
        } catch (Throwable $e) {
            fwrite(STDERR, '  Failed: ' . $e->getMessage() . "\nFix the cause and run php tools/update.php --migrate again; finished migrations are not repeated.\n");
            exit(1);
        }
    }
    exit(0);
}
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

$beforeCommit = trim((string) git_run(['rev-parse', 'HEAD']));
$kept = [];
if ($log !== '') {
    // The template and stylesheet are tracked by git but edited in the control panel, so the site's own
    // versions are set aside for the pull and put back after it: what the owner wrote always wins.
    // .htaccess is there too: cPanel appends its PHP handler block to it, and that must stay.
    $owned = ['content/layout.html', 'assets/css/site.css', '.htaccess'];
    // Each porcelain line is two status letters, a space and the path; the leading space matters.
    $dirty = array_filter(explode("\n", rtrim((string) git_run(['status', '--porcelain', '--untracked-files=no']), "\n")), 'strlen');
    foreach ($dirty as $line) {
        $path = substr($line, 3);
        if (in_array($path, $owned, true)) {
            $kept[$path] = (string) file_get_contents(ROOT . '/' . $path);
        } else {
            fwrite(STDERR, "There are local changes to the kit's files, so a fast-forward pull would fail:\n" . implode("\n", $dirty) . "\nCommit or stash them first (site/, content/ and uploads/ are never involved).\n");
            exit(1);
        }
    }
    $before_kit = [];
    foreach ($kept as $path => $_) {
        $before_kit[$path] = (string) git_run(['show', "HEAD:$path"]);
        git_run(['checkout', '--', $path]);
    }
    echo "Pulling...\n";
    $pulled = git_run(['pull', '--ff-only', '--quiet', $remote], 300) !== null;
    foreach ($kept as $path => $contents) {
        $kitNow = (string) file_get_contents(ROOT . '/' . $path);
        write_file(ROOT . '/' . $path, $contents);
        if ($pulled && $kitNow !== $before_kit[$path]) {
            echo "  Note: the kit changed $path, but this site's own version (edited in the control panel) was kept. Compare them by hand if you want the kit's change.\n";
        }
    }
    if (!$pulled) {
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

if ($verify && $beforeCommit !== '') {
    echo "Checking the updated site (tools/selftest.php)...\n";
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOT . '/tools/selftest.php') . ' 2>&1', $code);
    if ($code !== 0) {
        $nowCommit = trim((string) git_run(['rev-parse', 'HEAD']));
        if ($nowCommit !== $beforeCommit) {
            foreach ($kept as $path => $_) {
                git_run(['checkout', '--', $path]);
            }
            $back = git_run(['reset', '--hard', '--quiet', $beforeCommit], 120) !== null;
            foreach ($kept as $path => $contents) {
                write_file(ROOT . '/' . $path, $contents);
            }
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            fwrite(STDERR, $back
                ? "The checks failed, so the code was put back to version $before (commit " . substr($beforeCommit, 0, 7) . "). The site is as it was before this update.\n"
                : "The checks failed and the code could NOT be put back automatically. Run: git reset --hard $beforeCommit\n");
        } else {
            fwrite(STDERR, "The checks failed. Nothing was pulled, so there is nothing to put back; the problem is already on this site.\n");
        }
        exit(2);
    }
    echo "  The updated site passed its checks.\n";
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
