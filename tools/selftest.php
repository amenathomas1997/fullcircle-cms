<?php
/**
 * Checks the kit can run, from the command line or in CI:
 *   php tools/selftest.php                       syntax of every PHP file, the bootstrap, the registry, the
 *                                                appointment engine and calendars (against stand-ins), page history,
 *                                                sign-in codes, the monthly report and the alert logic, all without a web server
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
$try = function (callable $f, string &$why) {
    try {
        return $f();
    } catch (Throwable $e) {
        $why = $e->getMessage();
        return null;
    }
};
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

// Minutes kept free before and after: a service's own numbers win over the site's.
$swapSettings = function (array $appt, callable $f) use ($settingsBefore) {
    $file = CONTENT_DIR . '/settings.json';
    $orig = is_file($file) ? (string) file_get_contents($file) : null;
    try {
        write_json($file, ['appointments' => $appt] + $settingsBefore);
        settings(true);
        return $f();
    } finally {
        $orig === null ? @unlink($file) : file_put_contents($file, $orig);
        settings(true);
    }
};
$why = '';
$open = $try(fn() => $swapSettings(['hours' => ['tue' => [['09:00', '17:00']]], 'step' => 30, 'buffer_before' => 0, 'buffer_after' => 0, 'notice_hours' => 0, 'window_days' => 60],
    fn() => array_map(fn($d) => $d->format('H:i'), appt_openings($svc + ['buffer_before' => 15, 'buffer_after' => 15], $day, $busy))), $why);
$check('openings keep the service’s own minutes free before and after a busy time', $open === ['09:00', '11:30', '12:00', '12:30', '13:00', '13:30', '14:00', '14:30', '15:00', '15:30', '16:00'], $why ?: implode(' ', (array) $open));
$open = $try(fn() => $swapSettings(['hours' => ['tue' => [['09:00', '17:00']]], 'step' => 30, 'buffer' => 30, 'notice_hours' => 0, 'window_days' => 60],
    fn() => array_map(fn($d) => $d->format('H:i'), appt_openings($svc, $day, $busy))), $why);
$check('an older site’s single gap setting still applies on both sides', $open === ['11:30', '12:00', '12:30', '13:00', '13:30', '14:00', '14:30', '15:00', '15:30', '16:00'], $why ?: implode(' ', (array) $open));

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

// Changing the time of a booking, end to end against the Microsoft stand-in: the busy check leaves the old booking's own event out,
// the new event is made before the old one is deleted, both records are marked, and the visitor and owner each get one "moved" email.
$moveDay = (new DateTimeImmutable('next tuesday', $zone))->setTime(0, 0);
$oldId = $moveDay->format('Ymd') . '-000000-5e1f7e';
$old = ['id' => $oldId, 'uid' => $oldId . '@example.com', 'created' => date('c'), 'status' => 'booked', 'service' => 'selftest-service', 'service_title' => 'Consultation', 'duration' => 45, 'price' => '', 'location' => '',
    'start' => $moveDay->setTime(10, 0)->format('c'), 'end' => $moveDay->setTime(10, 45)->format('c'), 'name' => 'Pat Example', 'email' => 'pat@example.com', 'phone' => '515-555-0100', 'notes' => '', 'sms_opt_in' => true,
    'language' => 'en', 'calendar' => ['type' => 'microsoft', 'event_id' => 'OLD1'], 'emails' => []];
$utcOf = fn(DateTimeImmutable $d) => $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s') . '.0000000';
$queue = [
    ['#^POST https://login\.microsoftonline\.com/consumers/oauth2/v2\.0/token$#', $json(200, ['access_token' => 'at-3', 'expires_in' => 3599])],
    ['#calendarView#', $json(200, ['value' => [['start' => ['dateTime' => $utcOf($moveDay->setTime(10, 0)), 'timeZone' => 'UTC'], 'end' => ['dateTime' => $utcOf($moveDay->setTime(10, 45)), 'timeZone' => 'UTC'], 'showAs' => 'busy']]])],
    ['#^POST https://graph\.microsoft\.com/v1\.0/me/calendars/CAL1/events$#', $json(201, ['id' => 'NEW1', 'webLink' => 'https://outlook.live.com/calendar/item/NEW1'])],
    ['#^DELETE https://graph\.microsoft\.com/v1\.0/me/events/OLD1$#', [204, '', []]],
];
$log = [];
$mails = [];
$GLOBALS['mail_mock'] = function (array $to, string $subject, string $html) use (&$mails): void {
    $mails[] = ['to' => $to, 'subject' => $subject, 'html' => $html];
};
$GLOBALS['appt_calendar_mock'] = $ms;
$why = '';
$new = null;
$oldAfter = null;
try {
    if (!is_dir(APPT_DIR)) {
        mkdir(APPT_DIR, 0700, true);
    }
    appt_save_booking($old);
    $new = $try(fn() => $swapSettings(['hours' => ['tue' => [['09:00', '17:00']]], 'step' => 30, 'buffer' => 0, 'notice_hours' => 0, 'window_days' => 60, 'invite' => false],
        fn() => appt_move($old, $moveDay->setTime(10, 30))), $why);
    $oldAfter = appt_booking($oldId);
} finally {
    @unlink(APPT_DIR . '/' . $oldId . '.json');
    if ($new) {
        @unlink(APPT_DIR . '/' . $new['id'] . '.json');
    }
    @unlink(APPT_BUSY_CACHE);
    unset($GLOBALS['appt_calendar_mock'], $GLOBALS['mail_mock']);
}
$methods = implode(' ', array_map(fn($r) => $r['method'] . ' ' . basename((string) parse_url($r['url'], PHP_URL_PATH)), $log));
$check('reschedule: the old booking’s own event does not block the new time that overlaps it', $new !== null && ($new['start'] ?? '') === $moveDay->setTime(10, 30)->format('c'), $why ?: $methods);
$check('reschedule: the new event is made before the old one is deleted', count($log) === 4 && $log[2]['method'] === 'POST' && $log[3]['method'] === 'DELETE' && str_ends_with($log[3]['url'], '/OLD1'), $methods);
$check('reschedule: both records are marked and point at each other', ($new['moved_from']['id'] ?? '') === $oldId && ($new['calendar']['event_id'] ?? '') === 'NEW1'
    && ($oldAfter['status'] ?? '') === 'cancelled' && ($oldAfter['cancelled_by'] ?? '') === 'moved' && ($oldAfter['moved_to'] ?? '') === ($new['id'] ?? '-'), json_encode([$new['moved_from'] ?? null, $oldAfter['status'] ?? null, $oldAfter['moved_to'] ?? null]));
$check('reschedule: one "moved" email each to the visitor and the owner, with the old time named', count($mails) === 2 && ($mails[0]['to'][0] ?? '') === 'pat@example.com' && str_contains($mails[0]['subject'], 'moved')
    && str_contains($mails[0]['html'], 'It was on') && str_contains($mails[0]['html'], '/book/move/') && ($mails[1]['to'][0] ?? '') === 'owner' && str_contains($mails[1]['subject'], 'Moved appointment'),
    implode(' | ', array_map(fn($m) => implode(',', $m['to']) . ': ' . $m['subject'], $mails)));

// Text reminders: the day before, in the daytime, through the texting stand-in.
$texts = [];
$GLOBALS['sms_mock'] = function (string $to, string $body) use (&$texts): array {
    $texts[] = [$to, $body];
    return ['ok' => true, 'id' => 'SM1', 'status' => 'queued', 'error' => null];
};
$dayBefore = $moveDay->modify('-1 day');
$check('text reminder: due the day before between 9 am and 8 pm, not at night or two days out',
    appt_sms_reminder_due($old, $dayBefore->setTime(9, 5)) && !appt_sms_reminder_due($old, $dayBefore->setTime(7, 0)) && !appt_sms_reminder_due($old, $dayBefore->modify('-1 day')->setTime(12, 0)) && !appt_sms_reminder_due($old, $moveDay->setTime(9, 0)));
$sent = appt_sms_send($old, appt_sms_reminder_text($old));
$check('text reminder: first name, service, site and time, sent to the number in +1 form', !empty($sent['ok']) && ($texts[0][0] ?? '') === '+15155550100' && str_starts_with($texts[0][1] ?? '', 'Hi Pat, a reminder of your Consultation with ') && str_contains($texts[0][1] ?? '', 'Reply STOP'), json_encode($texts));
$check('phone numbers: US forms and international ones are read, short ones refused', sms_number('(515) 555-0100') === '+15155550100' && sms_number('1 515 555 0100') === '+15155550100' && sms_number('+44 20 7946 0958') === '+442079460958' && sms_number('12345') === null);
unset($GLOBALS['sms_mock']);

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
$settingsBefore = settings();
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

/* ---------- 6. Page history: save, list, put back, undo ---------- */
// The edit-screen section builds links and forms with two control panel helpers; stand-ins are enough here.
if (!function_exists('admin_url')) {
    function admin_url(string $action = '', array $params = []): string
    {
        return '/admin/?' . http_build_query(['a' => $action] + $params);
    }
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf" value="test">';
    }
}
$slug = 'selftest-history-' . bin2hex(random_bytes(3));
$GLOBALS['current_editor'] = 'Self-test';
$why = '';
$hist = $try(function () use ($slug): array {
    try {
        $base = ['title' => 'History one', 'slug' => $slug, 'status' => 'draft', 'hide_title' => false, 'description' => '', 'body' => '<p>one</p>'];
        save_item('pages', $slug, $base);
        save_item('pages', $slug, ['title' => 'History two', 'body' => '<p>two</p>'] + $base);
        save_item('pages', $slug, ['title' => 'History three', 'body' => '<p>three</p>'] + $base);
        $before = history_versions('pages', $slug);
        $restored = history_restore('pages', $slug, $before[1]['id']);  // put "one" back
        $after = history_versions('pages', $slug);
        $same = history_versions('pages', $slug)[0]['same'] ?? null;
        return ['before' => $before, 'restored' => $restored, 'after' => $after, 'section' => history_section('pages', $slug)];
    } finally {
        delete_item('pages', $slug);
        foreach (glob(revision_dir("pages/$slug") . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir(revision_dir("pages/$slug"));
    }
}, $why);
$check('page history: the two earlier saves are listed newest first, with who saved them', $hist && count($hist['before']) === 2 && $hist['before'][0]['title'] === 'History two' && $hist['before'][1]['title'] === 'History one' && $hist['before'][0]['saved_by'] === 'Self-test', $why);
$check('page history: putting a version back restores its title and content and keeps the status', $hist && $hist['restored']['title'] === 'History one' && $hist['restored']['body'] === '<p>one</p>' && $hist['restored']['status'] === 'draft', $why);
$check('page history: the replaced version joins the list, so the restore can be undone', $hist && count($hist['after']) === 3 && $hist['after'][0]['title'] === 'History three' && $hist['after'][2]['same'] === true, $why);
$check('page history: the edit-screen section has a "Put this version back" button per version', $hist && substr_count($hist['section'], 'Put this version back') === 2 && str_contains($hist['section'], 'Same as now'), $why);
unset($GLOBALS['current_editor']);

/* ---------- 7. Two-step sign-in by emailed code: the whole lifecycle at fixed times ---------- */
$t0 = 1_800_000_000;
$st = ['codes' => [], 'browsers' => []];
[$st, $code1] = signin_code_issue($st, 'u1', $t0);
[$st, $okWrong, $whyWrong] = signin_code_check($st, 'u1', '000000', $t0 + 10);
[$st, $okOther] = signin_code_check($st, 'u2', (string) $code1, $t0 + 10);
[$st, $okRight] = signin_code_check($st, 'u1', ' ' . substr((string) $code1, 0, 3) . ' ' . substr((string) $code1, 3) . ' ', $t0 + 20);
[$st, $okTwice, $whyTwice] = signin_code_check($st, 'u1', (string) $code1, $t0 + 30);
$check('sign-in code: six digits, a wrong one is refused, another account cannot use it, spaces are ignored, and it works once',
    is_string($code1) && preg_match('/^\d{6}$/', $code1) && !$okWrong && str_contains($whyWrong, 'not right') && !$okOther && $okRight && !$okTwice && str_contains($whyTwice, 'no code'), json_encode([$code1 !== null, $okWrong, $okOther, $okRight, $okTwice]));
[$st, $code2] = signin_code_issue($st, 'u1', $t0 + 60);
[$st, $okLate, $whyLate] = signin_code_check($st, 'u1', (string) $code2, $t0 + 60 + SIGNIN_CODE_LIFE + 1);
$check('sign-in code: expires after 10 minutes', !$okLate && str_contains($whyLate, 'expired'), $whyLate);
[$st, $code3] = signin_code_issue($st, 'u1', $t0 + 120);
[$st, $code4, $whyMany] = signin_code_issue($st, 'u1', $t0 + 180);
[$st, $code5] = signin_code_issue($st, 'u1', $t0 + 180 + SIGNIN_CODE_SEND_WINDOW);
$check('sign-in code: at most 3 codes in 15 minutes, then allowed again', $code3 !== null && $code4 === null && str_contains($whyMany, 'Several codes') && $code5 !== null, $whyMany);
for ($i = 0; $i < SIGNIN_CODE_MAX_TRIES; $i++) {
    [$st] = signin_code_check($st, 'u1', '111111', $t0 + 200 + $i);
}
[$st, $okWorn, $whyWorn] = signin_code_check($st, 'u1', (string) $code5, $t0 + 210);
$check('sign-in code: void after 5 wrong tries, even if the right one comes next', !$okWorn && str_contains($whyWorn, 'too many'), $whyWorn);
[$st, $token] = signin_browser_remember($st, 'u1', $t0);
$check('remembered browser: known for its account for 30 days, not for another account, not after, not with a made-up token',
    signin_browser_known($st, $token, 'u1', $t0 + 29 * 86400) && !signin_browser_known($st, $token, 'u2', $t0 + 10) && !signin_browser_known($st, $token, 'u1', $t0 + 31 * 86400) && !signin_browser_known($st, str_repeat('a', 64), 'u1', $t0 + 10));
$tidied = signin_browsers_tidy($st, $t0 + 31 * 86400);
$check('remembered browser: forgotten by the tidy-up once it is past its 30 days, and the code records go with it', $tidied['browsers'] === [] && $tidied['codes'] === []);
$mails = [];
$GLOBALS['mail_mock'] = function (array $to, string $subject, string $html) use (&$mails): void {
    $mails[] = ['to' => $to, 'subject' => $subject, 'html' => $html];
};
$stateFile = SIGNIN_STATE_FILE;
$stateBefore = is_file($stateFile) ? (string) file_get_contents($stateFile) : null;
try {
    $sendWhy = signin_code_send(['id' => 'selftest-user', 'name' => 'Self Test', 'email' => 'self@example.com']);
    $savedState = signin_state();
} finally {
    $stateBefore === null ? @unlink($stateFile) : file_put_contents($stateFile, $stateBefore);
    unset($GLOBALS['mail_mock']);
}
preg_match('/(\d{6})/', (string) ($mails[0]['subject'] ?? ''), $mm);
$check('sign-in code: the email goes to the account’s address with the code, and only its hash is kept', $sendWhy === '' && ($mails[0]['to'][0] ?? '') === 'self@example.com' && isset($mm[1]) && str_contains($mails[0]['html'], $mm[1])
    && ($savedState['codes']['selftest-user']['hash'] ?? '') === signin_code_hash('selftest-user', $mm[1]) && !str_contains(json_encode($savedState), $mm[1]), $sendWhy ?: json_encode($mails[0]['subject'] ?? null));

/* ---------- 8. The monthly report, for a fixed month ---------- */
$fixed = ['month' => '2026-10', 'label' => 'October 2026', 'site' => 'Example Site', 'url' => 'https://example.com/', 'visitors' => 120, 'views' => 540, 'bookings_made' => 4, 'bookings_cancelled' => 1,
    'form_answers' => null, 'messages' => 3, 'messages_unsent' => 1, 'uptime' => [['name' => 'Other Site', 'checks' => 4000, 'fails' => 4, 'percent' => 99.9], ['name' => 'New Site', 'checks' => 0, 'fails' => 0, 'percent' => null]],
    'last_backup' => date('c', mktime(3, 0, 0, 10, 31, 2026)), 'ssl_days' => 9, 'version' => '1.6.0', 'changes' => ['1.6.0' => ['date' => '2026-10-20', 'text' => "### Undo and more\n\n- First bullet."]]];
$html = monthly_report_html($fixed);
$check('monthly report: every figure is in the email, in words a person can read', str_contains($html, '120 visitors and 540 page views') && str_contains($html, '4 appointments made and 1 cancelled')
    && str_contains($html, 'Forms are not turned on') && str_contains($html, '3 contact messages') && str_contains($html, '1 could not be emailed') && str_contains($html, 'Other Site: 99.9% up over 4000 checks, 4 misses')
    && str_contains($html, 'New Site: no checks recorded yet') && str_contains($html, 'Latest backup: October 31, 2026') && str_contains($html, '9 days left') && str_contains($html, 'renew it soon')
    && str_contains($html, '1.6.0 (October 20, 2026): Undo and more'), strip_tags($html));
$none = monthly_report_html(['visitors' => null, 'views' => null, 'bookings_made' => null, 'bookings_cancelled' => null, 'form_answers' => null, 'messages' => 0, 'messages_unsent' => 0, 'uptime' => null, 'last_backup' => null, 'ssl_days' => null, 'version' => '1.6.0', 'changes' => []] + $fixed, true);
$check('monthly report: says "not counted" rather than inventing visitors, and "so far" for the month in progress', str_contains($none, 'Visitors were not counted') && str_contains($none, 'So far in October 2026') && str_contains($none, 'No nightly backup') && !str_contains($none, 'Sites you watch'));
$why = '';
$real = $try(fn() => monthly_report_data('2020-01'), $why);
$check('monthly report: a month with no records builds without error and counts nothing', $real !== null && $real['visitors'] === null && $real['messages'] === 0 && $real['label'] === 'January 2020' && $real['changes'] === [], $why);
$tmpLog = tempnam(sys_get_temp_dir(), 'changelog');
file_put_contents($tmpLog, "# Changelog\n\n## 1.6.0 — 2026-11-18\nText a.\n\n## 1.5.0 — 2026-10-31\nText b.\n\n## 1.4.0 — 2026-10-02\nText c.\n");
$changes = monthly_report_changes('2026-10', $tmpLog);
@unlink($tmpLog);
$check('monthly report: picks the changelog entries dated in the month', array_keys($changes) === ['1.5.0', '1.4.0'] && $changes['1.4.0']['text'] === 'Text c.', json_encode(array_keys($changes)));
$tally = fleet_uptime_tally([], [['name' => 'A', 'url' => 'https://a.test', 'ok' => true], ['name' => 'B', 'url' => 'https://b.test', 'ok' => false]], $t0);
$tally = fleet_uptime_tally($tally, [['name' => 'A', 'url' => 'https://a.test', 'ok' => true], ['name' => 'B', 'url' => 'https://b.test', 'ok' => true]], $t0 + 600);
$m = date('Y-m', $t0);
$check('uptime tally: counts checks and misses per site per month', ($tally[$m]['https://a.test']['checks'] ?? 0) === 2 && ($tally[$m]['https://a.test']['fails'] ?? 1) === 0 && ($tally[$m]['https://b.test']['fails'] ?? 0) === 1, json_encode($tally));

/* ---------- 8b. Custom forms: conditions, attached files, signatures, save and come back ---------- */
$slugT = 'selftest-form';
$formT = ['slug' => $slugT, 'title' => 'Self-test form', 'resume' => true, 'fields' => [
    ['label' => 'Your name', 'type' => 'text', 'required' => true],
    ['label' => 'Email address', 'type' => 'email', 'required' => true],
    ['label' => 'How did you hear about us?', 'type' => 'select', 'required' => true, 'options' => "A friend\nOnline\nOther"],
    ['label' => 'Please say where', 'type' => 'text', 'required' => true, 'show_if' => '2', 'show_if_value' => 'Other'],
    ['label' => 'Send a referral letter', 'type' => 'checkbox'],
    ['label' => 'Referral letter', 'type' => 'file', 'required' => true, 'show_if' => '4', 'show_if_value' => 'yes'],
    ['label' => 'Signature', 'type' => 'signature', 'required' => true],
]];
$fieldsT = array_map('form_field_defaults', $formT['fields']);
$check('forms: a field that depends on another is hidden until that answer is given', !form_field_shown($fieldsT[3], $formT['fields'], [2 => 'Online']) && form_field_shown($fieldsT[3], $formT['fields'], [2 => 'Other']));
$check('forms: a yes/no condition reads yes as ticked and no as not ticked', form_condition_met('checkbox', '1', 'yes') && form_condition_met('checkbox', '', 'no') && !form_condition_met('checkbox', '', 'yes'));
$check('forms: a checkboxes condition matches any ticked box, ignoring case', form_condition_met('checkboxes', ['Mornings', 'Evenings'], 'evenings') && !form_condition_met('checkboxes', ['Mornings'], 'Evenings'));

$sigPng = function_exists('imagecreatetruecolor') ? (function (): string {
    $im = imagecreatetruecolor(300, 100);
    $white = imagecolorallocate($im, 255, 255, 255);
    imagefill($im, 0, 0, $white);
    imageline($im, 10, 80, 290, 20, imagecolorallocate($im, 0, 0, 0));
    ob_start();
    imagepng($im);
    return (string) ob_get_clean();
})() : '';
$posted = [0 => 'Pat Example', 1 => 'pat@example.com', 2 => 'Online', 3 => 'should be ignored', 4 => '', 6 => ['name' => 'Pat Example', 'agree' => '1', 'drawn' => $sigPng !== '' ? 'data:image/png;base64,' . base64_encode($sigPng) : '']];
$got = form_collect_answers($formT, $posted, []);
$labels = array_column($got['answers'], 'label');
$check('forms: hidden fields are neither checked nor recorded (the "where" and the letter are skipped)', !$got['errors'] && $labels === ['Your name', 'Email address', 'How did you hear about us?', 'Send a referral letter', 'Signature'], json_encode($got['errors']) . ' ' . json_encode($labels));
$sigAnswer = end($got['answers']);
$check('forms: a signature records the typed name, the time, and the drawing when one is sent', is_array($sigAnswer['value']) && $sigAnswer['value']['name'] === 'Pat Example' && $sigAnswer['value']['signed'] !== '' && ($sigPng === '' || ($got['signatures'][6] ?? null) === $sigPng));

$got = form_collect_answers($formT, [0 => 'Pat', 1 => 'pat@example.com', 2 => 'Other', 3 => '', 4 => '1', 6 => ['name' => 'Pat', 'agree' => '']], []);
$check('forms: once shown, a dependent field is required, a required file is asked for, and an unticked signature box is refused',
    isset($got['errors'][3]) && isset($got['errors'][5]) && isset($got['errors'][6]) && str_contains($got['errors'][6], 'Tick the box'), json_encode($got['errors']));

$tmpFile = tempnam(sys_get_temp_dir(), 'fcf');
file_put_contents($tmpFile, "Dear team,\nPlease see the referral.\n");
$fileEntry = ['name' => 'Referral: letter?.txt', 'type' => 'text/plain', 'tmp_name' => $tmpFile, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmpFile)];
$got = form_collect_answers($formT, [0 => 'Pat', 1 => 'pat@example.com', 2 => 'Online', 4 => '1', 6 => ['name' => 'Pat', 'agree' => '1', 'drawn' => '']], [5 => $fileEntry]);
$check('forms: a plain text file is accepted and its name cleaned', !$got['errors'] && ($got['uploads'][5]['name'] ?? '') === 'Referral letter .txt', json_encode($got['errors']) . ' ' . json_encode($got['uploads'][5] ?? null));
$exe = tempnam(sys_get_temp_dir(), 'fcx');
file_put_contents($exe, "MZ\x90\x00binary");
$got = form_collect_answers($formT, [0 => 'Pat', 1 => 'pat@example.com', 2 => 'Online', 4 => '1', 6 => ['name' => 'Pat', 'agree' => '1', 'drawn' => '']], [5 => ['name' => 'virus.pdf', 'type' => 'application/pdf', 'tmp_name' => $exe, 'error' => UPLOAD_ERR_OK, 'size' => 20]]);
$check('forms: a file whose contents do not match its name is refused', isset($got['errors'][5]) && str_contains($got['errors'][5], 'kind the form does not take'), json_encode($got['errors']));
$got = form_collect_answers($formT, [0 => 'Pat', 1 => 'pat@example.com', 2 => 'Online', 4 => '1', 6 => ['name' => 'Pat', 'agree' => '1', 'drawn' => '']], [5 => ['error' => UPLOAD_ERR_INI_SIZE] + $fileEntry]);
$check('forms: a file the server found too big is reported with the limit', isset($got['errors'][5]) && str_contains($got['errors'][5], 'too big') && preg_match('/\d+ (MB|KB)/', $got['errors'][5]), json_encode($got['errors']));

// Attach and read back through the stored copy, then remove everything the test made.
$idT = date('Y-m-d_His') . '-abc123';
$got = form_collect_answers($formT, [0 => 'Pat', 1 => 'pat@example.com', 2 => 'Online', 4 => '1', 6 => ['name' => 'Pat', 'agree' => '1', 'drawn' => $sigPng !== '' ? 'data:image/png;base64,' . base64_encode($sigPng) : '']], [5 => $fileEntry]);
$answersT = form_attach_files($formT, $idT, $got);
write_json(form_submission_dir($slugT) . "/$idT.json", ['received' => date('c'), 'answers' => $answersT]);
$fileAnswer = $answersT[4] ?? [];
$stored = form_stored_file($slugT, $idT, 5);
$check('forms: an attached file is kept under data/, never in uploads/, and comes back with its original name',
    ($fileAnswer['value']['file'] ?? '') === "$idT-5.txt" && $stored !== null && str_starts_with($stored[0], DATA_DIR . '/forms/') && $stored[1] === 'Referral letter .txt' && $stored[2] === 'text/plain', json_encode($fileAnswer) . ' ' . json_encode($stored));
$check('forms: the file answer reads well in an email or CSV', form_answer_text($fileAnswer) === 'Referral letter .txt (1 KB)', form_answer_text($fileAnswer));
if ($sigPng !== '') {
    $sigStored = form_stored_file($slugT, $idT, 6);
    $check('forms: the drawn signature is kept as a PNG beside the submission', $sigStored !== null && $sigStored[2] === 'image/png' && str_starts_with((string) file_get_contents($sigStored[0]), "\x89PNG"));
}
$check('forms: a drawn signature that is not a PNG is refused', form_signature_png('data:image/png;base64,' . base64_encode('<svg onload=alert(1)>')) === null && form_signature_png('data:text/html;base64,PGI+') === null);

$tokenT = form_draft_save($slugT, '/apply/', [0 => 'Pat', 6 => ['name' => 'Pat', 'agree' => '', 'drawn' => 'data:image/png;base64,AAAA']]);
$draftT = form_draft_load($slugT, $tokenT);
$check('forms: a saved half-finished form comes back by its token, without any drawing', $draftT !== null && ($draftT['values'][0] ?? '') === 'Pat' && ($draftT['values'][6]['drawn'] ?? 'x') === '' && strtotime((string) $draftT['expires']) > time() + 29 * 86400);
$check('forms: a wrong or malformed token finds nothing', form_draft_load($slugT, str_repeat('0', 32)) === null && form_draft_load($slugT, '../x') === null);
write_json(form_drafts_dir($slugT) . '/' . str_repeat('a', 32) . '.json', ['expires' => date('c', time() - 60), 'values' => []]);
$tidied = forms_tidy_drafts();
$check('forms: the daily tidy removes only expired drafts', $tidied >= 1 && form_draft_load($slugT, $tokenT) !== null && !is_file(form_drafts_dir($slugT) . '/' . str_repeat('a', 32) . '.json'), "tidied $tidied");
form_draft_delete($slugT, $tokenT);
$check('forms: a draft is gone once the form is sent', form_draft_load($slugT, $tokenT) === null);

$moved = form_fields_remap($fieldsT, fn(int $k) => $k === 2 ? 3 : ($k === 3 ? 2 : $k));
$removed = form_fields_remap($fieldsT, fn(int $k) => $k === 4 ? null : ($k > 4 ? $k - 1 : $k));
$check('forms: conditions follow their field when fields move, and clear when it is removed', $moved[3]['show_if'] === '3' && $removed[5]['show_if'] === '' && $removed[3]['show_if'] === '2');

foreach (array_merge(glob(form_submission_dir($slugT) . '/files/*') ?: [], glob(form_submission_dir($slugT) . '/drafts/*') ?: [], glob(form_submission_dir($slugT) . '/*.json') ?: []) as $f) {
    @unlink($f);
}
@rmdir(form_submission_dir($slugT) . '/files');
@rmdir(form_submission_dir($slugT) . '/drafts');
@rmdir(form_submission_dir($slugT));
@unlink($tmpFile);
@unlink($exe);

/* ---------- 9. A running site, when asked ---------- */
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
