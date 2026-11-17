<?php
/**
 * Operations for people who host several sites built from the kit:
 *  - the kit's version (VERSION, lib/version.php) and an update check against the kit's git repository,
 *    stored in data/update-status.json by a daily cron job and shown on the System screen;
 *  - /status.json, a signed summary of one site's health for the Sites screen of another site;
 *  - the Sites screen, which asks every site listed in data/config.php for its status in one go;
 *  - the Backups screen: nightly archives from tools/backup.sh, restore, export and import.
 * Shared helpers live here; the control panel screens are in admin/ops.php, loaded only when needed.
 * Secrets (this site's fleet_secret, the other sites' secrets, backup_dir) live in data/config.php.
 */

declare(strict_types=1);

require_once ROOT . '/lib/version.php';

const UPDATE_STATUS = DATA_DIR . '/update-status.json';
const FLEET_STATUS = DATA_DIR . '/fleet-status.json';
const MIGRATIONS_FILE = DATA_DIR . '/migrations.json';
/** How long a signed status request stays valid, in seconds. */
const FLEET_SIGNATURE_WINDOW = 300;
/** How many nightly archives tools/backup.sh keeps. */
const BACKUP_KEEP = 30;

/* ---------- Backups: where they are ---------- */

/** The folder tools/backup.sh writes to: backup_dir in data/config.php, or ~/site-backups/content beside the web folder. */
function backup_dir(): string
{
    $dir = trim((string) (config()['backup_dir'] ?? ''));
    return $dir !== '' ? rtrim($dir, '/') : dirname(realpath(ROOT) ?: ROOT) . '/site-backups/content';
}

/** Every nightly archive, newest first: name, path, time, size and the manifest beside it when there is one. */
function backup_archives(): array
{
    $out = [];
    foreach (glob(backup_dir() . '/site-content-*.tgz') ?: [] as $file) {
        $name = basename($file);
        if (!preg_match('/^site-content-(\d{4}-\d{2}-\d{2})\.tgz$/', $name, $m)) {
            continue;
        }
        $out[] = ['name' => $name, 'date' => $m[1], 'path' => realpath($file) ?: $file, 'time' => (int) filemtime($file), 'size' => (int) filesize($file),
            'manifest' => read_json(substr($file, 0, -4) . '.json')];
    }
    usort($out, fn($a, $b) => strcmp($b['name'], $a['name']));
    return $out;
}

/** One archive by its file name, or null when the name is not one of ours. Names are checked, never trusted. */
function backup_archive(string $name): ?array
{
    foreach (backup_archives() as $archive) {
        if ($archive['name'] === $name) {
            return $archive;
        }
    }
    return null;
}

/* ---------- Git and the update check ---------- */

/** Can this site run git? The exec functions are turned off on some hosts. */
function git_available(): bool
{
    static $ok = null;
    if ($ok === null) {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $ok = function_exists('proc_open') && !in_array('proc_open', $disabled, true) && git_run(['--version']) !== null;
    }
    return $ok;
}

/**
 * Run git in the site folder and return what it printed, or null when it failed. Never asks for a
 * password: a remote that needs one fails straight away instead of hanging.
 */
