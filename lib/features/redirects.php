<?php
/**
 * Redirects and the 404 log (always on).
 *
 * Redirects: data/redirects.json holds a list of from-path => to (a path on this site or an
 * https address) with a type, 301 (moved for good) or 302 (moved for now). A request whose
 * path matches a from-path, with or without a trailing slash and in any letter case, is sent
 * on. Matching happens in a registered route and again from index.php's not_found(), through
 * log_404(), so addresses the core claims first (such as /blog/old-post/) are rescued too.
 *
 * 404 log: log_404() counts hits per path per day in data/404s.json, keeping 90 days and at most
 * 500 distinct paths. Paths with a file extension and the usual scanner noise are not counted.
 * The Redirects screen shows the most-missed addresses of the last 30 days with an
 * "Add a redirect" link for each, and the Dashboard says how many addresses got 404s this week.
 *
 * Control panel screens live in admin/redirects.php and are loaded only when needed.
 */

declare(strict_types=1);

const REDIRECTS_FILE = DATA_DIR . '/redirects.json';
const NOT_FOUND_LOG = DATA_DIR . '/404s.json';
const NOT_FOUND_KEEP_DAYS = 90;
const NOT_FOUND_MAX_PATHS = 500;

/* ---------- Redirects ---------- */

/** Every redirect: ['id' => hex, 'from' => '/old/', 'to' => '/new/' or 'https://…', 'code' => 301|302]. */
function redirects(): array
{
    $list = read_json(REDIRECTS_FILE)['redirects'] ?? [];
    return array_values(array_filter($list, fn($r) => is_array($r) && isset($r['from'], $r['to'])));
}

function save_redirects(array $list): void
{
    write_json(REDIRECTS_FILE, ['redirects' => array_values($list)]);
    @chmod(REDIRECTS_FILE, 0600);
}

/** The form a path is compared in: lowercase, a leading slash, no trailing slash ("/" stays "/"). */
function redirect_key(string $path): string
{
    $p = strtolower(trim($path));
    $p = '/' . ltrim($p, '/');
    $p = rtrim($p, '/');
    return $p === '' ? '/' : $p;
}

/** A from-path as typed is cleaned up: no scheme or host, no query string, one leading slash. */
function clean_from_path(string $from): string
{
    $from = trim($from);
    if (preg_match('#^https?://[^/]+(/.*)?$#i', $from, $m)) {
        $from = $m[1] ?? '/';
    }
    $from = (string) (parse_url($from, PHP_URL_PATH) ?? $from);
    return '/' . ltrim($from, '/');
}

/** Only a path on this site ("/new-page/") or an https address; never a line break or a protocol-relative "//host". */
function valid_redirect_target(string $to): bool
{
    if (preg_match('/[\r\n\s]/', $to) || str_contains($to, '\\')) {
        return false;
    }
    if (preg_match('#^/(?!/)#', $to)) {
        return true;
    }
    return str_starts_with(strtolower($to), 'https://') && filter_var($to, FILTER_VALIDATE_URL) !== false && (string) parse_url($to, PHP_URL_HOST) !== '';
}

function find_redirect(string $path): ?array
{
    $key = redirect_key($path);
    foreach (redirects() as $r) {
        if (redirect_key((string) $r['from']) === $key) {
            return $r;
        }
    }
    return null;
}

function send_redirect(array $r): never
{
    $to = (string) $r['to'];
    if (!valid_redirect_target($to)) {
        $to = '/';
    }
    header('Location: ' . $to, true, (int) ($r['code'] ?? 301) === 302 ? 302 : 301);
    exit;
}

register_route(function (string $path, array $parts): bool {
    if ($r = find_redirect($path)) {
        send_redirect($r);
    }
    return false;
});

/* ---------- 404 log ---------- */

/** Paths nobody meant to visit: automated scans for other software's weak spots. */
function scanner_noise(string $path): bool
{
    return (bool) preg_match('#^/(wp-|wordpress|xmlrpc|phpmyadmin|pma\b|myadmin|cgi-bin|vendor/|\.well-known|\.git|\.env|\.aws|\.vscode|\.ssh|admin/|administrator|owa/|autodiscover|ecp/|_ignition|solr|actuator|telescope|console|boaform|hnap1|manager/|jenkins|phpinfo|shell|cmd\b|eval|wlwmanifest|server-status|magento|joomla|drupal|typo3|laravel|storage/|backup|dump|sql|config|setup|install|api/v\d|graphql|\.php)#i', $path)
        || str_contains($path, '//') || str_contains($path, '..') || str_contains($path, '<') || str_contains($path, '{');
}

