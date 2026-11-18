<?php
/**
 * Checks the kit can run, from the command line or in CI:
 *   php tools/selftest.php                       syntax of every PHP file, the bootstrap, the registry and the
 *                                                appointment engine, all without a web server
 *   php tools/selftest.php --site http://localhost:8000 [--email owner@example.com --password ...]
 *                                                also crawls a running site: public pages, and with the sign-in
 *                                                details every control panel screen, looking for PHP errors
 * Prints one line per check and exits 1 when anything failed.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this from the command line.');
}

$root = dirname(__DIR__);
$opts = getopt('', ['site:', 'email:', 'password:']);
$failed = 0;
$check = function (string $name, bool $ok, string $detail = '') use (&$failed): void {
    echo $ok ? 'ok   ' : 'FAIL ', $name, $detail !== '' ? ' — ' . $detail : '', "\n";
    if (!$ok) {
        $failed++;
    }
};

/* ---------- 1. Every PHP file compiles ---------- */
$bad = [];
$it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    fn($f) => !in_array($f->getFilename(), ['vendor', '.git', 'node_modules', 'backups', 'uploads', 'data'], true)));
$count = 0;
foreach ($it as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $count++;
    exec('php -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        $bad[] = substr($file->getPathname(), strlen($root) + 1) . ': ' . trim(implode(' ', $out));
    }
    $out = [];
}
$check("syntax of $count PHP files", !$bad, implode('; ', array_slice($bad, 0, 5)));

/* ---------- 2. The bootstrap loads and the registry is populated ---------- */
chdir($root);
try {
    require $root . '/lib/bootstrap.php';
    $check('bootstrap loads', true);
} catch (Throwable $e) {
    $check('bootstrap loads', false, $e->getMessage());
    exit(1);
}
$features = array_keys(registered('features'));
foreach (['appointments', 'deploy', 'events', 'forms', 'payments'] as $f) {
    $check("feature registered: $f", in_array($f, $features, true));
}
foreach (['appointments', 'deploy', 'events', 'forms', 'products'] as $screen) {
    $check("admin screen registered: $screen", isset(registered('admin')[$screen]));
}
$check('version file matches lib/version.php', !defined('KIT_VERSION') || trim((string) file_get_contents($root . '/VERSION')) === KIT_VERSION,
    'VERSION=' . trim((string) @file_get_contents($root . '/VERSION')) . (defined('KIT_VERSION') ? ' KIT_VERSION=' . KIT_VERSION : ''));

/* ---------- 3. Translations: every English key used by t() in the code exists ---------- */
$en = require $root . '/lib/languages/en.php';
$used = [];
foreach (array_merge(glob($root . '/lib/*.php') ?: [], glob($root . '/lib/features/*.php') ?: [], glob($root . '/admin/*.php') ?: [], [$root . '/index.php']) as $f) {
    if (preg_match_all('/\bt(?:_in\([^,]+,)?\(\s*\'([a-z0-9_]+)\'\s*\)/', (string) file_get_contents($f), $m)) {
        foreach ($m[1] as $k) {
            $used[$k] = true;
        }
    }
}
unset($used['key']); // the example in the i18n.php comment
$missing = array_diff(array_keys($used), array_keys($en));
$check('every t() key has English text', !$missing, implode(', ', array_slice($missing, 0, 8)));

/* ---------- 4. The appointment engine ---------- */
$zone = appt_zone();
$svc = ['slug' => 'test', 'title' => 'Test', 'duration' => 45, 'status' => 'published'];
$settingsBefore = settings();
$s = $settingsBefore;
$s['appointments'] = ['hours' => ['tue' => [['09:00', '17:00']]], 'step' => 30, 'buffer' => 0, 'notice_hours' => 0, 'window_days' => 60];
// appt_openings() reads appt_settings(), so the settings file is swapped for a moment and put back whatever happens.
$day = (new DateTimeImmutable('next tuesday', $zone))->setTime(0, 0);
$busy = [[$day->setTime(10, 0), $day->setTime(11, 0)]];
$engineOk = false;
$detail = '';
try {
    $file = CONTENT_DIR . '/settings.json';
    $orig = is_file($file) ? (string) file_get_contents($file) : null;
    write_json($file, $s);
    settings(true);
    $open = array_map(fn($d) => $d->format('H:i'), appt_openings($svc, $day, $busy));
    $engineOk = $open === ['09:00', '11:00', '11:30', '12:00', '12:30', '13:00', '13:30', '14:00', '14:30', '15:00', '15:30', '16:00'];
    $detail = implode(' ', $open);
} catch (Throwable $e) {
    $detail = $e->getMessage();
} finally {
    if (isset($file)) {
        $orig === null ? @unlink($file) : file_put_contents($file, $orig);
        settings(true);
    }
}
$check('openings skip busy times and the end of the day', $engineOk, $detail);

