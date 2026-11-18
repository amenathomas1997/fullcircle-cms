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

/* ---------- 4b. The three calendar kinds, against a stand-in for each service ----------
   appt_http() hands every request to $GLOBALS['appt_http_mock'] while it is set, so the code that builds
   requests, follows paging, reads answers and reports problems runs exactly as it does live, with no network. */
$log = [];
$queue = [];
$GLOBALS['appt_http_mock'] = function (string $method, string $url, array $headers, string $body, ?string $auth) use (&$log, &$queue): array {
    $log[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'auth' => $auth];
    foreach ($queue as $i => [$match, $answer]) {
        if (preg_match($match, $method . ' ' . $url)) {
            unset($queue[$i]);
            return $answer;
        }
    }
    return [599, 'no stand-in answer for ' . $method . ' ' . $url, []];
};
$json = fn(int $status, array $data, array $headers = []) => [$status, (string) json_encode($data), $headers];
$try = function (callable $f, string &$why) {
    try {
        return $f();
    } catch (Throwable $e) {
        $why = $e->getMessage();
        return null;
    }
};
$win = new DateTimeImmutable('2026-12-01 00:00', $zone);
$booking = ['id' => '20261203-100000-abc123', 'uid' => '20261203-100000-abc123@example.com', 'start' => '2026-12-03T10:00:00-06:00', 'end' => '2026-12-03T10:45:00-06:00',
    'service_title' => 'Consultation', 'duration' => 45, 'name' => 'Pat Example', 'email' => 'pat@example.com', 'phone' => '', 'notes' => 'first visit', 'location' => 'Room 2', 'status' => 'booked', 'language' => 'en'];

