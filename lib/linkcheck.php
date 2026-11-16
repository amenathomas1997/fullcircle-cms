<?php
/**
 * Keeping the site healthy:
 *  - a monthly link check of every page,
 *    reported by email and under Link check in the control panel;
 *  - daily checks that the SSL certificates aren't about to expire and that
 *    PHP errors haven't suddenly jumped;
 *  - /health.txt, for an outside uptime monitor such as UptimeRobot.
 * tools/check-links.php runs these from cron.
 */

declare(strict_types=1);

const LINK_REPORT = DATA_DIR . '/link-report.json';
const LINK_REQUEST = DATA_DIR . '/link-check-requested';
const HEALTH_STATE = DATA_DIR . '/health-state.json';
/** Hosts whose SSL certificates are checked daily: the site itself, plus any listed under cert_hosts in Settings. */
function cert_hosts(): array
{
    return array_values(array_unique(array_filter(array_merge([site_host()], (array) (settings()['cert_hosts'] ?? [])))));
}

/** Addresses inside this server's network are never fetched, whatever a page links to. */
function private_host(string $url): bool
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
        return true;
    }
    $ip = filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) ? trim($host, '[]') : gethostbyname($host);
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

/** Fetch a URL; returns [status, final URL]. Status 0 means no answer. */
function probe_url(string $url, bool $head = true): array
{
    if (private_host($url)) {
        return [0, $url];
    }
    $received = 0;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => $head,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 6,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; SiteLinkCheck/1.0; +' . site_url('/') . ')',
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,*/*;q=0.8', 'Accept-Language: en-US,en;q=0.8'],
        // Only the status matters: stop reading after the first 64 KB rather than downloading whole files.
        CURLOPT_WRITEFUNCTION => function ($ch, string $data) use (&$received): int {
            $received += strlen($data);
            return $received > 65536 ? -1 : strlen($data);
        },
    ]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    // Some servers refuse HEAD requests; ask again with a normal GET.
    if ($head && in_array($status, [0, 400, 403, 404, 405, 501], true)) {
        return probe_url($url, false);
    }
    return [$status, $final];
}

function link_verdict(int $status): string
{
    return match (true) {
        $status >= 200 && $status < 400 && $status !== 202 => 'ok',
        in_array($status, [401, 403, 406, 417, 429, 202, 999], true) => 'blocked',
        in_array($status, [404, 410], true) => 'broken',
        $status >= 500 => 'error',
        $status === 0 => 'no-answer',
        default => 'broken',
    };
}

/** The main part of a host name: "www.dcf.ks.gov" -> "ks.gov", "a.github.com" -> "github.com". */
function base_domain(string $host): string
{
    $parts = explode('.', strtolower($host));
    $n = count($parts);
    // Government and school sites keep their state or school name: "dcf.ks.gov", "gatfl.gatech.edu".
    if ($n >= 3 && in_array($parts[$n - 1], ['gov', 'edu', 'us'], true)) {
        return implode('.', array_slice($parts, -3));
    }
    return implode('.', array_slice($parts, -2));
}

/** Bot-check pages and file servers aren't real moves. */
function redirect_kind(string $fromHost, string $toHost): string
{
    if ($toHost === '' || base_domain($fromHost) === base_domain($toHost)) {
        return 'ok';
    }
    if (preg_match('/(perfdrive|shieldsquare|captcha|challenge|cloudflare|incapsula|akamai)/i', $toHost)) {
        return 'blocked';
    }
    if (preg_match('/(githubusercontent\.com|cloudfront\.net|amazonaws\.com|blob\.core\.windows\.net|googleusercontent\.com)$/i', $toHost)) {
        return 'ok';
    }
    return 'moved';
}

/** Every link on every page in the sitemap, plus the Help near you agencies, with the pages that use each. */
function collect_links(): array
{
    $links = [];
    $add = function (string $href, string $from) use (&$links) {
        $href = html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($href === '' || preg_match('#^(mailto:|tel:|javascript:|\#)#i', $href)) {
            return;
        }
        $href = preg_replace('/#.*$/', '', $href);
        $url = str_starts_with($href, '/') ? rtrim(site_url('/'), '/') . $href : $href;
        if (!preg_match('#^https?://#i', $url)) {
            return;
        }
        $links[$url][$from] = true;
    };
    [$code, $sitemap] = [0, ''];
    $ch = curl_init(site_url('/sitemap.xml'));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $sitemap = (string) curl_exec($ch);
    preg_match_all('#<loc>([^<]+)</loc>#', $sitemap, $m);
    foreach (array_unique($m[1] ?? []) as $page) {
        $ch = curl_init(html_entity_decode($page));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_USERAGENT => 'SiteLinkCheck/1.0']);
        $html = (string) curl_exec($ch);
        $from = (string) parse_url($page, PHP_URL_PATH);
        preg_match_all('#<(?:a|link)\b[^>]*\bhref="([^"]+)"#i', $html, $hrefs);
        preg_match_all('#<(?:img|iframe|audio|script)\b[^>]*\bsrc="([^"]+)"#i', $html, $srcs);
        foreach (array_merge($hrefs[1], $srcs[1]) as $href) {
            $add($href, $from);
        }
    }
    return array_map('array_keys', $links);
}