$ics = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:a\r\nDTSTART;TZID=America/Chicago:20261124T090000\r\nDTEND;TZID=America/Chicago:20261124T100000\r\nRRULE:FREQ=WEEKLY;BYDAY=MO,TU;COUNT=6\r\nEXDATE;TZID=America/Chicago:20261201T090000\r\nEND:VEVENT\r\n"
    . "BEGIN:VEVENT\r\nUID:b\r\nDTSTART;VALUE=DATE:20261126\r\nTRANSP:TRANSPARENT\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:c\r\nDTSTART:20261127T150000Z\r\nDURATION:PT30M\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
$events = appt_ics_events($ics);
$from = new DateTimeImmutable('2026-11-20', $zone);
$to = new DateTimeImmutable('2026-12-20', $zone);
$pairs = [];
foreach ($events as $e) {
    foreach (appt_ics_expand($e, $from, $to) as [$a, $b]) {
        $pairs[] = $a->setTimezone(new DateTimeZone('America/Chicago'))->format('Y-m-d H:i');
    }
}
sort($pairs);
$check('iCalendar parser: repeats, exclusions, free time, durations', count($events) === 3 && $pairs === ['2026-11-24 09:00', '2026-11-27 09:00', '2026-11-30 09:00', '2026-12-07 09:00', '2026-12-08 09:00', '2026-12-14 09:00'], implode(', ', $pairs));

$x = appt_caldav_parse_multistatus('<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:response><d:href>/cal/</d:href><d:propstat><d:prop><d:resourcetype><d:collection/><c:calendar/></d:resourcetype><d:displayname>Mine</d:displayname></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>');
$check('CalDAV multistatus parser', ($x['/cal/']['displayname'] ?? '') === 'Mine' && in_array('calendar', $x['/cal/']['resourcetype'] ?? [], true));

$b = ['id' => '20261124-120000-abcdef', 'start' => '2026-11-24T14:00:00-06:00', 'end' => '2026-11-24T14:45:00-06:00', 'service_title' => 'Test', 'duration' => 45, 'name' => 'A, B; C', 'email' => 'a@example.com', 'status' => 'booked'];
$out = appt_booking_ics($b);
$check('booking .ics has UTC times and escaped text', str_contains($out, 'DTSTART:20261124T200000Z') && str_contains($out, 'SUMMARY:Test — A\, B\; C'));
$check('booking tokens', appt_token_ok('20261124-120000-abcdef', appt_token('20261124-120000-abcdef')) && !appt_token_ok('20261124-120000-abcdef', 'nope'));