function git_run(array $args, int $timeout = 60): ?string
{
    $cmd = array_merge(['git', '-c', 'credential.helper='], $args);
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $env = getenv() + ['GIT_TERMINAL_PROMPT' => '0', 'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin', 'HOME' => getenv('HOME') ?: sys_get_temp_dir()];
    $proc = @proc_open($cmd, $spec, $pipes, ROOT, $env);
    if (!is_resource($proc)) {
        return null;
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = '';
    $deadline = time() + $timeout;
    while (true) {
        $out .= (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        $status = proc_get_status($proc);
        if (!$status['running']) {
            $out .= (string) stream_get_contents($pipes[1]);
            break;
        }
        if (time() > $deadline) {
            proc_terminate($proc, 9);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            return null;
        }
        usleep(50000);
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    return $status['exitcode'] === 0 ? $out : null;
}

/** Is the site folder a git checkout? */
function git_checkout(): bool
{
    return git_available() && (is_dir(ROOT . '/.git') || is_file(ROOT . '/.git'));
}

/** The branch the site is on, or '' when it is not a checkout. */
function git_branch(): string
{
    return git_checkout() ? trim((string) git_run(['rev-parse', '--abbrev-ref', 'HEAD'])) : '';
}

/** The branch on the kit's remote that updates come from: update_branch in data/config.php, or origin/main. */
function update_ref(): string
{
    $ref = trim((string) (config()['update_branch'] ?? ''));
    return preg_match('#^[A-Za-z0-9_./-]+$#', $ref) ? $ref : 'origin/main';
}

/**
 * Ask the kit's repository whether a newer version exists and remember the answer in data/update-status.json.
 * status is 'up-to-date', 'available', 'not-git' or 'error'.
 */
function check_for_updates(): array
{
    $result = ['checked' => date('c'), 'installed' => cms_version(), 'status' => 'not-git', 'available' => '', 'behind' => 0, 'branch' => '', 'message' => ''];
    if (!git_available()) {
        $result['message'] = 'Git is not available on this server.';
    } elseif (!git_checkout()) {
        $result['message'] = 'This site is not a git checkout.';
    } else {
        $result['branch'] = git_branch();
        $ref = update_ref();
        [$remote] = explode('/', $ref, 2) + [''];
        if (git_run(['fetch', '--quiet', $remote], 120) === null) {
            $result['status'] = 'error';
            $result['message'] = 'Could not reach the kit repository to check for updates.';
        } else {
            $behind = trim((string) git_run(['rev-list', '--count', "HEAD..$ref"]));
            $remoteVersion = trim((string) git_run(['show', "$ref:VERSION"]));
            $result['behind'] = (int) $behind;
            $result['available'] = preg_match('/^\d+\.\d+\.\d+$/', $remoteVersion) ? $remoteVersion : '';
            $newer = $result['available'] !== '' && version_compare($result['available'], cms_version(), '>');
            $result['status'] = $newer || $result['behind'] > 0 ? 'available' : 'up-to-date';
            if ($result['status'] === 'available' && $result['available'] === '') {
                $result['available'] = cms_version();
            }
        }
    }
    $previous = read_json(UPDATE_STATUS) ?? [];
    write_json(UPDATE_STATUS, $result + array_intersect_key($previous, ['ssl_days' => 1, 'ssl_checked' => 1]));
    return $result;
}

/** The last update check, or null when none has run. */
function update_status(): ?array
{
    return read_json(UPDATE_STATUS);
}

/** Plain words for the last update check, for the System screen, the Dashboard and the fleet. */
function update_words(?array $status): string
{
    if (!$status) {
        return 'Not checked yet.';
    }
    return match ($status['status'] ?? '') {
        'up-to-date' => 'Up to date',
        'available' => 'Version ' . ($status['available'] ?: '') . ' is available: ask your host to update',
        'not-git' => 'Not a git checkout',
        default => (string) ($status['message'] ?: 'The last check did not finish.'),
    };
}

/** Days until this site's own SSL certificate expires, remembered by the daily job so pages never wait on it. */
function own_certificate_days(bool $refresh = false): ?int
{
    $status = read_json(UPDATE_STATUS) ?? [];
    if (!$refresh && isset($status['ssl_checked']) && strtotime((string) $status['ssl_checked']) > strtotime('-2 days')) {
        return $status['ssl_days'] ?? null;
    }
    $days = null;
    if (!in_array(site_host(), ['localhost', '127.0.0.1'], true)) {
        $days = certificate_days()[site_host()] ?? null;
    }
    $status['ssl_days'] = $days;
    $status['ssl_checked'] = date('c');
    write_json(UPDATE_STATUS, $status);
    return $days;
}

// Once a day: look for a newer kit and note how long the certificate has left.
register_cron('daily', function (): void {
    check_for_updates();
    own_certificate_days(true);
});

/* ---------- /status.json: this site's health for the Sites screen of another site ---------- */

/** The signature for a status request made at time $t. */
function fleet_signature(int $t, string $secret): string
{
    return hash_hmac('sha256', (string) $t, $secret);
}

/** Everything /status.json reports. */
function site_status(): array
{
    $messages = glob(MESSAGE_DIR . '/*.json') ?: [];
    $unsent = count(array_filter($messages, fn($f) => !(read_json($f)['emailed'] ?? false)));
    $backup = backup_archives()[0] ?? null;
    $report = read_json(LINK_REPORT);
    $update = update_status();
    return [
        'version' => cms_version(),
        'site_name' => site_name(),
        'site_url' => site_url('/'),
        'php' => PHP_VERSION,
        'last_backup' => $backup ? date('c', $backup['time']) : null,
        'unsent_messages' => $unsent,
        'link_problems' => $report ? count($report['problems'] ?? []) : null,
        'ssl_days' => own_certificate_days(),
        'update_available' => ($update['status'] ?? '') === 'available' ? (string) $update['available'] : '',
        'update_status' => $update['status'] ?? 'unknown',
        'timestamp' => date('c'),
    ];
}

register_route(function (string $path, array $parts): bool {
    if ($path !== '/status.json') {
        return false;
    }
    $secret = (string) (config()['fleet_secret'] ?? '');
    $t = (int) ($_GET['t'] ?? 0);
    $sig = (string) ($_GET['sig'] ?? '');
    if ($secret === '' || abs(time() - $t) > FLEET_SIGNATURE_WINDOW || !hash_equals(fleet_signature($t, $secret), $sig)) {
        not_found();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    echo json_encode(site_status(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit;
});

/* ---------- The fleet: other sites listed in data/config.php ---------- */

/** The other sites, each [name, url, secret]. Kept in data/config.php because of the secrets. */
function fleet_sites(): array
{
    $out = [];
    foreach ((array) (config()['fleet_sites'] ?? []) as $site) {
        if (is_array($site) && ($site['url'] ?? '') !== '') {
            $out[] = ['name' => (string) ($site['name'] ?? ''), 'url' => rtrim((string) $site['url'], '/'), 'secret' => (string) ($site['secret'] ?? '')];
        }
    }
    return $out;
}

/** Fetch one site's /status.json with a signed request. Returns ['ok' => bool, 'status' => array|null, 'error' => string]. */
function fetch_site_status(array $site): array
{
    $t = time();
    $url = $site['url'] . '/status.json?' . http_build_query(['t' => $t, 'sig' => fleet_signature($t, $site['secret'])]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'FullCircleFleet/' . cms_version() . ' (+' . site_url('/') . ')',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    if ($code === 0) {
        return ['ok' => false, 'status' => null, 'error' => 'No answer' . ($err !== '' ? " ($err)" : '') . '.'];
    }
    if ($code === 404) {
        return ['ok' => false, 'status' => null, 'error' => 'The site said "not found": its fleet secret may not match this one, or it is an older kit.'];
    }
    if ($code !== 200) {
        return ['ok' => false, 'status' => null, 'error' => "The site answered with status $code."];
    }
    $data = json_decode($body, true);
    if (!is_array($data) || !isset($data['version'])) {
        return ['ok' => false, 'status' => null, 'error' => 'The answer was not a status report.'];
    }
    return ['ok' => true, 'status' => $data, 'error' => ''];
}

/** The things to look at in one site's status, in plain words; empty when all is well. */
function status_concerns(array $s): array
{
    $concerns = [];
    if (($s['unsent_messages'] ?? 0) > 0) {
        $n = (int) $s['unsent_messages'];
        $concerns[] = "$n contact " . ($n === 1 ? 'message' : 'messages') . ' not emailed';
    }
    if (($s['link_problems'] ?? 0) > 0) {
        $n = (int) $s['link_problems'];
        $concerns[] = "$n " . ($n === 1 ? 'link' : 'links') . ' to look at';
    }
    if (array_key_exists('ssl_days', $s) && $s['ssl_days'] !== null && $s['ssl_days'] < 14) {
        $concerns[] = 'the SSL certificate expires in ' . (int) $s['ssl_days'] . ' days';
    }
    if (empty($s['last_backup'])) {
        $concerns[] = 'no backup yet';
    } elseif (strtotime((string) $s['last_backup']) < strtotime('-2 days')) {
        $concerns[] = 'the last backup was ' . format_date((string) $s['last_backup']);
    }
    if (($s['update_available'] ?? '') !== '') {
        $concerns[] = 'version ' . $s['update_available'] . ' of the kit is available';
    }
    return $concerns;
}

/** "All good" or "2 things to look at: …". */
function status_summary(array $result): string
{
    if (!$result['ok']) {
        return 'Could not be checked: ' . $result['error'];
    }
    $concerns = status_concerns($result['status']);
    if (!$concerns) {
        return 'All good';
    }
    return count($concerns) === 1 ? '1 thing to look at: ' . $concerns[0] : count($concerns) . ' things to look at: ' . implode('; ', $concerns);
}

/** Ask every site in turn and remember the answers in data/fleet-status.json. */
function check_fleet(): array
{
    $results = [];
    foreach (fleet_sites() as $site) {
        $result = fetch_site_status($site);
        $results[] = ['name' => $site['name'], 'url' => $site['url'], 'ok' => $result['ok'], 'status' => $result['status'], 'error' => $result['error'],
            'summary' => status_summary($result), 'fine' => $result['ok'] && !status_concerns($result['status'])];
    }
    $all = ['checked' => date('c'), 'sites' => $results];
    write_json(FLEET_STATUS, $all);
    return $all;
}

/* ---------- Activity log, when another part of the kit provides one ---------- */

/** Note what was restored or imported, if the activity log exists; never fails the action it describes. */
function ops_log(string $message): void
{
    if (!function_exists('log_activity')) {
        return;
    }
    try {
        log_activity($message);
    } catch (Throwable $e) {
        error_log('Activity log failed: ' . $e->getMessage());
    }
}

/* ---------- Control panel ---------- */

/** The screens are for the owner. If the kit has a roles check, follow it; otherwise anyone signed in is the owner. */
function ops_owner_only(): void
{
    if (function_exists('is_owner') && !is_owner()) {
        not_found_screen();
    }
}

$opsScreen = function (string $name): callable {
    return function (bool $post) use ($name): never {
        require_once ROOT . '/admin/ops.php';
        ops_owner_only();
        ("ops_{$name}_screen")($post);
    };
};
register_admin_screen('system', $opsScreen('system'), 'System');
register_admin_screen('sites', $opsScreen('sites'), 'Sites');
register_admin_screen('backups', $opsScreen('backups'), 'Backups');
unset($opsScreen);

register_dashboard_card(function (): string {
    if (!fleet_sites()) {
        return '';
    }
    $fleet = read_json(FLEET_STATUS);
    $total = count(fleet_sites());
    if (!$fleet) {
        $words = $total . ' other ' . ($total === 1 ? 'site' : 'sites') . ' listed; not checked yet.';
    } else {
        $fine = count(array_filter($fleet['sites'] ?? [], fn($s) => !empty($s['fine'])));
        $n = count($fleet['sites']);
        $words = ($n === 1 ? ($fine === 1 ? 'The 1 other site is fine.' : 'The 1 other site has something to look at.') : "$fine of $n sites are fine.") . ' Last checked ' . format_time((string) $fleet['checked']) . '.';
    }
    return '<li><h2><a href="' . h(admin_url('sites')) . '">Sites</a></h2><p>' . h($words) . '</p></li>';
});

register_dashboard_card(function (): string {
    $status = update_status();
    if (($status['status'] ?? '') !== 'available') {
        return '';
    }
    return '<li><h2><a href="' . h(admin_url('system')) . '">System</a></h2><p>' . h(update_words($status)) . '</p></li>';
});