/** Check every link (a few minutes) and save the report. */
function run_link_check(): array
{
    @unlink(LINK_REQUEST);
    $ownHost = strtolower((string) parse_url(site_url('/'), PHP_URL_HOST));
    $problems = [];
    $counts = ['ok' => 0, 'moved' => 0, 'blocked' => 0, 'broken' => 0, 'error' => 0, 'no-answer' => 0];
    $links = collect_links();
    foreach ($links as $url => $pages) {
        [$status, $final] = probe_url($url);
        $verdict = link_verdict($status);
        $fromHost = strtolower((string) parse_url($url, PHP_URL_HOST));
        $toHost = strtolower((string) parse_url($final, PHP_URL_HOST));
        if ($verdict === 'ok') {
            $verdict = redirect_kind($fromHost, $toHost);
        }
        $counts[$verdict]++;
        if ($verdict !== 'ok') {
            $problems[] = ['url' => $url, 'status' => $status, 'verdict' => $verdict, 'final' => $final, 'pages' => array_slice($pages, 0, 8),
                'internal' => $fromHost === $ownHost];
        }
    }
    $order = ['broken' => 0, 'error' => 1, 'no-answer' => 2, 'moved' => 3, 'blocked' => 4];
    usort($problems, fn($a, $b) => [$order[$a['verdict']], $a['url']] <=> [$order[$b['verdict']], $b['url']]);
    $report = ['checked' => date('c'), 'total' => count($links), 'counts' => $counts, 'problems' => $problems];
    write_json(LINK_REPORT, $report);
    return $report;
}

function link_report_email(array $report): void
{
    $serious = array_filter($report['problems'], fn($p) => in_array($p['verdict'], ['broken', 'error', 'no-answer', 'moved'], true));
    if (!$serious || !mail_configured()) {
        return;
    }
    $rows = '';
    foreach ($serious as $p) {
        $rows .= '<li><a href="' . h($p['url']) . '">' . h($p['url']) . '</a>: ' . h(link_verdict_words($p))
            . '<br>On: ' . h(implode(', ', $p['pages'])) . '</li>';
    }
    send_mail_owner('Website link check: ' . count($serious) . ' to look at',
        '<p>The monthly link check of ' . h(site_host()) . ' found ' . count($serious) . ' ' . (count($serious) === 1 ? 'link' : 'links') . ' to look at:</p><ul>' . $rows . '</ul>'
        . '<p>See the full report under Link check in the <a href="' . h(site_url('/admin/?a=links')) . '">control panel</a>.</p>');
}

function link_verdict_words(array $p): string
{
    return match ($p['verdict']) {
        'broken' => 'not found (' . $p['status'] . ')',
        'error' => 'that website had an error (' . $p['status'] . ')',
        'no-answer' => 'didn’t answer (the site may be down or the address wrong)',
        'moved' => 'now goes to ' . $p['final'],
        'blocked' => 'that website blocks automatic checks (' . $p['status'] . '); open it yourself to be sure',
        default => (string) $p['status'],
    };
}

/** Days until each site's SSL certificate expires. */
function certificate_days(): array
{
    $out = [];
    foreach (cert_hosts() as $host) {
        $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $client = @stream_socket_client("ssl://$host:443", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$client) {
            $out[$host] = null;
            continue;
        }
        $cert = openssl_x509_parse(stream_context_get_params($client)['options']['ssl']['peer_certificate'] ?? '');
        fclose($client);
        $out[$host] = $cert ? (int) floor(($cert['validTo_time_t'] - time()) / 86400) : null;
    }
    return $out;
}

/** Daily: warn about certificates expiring within 14 days and a jump in PHP errors. One email a day at most. */
function daily_health_check(): array
{
    $state = read_json(HEALTH_STATE) ?? [];
    $warnings = [];
    foreach (certificate_days() as $host => $days) {
        if ($days === null) {
            $warnings[] = "Couldn’t check the SSL certificate for $host.";
        } elseif ($days < 14) {
            $warnings[] = "The SSL certificate for $host expires in $days " . ($days === 1 ? 'day' : 'days') . '. In cPanel, open SSL/TLS Status and run AutoSSL.';
        }
    }
    // cPanel writes PHP errors to ~/logs/<domain with underscores>.php.error.log.
    $logName = str_replace('.', '_', site_host()) . '.php.error.log';
    $log = dirname(ROOT) . '/logs/' . $logName;
    if (is_readable($log)) {
        $today = date('d-M-Y');
        $count = 0;
        $fh = fopen($log, 'r');
        fseek($fh, max(0, filesize($log) - 2_000_000));
        while (($line = fgets($fh)) !== false) {
            if (str_starts_with($line, "[$today") && !str_contains($line, 'email failed')) {
                $count++;
            }
        }
        fclose($fh);
        if ($count >= 25) {
            $warnings[] = "The website has logged $count PHP errors today. Look at ~/logs/$logName, or ask for help.";
        }
    }
    $state['last_daily'] = date('c');
    $state['warnings'] = $warnings;
    if ($warnings && ($state['last_alert'] ?? '') !== date('Y-m-d') && mail_configured()) {
        try {
            send_mail_owner('Website health: ' . count($warnings) . ' to look at', '<ul><li>' . implode('</li><li>', array_map('h', $warnings)) . '</li></ul>');
            $state['last_alert'] = date('Y-m-d');
        } catch (Throwable $e) {
            error_log('Health alert email failed: ' . $e->getMessage());
        }
    }
    write_json(HEALTH_STATE, $state);
    return $warnings;
}

/** /health.txt: "OK" with status 200 when the site can show pages and save changes; 503 otherwise. */
function health_endpoint(): never
{
    $problems = [];
    if (!layout_template()) {
        $problems[] = 'template missing';
    }
    if (!is_writable(DATA_DIR) || !is_writable(CONTENT_DIR)) {
        $problems[] = 'storage not writable';
    }
    if (($free = @disk_free_space(ROOT)) !== false && $free < 300 * 1024 * 1024) {
        $problems[] = 'disk nearly full';
    }
    http_response_code($problems ? 503 : 200);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    echo $problems ? 'PROBLEM: ' . implode(', ', $problems) : 'OK';
    exit;
}