/* ---------- 5. Fleet alerts decide correctly ---------- */
$t0 = 1_800_000_000;
$fine = fn(string $n, array $extra = []) => ['name' => $n, 'url' => "https://$n", 'ok' => true, 'error' => '', 'status' => $extra + ['ssl_days' => 60, 'last_backup' => date('c', $t0 - 3600), 'unsent_messages' => 0, 'timestamp' => date('c', $t0)]];
$gone = fn(string $n) => ['name' => $n, 'url' => "https://$n", 'ok' => false, 'error' => 'No answer', 'status' => null];
$st = [];
[$a1, $st] = fleet_alert_decisions([$fine('a.test'), $fine('b.test')], $st, $t0);
[$a2, $st] = fleet_alert_decisions([$gone('a.test'), $fine('b.test')], $st, $t0 + 600);
[$a3, $st] = fleet_alert_decisions([$gone('a.test'), $fine('b.test', ['ssl_days' => 5])], $st, $t0 + 1200);
[$a4, $st] = fleet_alert_decisions([$gone('a.test'), $fine('b.test', ['ssl_days' => 5])], $st, $t0 + 1800);
[$a5, $st] = fleet_alert_decisions([$fine('a.test'), $fine('b.test', ['ssl_days' => 5])], $st, $t0 + 2400);
[$a6, $st] = fleet_alert_decisions([$fine('a.test'), $fine('b.test', ['ssl_days' => 5])], $st, $t0 + 90000);
$kinds = fn(array $a) => implode(',', array_column($a, 'kind'));
$check('fleet alerts: down after two misses, no repeats within a day, back, and again a day later',
    $kinds($a1) === '' && $kinds($a2) === '' && $kinds($a3) === 'down,ssl' && $kinds($a4) === '' && $kinds($a5) === 'back' && $kinds($a6) === 'ssl',
    implode(' / ', array_map($kinds, [$a1, $a2, $a3, $a4, $a5, $a6])));

/* ---------- 6. A running site, when asked ---------- */
if (!empty($opts['site'])) {
    $base = rtrim((string) $opts['site'], '/');
    $jar = tempnam(sys_get_temp_dir(), 'selftest-cookies');
    $fetch = function (string $path, array $post = []) use ($base, $jar): array {
        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false]);
        if ($post) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $redirect = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        return [$code, $body, $redirect];
    };
    $clean = fn(string $body) => !preg_match('/Something went wrong|<b>Warning<\/b>|<b>Fatal error<\/b>|<b>Notice<\/b>|<b>Deprecated<\/b>|PHP (Warning|Fatal|Notice|Deprecated)/', $body);
    foreach (['/' => 200, '/admin/' => 200, '/sitemap.xml' => 200, '/robots.txt' => 200, '/no-such-page/' => 404, '/admin/?a=login' => 200] as $path => $want) {
        [$code, $body] = $fetch($path);
        $check("GET $path", $code === $want && $clean($body), "status $code");
    }
    [, $home] = $fetch('/');
    $links = [];
    if (preg_match_all('/href="(\/[^"#?]*)"/', $home, $m)) {
        $links = array_unique(array_filter($m[1], fn($l) => !str_starts_with($l, '/admin') && !preg_match('/\.(css|js|png|jpg|svg|ico|xml|ics|docx|brf|pdf)$/', $l)));
    }
    $problems = [];
    foreach (array_slice($links, 0, 40) as $l) {
        [$code, $body] = $fetch($l);
        if (!in_array($code, [200, 301, 302], true) || !$clean($body)) {
            $problems[] = "$l ($code)";
        }
    }
    $check('every link on the home page opens (' . min(count($links), 40) . ' checked)', !$problems, implode(', ', $problems));

    if (!empty($opts['email']) && !empty($opts['password'])) {
        [, $login] = $fetch('/admin/?a=login');
        preg_match('/name="csrf" value="([^"]+)"/', $login, $m);
        [$code, , $redirect] = $fetch('/admin/?a=login', ['csrf' => $m[1] ?? '', 'email' => $opts['email'], 'password' => $opts['password']]);
        $check('control panel sign-in', $code === 303 || $code === 302, "status $code" . ($redirect !== '' ? " to $redirect" : ''));
        [, $dash] = $fetch('/admin/');
        $screens = [];
        if (preg_match_all('/href="[^"]*\?a=([a-z_]+)"/', $dash, $m)) {
            $screens = array_values(array_unique($m[1]));
        }
        $screens = array_diff($screens, ['logout']);
        $bad = [];
        foreach ($screens as $a) {
            [$code, $body] = $fetch('/admin/?a=' . $a);
            if (!in_array($code, [200, 302, 303], true) || !$clean($body)) {
                $bad[] = "$a ($code)";
            }
        }
        $check('every control panel screen opens (' . count($screens) . ' checked)', $screens && !$bad, implode(', ', $bad));
    } else {
        echo "skip control panel screens (no --email and --password given)\n";
    }
    @unlink($jar);
}

echo $failed === 0 ? "\nAll checks passed.\n" : "\n$failed check(s) failed.\n";
exit($failed === 0 ? 0 : 1);