// Microsoft: a refresh-token renewal, a two-page calendarView, an event made with the visitor invited, then removed.
$ms = ['type' => 'microsoft', 'tenant' => 'consumers', 'refresh_token' => 'rt-1', 'ms_calendar_id' => 'CAL1'];
$queue = [
    ['#^POST https://login\.microsoftonline\.com/consumers/oauth2/v2\.0/token$#', $json(200, ['access_token' => 'at-1', 'refresh_token' => 'rt-1', 'expires_in' => 3599])],
    ['#^GET https://graph\.microsoft\.com/v1\.0/me/calendars/CAL1/calendarView\?#', $json(200, ['value' => [
        ['start' => ['dateTime' => '2026-12-02T15:00:00.0000000', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2026-12-02T16:00:00.0000000', 'timeZone' => 'UTC'], 'showAs' => 'busy'],
        ['start' => ['dateTime' => '2026-12-02T17:00:00.0000000', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2026-12-02T18:00:00.0000000', 'timeZone' => 'UTC'], 'showAs' => 'free'],
        ['start' => ['dateTime' => '2026-12-02T19:00:00.0000000', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2026-12-02T20:00:00.0000000', 'timeZone' => 'UTC'], 'showAs' => 'busy', 'isCancelled' => true],
    ], '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/calendars/CAL1/calendarView?page2'])],
    ['#^GET https://graph\.microsoft\.com/v1\.0/me/calendars/CAL1/calendarView\?page2$#', $json(200, ['value' => [
        ['start' => ['dateTime' => '2026-12-04T09:00:00.0000000', 'timeZone' => 'Central Standard Time'], 'end' => ['dateTime' => '2026-12-04T09:30:00.0000000', 'timeZone' => 'Central Standard Time'], 'showAs' => 'tentative'],
    ]])],
    ['#^POST https://graph\.microsoft\.com/v1\.0/me/calendars/CAL1/events$#', $json(201, ['id' => 'EV1', 'webLink' => 'https://outlook.live.com/calendar/item/EV1'])],
    ['#^DELETE https://graph\.microsoft\.com/v1\.0/me/events/EV1$#', [204, '', []]],
];
$log = [];
$why = '';
$busy = $try(fn() => appt_ms_busy($ms, $win, $win->modify('+7 days')), $why);
$got = $busy === null ? [] : array_map(fn($p) => $p[0]->setTimezone(new DateTimeZone('UTC'))->format('m-d H:i') . '-' . $p[1]->setTimezone(new DateTimeZone('UTC'))->format('H:i'), $busy);
$tokenReq = $log[0] ?? [];
parse_str((string) ($tokenReq['body'] ?? ''), $tokenFields);
$check('Microsoft: renews the access token with the refresh token', ($tokenFields['grant_type'] ?? '') === 'refresh_token' && ($tokenFields['refresh_token'] ?? '') === 'rt-1' && isset($tokenFields['client_id'], $tokenFields['client_secret'], $tokenFields['scope']), $why);
$check('Microsoft: calendarView asks for the window in UTC and sends the bearer token', str_contains((string) ($log[1]['url'] ?? ''), 'startDateTime=2026-12-01T06%3A00%3A00Z') && in_array('Authorization: Bearer at-1', $log[1]['headers'] ?? [], true), (string) ($log[1]['url'] ?? ''));
$check('Microsoft: busy times follow paging, skip free and cancelled, read Windows zone names', $got === ['12-02 15:00-16:00', '12-04 15:00-15:30'], $why ?: implode(', ', $got));
$queue = array_values($queue);
$made = $try(fn() => appt_ms_create($ms, $booking), $why);
$ev = json_decode((string) ($log[3]['body'] ?? ''), true) ?: [];
$check('Microsoft: the event is made in the chosen calendar with the visitor invited', ($made['event_id'] ?? '') === 'EV1' && ($ev['start']['dateTime'] ?? '') === '2026-12-03T16:00:00' && ($ev['transactionId'] ?? '') === $booking['id']
    && ($ev['attendees'][0]['emailAddress']['address'] ?? '') === 'pat@example.com' && ($ev['location']['displayName'] ?? '') === 'Room 2' && ($ev['showAs'] ?? '') === 'busy', $why ?: json_encode($ev));
$queue = array_values($queue);
$try(fn() => appt_ms_delete($ms, $made ?? []), $why);
$check('Microsoft: cancelling removes the event', ($log[4]['method'] ?? '') === 'DELETE' && $why === '', $why);
$queue = [
    ['#calendarView#', $json(401, ['error' => ['code' => 'InvalidAuthenticationToken', 'message' => 'Access token has expired.']])],
    ['#^POST https://login\.microsoftonline\.com/consumers/oauth2/v2\.0/token$#', $json(200, ['access_token' => 'at-2', 'refresh_token' => 'rt-1', 'expires_in' => 3599])],
    ['#calendarView#', $json(200, ['value' => []])],
];
$log = [];
$why = '';
$busy = $try(fn() => appt_ms_busy($ms, $win, $win->modify('+1 day')), $why);
$check('Microsoft: a revoked access token is renewed once and the call repeated', $busy === [] && count($log) === 3 && in_array('Authorization: Bearer at-2', $log[2]['headers'] ?? [], true), $why ?: count($log) . ' requests');
unset($GLOBALS['appt_mock_tokens']);
$queue = [['#token#', $json(400, ['error' => 'invalid_grant', 'error_description' => 'AADSTS70000: The refresh token has expired.'])]];
$why = '';
$try(fn() => appt_ms_busy($ms, $win, $win->modify('+1 day')), $why);
$check('Microsoft: an expired connection is reported in plain words', str_contains($why, 'would not renew') && str_contains($why, 'Connect the calendar again'), $why);

unset($GLOBALS['appt_mock_tokens']);
// Google: a signed JWT for the service account, freeBusy, an event made and removed, and a shared-calendar mistake explained.
$pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($pair, $pem);
$public = openssl_pkey_get_details($pair)['key'];
$goog = ['type' => 'google', 'service_account' => ['client_email' => 'robot@example.iam.gserviceaccount.com', 'private_key' => $pem], 'google_calendar_id' => 'owner@gmail.com'];
$queue = [
    ['#^POST https://oauth2\.googleapis\.com/token$#', $json(200, ['access_token' => 'g-at', 'expires_in' => 3599])],
    ['#^POST https://www\.googleapis\.com/calendar/v3/freeBusy$#', $json(200, ['calendars' => ['owner@gmail.com' => ['busy' => [['start' => '2026-12-02T15:00:00Z', 'end' => '2026-12-02T16:00:00Z']]]]])],
    ['#^POST https://www\.googleapis\.com/calendar/v3/calendars/owner%40gmail\.com/events$#', $json(200, ['id' => 'gev1', 'htmlLink' => 'https://calendar.google.com/event?eid=gev1'])],
    ['#^DELETE https://www\.googleapis\.com/calendar/v3/calendars/owner%40gmail\.com/events/gev1$#', [204, '', []]],
    ['#^POST https://www\.googleapis\.com/calendar/v3/freeBusy$#', $json(200, ['calendars' => ['owner@gmail.com' => ['errors' => [['domain' => 'global', 'reason' => 'notFound']]]]])],
];
$log = [];
$why = '';
$busy = $try(fn() => appt_google_busy($goog, $win, $win->modify('+7 days')), $why);
parse_str((string) ($log[0]['body'] ?? ''), $jwtFields);
$jwtOk = false;
if (isset($jwtFields['assertion'])) {
    [$h, $p, $sig] = array_pad(explode('.', (string) $jwtFields['assertion']), 3, '');
    $claims = json_decode((string) base64_decode(strtr($p, '-_', '+/')), true) ?: [];
    $jwtOk = ($jwtFields['grant_type'] ?? '') === 'urn:ietf:params:oauth:grant-type:jwt-bearer' && ($claims['iss'] ?? '') === 'robot@example.iam.gserviceaccount.com'
        && ($claims['scope'] ?? '') === 'https://www.googleapis.com/auth/calendar'
        && openssl_verify("$h.$p", (string) base64_decode(strtr($sig, '-_', '+/')), $public, OPENSSL_ALGO_SHA256) === 1;
}
$check('Google: the service account signs in with a JWT the public key verifies', $jwtOk, $why);
$fb = json_decode((string) ($log[1]['body'] ?? ''), true) ?: [];
$check('Google: freeBusy covers the window and the busy times come back', ($fb['items'][0]['id'] ?? '') === 'owner@gmail.com' && str_starts_with((string) ($fb['timeMin'] ?? ''), '2026-12-01T00:00:00') && $busy && $busy[0][0]->format('U') === (new DateTimeImmutable('2026-12-02T15:00:00Z'))->format('U'), $why ?: json_encode($fb));
$queue = array_values($queue);
$made = $try(fn() => appt_google_create($goog, $booking), $why);
$ev = json_decode((string) ($log[2]['body'] ?? ''), true) ?: [];
$check('Google: the event is made with the booking times and no guests', ($made['event_id'] ?? '') === 'gev1' && ($ev['start']['dateTime'] ?? '') === '2026-12-03T10:00:00-06:00' && !isset($ev['attendees']) && ($ev['location'] ?? '') === 'Room 2', $why ?: json_encode($ev));
$queue = array_values($queue);
$why = '';
$try(fn() => appt_google_delete($goog, $made ?? []), $why);
$check('Google: cancelling removes the event', ($log[3]['method'] ?? '') === 'DELETE' && $why === '', $why);
$queue = array_values($queue);
$why = '';
$try(fn() => appt_google_busy($goog, $win, $win->modify('+1 day')), $why);
$check('Google: a calendar not shared with the robot is explained', str_contains($why, 'notFound') && str_contains($why, 'Share the calendar'), $why);

// CalDAV (cPanel's server): discovery through the principal and calendar home, a busy-time REPORT, PUT and DELETE with the saved password.
$dav = ['type' => 'caldav', 'url' => 'https://example.com:2080', 'user' => 'owner@example.com', 'password' => 'secret', 'calendar_url' => 'https://example.com:2080/calendars/owner@example.com/calendar/'];
$ms2 = fn(string $inner) => [207, '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">' . $inner . '</d:multistatus>', []];
$resp = fn(string $href, string $props) => '<d:response><d:href>' . $href . '</d:href><d:propstat><d:prop>' . $props . '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';
$queue = [
    ['#^PROPFIND https://example\.com:2080/$#', $ms2($resp('/', '<d:current-user-principal><d:href>/principals/owner@example.com/</d:href></d:current-user-principal><d:resourcetype><d:collection/></d:resourcetype>'))],
    ['#^PROPFIND https://example\.com:2080/principals/owner@example\.com/$#', $ms2($resp('/principals/owner@example.com/', '<c:calendar-home-set><d:href>/calendars/owner@example.com/</d:href></c:calendar-home-set><d:resourcetype><d:principal/></d:resourcetype>'))],
    ['#^PROPFIND https://example\.com:2080/calendars/owner@example\.com/$#', $ms2(
        $resp('/calendars/owner@example.com/', '<d:resourcetype><d:collection/></d:resourcetype>')
        . $resp('/calendars/owner@example.com/calendar/', '<d:resourcetype><d:collection/><c:calendar/></d:resourcetype><d:displayname>Work</d:displayname><c:supported-calendar-component-set><c:comp name="VEVENT"/></c:supported-calendar-component-set>')
        . $resp('/calendars/owner@example.com/tasks/', '<d:resourcetype><d:collection/><c:calendar/></d:resourcetype><d:displayname>Tasks</d:displayname><c:supported-calendar-component-set><c:comp name="VTODO"/></c:supported-calendar-component-set>')
        . $resp('/calendars/owner@example.com/inbox/', '<d:resourcetype><d:collection/><c:schedule-inbox/></d:resourcetype>'))],
    ['#^REPORT https://example\.com:2080/calendars/owner@example\.com/calendar/$#', $ms2($resp('/calendars/owner@example.com/calendar/x.ics', '<d:getetag>"1"</d:getetag><c:calendar-data>BEGIN:VCALENDAR
BEGIN:VEVENT
UID:x
DTSTART:20261202T150000Z
DTEND:20261202T160000Z
END:VEVENT
END:VCALENDAR</c:calendar-data>'))],
    ['#^PUT https://example\.com:2080/calendars/owner@example\.com/calendar/20261203-100000-abc123\.ics$#', [201, '', ['etag' => '"abc"']]],
    ['#^DELETE https://example\.com:2080/calendars/owner@example\.com/calendar/20261203-100000-abc123\.ics$#', [204, '', []]],
    ['#^PROPFIND #', [401, '', []]],
];
$log = [];
$why = '';
$found = $try(fn() => appt_caldav_discover($dav), $why);
$check('CalDAV: finds the event calendar through the principal and home, leaving tasks and the inbox out', $found === [['https://example.com:2080/calendars/owner@example.com/calendar/', 'Work']] && ($log[0]['auth'] ?? '') === 'owner@example.com:secret' && in_array('Depth: 0', $log[0]['headers'] ?? [], true), $why ?: json_encode($found));
$queue = array_values($queue);
$busy = $try(fn() => appt_caldav_busy($dav, $win, $win->modify('+7 days')), $why);
$report = $log[count($log) - 1] ?? [];
$check('CalDAV: the busy-time REPORT covers the window and its events are read', str_contains((string) ($report['body'] ?? ''), 'time-range start="20261130T060000Z"') && $busy && $busy[0][0]->format('U') === (new DateTimeImmutable('2026-12-02T15:00:00Z'))->format('U'), $why ?: (string) ($report['body'] ?? ''));
$queue = array_values($queue);
$made = $try(fn() => appt_caldav_create($dav, $booking), $why);
$put = $log[count($log) - 1] ?? [];
$check('CalDAV: the booking is PUT as an .ics with If-None-Match and the etag kept', ($made['etag'] ?? '') === '"abc"' && in_array('If-None-Match: *', $put['headers'] ?? [], true) && str_contains((string) ($put['body'] ?? ''), 'DTSTART:20261203T160000Z') && str_contains((string) ($put['body'] ?? ''), 'UID:20261203-100000-abc123@example.com'), $why ?: json_encode($made));
$queue = array_values($queue);
$why = '';
$try(fn() => appt_caldav_delete($dav, $made ?? []), $why);
$check('CalDAV: cancelling removes the .ics', ($log[count($log) - 1]['method'] ?? '') === 'DELETE' && $why === '', $why);
$queue = array_values($queue);
$why = '';
$try(fn() => appt_caldav_discover($dav), $why);
$check('CalDAV: a wrong password is reported as such', str_contains($why, 'user name and password'), $why);
unset($GLOBALS['appt_http_mock']);
unset($GLOBALS['appt_mock_tokens']);

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
