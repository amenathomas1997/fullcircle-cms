<?php
/**
 * Private visitor counts. No cookies and nothing sent to other companies.
 * A visitor is counted once a day using a scrambled code made from their
 * internet address and browser with a secret that changes every day and is
 * then deleted, so visits can't be traced back to a person or linked across days.
 * One small file per day in data/stats/, kept for 13 months.
 */

declare(strict_types=1);

const STATS_DIR = DATA_DIR . '/stats';

function is_bot(string $ua): bool
{
    return $ua === '' || (bool) preg_match('/bot|crawl|spider|slurp|preview|fetch|monitor|curl|wget|python|httpclient|headless|lighthouse/i', $ua);
}

function daily_salt(string $day): string
{
    $file = STATS_DIR . "/salt-$day.txt";
    if (!is_file($file)) {
        write_file($file, bin2hex(random_bytes(16)));
        // Yesterday's secret is no longer needed: delete it so old codes can't be recreated.
        foreach (glob(STATS_DIR . '/salt-*.txt') ?: [] as $old) {
            if ($old !== $file) {
                @unlink($old);
            }
        }
    }
    return (string) file_get_contents($file);
}

/** Count a page view. Called after a page is shown successfully. */
function record_visit(string $path): void
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (is_bot($ua) || !empty($_COOKIE['fcat_admin']) || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET'
        || ($_SERVER['HTTP_SEC_PURPOSE'] ?? $_SERVER['HTTP_PURPOSE'] ?? '') !== '') {
        return;
    }
    try {
        if (!is_dir(STATS_DIR)) {
            mkdir(STATS_DIR, 0700, true);
        }
        $day = date('Y-m-d');
        $visitor = substr(hash('sha256', daily_salt($day) . ($_SERVER['REMOTE_ADDR'] ?? '') . $ua), 0, 16);
        $referrer = '';
        $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $refHost = strtolower((string) parse_url($ref, PHP_URL_HOST));
        $ownHost = strtolower((string) parse_url(site_url('/'), PHP_URL_HOST));
        if ($refHost !== '' && $refHost !== $ownHost && $refHost !== 'www.' . $ownHost && $refHost !== ($_SERVER['HTTP_HOST'] ?? '')) {
            $referrer = preg_replace('/^www\./', '', $refHost);
        }

        $file = STATS_DIR . "/$day.json";
        $fh = fopen($file, 'c+');
        if (!$fh) {
            return;
        }
        flock($fh, LOCK_EX);
        $data = json_decode((string) stream_get_contents($fh), true) ?: ['views' => 0, 'visitors' => [], 'pages' => [], 'referrers' => []];
        $data['views']++;
        $data['visitors'][$visitor] = 1;
        // The first website that sent this visitor today, so a download can be credited to it (kept for the day only, under the same scrambled code).
        if ($referrer !== '' && !isset($data['visitor_ref'][$visitor])) {
            $data['visitor_ref'][$visitor] = $referrer;
        }
        $data['pages'][$path] = ($data['pages'][$path] ?? 0) + 1;
        if ($referrer !== '') {
            $data['referrers'][$referrer] = ($data['referrers'][$referrer] ?? 0) + 1;
        }
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($data, JSON_UNESCAPED_SLASHES));
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);

        // Once a day, remove days older than 13 months.
        if (count($data['visitors']) === 1 && $data['views'] === 1) {
            $cutoff = date('Y-m-d', strtotime('-13 months'));
            foreach (glob(STATS_DIR . '/????-??-??.json') ?: [] as $f) {
                if (basename($f, '.json') < $cutoff) {
                    @unlink($f);
                }
            }
        }
    } catch (Throwable $e) {
        error_log('Visitor count failed: ' . $e->getMessage());
    }
}

/**
 * Count one press of an app's download button (14 November 2026). The same
 * rules as a page view: no cookies, no robots, not while signed in to the
 * control panel. Kept in the day's file: how many presses of each app and
 * version, in which language, and how many different people pressed (the
 * same scrambled daily code as visitors, so it can't be traced to anyone).
 */