/** Worth logging: a plain address with no file extension, printable, not too long, not scanner noise, not a bot. */
function loggable_404(string $path): bool
{
    if ($path === '' || strlen($path) > 150 || preg_match('/[\x00-\x1f\x7f]/', $path) || !str_starts_with($path, '/')) {
        return false;
    }
    if (str_contains(basename($path), '.')) {
        return false;
    }
    if (scanner_noise($path)) {
        return false;
    }
    return !(function_exists('is_bot') && is_bot((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')));
}

/**
 * Called by index.php's not_found(). If a redirect matches the path, it is sent instead of the 404 page
 * (so old blog and cheat sheet addresses can be rescued too); otherwise the miss is counted.
 * Nothing here can stop the 404 page: every problem is swallowed.
 */
function log_404(string $path): void
{
    if ($r = find_redirect($path)) {
        send_redirect($r);
    }
    try {
        if (!loggable_404($path)) {
            return;
        }
        $fh = @fopen(NOT_FOUND_LOG, 'c+');
        if (!$fh) {
            return;
        }
        try {
            flock($fh, LOCK_EX);
            $log = json_decode((string) stream_get_contents($fh), true);
            $log = is_array($log) ? $log : [];
            $today = date('Y-m-d');
            $cutoff = date('Y-m-d', strtotime('-' . NOT_FOUND_KEEP_DAYS . ' days'));
            foreach ($log as $p => $days) {
                $log[$p] = array_filter((array) $days, fn($n, $d) => $d >= $cutoff, ARRAY_FILTER_USE_BOTH);
                if (!$log[$p]) {
                    unset($log[$p]);
                }
            }
            $key = rtrim($path, '/') ?: '/';
            if (!isset($log[$key]) && count($log) >= NOT_FOUND_MAX_PATHS) {
                // Full: the address with the fewest hits makes room.
                uasort($log, fn($a, $b) => array_sum($a) <=> array_sum($b));
                unset($log[array_key_first($log)]);
            }
            $log[$key][$today] = (int) ($log[$key][$today] ?? 0) + 1;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($log, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            fflush($fh);
            flock($fh, LOCK_UN);
        } finally {
            fclose($fh);
        }
        @chmod(NOT_FOUND_LOG, 0600);
    } catch (Throwable $e) {
        error_log('404 log: ' . $e->getMessage());
    }
}

/** The most-missed addresses of the last $days days: [['path', 'hits', 'last'], …], most hits first. */
function missing_addresses(int $days = 30): array
{
    $log = read_json(NOT_FOUND_LOG) ?? [];
    $cutoff = date('Y-m-d', strtotime('-' . $days . ' days'));
    $out = [];
    foreach ($log as $path => $byDay) {
        $hits = 0;
        $last = '';
        foreach ((array) $byDay as $day => $n) {
            if ($day >= $cutoff) {
                $hits += (int) $n;
                $last = max($last, (string) $day);
            }
        }
        if ($hits > 0) {
            $out[] = ['path' => (string) $path, 'hits' => $hits, 'last' => $last];
        }
    }
    usort($out, fn($a, $b) => [$b['hits'], $b['last']] <=> [$a['hits'], $a['last']]);
    return $out;
}

/* ---------- Control panel ---------- */

register_admin_screen('redirects', function (bool $post): void {
    require_once ROOT . '/admin/redirects.php';
    redirects_screen($post);
}, 'Redirects');

register_dashboard_card(function (): string {
    $n = count(missing_addresses(7));
    if ($n === 0) {
        return '';
    }
    return '<li><h2><a href="' . h(admin_url('redirects')) . '">Redirects</a></h2><p><strong>' . $n . ' ' . ($n === 1 ? 'address' : 'addresses') . ' got 404s this week.</strong> See which, and send visitors to the right place.</p></li>';
});