function record_download(string $app, string $version, string $lang): void
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (is_bot($ua) || !empty($_COOKIE['fcat_admin']) || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET'
        || ($_SERVER['HTTP_SEC_PURPOSE'] ?? $_SERVER['HTTP_PURPOSE'] ?? '') !== '') {
        return;
    }
    try {
        if (!is_dir(STATS_DIR)) {
            mkdir(STATS_DIR, 0700, true);
        }
        $day = date('Y-m-d');
        $person = substr(hash('sha256', daily_salt($day) . ($_SERVER['REMOTE_ADDR'] ?? '') . $ua), 0, 16);
        $fh = fopen(STATS_DIR . "/$day.json", 'c+');
        if (!$fh) {
            return;
        }
        flock($fh, LOCK_EX);
        $data = json_decode((string) stream_get_contents($fh), true) ?: ['views' => 0, 'visitors' => [], 'pages' => [], 'referrers' => []];
        $key = "$app $version";
        $data['downloads'][$key] = ($data['downloads'][$key] ?? 0) + 1;
        $data['download_langs'][$lang] = ($data['download_langs'][$lang] ?? 0) + 1;
        $data['downloaders'][$person] = 1;
        $source = (string) ($data['visitor_ref'][$person] ?? 'No linking website (typed, bookmarked or from an app)');
        $data['download_sources'][$source] = ($data['download_sources'][$source] ?? 0) + 1;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($data, JSON_UNESCAPED_SLASHES));
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
    } catch (Throwable $e) {
        error_log('Download count failed: ' . $e->getMessage());
    }
}

/** Totals for the last $days days, newest first. */
function stats_summary(int $days = 30): array
{
    $daysOut = [];
    $pages = [];
    $referrers = [];
    $totalViews = 0;
    $totalVisitors = 0;
    $downloads = [];
    $downloadLangs = [];
    $downloadSources = [];
    $downloadPeople = 0;
    for ($i = 0; $i < $days; $i++) {
        $day = date('Y-m-d', strtotime("-$i days"));
        $d = read_json(STATS_DIR . "/$day.json") ?? ['views' => 0, 'visitors' => [], 'pages' => [], 'referrers' => []];
        $visitors = count($d['visitors'] ?? []);
        $daysOut[] = ['day' => $day, 'views' => (int) ($d['views'] ?? 0), 'visitors' => $visitors,
            'downloads' => (int) array_sum($d['downloads'] ?? []), 'downloaders' => count($d['downloaders'] ?? [])];
        $downloadPeople += count($d['downloaders'] ?? []);
        foreach ($d['downloads'] ?? [] as $k => $n) {
            $downloads[$k] = ($downloads[$k] ?? 0) + $n;
        }
        foreach ($d['download_langs'] ?? [] as $k => $n) {
            $downloadLangs[$k] = ($downloadLangs[$k] ?? 0) + $n;
        }
        foreach ($d['download_sources'] ?? [] as $k => $n) {
            $downloadSources[$k] = ($downloadSources[$k] ?? 0) + $n;
        }
        $totalViews += (int) ($d['views'] ?? 0);
        $totalVisitors += $visitors;
        foreach ($d['pages'] ?? [] as $p => $n) {
            $pages[$p] = ($pages[$p] ?? 0) + $n;
        }
        foreach ($d['referrers'] ?? [] as $r => $n) {
            $referrers[$r] = ($referrers[$r] ?? 0) + $n;
        }
    }
    arsort($pages);
    arsort($referrers);
    arsort($downloads);
    arsort($downloadSources);
    return ['days' => $daysOut, 'views' => $totalViews, 'visitors' => $totalVisitors, 'pages' => $pages, 'referrers' => $referrers,
        'downloads' => $downloads, 'download_langs' => $downloadLangs, 'download_people' => $downloadPeople, 'download_sources' => $downloadSources];
}
