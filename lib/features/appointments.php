<?php
/**
 * Appointments (feature switch "appointments", off by default): visitors book a time
 * on the website, and the time goes straight into the owner's calendar.
 *
 * The owner sets up services (a name, how long it takes, a price), the hours they take
 * appointments, and one calendar that holds the truth about when they are busy:
 *   - a cPanel-hosted calendar, or any other CalDAV calendar (iCloud, Fastmail, Nextcloud);
 *   - a Microsoft 365 or Outlook.com calendar, connected once by signing in to Microsoft;
 *   - a Google Calendar shared with a Google service account.
 * Times already taken in that calendar are never offered. A booking is written into the
 * calendar as an event and kept here as one JSON file in data/appointments/, the visitor
 * and the owner are emailed, and a reminder goes out the day before, by email and, for a
 * visitor who ticked the box, by text message (lib/features/sms.php).
 * Minutes kept free before and after a booking come from the service, or the site default.
 *
 *   /book/                       the services, or straight to the only one
 *   /book/<service>/             days with openings
 *   /book/<service>/<date>/      the times that day and the booking form
 *   /book/done/<id>/?t=          confirmation, with an “Add to my calendar” file, a cancel link and a “change the time” link
 *   /book/cancel/<id>/?t=        cancel a booking (asks first)
 *   /book/move/<id>/?t=          pick a new time; the old booking is released only once the new one is confirmed
 *                                (the openings pages take ?move=<id>&t= to carry that through)
 *   /book/<id>.ics?t=            the booking as an iCalendar file
 *   [book]                       the list of services, for any page
 *
 * Services are items of type "services" in content/services/ (Appointments screen).
 * Hours and wording live in content/settings.json under "appointments"; the calendar
 * connection, with its password or tokens, in data/config.php under "appt_calendar".
 * Control panel screens live in admin/appointments.php and are loaded only when needed.
 */

declare(strict_types=1);

const APPT_DIR = DATA_DIR . '/appointments';
const APPT_BUSY_CACHE = DATA_DIR . '/cache/appt-busy.json';
const APPT_TOKEN_CACHE = DATA_DIR . '/appt-token.php';
const APPT_BUSY_TTL = 120;          // seconds a calendar's busy times are reused between page views
const APPT_MAX_DAYS_SHOWN = 42;     // days with openings listed at once
const APPT_RRULE_LIMIT = 400;       // occurrences worked out for one repeating CalDAV event
const APPT_WEEKDAYS = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];
const APPT_STEPS = [15 => 'Every 15 minutes', 20 => 'Every 20 minutes', 30 => 'Every 30 minutes', 60 => 'Every hour'];
const APPT_CALENDAR_TYPES = [
    'none' => 'No calendar: only check against bookings made here',
    'caldav' => 'A cPanel-hosted calendar, or another CalDAV calendar (iCloud, Fastmail, Nextcloud)',
    'microsoft' => 'A Microsoft 365 or Outlook.com calendar',
    'google' => 'A Google Calendar',
];
const APPT_MS_SCOPE = 'openid email offline_access Calendars.ReadWrite';

register_feature('appointments', 'Appointments', 'Let visitors book a time at /book/. Openings come from your hours and your own calendar (cPanel, Microsoft or Google), and each booking is written into that calendar.');

/* =====================================================================
   Settings, services and bookings
   ===================================================================== */

/** The appointment settings with every key filled in. */
function appt_settings(): array
{
    $s = (array) (settings()['appointments'] ?? []);
    $hours = [];
    foreach (APPT_WEEKDAYS as $day => $name) {
        $ranges = [];
        foreach ((array) ($s['hours'][$day] ?? []) as $r) {
            if (is_array($r) && count($r) === 2 && appt_valid_clock((string) $r[0]) && appt_valid_clock((string) $r[1]) && $r[0] < $r[1]) {
                $ranges[] = [(string) $r[0], (string) $r[1]];
            }
        }
        $hours[$day] = $ranges;
    }
    if (!array_key_exists('hours', $s)) {
        foreach (['mon', 'tue', 'wed', 'thu', 'fri'] as $day) {
            $hours[$day] = [['09:00', '17:00']];
        }
    }
    $step = (int) ($s['step'] ?? 30);
    return [
        'hours' => $hours,
        'step' => isset(APPT_STEPS[$step]) ? $step : 30,
        'buffer' => max(0, min(120, (int) ($s['buffer'] ?? 0))),
        // Minutes kept free before and after every booking (a service can set its own). Older sites had one "buffer" for both.
        'buffer_before' => max(0, min(120, (int) ($s['buffer_before'] ?? $s['buffer'] ?? 0))),
        'buffer_after' => max(0, min(120, (int) ($s['buffer_after'] ?? $s['buffer'] ?? 0))),
        'notice_hours' => max(0, min(24 * 30, (int) ($s['notice_hours'] ?? 24))),
        'window_days' => max(1, min(365, (int) ($s['window_days'] ?? 60))),
        'ask_phone' => array_key_exists('ask_phone', $s) ? !empty($s['ask_phone']) : true,
        'notes_label' => trim((string) ($s['notes_label'] ?? '')),
        'intro' => trim((string) ($s['intro'] ?? '')),
        'after' => trim((string) ($s['after'] ?? '')),
        'reminders' => array_key_exists('reminders', $s) ? !empty($s['reminders']) : true,
        'invite' => array_key_exists('invite', $s) ? !empty($s['invite']) : true,
        'sms_reminders' => array_key_exists('sms_reminders', $s) ? !empty($s['sms_reminders']) : true,
    ];
}

function appt_valid_clock(string $t): bool
{
    return preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $t) === 1;
}

/** The calendar connection kept in data/config.php (type and credentials). */
function appt_calendar(): array
{
    if (isset($GLOBALS['appt_calendar_mock'])) {
        return (array) $GLOBALS['appt_calendar_mock']; // the self-test books against a stand-in calendar
    }
    $c = (array) (config()['appt_calendar'] ?? []);
    $type = (string) ($c['type'] ?? 'none');
    return ['type' => isset(APPT_CALENDAR_TYPES[$type]) ? $type : 'none'] + $c;
}

function appt_save_calendar(array $changes): void
{
    save_config(['appt_calendar' => array_merge(appt_calendar(), $changes)]);
    @unlink(APPT_TOKEN_CACHE);
    @unlink(APPT_BUSY_CACHE);
}

function appt_zone(): DateTimeZone
{
    try {
        return new DateTimeZone((string) (settings()['timezone'] ?: 'UTC'));
    } catch (Throwable) {
        return new DateTimeZone('UTC');
    }
}

function appt_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', appt_zone());
}

/** Published services, shortest name first. */
function appt_services(bool $all = false): array
{
    return array_values(array_filter(all_items('services'), fn($s) => $all || ($s['status'] ?? '') === 'published'));
}

function appt_service(string $slug): ?array
{
    if (!valid_slug($slug) || !($s = get_item('services', $slug))) {
        return null;
    }
    $s['duration'] = max(5, min(480, (int) ($s['duration'] ?? 30)));
    foreach (['buffer_before', 'buffer_after'] as $k) {
        $s[$k] = isset($s[$k]) && $s[$k] !== '' ? max(0, min(120, (int) $s[$k])) : null;
    }
    return $s;
}

/** Minutes kept free [before, after] a booking of this service: the service's own numbers, else the site's. */
function appt_buffers(array $service): array
{
    $s = appt_settings();
    $pick = fn(string $k) => isset($service[$k]) && $service[$k] !== '' && $service[$k] !== null ? max(0, min(120, (int) $service[$k])) : (int) $s[$k];
    return [$pick('buffer_before'), $pick('buffer_after')];
}

function appt_bookings(): array
{
    $list = [];
    foreach (glob(APPT_DIR . '/*.json') ?: [] as $file) {
        if ($b = read_json($file)) {
            $list[] = $b;
        }
    }
    usort($list, fn($a, $b) => strcmp((string) ($a['start'] ?? ''), (string) ($b['start'] ?? '')));
    return $list;
}

function appt_booking(string $id): ?array
{
    return preg_match('/^[0-9]{8}-[0-9]{6}-[0-9a-f]{6}$/', $id) ? read_json(APPT_DIR . "/$id.json") : null;
}

function appt_save_booking(array $b): void
{
    write_json(APPT_DIR . '/' . $b['id'] . '.json', $b);
    @chmod(APPT_DIR, 0700);
}

/** The visitor's key to their own booking, carried in the confirmation and cancel links. */
function appt_token(string $id): string
{
    return substr(hash_hmac('sha256', 'appointment|' . $id, site_secret()), 0, 32);
}

function appt_token_ok(string $id, string $token): bool
{
    return $token !== '' && hash_equals(appt_token($id), $token);
}

function appt_start(array $b): DateTimeImmutable
{
    return (new DateTimeImmutable((string) $b['start']))->setTimezone(appt_zone());
}

function appt_end(array $b): DateTimeImmutable
{
    return (new DateTimeImmutable((string) $b['end']))->setTimezone(appt_zone());
}

/** "Tuesday, November 25, 2026 from 2:00 pm to 2:45 pm (Central Time)". */
function appt_when_words(array $b, ?string $lang = null): string
{
    $start = appt_start($b);
    $end = appt_end($b);
    return appt_date_words($start, $lang) . ' ' . t_in($lang ?? current_lang(), 'book_from') . ' ' . appt_time_words($start, $lang)
        . ' ' . t_in($lang ?? current_lang(), 'book_to') . ' ' . appt_time_words($end, $lang) . ' (' . appt_zone_words($start) . ')';
}

function appt_date_words(DateTimeImmutable $d, ?string $lang = null): string
{
    $lang ??= current_lang();
    if (class_exists('IntlDateFormatter')) {
        $f = new IntlDateFormatter($lang === 'es' ? 'es' : 'en_US', IntlDateFormatter::FULL, IntlDateFormatter::NONE, $d->getTimezone());
        $words = $f->format($d);
        if (is_string($words) && $words !== '') {
            return $words;
        }
    }
    if ($lang === 'es') {
        $days = ['Monday' => 'lunes', 'Tuesday' => 'martes', 'Wednesday' => 'miércoles', 'Thursday' => 'jueves', 'Friday' => 'viernes', 'Saturday' => 'sábado', 'Sunday' => 'domingo'];
        $months = ['January' => 'enero', 'February' => 'febrero', 'March' => 'marzo', 'April' => 'abril', 'May' => 'mayo', 'June' => 'junio', 'July' => 'julio', 'August' => 'agosto', 'September' => 'septiembre', 'October' => 'octubre', 'November' => 'noviembre', 'December' => 'diciembre'];
        return $days[$d->format('l')] . ', ' . $d->format('j') . ' de ' . $months[$d->format('F')] . ' de ' . $d->format('Y');
    }
    return $d->format('l, F j, Y');
}

function appt_time_words(DateTimeImmutable $d, ?string $lang = null): string
{
    $lang ??= current_lang();
    return $lang === 'es' ? $d->format('H:i') : strtolower($d->format('g:i a'));
}

function appt_zone_words(DateTimeImmutable $d): string
{
    $name = $d->getTimezone()->getName();
    $labels = ['America/New_York' => 'Eastern Time', 'America/Chicago' => 'Central Time', 'America/Denver' => 'Mountain Time',
        'America/Phoenix' => 'Arizona Time', 'America/Los_Angeles' => 'Pacific Time', 'America/Anchorage' => 'Alaska Time', 'Pacific/Honolulu' => 'Hawaii Time'];
    return $labels[$name] ?? str_replace('_', ' ', $name) . ' ' . $d->format('T');
}

/* =====================================================================
   Working out the openings
   ===================================================================== */

/**
 * Busy times between two moments: the connected calendar's events plus bookings made here.
 * Each item is [start, end] as DateTimeImmutable. Throws when the calendar cannot be read.
 * $except is the id of a booking being moved: its own time (here and in the calendar) counts as free.
 */
function appt_busy(DateTimeImmutable $from, DateTimeImmutable $to, bool $fresh = false, string $except = ''): array
{
    $busy = [];
    $old = null;
    foreach (appt_bookings() as $b) {
        if ($except !== '' && ($b['id'] ?? '') === $except) {
            $old = $b;
            continue;
        }
        if (($b['status'] ?? '') === 'booked' && (string) $b['end'] > $from->format('c') && (string) $b['start'] < $to->format('c')) {
            $busy[] = [new DateTimeImmutable((string) $b['start']), new DateTimeImmutable((string) $b['end'])];
        }
    }
    $cal = appt_calendar();
    if ($cal['type'] === 'none') {
        return $busy;
    }
    $key = $cal['type'] . '|' . $from->format('c') . '|' . $to->format('c');
    $cache = read_json(APPT_BUSY_CACHE);
    if (!$fresh && $cache && ($cache['key'] ?? '') === $key && ($cache['at'] ?? 0) > time() - APPT_BUSY_TTL) {
        $found = [];
        foreach ($cache['busy'] as [$s, $e]) {
            $found[] = [new DateTimeImmutable($s), new DateTimeImmutable($e)];
        }
    } else {
        $found = match ($cal['type']) {
            'caldav' => appt_caldav_busy($cal, $from, $to),
            'microsoft' => appt_ms_busy($cal, $from, $to),
            'google' => appt_google_busy($cal, $from, $to),
        };
        write_json(APPT_BUSY_CACHE, ['key' => $key, 'at' => time(), 'busy' => array_map(fn($p) => [$p[0]->format('c'), $p[1]->format('c')], $found)]);
    }
    if ($old !== null) {
        // The booking being moved is in the calendar as an event of its own: that event is not in the way.
        $os = (new DateTimeImmutable((string) $old['start']))->getTimestamp();
        $oe = (new DateTimeImmutable((string) $old['end']))->getTimestamp();
        $found = array_values(array_filter($found, fn($p) => !($p[0]->getTimestamp() === $os && $p[1]->getTimestamp() === $oe)));
    }
    return array_merge($busy, $found);
}

/** The first and last moments a visitor may book right now. */
function appt_window(): array
{
    $s = appt_settings();
    $now = appt_now();
    return [$now->modify('+' . $s['notice_hours'] . ' hours'), $now->modify('+' . $s['window_days'] . ' days')->setTime(23, 59, 59)];
}

/** Start times offered for a service on one day, given the busy list. */
function appt_openings(array $service, DateTimeImmutable $day, array $busy): array
{
    $s = appt_settings();
    [$earliest, $latest] = appt_window();
    $day = $day->setTimezone(appt_zone())->setTime(0, 0);
    if ($day > $latest) {
        return [];
    }
    $length = $service['duration'] * 60;
    [$before, $after] = appt_buffers($service);
    $before *= 60;
    $after *= 60;
    $out = [];
    foreach ($s['hours'][strtolower($day->format('D'))] ?? [] as [$open, $close]) {
        [$oh, $om] = explode(':', $open);
        [$ch, $cm] = explode(':', $close);
        $start = $day->setTime((int) $oh, (int) $om);
        $end = $day->setTime((int) $ch, (int) $cm);
        while ($start->getTimestamp() + $length <= $end->getTimestamp()) {
            $finish = $start->getTimestamp() + $length;
            if ($start >= $earliest && $start <= $latest) {
                $free = true;
                foreach ($busy as [$bs, $be]) {
                    // Busy if anything overlaps the booking plus its free minutes on either side.
                    if ($bs->getTimestamp() < $finish + $after && $be->getTimestamp() > $start->getTimestamp() - $before) {
                        $free = false;
                        break;
                    }
                }
                if ($free) {
                    $out[] = $start;
                }
            }
            $start = $start->modify('+' . $s['step'] . ' minutes');
        }
    }
    return $out;
}

/** Days from $from with at least one opening: ['Y-m-d' => count], at most APPT_MAX_DAYS_SHOWN of them. */
function appt_days(array $service, DateTimeImmutable $from, array $busy): array
{
    [, $latest] = appt_window();
    $days = [];
    for ($d = $from->setTime(0, 0); $d <= $latest && count($days) < APPT_MAX_DAYS_SHOWN; $d = $d->modify('+1 day')) {
        $n = count(appt_openings($service, $d, $busy));
        if ($n > 0) {
            $days[$d->format('Y-m-d')] = $n;
        }
    }
    return $days;
}

/* =====================================================================
   HTTP, shared by the three calendar kinds
   ===================================================================== */

/** @return array{int, string, array} status, body and lower-cased response headers. */
function appt_http(string $method, string $url, array $headers = [], string $body = '', ?string $basicAuth = null): array
{
    if (isset($GLOBALS['appt_http_mock'])) {
        return ($GLOBALS['appt_http_mock'])($method, $url, $headers, $body, $basicAuth); // the self-test stands in for the calendar services
    }
    $ch = curl_init($url);
    $responseHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'Full Circle CMS appointments',
        CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$responseHeaders): int {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($k))] = trim($v);
            }
            return strlen($line);
        },
    ]);
    if ($basicAuth !== null) {
        curl_setopt($ch, CURLOPT_USERPWD, $basicAuth);
    }
    if ($body !== '' || in_array($method, ['POST', 'PUT', 'REPORT', 'PROPFIND', 'PATCH'], true)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        throw new RuntimeException('Could not reach ' . (parse_url($url, PHP_URL_HOST) ?: 'the calendar server') . ': ' . $err);
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return [$status, (string) $response, $responseHeaders];
}

/** Short-lived access tokens for Microsoft and Google, kept in a private file. */
function appt_cached_token(string $kind): ?string
{
    if (isset($GLOBALS['appt_http_mock'])) {
        return $GLOBALS['appt_mock_tokens'][$kind] ?? null; // the self-test keeps tokens in memory, never in the real cache file
    }
    $cached = is_file(APPT_TOKEN_CACHE) ? include APPT_TOKEN_CACHE : null;
    return is_array($cached) && ($cached['kind'] ?? '') === $kind && ($cached['expires'] ?? 0) > time() + 60 ? (string) $cached['token'] : null;
}

function appt_store_token(string $kind, string $token, int $lifetime): void
{
    if (isset($GLOBALS['appt_http_mock'])) {
        $GLOBALS['appt_mock_tokens'][$kind] = $token;
        return;
    }
    write_file(APPT_TOKEN_CACHE, '<?php return ' . var_export(['kind' => $kind, 'token' => $token, 'expires' => time() + $lifetime], true) . ';');
    @chmod(APPT_TOKEN_CACHE, 0600);
}

function appt_forget_token(string $kind): void
{
    if (isset($GLOBALS['appt_http_mock'])) {
        unset($GLOBALS['appt_mock_tokens'][$kind]);
        return;
    }
    @unlink(APPT_TOKEN_CACHE);
}

function appt_utc(DateTimeImmutable $d): string
{
    return $d->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
}

/* =====================================================================
   CalDAV (cPanel's Calendars and Contacts server, iCloud, Fastmail, Nextcloud, …)
   ===================================================================== */

function appt_caldav_auth(array $cal): string
{
    return (string) ($cal['user'] ?? '') . ':' . (string) ($cal['password'] ?? '');
}

/** One PROPFIND, parsed into [href => [property local name => value or list]]. */
function appt_caldav_propfind(array $cal, string $url, string $depth, string $props): array
{
    $body = '<?xml version="1.0" encoding="utf-8"?><d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:cs="http://calendarserver.org/ns/"><d:prop>' . $props . '</d:prop></d:propfind>';
    [$status, $xml] = appt_http('PROPFIND', $url, ['Depth: ' . $depth, 'Content-Type: application/xml; charset=utf-8'], $body, appt_caldav_auth($cal));
    if ($status === 401 || $status === 403) {
        throw new RuntimeException('The calendar server did not accept that user name and password.');
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("The calendar server answered PROPFIND $url with status $status.");
    }
    return appt_caldav_parse_multistatus($xml);
}

/** Turn a multistatus answer into [href => props]; properties are strings, or lists of hrefs for resourcetype/href-valued ones. */
function appt_caldav_parse_multistatus(string $xml): array
{
    $out = [];
    $prev = libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml);
    libxml_use_internal_errors($prev);
    if ($doc === false) {
        throw new RuntimeException('The calendar server sent an answer that could not be read.');
    }
    foreach ($doc->xpath('//*[local-name()="response"]') ?: [] as $response) {
        $href = (string) ($response->xpath('./*[local-name()="href"]')[0] ?? '');
        $props = [];
        foreach ($response->xpath('./*[local-name()="propstat"]') ?: [] as $propstat) {
            $statusText = (string) ($propstat->xpath('./*[local-name()="status"]')[0] ?? '');
            if ($statusText !== '' && !str_contains($statusText, ' 200 ')) {
                continue;
            }
            foreach ($propstat->xpath('./*[local-name()="prop"]/*') ?: [] as $prop) {
                $name = $prop->getName();
                $children = $prop->xpath('./*');
                if ($children) {
                    $list = [];
                    foreach ($children as $child) {
                        $list[] = $child->getName() === 'href' ? trim((string) $child) : (isset($child['name']) ? $child->getName() . ':' . (string) $child['name'] : $child->getName());
                    }
                    $props[$name] = $list;
                } else {
                    $props[$name] = trim((string) $prop);
                }
            }
        }
        $out[$href] = $props;
    }
    return $out;
}

/** Make an href absolute against the server address. */
function appt_caldav_absolute(string $base, string $href): string
{
    if (preg_match('#^https?://#i', $href)) {
        return $href;
    }
    $p = parse_url($base);
    return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '') . '/' . ltrim($href, '/');
}

/**
 * Find the calendars behind a server address: [[url, name], …].
 * Tries the address as given, then /.well-known/caldav, then cPanel's /calendars/<user>/ shape.
 */
function appt_caldav_discover(array $cal): array
{
    $base = rtrim((string) ($cal['url'] ?? ''), '/');
    if ($base === '') {
        throw new RuntimeException('Enter the calendar server address first.');
    }
    $user = (string) ($cal['user'] ?? '');
    $tries = [$base . '/', $base . '/.well-known/caldav', appt_caldav_absolute($base, '/.well-known/caldav')];
    if ($user !== '') {
        $tries[] = appt_caldav_absolute($base, '/calendars/' . rawurlencode($user) . '/');
        $tries[] = appt_caldav_absolute($base, '/principals/' . rawurlencode($user) . '/');
    }
    $calendars = [];
    $seen = [];
    foreach (array_unique($tries) as $url) {
        try {
            $calendars = appt_caldav_calendars_under($cal, $url, $seen);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'user name and password')) {
                throw $e;
            }
            continue;
        }
        if ($calendars) {
            return $calendars;
        }
    }
    throw new RuntimeException('No calendars were found behind that address. For a cPanel calendar the address is usually https://your-domain:2080 and the user name is the email account.');
}

/** Calendars at a URL, following current-user-principal and calendar-home-set when the URL is not the home itself. */
function appt_caldav_calendars_under(array $cal, string $url, array &$seen): array
{
    if (isset($seen[$url]) || count($seen) > 8) {
        return [];
    }
    $seen[$url] = true;
    $props = appt_caldav_propfind($cal, $url, '0', '<d:current-user-principal/><c:calendar-home-set/><d:resourcetype/>');
    $self = reset($props) ?: [];
    if (!empty($self['calendar-home-set'][0])) {
        $home = appt_caldav_absolute($url, (string) $self['calendar-home-set'][0]);
        $found = appt_caldav_list_calendars($cal, $home);
        if ($found) {
            return $found;
        }
    }
    if (!empty($self['current-user-principal'][0])) {
        $found = appt_caldav_calendars_under($cal, appt_caldav_absolute($url, (string) $self['current-user-principal'][0]), $seen);
        if ($found) {
            return $found;
        }
    }
    return appt_caldav_list_calendars($cal, $url);
}

function appt_caldav_list_calendars(array $cal, string $home): array
{
    $members = appt_caldav_propfind($cal, rtrim($home, '/') . '/', '1', '<d:resourcetype/><d:displayname/><c:supported-calendar-component-set/>');
    $out = [];
    foreach ($members as $href => $props) {
        $types = (array) ($props['resourcetype'] ?? []);
        if (!in_array('calendar', $types, true)) {
            continue;
        }
        // Servers say what a calendar holds as <c:comp name="VEVENT"/>; the parser keeps that as "comp:VEVENT". A tasks-only calendar is no use for bookings.
        $components = array_filter((array) ($props['supported-calendar-component-set'] ?? []), fn($c) => str_starts_with((string) $c, 'comp:'));
        if ($components && !in_array('comp:VEVENT', $components, true)) {
            continue;
        }
        $out[] = [appt_caldav_absolute($home, $href), (string) ($props['displayname'] ?? '') ?: basename(rtrim($href, '/'))];
    }
    return $out;
}

/** Busy times from the calendar: a calendar-query REPORT for the window, expanded here for repeats. */
function appt_caldav_busy(array $cal, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $url = (string) ($cal['calendar_url'] ?? '');
    if ($url === '') {
        throw new RuntimeException('No calendar has been chosen yet on the Appointments screen.');
    }
    $body = '<?xml version="1.0" encoding="utf-8"?><c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
        . '<d:prop><d:getetag/><c:calendar-data/></d:prop>'
        . '<c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT"><c:time-range start="' . appt_utc($from->modify('-1 day')) . '" end="' . appt_utc($to) . '"/></c:comp-filter></c:comp-filter></c:filter>'
        . '</c:calendar-query>';
    [$status, $xml] = appt_http('REPORT', $url, ['Depth: 1', 'Content-Type: application/xml; charset=utf-8'], $body, appt_caldav_auth($cal));
    if ($status === 401 || $status === 403) {
        throw new RuntimeException('The calendar server did not accept the saved user name and password.');
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("The calendar server answered the busy-time query with status $status.");
    }
    $busy = [];
    foreach (appt_caldav_parse_multistatus($xml) as $props) {
        $ics = (string) ($props['calendar-data'] ?? '');
        if ($ics === '') {
            continue;
        }
        foreach (appt_ics_events($ics) as $event) {
            foreach (appt_ics_expand($event, $from, $to) as $pair) {
                $busy[] = $pair;
            }
        }
    }
    return $busy;
}

/** The VEVENTs in an iCalendar text, with the few fields busy times need. */
function appt_ics_events(string $ics): array
{
    $lines = preg_split('/\r?\n/', str_replace(["\r\n ", "\r\n\t", "\n ", "\n\t"], '', $ics)) ?: [];
    $events = [];
    $current = null;
    foreach ($lines as $line) {
        if ($line === 'BEGIN:VEVENT') {
            $current = ['exdates' => []];
            continue;
        }
        if ($line === 'END:VEVENT') {
            if ($current !== null && isset($current['start'])) {
                $events[] = $current;
            }
            $current = null;
            continue;
        }
        if ($current === null || !str_contains($line, ':')) {
            continue;
        }
        [$head, $value] = explode(':', $line, 2);
        $parts = explode(';', $head);
        $name = strtoupper(array_shift($parts));
        $params = [];
        foreach ($parts as $p) {
            if (str_contains($p, '=')) {
                [$k, $v] = explode('=', $p, 2);
                $params[strtoupper($k)] = trim($v, '"');
            }
        }
        switch ($name) {
            case 'DTSTART':
                $current['start'] = appt_ics_time($value, $params);
                $current['all_day'] = ($params['VALUE'] ?? '') === 'DATE';
                break;
            case 'DTEND':
                $current['end'] = appt_ics_time($value, $params);
                break;
            case 'DURATION':
                $current['duration'] = $value;
                break;
            case 'RRULE':
                $current['rrule'] = $value;
                break;
            case 'EXDATE':
                foreach (explode(',', $value) as $x) {
                    if ($d = appt_ics_time($x, $params)) {
                        $current['exdates'][] = $d->getTimestamp();
                    }
                }
                break;
            case 'TRANSP':
                $current['transparent'] = strtoupper(trim($value)) === 'TRANSPARENT';
                break;
            case 'STATUS':
                $current['cancelled'] = strtoupper(trim($value)) === 'CANCELLED';
                break;
            case 'RECURRENCE-ID':
                $current['recurrence_id'] = true;
                break;
        }
    }
    foreach ($events as &$e) {
        if ($e['start'] === null) {
            $e = null;
            continue;
        }
        if (empty($e['end'])) {
            if (!empty($e['duration']) && preg_match('/^P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', $e['duration'], $m)) {
                $secs = ((int) ($m[1] ?? 0)) * 604800 + ((int) ($m[2] ?? 0)) * 86400 + ((int) ($m[3] ?? 0)) * 3600 + ((int) ($m[4] ?? 0)) * 60 + (int) ($m[5] ?? 0);
                $e['end'] = $e['start']->modify('+' . $secs . ' seconds');
            } else {
                $e['end'] = $e['start']->modify(!empty($e['all_day']) ? '+1 day' : '+1 hour');
            }
        }
    }
    return array_values(array_filter($events));
}

/** An iCalendar date or date-time as a moment; floating times are read in the site's zone. */
function appt_ics_time(string $value, array $params): ?DateTimeImmutable
{
    $value = trim($value);
    try {
        if (preg_match('/^(\d{8})$/', $value)) {
            return new DateTimeImmutable($value, appt_zone());
        }
        if (preg_match('/^(\d{8}T\d{6})Z$/', $value, $m)) {
            return new DateTimeImmutable($m[1], new DateTimeZone('UTC'));
        }
        if (preg_match('/^(\d{8}T\d{6})$/', $value, $m)) {
            if (($params['TZID'] ?? '') !== '') {
                $d = graph_datetime(['dateTime' => substr($m[1], 0, 4) . '-' . substr($m[1], 4, 2) . '-' . substr($m[1], 6, 2) . substr($m[1], 8), 'timeZone' => $params['TZID']]);
                return $d;
            }
            return new DateTimeImmutable($m[1], appt_zone());
        }
    } catch (Throwable) {
    }
    return null;
}

/** The busy pairs one event produces inside the window; repeating events are expanded for the common rules. */
function appt_ics_expand(array $e, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    if (!empty($e['transparent']) || !empty($e['cancelled'])) {
        return [];
    }
    $length = $e['end']->getTimestamp() - $e['start']->getTimestamp();
    if ($length <= 0) {
        return [];
    }
    $inWindow = fn(DateTimeImmutable $s) => $s->getTimestamp() < $to->getTimestamp() && $s->getTimestamp() + $length > $from->getTimestamp();
    $rule = [];
    foreach (explode(';', (string) ($e['rrule'] ?? '')) as $part) {
        if (str_contains($part, '=')) {
            [$k, $v] = explode('=', $part, 2);
            $rule[strtoupper($k)] = strtoupper($v);
        }
    }
    $out = [];
    if (empty($rule['FREQ']) || !in_array($rule['FREQ'], ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], true)) {
        return $inWindow($e['start']) ? [[$e['start'], $e['end']]] : [];
    }
    $interval = max(1, (int) ($rule['INTERVAL'] ?? 1));
    $count = isset($rule['COUNT']) ? (int) $rule['COUNT'] : null;
    $until = isset($rule['UNTIL']) ? appt_ics_time($rule['UNTIL'], []) : null;
    $byDay = [];
    if ($rule['FREQ'] === 'WEEKLY' && !empty($rule['BYDAY'])) {
        $map = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];
        foreach (explode(',', $rule['BYDAY']) as $d) {
            if (isset($map[$d])) {
                $byDay[] = $map[$d];
            }
        }
    }
    $made = 0;
    $cursor = $e['start'];
    $limitTime = min($to->getTimestamp(), $until ? $until->getTimestamp() : PHP_INT_MAX);
    for ($i = 0; $i < APPT_RRULE_LIMIT && $cursor->getTimestamp() <= $limitTime; $i++) {
        $starts = [];
        if ($byDay) {
            $weekStart = $cursor->modify('monday this week')->setTime((int) $cursor->format('G'), (int) $cursor->format('i'), (int) $cursor->format('s'));
            foreach ($byDay as $n) {
                $s = $weekStart->modify('+' . ($n - 1) . ' days');
                if ($s >= $e['start']) {
                    $starts[] = $s;
                }
            }
        } else {
            $starts[] = $cursor;
        }
        foreach ($starts as $s) {
            if ($count !== null && $made >= $count) {
                break 2;
            }
            if ($until && $s > $until) {
                break 2;
            }
            $made++;
            if (!in_array($s->getTimestamp(), $e['exdates'], true) && $inWindow($s)) {
                $out[] = [$s, $s->modify('+' . $length . ' seconds')];
            }
        }
        $cursor = match ($rule['FREQ']) {
            'DAILY' => $cursor->modify('+' . $interval . ' days'),
            'WEEKLY' => $cursor->modify('+' . $interval . ' weeks'),
            'MONTHLY' => $cursor->modify('+' . $interval . ' months'),
            default => $cursor->modify('+' . $interval . ' years'),
        };
    }
    return $out;
}

function appt_ics_escape(string $s): string
{
    return str_replace(["\\", ";", ",", "\r\n", "\n"], ["\\\\", "\\;", "\\,", "\\n", "\\n"], $s);
}

/** The booking as an iCalendar text (used for the calendar write and the visitor's download). */
function appt_booking_ics(array $b, ?string $method = null): string
{
    $s = settings();
    $uid = (string) ($b['uid'] ?? ($b['id'] . '@' . site_host()));
    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Full Circle CMS//Appointments//EN', 'CALSCALE:GREGORIAN'];
    if ($method !== null) {
        $lines[] = 'METHOD:' . $method;
    }
    $lines[] = 'BEGIN:VEVENT';
    $lines[] = 'UID:' . $uid;
    $lines[] = 'DTSTAMP:' . appt_utc(new DateTimeImmutable());
    $lines[] = 'DTSTART:' . appt_utc(new DateTimeImmutable((string) $b['start']));
    $lines[] = 'DTEND:' . appt_utc(new DateTimeImmutable((string) $b['end']));
    $lines[] = 'SUMMARY:' . appt_ics_escape(appt_event_title($b));
    $lines[] = 'DESCRIPTION:' . appt_ics_escape(appt_event_description($b));
    if (($b['location'] ?? '') !== '') {
        $lines[] = 'LOCATION:' . appt_ics_escape((string) $b['location']);
    }
    $lines[] = 'STATUS:' . (($b['status'] ?? '') === 'cancelled' ? 'CANCELLED' : 'CONFIRMED');
    $lines[] = 'URL:' . site_url('/');
    $lines[] = 'END:VEVENT';
    $lines[] = 'END:VCALENDAR';
    return implode("\r\n", array_map(fn($l) => function_exists('ics_fold') ? ics_fold($l) : $l, $lines)) . "\r\n";
}

function appt_event_title(array $b): string
{
    return $b['service_title'] . ' — ' . $b['name'];
}

function appt_event_description(array $b): string
{
    $lines = [$b['service_title'] . ' (' . $b['duration'] . ' min)', 'Booked on ' . site_name() . ' (' . site_url('/') . ')', '', 'Name: ' . $b['name'], 'Email: ' . $b['email']];
    if (($b['phone'] ?? '') !== '') {
        $lines[] = 'Phone: ' . $b['phone'];
    }
    if (($b['notes'] ?? '') !== '') {
        $lines[] = '';
        $lines[] = 'Notes: ' . $b['notes'];
    }
    return implode("\n", $lines);
}

function appt_caldav_create(array $cal, array $b): array
{
    $url = rtrim((string) ($cal['calendar_url'] ?? ''), '/') . '/' . $b['id'] . '.ics';
    [$status, , $headers] = appt_http('PUT', $url, ['Content-Type: text/calendar; charset=utf-8', 'If-None-Match: *'], appt_booking_ics($b), appt_caldav_auth($cal));
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("The calendar server refused the new event (status $status).");
    }
    return ['type' => 'caldav', 'href' => $url, 'etag' => (string) ($headers['etag'] ?? '')];
}

function appt_caldav_delete(array $cal, array $ref): void
{
    [$status] = appt_http('DELETE', (string) $ref['href'], [], '', appt_caldav_auth($cal));
    if ($status !== 404 && ($status < 200 || $status >= 300)) {
        throw new RuntimeException("The calendar server would not remove the event (status $status).");
    }
}

/* =====================================================================
   Microsoft 365 and Outlook.com, as the signed-in owner (refresh token)
   ===================================================================== */

function appt_ms_tenant(array $cal): string
{
    $t = (string) ($cal['tenant'] ?? '');
    return $t !== '' ? $t : 'common';
}

/** Where Microsoft sends the owner back after they allow calendar access. Must be listed on the app registration. */
function appt_ms_redirect_uri(): string
{
    return site_url('/admin/calendar-oauth.php');
}

function appt_ms_access_token(array $cal): string
{
    if ($t = appt_cached_token('microsoft')) {
        return $t;
    }
    $c = config();
    if (($cal['refresh_token'] ?? '') === '') {
        throw new RuntimeException('The Microsoft calendar is not connected yet. Use “Connect a Microsoft calendar” on the Appointments screen.');
    }
    [$status, $body] = appt_http('POST', 'https://login.microsoftonline.com/' . rawurlencode(appt_ms_tenant($cal)) . '/oauth2/v2.0/token',
        ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
            'client_id' => $c['graph_client_id'], 'client_secret' => $c['graph_client_secret'],
            'grant_type' => 'refresh_token', 'refresh_token' => $cal['refresh_token'], 'scope' => APPT_MS_SCOPE,
        ]));
    $json = json_decode($body, true) ?: [];
    if ($status !== 200 || empty($json['access_token'])) {
        throw new RuntimeException('Microsoft would not renew the calendar connection: ' . mb_substr((string) ($json['error_description'] ?? "HTTP $status"), 0, 200) . ' Connect the calendar again on the Appointments screen.');
    }
    if (!empty($json['refresh_token']) && $json['refresh_token'] !== $cal['refresh_token']) {
        save_config(['appt_calendar' => array_merge(appt_calendar(), ['refresh_token' => (string) $json['refresh_token']])]);
    }
    appt_store_token('microsoft', (string) $json['access_token'], (int) ($json['expires_in'] ?? 3000));
    return (string) $json['access_token'];
}

/** Call Graph as the owner. Returns the decoded JSON; throws with Microsoft's reason. */
function appt_ms_request(array $cal, string $method, string $pathOrUrl, ?array $json = null, bool $retry = true): array
{
    $url = str_starts_with($pathOrUrl, 'https://') ? $pathOrUrl : 'https://graph.microsoft.com/v1.0' . $pathOrUrl;
    [$status, $body] = appt_http($method, $url, ['Authorization: Bearer ' . appt_ms_access_token($cal), 'Content-Type: application/json', 'Prefer: outlook.timezone="UTC"'],
        $json === null ? '' : (string) json_encode($json));
    if ($status === 401 && $retry) {
        appt_forget_token('microsoft'); // the cached access token was revoked early; renew it once and try again
        return appt_ms_request($cal, $method, $pathOrUrl, $json, false);
    }
    $data = json_decode($body, true) ?: [];
    if ($status === 404 && $method === 'DELETE') {
        return [];
    }
    if ($status >= 300) {
        throw new RuntimeException('Microsoft Graph: ' . ($data['error']['message'] ?? "HTTP $status"));
    }
    return $data;
}

function appt_ms_calendar_path(array $cal): string
{
    $id = (string) ($cal['ms_calendar_id'] ?? '');
    return $id !== '' ? '/me/calendars/' . rawurlencode($id) : '/me/calendar';
}

function appt_ms_busy(array $cal, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $utc = new DateTimeZone('UTC');
    $url = appt_ms_calendar_path($cal) . '/calendarView?' . http_build_query([
        'startDateTime' => $from->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'), 'endDateTime' => $to->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
        '$select' => 'start,end,showAs,isCancelled,isAllDay', '$top' => 250,
    ]);
    $busy = [];
    for ($page = 0; $url !== '' && $page < 10; $page++) {
        $data = appt_ms_request($cal, 'GET', $url);
        foreach ((array) ($data['value'] ?? []) as $ev) {
            if (!empty($ev['isCancelled']) || in_array((string) ($ev['showAs'] ?? 'busy'), ['free', 'workingElsewhere'], true)) {
                continue;
            }
            $s = graph_datetime((array) ($ev['start'] ?? []));
            $e = graph_datetime((array) ($ev['end'] ?? []));
            if ($s && $e && $e > $s) {
                $busy[] = [$s, $e];
            }
        }
        $url = (string) ($data['@odata.nextLink'] ?? '');
    }
    return $busy;
}

function appt_ms_create(array $cal, array $b): array
{
    $utc = new DateTimeZone('UTC');
    $event = [
        'subject' => appt_event_title($b),
        'body' => ['contentType' => 'text', 'content' => appt_event_description($b)],
        'start' => ['dateTime' => (new DateTimeImmutable((string) $b['start']))->setTimezone($utc)->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
        'end' => ['dateTime' => (new DateTimeImmutable((string) $b['end']))->setTimezone($utc)->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
        'showAs' => 'busy',
        'transactionId' => $b['id'],
    ];
    if (($b['location'] ?? '') !== '') {
        $event['location'] = ['displayName' => $b['location']];
    }
    if (appt_settings()['invite']) {
        $event['attendees'] = [['emailAddress' => ['address' => $b['email'], 'name' => $b['name']], 'type' => 'required']];
    }
    $made = appt_ms_request($cal, 'POST', appt_ms_calendar_path($cal) . '/events', $event);
    return ['type' => 'microsoft', 'event_id' => (string) ($made['id'] ?? ''), 'link' => (string) ($made['webLink'] ?? '')];
}

function appt_ms_delete(array $cal, array $ref): void
{
    if (($ref['event_id'] ?? '') !== '') {
        appt_ms_request($cal, 'DELETE', '/me/events/' . rawurlencode((string) $ref['event_id']));
    }
}

/** The owner's calendars, for the chooser: [[id, name, isDefault], …]. */
function appt_ms_calendars(array $cal): array
{
    $out = [];
    foreach ((array) (appt_ms_request($cal, 'GET', '/me/calendars?$select=id,name,isDefaultCalendar,canEdit&$top=50')['value'] ?? []) as $c) {
        if (!empty($c['canEdit'])) {
            $out[] = [(string) $c['id'], (string) $c['name'], !empty($c['isDefaultCalendar'])];
        }
    }
    return $out;
}

/* =====================================================================
   Google Calendar, through a service account the calendar is shared with
   ===================================================================== */

function appt_google_account(array $cal): array
{
    $sa = (array) ($cal['service_account'] ?? []);
    if (($sa['client_email'] ?? '') === '' || ($sa['private_key'] ?? '') === '') {
        throw new RuntimeException('The Google service account key is not saved yet. Paste the key file’s contents on the Appointments screen.');
    }
    return $sa;
}

function appt_google_access_token(array $cal): string
{
    if ($t = appt_cached_token('google')) {
        return $t;
    }
    $sa = appt_google_account($cal);
    $now = time();
    $b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $unsigned = $b64((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.'
        . $b64((string) json_encode(['iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/calendar', 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600]));
    $key = openssl_pkey_get_private((string) $sa['private_key']);
    if ($key === false || !openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('The Google service account key could not be used to sign in. Paste the whole key file again.');
    }
    [$status, $body] = appt_http('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $unsigned . '.' . $b64($signature)]));
    $json = json_decode($body, true) ?: [];
    if ($status !== 200 || empty($json['access_token'])) {
        throw new RuntimeException('Google would not sign the service account in: ' . mb_substr((string) ($json['error_description'] ?? $json['error'] ?? "HTTP $status"), 0, 200));
    }
    appt_store_token('google', (string) $json['access_token'], (int) ($json['expires_in'] ?? 3000));
    return (string) $json['access_token'];
}

function appt_google_request(array $cal, string $method, string $path, ?array $json = null, bool $retry = true): array
{
    [$status, $body] = appt_http($method, 'https://www.googleapis.com/calendar/v3' . $path, ['Authorization: Bearer ' . appt_google_access_token($cal), 'Content-Type: application/json'],
        $json === null ? '' : (string) json_encode($json));
    if ($status === 401 && $retry) {
        appt_forget_token('google');
        return appt_google_request($cal, $method, $path, $json, false);
    }
    $data = json_decode($body, true) ?: [];
    if (($status === 404 || $status === 410) && $method === 'DELETE') {
        return [];
    }
    if ($status >= 300) {
        $reason = (string) ($data['error']['message'] ?? "HTTP $status");
        if ($status === 404) {
            $reason .= ' Check the calendar ID, and that the calendar is shared with the service account’s email address.';
        }
        throw new RuntimeException('Google Calendar: ' . $reason);
    }
    return $data;
}

function appt_google_calendar_id(array $cal): string
{
    $id = trim((string) ($cal['google_calendar_id'] ?? ''));
    if ($id === '') {
        throw new RuntimeException('Enter the Google calendar ID on the Appointments screen (usually your Gmail address).');
    }
    return $id;
}

function appt_google_busy(array $cal, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $id = appt_google_calendar_id($cal);
    $data = appt_google_request($cal, 'POST', '/freeBusy', ['timeMin' => $from->format(DATE_ATOM), 'timeMax' => $to->format(DATE_ATOM), 'items' => [['id' => $id]]]);
    $entry = (array) ($data['calendars'][$id] ?? []);
    if (!empty($entry['errors'])) {
        throw new RuntimeException('Google Calendar: ' . ($entry['errors'][0]['reason'] ?? 'the calendar could not be read') . '. Share the calendar with the service account’s email address, with permission to make changes to events.');
    }
    $busy = [];
    foreach ((array) ($entry['busy'] ?? []) as $p) {
        try {
            $busy[] = [new DateTimeImmutable((string) $p['start']), new DateTimeImmutable((string) $p['end'])];
        } catch (Throwable) {
        }
    }
    return $busy;
}

function appt_google_create(array $cal, array $b): array
{
    $event = [
        'summary' => appt_event_title($b),
        'description' => appt_event_description($b),
        'start' => ['dateTime' => (new DateTimeImmutable((string) $b['start']))->format(DATE_ATOM)],
        'end' => ['dateTime' => (new DateTimeImmutable((string) $b['end']))->format(DATE_ATOM)],
        'transparency' => 'opaque',
        'source' => ['title' => site_name(), 'url' => site_url('/')],
    ];
    if (($b['location'] ?? '') !== '') {
        $event['location'] = $b['location'];
    }
    // A service account cannot invite guests, so the visitor gets the email and the .ics file from here instead.
    $made = appt_google_request($cal, 'POST', '/calendars/' . rawurlencode(appt_google_calendar_id($cal)) . '/events', $event);
    return ['type' => 'google', 'event_id' => (string) ($made['id'] ?? ''), 'link' => (string) ($made['htmlLink'] ?? '')];
}

function appt_google_delete(array $cal, array $ref): void
{
    if (($ref['event_id'] ?? '') !== '') {
        appt_google_request($cal, 'DELETE', '/calendars/' . rawurlencode(appt_google_calendar_id($cal)) . '/events/' . rawurlencode((string) $ref['event_id']));
    }
}

/* =====================================================================
   The calendar, whichever kind it is
   ===================================================================== */

/** Write the booking into the connected calendar; returns a reference kept with the booking. */
function appt_calendar_create(array $b): array
{
    $cal = appt_calendar();
    return match ($cal['type']) {
        'caldav' => appt_caldav_create($cal, $b),
        'microsoft' => appt_ms_create($cal, $b),
        'google' => appt_google_create($cal, $b),
        default => ['type' => 'none'],
    };
}

function appt_calendar_delete(array $b): void
{
    $ref = (array) ($b['calendar'] ?? []);
    $cal = appt_calendar();
    if (($ref['type'] ?? 'none') === 'none' || $ref['type'] !== $cal['type']) {
        return;
    }
    match ($cal['type']) {
        'caldav' => appt_caldav_delete($cal, $ref),
        'microsoft' => appt_ms_delete($cal, $ref),
        'google' => appt_google_delete($cal, $ref),
        default => null,
    };
}

/** Try the connection and describe what was found; throws with a plain reason. */
function appt_calendar_test(): string
{
    $cal = appt_calendar();
    $from = appt_now();
    $to = $from->modify('+7 days');
    switch ($cal['type']) {
        case 'caldav':
            $busy = appt_caldav_busy($cal, $from, $to);
            return 'Connected to the CalDAV calendar at ' . $cal['calendar_url'] . '. It has ' . count($busy) . ' busy ' . (count($busy) === 1 ? 'time' : 'times') . ' in the next 7 days.';
        case 'microsoft':
            // No /me call: a personal account's token covers only the calendar (no User.Read) and Graph answers 403 to it.
            $busy = appt_ms_busy($cal, $from, $to);
            return 'Connected to the Microsoft calendar of ' . (($cal['account'] ?? '') !== '' ? $cal['account'] : 'the signed-in account')
                . (($cal['ms_calendar_name'] ?? '') !== '' ? ' (' . $cal['ms_calendar_name'] . ')' : '') . '. It has ' . count($busy) . ' busy ' . (count($busy) === 1 ? 'time' : 'times') . ' in the next 7 days.';
        case 'google':
            $info = appt_google_request($cal, 'GET', '/calendars/' . rawurlencode(appt_google_calendar_id($cal)));
            $busy = appt_google_busy($cal, $from, $to);
            return 'Connected to the Google calendar “' . ($info['summary'] ?? $cal['google_calendar_id']) . '”. It has ' . count($busy) . ' busy ' . (count($busy) === 1 ? 'time' : 'times') . ' in the next 7 days.';
    }
    return 'No calendar is connected; openings are checked only against bookings made on this website.';
}

/* =====================================================================
   Making, confirming and cancelling a booking
   ===================================================================== */

/**
 * Make the booking: check the time is still free, write the calendar event, save, email. Throws when it cannot.
 * With $moving (an existing booking), this is a change of time: the new booking is made first, and only then is
 * the old one released (its calendar event removed, its record marked moved) and the visitor and owner told once.
 */
function appt_book(array $service, DateTimeImmutable $start, array $visitor, ?array $moving = null): array
{
    $end = $start->modify('+' . $service['duration'] . ' minutes');
    $day = $start->setTime(0, 0);
    $busy = appt_busy($day, $day->modify('+1 day'), true, (string) ($moving['id'] ?? ''));
    $stillFree = false;
    foreach (appt_openings($service, $day, $busy) as $o) {
        if ($o->getTimestamp() === $start->getTimestamp()) {
            $stillFree = true;
            break;
        }
    }
    if (!$stillFree) {
        throw new RuntimeException(t('book_taken'));
    }
    $id = $start->format('Ymd') . '-' . date('His') . '-' . bin2hex(random_bytes(3));
    $b = [
        'id' => $id,
        'uid' => $id . '@' . site_host(),
        'created' => date('c'),
        'status' => 'booked',
        'service' => (string) $service['slug'],
        'service_title' => (string) $service['title'],
        'duration' => (int) $service['duration'],
        'price' => (string) ($service['price'] ?? ''),
        'location' => (string) ($service['location'] ?? ''),
        'start' => $start->format('c'),
        'end' => $end->format('c'),
        'name' => $visitor['name'],
        'email' => $visitor['email'],
        'phone' => (string) ($visitor['phone'] ?? ''),
        'notes' => (string) ($visitor['notes'] ?? ''),
        'sms_opt_in' => !empty($visitor['sms_opt_in']) && ($visitor['phone'] ?? '') !== '',
        'language' => $moving !== null ? (string) ($moving['language'] ?? current_lang()) : current_lang(),
        'calendar' => ['type' => 'none'],
        'emails' => [],
    ];
    if ($moving !== null) {
        $b['moved_from'] = ['id' => (string) $moving['id'], 'start' => (string) $moving['start'], 'end' => (string) $moving['end']];
    }
    if (!is_dir(APPT_DIR)) {
        mkdir(APPT_DIR, 0700, true);
    }
    $b['calendar'] = appt_calendar_create($b);
    appt_save_booking($b);
    @unlink(APPT_BUSY_CACHE);
    if ($moving !== null) {
        // The new time is confirmed: now release the old one, quietly (the "moved" emails say it all).
        $old = appt_booking((string) $moving['id']) ?? $moving;
        try {
            appt_calendar_delete($old);
        } catch (Throwable $e) {
            $b['calendar_problem'] = 'The old event may still be in the calendar: ' . $e->getMessage();
        }
        $old['status'] = 'cancelled';
        $old['cancelled'] = date('c');
        $old['cancelled_by'] = 'moved';
        $old['moved_to'] = $b['id'];
        appt_save_booking($old);
        appt_email_visitor($b, 'moved');
        appt_email_owner($b, 'moved');
    } else {
        appt_email_visitor($b, 'confirmed');
        appt_email_owner($b, 'new');
    }
    appt_save_booking($b);
    return $b;
}

/** Change the time of a booking: a new booking at $start for the same visitor, then the old one released. Throws when it cannot. */
function appt_move(array $old, DateTimeImmutable $start): array
{
    if (($old['status'] ?? '') !== 'booked') {
        throw new RuntimeException('That appointment is no longer booked.');
    }
    // The service as it is now; if it was deleted meanwhile, the booking's own copy of it.
    $service = appt_service((string) ($old['service'] ?? '')) ?? ['slug' => (string) ($old['service'] ?? ''), 'title' => (string) $old['service_title'],
        'duration' => (int) $old['duration'], 'price' => (string) ($old['price'] ?? ''), 'location' => (string) ($old['location'] ?? ''), 'status' => 'published'];
    return appt_book($service, $start, ['name' => $old['name'], 'email' => $old['email'], 'phone' => (string) ($old['phone'] ?? ''), 'notes' => (string) ($old['notes'] ?? ''),
        'sms_opt_in' => !empty($old['sms_opt_in'])], $old);
}

function appt_cancel(array $b, string $by): array
{
    if (($b['status'] ?? '') !== 'booked') {
        return $b;
    }
    $problem = '';
    try {
        appt_calendar_delete($b);
    } catch (Throwable $e) {
        $problem = $e->getMessage();
    }
    $b['status'] = 'cancelled';
    $b['cancelled'] = date('c');
    $b['cancelled_by'] = $by;
    if ($problem !== '') {
        $b['calendar_problem'] = 'The event may still be in the calendar: ' . $problem;
    }
    appt_save_booking($b);
    @unlink(APPT_BUSY_CACHE);
    appt_email_visitor($b, 'cancelled');
    if ($by === 'visitor') {
        appt_email_owner($b, 'cancelled');
    }
    appt_save_booking($b);
    return $b;
}

function appt_done_url(array $b): string
{
    return '/book/done/' . $b['id'] . '/?t=' . appt_token($b['id']);
}

function appt_cancel_url(array $b): string
{
    return '/book/cancel/' . $b['id'] . '/?t=' . appt_token($b['id']);
}

function appt_move_url(array $b): string
{
    return '/book/move/' . $b['id'] . '/?t=' . appt_token($b['id']);
}

function appt_ics_url(array $b): string
{
    return '/book/' . $b['id'] . '.ics?t=' . appt_token($b['id']);
}

/** Email the visitor about their booking; failures are noted on the booking, never shown as a crash. */
function appt_email_visitor(array &$b, string $kind): void
{
    $lang = (string) ($b['language'] ?: primary_lang());
    $t = fn(string $k) => t_in($lang, $k);
    $when = appt_when_words($b, $lang);
    $subjects = ['cancelled' => 'book_mail_cancelled_subject', 'reminder' => 'book_mail_reminder_subject', 'moved' => 'book_mail_moved_subject'];
    $bodies = ['cancelled' => 'book_mail_cancelled_body', 'reminder' => 'book_mail_reminder_body', 'moved' => 'book_mail_moved_body'];
    $subject = $t($subjects[$kind] ?? 'book_mail_confirmed_subject') . ': ' . $b['service_title'] . ', ' . appt_date_words(appt_start($b), $lang);
    $html = '<p>' . h($t('book_mail_hello')) . ' ' . h($b['name']) . ',</p>';
    $html .= '<p>' . h($t($bodies[$kind] ?? 'book_mail_confirmed_body')) . '</p>';
    $html .= '<p><strong>' . h($b['service_title']) . '</strong><br>' . h($when) . (($b['location'] ?? '') !== '' ? '<br>' . h((string) $b['location']) : '') . '</p>';
    if ($kind === 'moved' && !empty($b['moved_from']['start'])) {
        $html .= '<p>' . h(sprintf($t('book_moved_from'), appt_when_words(['start' => $b['moved_from']['start'], 'end' => $b['moved_from']['end']], $lang))) . '</p>';
    }
    if ($kind !== 'cancelled') {
        $after = appt_settings()['after'];
        if ($after !== '') {
            $html .= '<p>' . nl2br(h($after)) . '</p>';
        }
        $html .= '<p><a href="' . h(site_url(appt_ics_url($b))) . '">' . h($t('add_to_calendar')) . '</a><br>'
            . '<a href="' . h(site_url(appt_move_url($b))) . '">' . h($t('book_move_link')) . '</a><br>'
            . '<a href="' . h(site_url(appt_cancel_url($b))) . '">' . h($t('book_cancel_link')) . '</a></p>';
    }
    $html .= '<p>' . h(site_name()) . '<br>' . h(contact_words($lang)) . '</p>';
    try {
        send_mail_to([$b['email']], $subject, $html, contact_email() ?: null, site_name());
        $b['emails'][] = ['to' => 'visitor', 'kind' => $kind, 'at' => date('c'), 'ok' => true];
    } catch (Throwable $e) {
        $b['emails'][] = ['to' => 'visitor', 'kind' => $kind, 'at' => date('c'), 'ok' => false, 'error' => $e->getMessage()];
        error_log('Appointments: could not email ' . $b['email'] . ': ' . $e->getMessage());
    }
}

function appt_email_owner(array &$b, string $kind): void
{
    $subject = ['cancelled' => 'Cancelled appointment', 'moved' => 'Moved appointment'][$kind] ?? 'New appointment';
    $subject .= ': ' . $b['service_title'] . ' with ' . $b['name'] . ', ' . appt_start($b)->format('D M j, g:i a');
    $html = '<p>' . match ($kind) {
        'cancelled' => h($b['name']) . ' cancelled their appointment.',
        'moved' => h($b['name']) . ' moved their appointment' . (!empty($b['moved_from']['start']) ? ' from ' . h(appt_when_words(['start' => $b['moved_from']['start'], 'end' => $b['moved_from']['end']], primary_lang())) : '') . '. The old event was removed from the calendar and a new one made.',
        default => h($b['name']) . ' booked an appointment on ' . h(site_name()) . '.',
    } . '</p>'
        . '<p><strong>' . h($b['service_title']) . '</strong><br>' . h(appt_when_words($b, primary_lang())) . '</p>'
        . '<p>Name: ' . h($b['name']) . '<br>Email: <a href="mailto:' . h($b['email']) . '">' . h($b['email']) . '</a>'
        . (($b['phone'] ?? '') !== '' ? '<br>Phone: ' . h((string) $b['phone']) . (!empty($b['sms_opt_in']) ? ' (asked for a text reminder)' : '') : '') . '</p>'
        . (($b['notes'] ?? '') !== '' ? '<p>Notes:<br>' . nl2br(h((string) $b['notes'])) . '</p>' : '')
        . (($b['calendar']['type'] ?? 'none') === 'none' ? '<p>No calendar is connected, so this is only recorded on the website.</p>' : '')
        . (($b['calendar_problem'] ?? '') !== '' ? '<p>' . h((string) $b['calendar_problem']) . '</p>' : '')
        . '<p><a href="' . h(site_url('/admin/?a=appointments')) . '">Open the Appointments screen</a></p>';
    try {
        send_mail_owner($subject, $html, $b['email'], $b['name']);
        $b['emails'][] = ['to' => 'owner', 'kind' => $kind, 'at' => date('c'), 'ok' => true];
    } catch (Throwable $e) {
        $b['emails'][] = ['to' => 'owner', 'kind' => $kind, 'at' => date('c'), 'ok' => false, 'error' => $e->getMessage()];
        error_log('Appointments: could not email the owner: ' . $e->getMessage());
    }
}

/* =====================================================================
   The public pages
   ===================================================================== */

register_route(function (string $path, array $parts): bool {
    if (!feature_on('appointments') || ($parts[0] ?? '') !== 'book') {
        return false;
    }
    $rest = array_values(array_filter(array_slice($parts, 1), 'strlen'));
    if ($rest === []) {
        appt_services_page();
    }
    if (count($rest) === 2 && $rest[0] === 'done') {
        appt_done_page($rest[1]);
    }
    if (count($rest) === 2 && $rest[0] === 'cancel') {
        appt_cancel_page($rest[1]);
    }
    if (count($rest) === 2 && $rest[0] === 'move') {
        appt_move_page($rest[1]);
    }
    if (count($rest) === 1 && preg_match('/^([0-9]{8}-[0-9]{6}-[0-9a-f]{6})\.ics$/', $rest[0], $m)) {
        appt_ics_download($m[1]);
    }
    if (count($rest) === 1 && valid_slug($rest[0])) {
        appt_days_page($rest[0]);
    }
    if (count($rest) === 2 && valid_slug($rest[0]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rest[1])) {
        appt_times_page($rest[0], $rest[1]);
    }
    return false;
});

register_shortcode('[book]', fn(string $path) => feature_on('appointments') ? appt_services_html() : '');

function appt_page(string $title, string $html, string $path): never
{
    send_html(render_layout($title, $html, '', $path));
}

function appt_services_html(): string
{
    $services = appt_services();
    if (!$services) {
        return '<p>' . h(t('book_none')) . '</p>';
    }
    $out = '<ul class="service-list">';
    foreach ($services as $s) {
        $out .= '<li><h2><a href="/book/' . h($s['slug']) . '/">' . h((string) $s['title']) . '</a></h2>'
            . '<p class="service-meta">' . h(appt_length_words((int) $s['duration'])) . (($s['price'] ?? '') !== '' ? ' · ' . h((string) $s['price']) : '') . '</p>'
            . (($s['description'] ?? '') !== '' ? '<p>' . nl2br(h((string) $s['description'])) . '</p>' : '')
            . '<p><a class="button" href="/book/' . h($s['slug']) . '/">' . h(t('book_choose_time')) . '<span class="visually-hidden">: ' . h((string) $s['title']) . '</span></a></p></li>';
    }
    return $out . '</ul>';
}

function appt_length_words(int $minutes): string
{
    return $minutes . ' ' . t('book_minutes');
}

function appt_services_page(): never
{
    $services = appt_services();
    if (count($services) === 1) {
        header('Location: /book/' . $services[0]['slug'] . '/', true, 302);
        exit;
    }
    $intro = appt_settings()['intro'];
    appt_page(t('book_title'), '<h1>' . h(t('book_title')) . '</h1>' . ($intro !== '' ? '<p class="lede">' . nl2br(h($intro)) . '</p>' : '') . appt_services_html(), '/book/');
}

/**
 * The booking whose time is being changed, when the request carries ?move=<id>&t=<token> for this service;
 * null when it is an ordinary booking. A wrong id or token is a 404, like any other bad link.
 */
function appt_moving(string $serviceSlug): ?array
{
    $id = (string) ($_GET['move'] ?? $_POST['move'] ?? '');
    if ($id === '') {
        return null;
    }
    $b = appt_booking($id);
    if (!$b || !appt_token_ok($id, (string) ($_GET['t'] ?? $_POST['t'] ?? '')) || ($b['service'] ?? '') !== $serviceSlug) {
        not_found();
    }
    if (($b['status'] ?? '') !== 'booked') {
        header('Location: ' . appt_done_url(!empty($b['moved_to']) ? (appt_booking((string) $b['moved_to']) ?? $b) : $b), true, 303);
        exit;
    }
    if (($b['language'] ?? '') !== '' && $b['language'] !== current_lang()) {
        current_lang((string) $b['language']);
    }
    return $b;
}

/** "?move=<id>&t=<token>" to carry a change of time through the openings pages; '' for an ordinary booking. */
function appt_move_query(?array $moving): string
{
    return $moving ? '?move=' . $moving['id'] . '&t=' . appt_token((string) $moving['id']) : '';
}

/** The note at the top of the openings pages while a time is being changed. */
function appt_moving_note(array $moving): string
{
    return '<div class="notice" role="status"><p>' . h(sprintf(t('book_move_intro'), $moving['service_title'], appt_when_words($moving))) . '</p>'
        . '<p><a href="' . h(appt_done_url($moving)) . '">' . h(t('book_keep')) . '</a></p></div>';
}

/** /book/move/<id>/: start changing the time of a booking: the days with openings for its service. */
function appt_move_page(string $id): never
{
    $b = appt_load_visitor_booking($id);
    if (($b['status'] ?? '') !== 'booked') {
        header('Location: ' . appt_done_url(!empty($b['moved_to']) ? (appt_booking((string) $b['moved_to']) ?? $b) : $b), true, 303);
        exit;
    }
    header('Location: /book/' . rawurlencode((string) $b['service']) . '/' . appt_move_query($b), true, 303);
    exit;
}

function appt_days_page(string $slug): never
{
    $service = appt_service($slug);
    if (!$service || ($service['status'] ?? '') !== 'published') {
        not_found();
    }
    $moving = appt_moving($slug);
    $from = appt_now()->setTime(0, 0);
    if (isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['from'])) {
        try {
            $asked = new DateTimeImmutable((string) $_GET['from'], appt_zone());
            $from = max($from, $asked);
        } catch (Throwable) {
        }
    }
    [, $latest] = appt_window();
    $q = appt_move_query($moving);
    $html = '<h1>' . h($moving ? t('book_move_title') : (string) $service['title']) . '</h1>'
        . ($moving ? appt_moving_note($moving) : '')
        . '<p class="lede">' . h((string) $service['title']) . ': ' . h(appt_length_words((int) $service['duration'])) . (($service['price'] ?? '') !== '' ? ' · ' . h((string) $service['price']) : '') . '</p>'
        . (($service['description'] ?? '') !== '' && !$moving ? '<p>' . nl2br(h((string) $service['description'])) . '</p>' : '')
        . (($service['location'] ?? '') !== '' ? '<p>' . h((string) $service['location']) . '</p>' : '');
    try {
        $busy = appt_busy($from, $latest, false, (string) ($moving['id'] ?? ''));
        $days = appt_days($service, $from, $busy);
    } catch (Throwable $e) {
        error_log('Appointments: ' . $e->getMessage());
        appt_page((string) $service['title'], $html . '<p class="notice">' . h(t('book_unavailable')) . '</p>' . appt_back_link(), '/book/' . $slug . '/');
    }
    $html .= '<h2>' . h(t('book_pick_day')) . '</h2><p>' . h(sprintf(t('book_zone_note'), appt_zone_words(appt_now()))) . '</p>';
    if (!$days) {
        $html .= '<p>' . h(t('book_no_days')) . '</p>';
    } else {
        $html .= '<ul class="day-list">';
        foreach ($days as $date => $n) {
            $d = new DateTimeImmutable($date, appt_zone());
            $html .= '<li><a href="/book/' . h($slug) . '/' . $date . '/' . h($q) . '">' . h(appt_date_words($d)) . '</a> <span class="day-count">(' . $n . ' ' . h(t($n === 1 ? 'book_one_time' : 'book_times')) . ')</span></li>';
        }
        $html .= '</ul>';
        $last = new DateTimeImmutable(array_key_last($days), appt_zone());
        if (count($days) >= APPT_MAX_DAYS_SHOWN && $last->modify('+1 day') <= $latest) {
            $html .= '<p><a href="/book/' . h($slug) . '/?from=' . $last->modify('+1 day')->format('Y-m-d') . ($q !== '' ? '&' . substr($q, 1) : '') . '">' . h(t('book_later_days')) . '</a></p>';
        }
    }
    $html .= (count(appt_services()) > 1 && !$moving ? '<p><a href="/book/">' . h(t('book_other_services')) . '</a></p>' : '');
    appt_page((string) $service['title'], $html, '/book/' . $slug . '/');
}

function appt_back_link(): string
{
    return '<p><a href="' . h(settings()['contact_page'] ?: '/') . '">' . h(t('book_contact_instead')) . '</a></p>';
}

function appt_times_page(string $slug, string $date): never
{
    $service = appt_service($slug);
    if (!$service || ($service['status'] ?? '') !== 'published') {
        not_found();
    }
    try {
        $day = new DateTimeImmutable($date, appt_zone());
    } catch (Throwable) {
        not_found();
    }
    $path = '/book/' . $slug . '/' . $date . '/';
    $moving = appt_moving($slug);
    $q = appt_move_query($moving);
    $errors = [];
    $v = ['time' => '', 'name' => '', 'email' => '', 'phone' => '', 'notes' => '', 'sms_opt_in' => ''];
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['book_form'] ?? '') === '1') {
        [$errors, $v] = appt_handle_post($service, $day, $path, $moving);
    }
    try {
        $busy = appt_busy($day, $day->modify('+1 day'), false, (string) ($moving['id'] ?? ''));
        $openings = appt_openings($service, $day, $busy);
    } catch (Throwable $e) {
        error_log('Appointments: ' . $e->getMessage());
        appt_page((string) $service['title'], '<h1>' . h((string) $service['title']) . '</h1><p class="notice">' . h(t('book_unavailable')) . '</p>' . appt_back_link(), $path);
    }
    $title = ($moving ? t('book_move_title') . ': ' : (string) $service['title'] . ': ') . appt_date_words($day);
    $html = '<h1>' . h($title) . '</h1>' . ($moving ? appt_moving_note($moving) : '')
        . '<p class="lede">' . h((string) $service['title']) . ': ' . h(appt_length_words((int) $service['duration'])) . (($service['price'] ?? '') !== '' ? ' · ' . h((string) $service['price']) : '') . '</p>'
        . '<p><a href="/book/' . h($slug) . '/' . h($q) . '">' . h(t('book_other_day')) . '</a></p>';
    if (!$openings) {
        appt_page($title, $html . '<p>' . h(t('book_day_full')) . '</p>', $path);
    }
    if ($errors) {
        $html .= '<div id="book-status" class="error-summary" role="alert" tabindex="-1" data-focus-on-load><h2>' . h(t('form_error_title')) . '</h2><ul>';
        foreach ($errors as $field => $msg) {
            $html .= '<li><a href="#book-' . h($field) . ($field === 'time' ? '-0' : '') . '">' . h($msg) . '</a></li>';
        }
        $html .= '</ul></div>';
    }
    $field = function (string $name, string $label, string $type, bool $required, array $o = []) use ($errors, $v): string {
        $id = 'book-' . $name;
        $err = $errors[$name] ?? '';
        $describedBy = [];
        $extra = '';
        if (!empty($o['hint'])) {
            $extra .= '<p class="hint" id="' . $id . '-hint">' . h($o['hint']) . '</p>';
            $describedBy[] = $id . '-hint';
        }
        if ($err !== '') {
            $extra .= '<p class="error-message" id="' . $id . '-error">' . h($err) . '</p>';
            $describedBy[] = $id . '-error';
        }
        $attrs = ($required ? ' required' : '') . ($err !== '' ? ' aria-invalid="true"' : '') . ($describedBy ? ' aria-describedby="' . implode(' ', $describedBy) . '"' : '')
            . (isset($o['autocomplete']) ? ' autocomplete="' . $o['autocomplete'] . '"' : '');
        $control = $type === 'textarea'
            ? '<textarea id="' . $id . '" name="' . $name . '" rows="5"' . $attrs . '>' . h($v[$name]) . '</textarea>'
            : '<input id="' . $id . '" name="' . $name . '" type="' . $type . '" value="' . h($v[$name]) . '"' . $attrs . '>';
        return '<div class="field' . ($err !== '' ? ' field-error' : '') . '"><label for="' . $id . '">' . h($label) . ($required ? '' : ' ' . h(t('form_optional'))) . '</label>' . $extra . $control . '</div>';
    };
    $s = appt_settings();
    $timeErr = $errors['time'] ?? '';
    $html .= '<form method="post" action="' . h($path . $q) . '" class="book-form" novalidate>'
        . '<input type="hidden" name="book_form" value="1"><input type="hidden" name="form_token" value="' . h(contact_form_token()) . '">'
        . ($moving ? '<input type="hidden" name="move" value="' . h((string) $moving['id']) . '"><input type="hidden" name="t" value="' . h(appt_token((string) $moving['id'])) . '">' : '')
        . '<fieldset class="field slots' . ($timeErr !== '' ? ' field-error' : '') . '"' . ($timeErr !== '' ? ' aria-describedby="book-time-error"' : '') . '><legend>' . h(t('book_pick_time')) . '</legend>'
        . ($timeErr !== '' ? '<p class="error-message" id="book-time-error">' . h($timeErr) . '</p>' : '')
        . '<ul class="slot-list">';
    foreach ($openings as $i => $o) {
        $val = $o->format('H:i');
        $html .= '<li><input type="radio" id="book-time-' . $i . '" name="time" value="' . $val . '"' . ($v['time'] === $val ? ' checked' : '') . ' required>'
            . '<label for="book-time-' . $i . '">' . h(appt_time_words($o)) . '<span class="visually-hidden"> ' . h(t('book_to')) . ' ' . h(appt_time_words($o->modify('+' . $service['duration'] . ' minutes'))) . '</span></label></li>';
    }
    $html .= '</ul></fieldset>';
    if ($moving) {
        // The visitor's details come from the booking being moved; only the time is asked.
        $html .= '<p>' . h(t('contact_name')) . ': ' . h($moving['name']) . '<br>' . h(t('contact_email')) . ': ' . h($moving['email']) . '</p>'
            . '<div class="visually-hidden" aria-hidden="true"><label for="book-website">Leave this empty</label><input id="book-website" name="website" type="text" tabindex="-1" autocomplete="off"></div>'
            . '<button type="submit" class="button">' . h(t('book_move_confirm')) . '</button></form>';
        appt_page($title, $html, $path);
    }
    $smsBox = '';
    if ($s['ask_phone'] && $s['sms_reminders'] && function_exists('sms_enabled') && sms_enabled()) {
        $smsErr = $errors['sms_opt_in'] ?? '';
        $smsBox = '<div class="field checkbox' . ($smsErr !== '' ? ' field-error' : '') . '"><input type="checkbox" id="book-sms_opt_in" name="sms_opt_in" value="1"' . ($v['sms_opt_in'] !== '' ? ' checked' : '')
            . ($smsErr !== '' ? ' aria-invalid="true" aria-describedby="book-sms_opt_in-error"' : '') . '><label for="book-sms_opt_in">' . h(t('book_sms_opt_in')) . '</label>'
            . ($smsErr !== '' ? '<p class="error-message" id="book-sms_opt_in-error">' . h($smsErr) . '</p>' : '') . '</div>';
    }
    $html .= '<h2>' . h(t('book_your_details')) . '</h2>'
        . $field('name', t('contact_name'), 'text', true, ['autocomplete' => 'name'])
        . $field('email', t('contact_email'), 'email', true, ['autocomplete' => 'email', 'hint' => t('book_email_hint')])
        . ($s['ask_phone'] ? $field('phone', t('contact_phone'), 'tel', false, ['autocomplete' => 'tel']) : '')
        . $smsBox
        . $field('notes', $s['notes_label'] !== '' ? $s['notes_label'] : t('book_notes'), 'textarea', false)
        . '<div class="visually-hidden" aria-hidden="true"><label for="book-website">Leave this empty</label><input id="book-website" name="website" type="text" tabindex="-1" autocomplete="off"></div>'
        . '<button type="submit" class="button">' . h(t('book_confirm')) . '</button></form>';
    appt_page($title, $html, $path);
}

/** Check the booking form; on success make the booking (or move $moving to the new time) and go to the confirmation page. */
function appt_handle_post(array $service, DateTimeImmutable $day, string $path, ?array $moving = null): array
{
    $v = [];
    foreach (['time', 'name', 'email', 'phone', 'notes', 'sms_opt_in'] as $k) {
        $v[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    if (($_POST['website'] ?? '') !== '' || !contact_token_ok((string) ($_POST['form_token'] ?? ''))) {
        // A bot, or a form left open for more than a day: start over quietly.
        header('Location: ' . $path . appt_move_query($moving), true, 303);
        exit;
    }
    $errors = [];
    if (!appt_valid_clock($v['time'])) {
        $errors['time'] = t('book_err_time');
    }
    if ($moving !== null) {
        if (!$errors) {
            [$hh, $mm] = explode(':', $v['time']);
            $start = $day->setTime((int) $hh, (int) $mm);
            if ($start->getTimestamp() === appt_start($moving)->getTimestamp()) {
                return [['time' => t('book_move_same')], $v];
            }
            try {
                $b = appt_move($moving, $start);
            } catch (Throwable $e) {
                error_log('Appointments: move failed: ' . $e->getMessage());
                return [['time' => $e->getMessage() === t('book_taken') ? t('book_taken') : t('book_failed')], $v];
            }
            header('Location: ' . appt_done_url($b) . '#book-status', true, 303);
            exit;
        }
        return [$errors, $v];
    }
    if ($v['sms_opt_in'] !== '' && $v['phone'] === '') {
        $errors['sms_opt_in'] = t('book_err_sms_phone');
    }
    if ($v['name'] === '' || mb_strlen($v['name']) > 120) {
        $errors['name'] = sprintf(t('form_err_required'), t('contact_name'));
    }
    if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = sprintf(t('form_err_email'), t('contact_email'));
    }
    if ($v['phone'] !== '' && !preg_match('/^[0-9 ()+.-]{5,30}$/', $v['phone'])) {
        $errors['phone'] = sprintf(t('form_err_phone'), t('contact_phone'));
    }
    if (mb_strlen($v['notes']) > 2000) {
        $errors['notes'] = sprintf(t('form_err_long'), t('book_notes'));
    }
    if ($errors) {
        return [$errors, $v];
    }
    if (rate_limited(DATA_DIR . '/book-rate.json', hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . site_secret()), 5, 3600)) {
        return [['time' => t('book_err_many')], $v];
    }
    [$hh, $mm] = explode(':', $v['time']);
    $start = $day->setTime((int) $hh, (int) $mm);
    try {
        $b = appt_book($service, $start, $v);
    } catch (Throwable $e) {
        error_log('Appointments: booking failed: ' . $e->getMessage());
        return [['time' => $e->getMessage() === t('book_taken') ? t('book_taken') : t('book_failed')], $v];
    }
    header('Location: ' . appt_done_url($b) . '#book-status', true, 303);
    exit;
}

function appt_load_visitor_booking(string $id): array
{
    $b = appt_booking($id);
    if (!$b || !appt_token_ok($id, (string) ($_GET['t'] ?? $_POST['t'] ?? ''))) {
        not_found();
    }
    if (($b['language'] ?? '') !== '' && $b['language'] !== current_lang()) {
        current_lang((string) $b['language']);
    }
    return $b;
}

function appt_done_page(string $id): never
{
    $b = appt_load_visitor_booking($id);
    if (($b['status'] ?? '') === 'cancelled' && !empty($b['moved_to']) && ($new = appt_booking((string) $b['moved_to']))) {
        header('Location: ' . appt_done_url($new), true, 303); // an old link to a booking that was moved shows the new time
        exit;
    }
    $cancelled = ($b['status'] ?? '') === 'cancelled';
    $moved = !$cancelled && !empty($b['moved_from']);
    $after = appt_settings()['after'];
    $html = '<h1 id="book-status" tabindex="-1" data-focus-on-load>' . h($cancelled ? t('book_cancelled_title') : ($moved ? t('book_moved_title') : t('book_done_title'))) . '</h1>'
        . '<p class="lede">' . h($cancelled ? t('book_cancelled_body') : ($moved ? t('book_moved_body') : t('book_done_body'))) . '</p>'
        . '<dl class="event-details"><dt>' . h(t('book_what')) . '</dt><dd>' . h($b['service_title']) . ' (' . h(appt_length_words((int) $b['duration'])) . ')</dd>'
        . '<dt>' . h(t('book_when')) . '</dt><dd>' . h(appt_when_words($b)) . '</dd>'
        . (($b['location'] ?? '') !== '' ? '<dt>' . h(t('book_where')) . '</dt><dd>' . h((string) $b['location']) . '</dd>' : '')
        . '<dt>' . h(t('contact_name')) . '</dt><dd>' . h($b['name']) . '</dd></dl>';
    if (!$cancelled) {
        $html .= ($after !== '' ? '<p>' . nl2br(h($after)) . '</p>' : '')
            . '<p>' . h(sprintf(t('book_emailed'), $b['email'])) . '</p>'
            . '<p class="buttons"><a class="button button-outline" href="' . h(appt_ics_url($b)) . '">' . h(t('add_to_calendar')) . '</a> '
            . '<a class="button button-outline" href="' . h(appt_move_url($b)) . '">' . h(t('book_move_link')) . '</a> '
            . '<a class="button button-outline" href="' . h(appt_cancel_url($b)) . '">' . h(t('book_cancel_link')) . '</a></p>';
    }
    $html .= '<p><a href="/">' . h(t('book_home')) . '</a></p>';
    appt_page($cancelled ? t('book_cancelled_title') : ($moved ? t('book_moved_title') : t('book_done_title')), $html, '/book/done/' . $id . '/');
}

function appt_cancel_page(string $id): never
{
    $b = appt_load_visitor_booking($id);
    $path = appt_cancel_url($b);
    if (($b['status'] ?? '') === 'cancelled') {
        header('Location: ' . appt_done_url($b), true, 303);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['cancel'] ?? '') === '1') {
        appt_cancel($b, 'visitor');
        header('Location: ' . appt_done_url($b) . '#book-status', true, 303);
        exit;
    }
    $html = '<h1>' . h(t('book_cancel_title')) . '</h1>'
        . '<p>' . h(t('book_cancel_ask')) . '</p>'
        . '<p><strong>' . h($b['service_title']) . '</strong><br>' . h(appt_when_words($b)) . '</p>'
        . '<form method="post" action="' . h($path) . '"><input type="hidden" name="cancel" value="1"><input type="hidden" name="t" value="' . h(appt_token($id)) . '">'
        . '<p class="buttons"><button type="submit" class="button">' . h(t('book_cancel_yes')) . '</button> <a class="button button-outline" href="' . h(appt_done_url($b)) . '">' . h(t('book_cancel_no')) . '</a></p></form>';
    appt_page(t('book_cancel_title'), $html, '/book/cancel/' . $id . '/');
}

function appt_ics_download(string $id): never
{
    $b = appt_load_visitor_booking($id);
    $ics = appt_booking_ics($b, ($b['status'] ?? '') === 'cancelled' ? 'CANCEL' : 'PUBLISH');
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="appointment-' . $id . '.ics"');
    header('Content-Length: ' . strlen($ics));
    echo $ics;
    exit;
}

/* =====================================================================
   Reminders and the control panel hooks
   ===================================================================== */

register_cron('hourly', function (): void {
    if (!feature_on('appointments')) {
        return;
    }
    $s = appt_settings();
    $from = time() + 23 * 3600;
    $to = time() + 25 * 3600;
    $texts = $s['sms_reminders'] && function_exists('sms_enabled') && sms_enabled();
    foreach (appt_bookings() as $b) {
        if (($b['status'] ?? '') !== 'booked') {
            continue;
        }
        $start = strtotime((string) $b['start']);
        if ($s['reminders'] && empty($b['reminded']) && $start >= $from && $start <= $to) {
            $b['reminded'] = date('c');
            appt_email_visitor($b, 'reminder');
            appt_save_booking($b);
        }
        if ($texts && !empty($b['sms_opt_in']) && empty($b['sms_reminded']) && appt_sms_reminder_due($b, appt_now())) {
            $b['sms_reminded'] = date('c');
            $b['sms'][] = ['kind' => 'reminder', 'at' => date('c')] + appt_sms_send($b, appt_sms_reminder_text($b));
            appt_save_booking($b);
        }
    }
});

/** A text reminder goes the day before, in the daytime (9 am to 8 pm in the site's zone): true at the first hourly run that fits. */
function appt_sms_reminder_due(array $b, DateTimeImmutable $now): bool
{
    $start = appt_start($b);
    $hour = (int) $now->format('G');
    return $start->format('Y-m-d') === $now->modify('+1 day')->format('Y-m-d') && $hour >= 9 && $hour < 20;
}

/** "Hi Pat, a reminder of your Consultation with Example Site on Tue, Nov 25 at 2:00 pm. Reply STOP to stop texts." */
function appt_sms_reminder_text(array $b): string
{
    $lang = (string) ($b['language'] ?: primary_lang());
    $first = explode(' ', trim((string) $b['name']))[0] ?: (string) $b['name'];
    $start = appt_start($b);
    $when = $lang === 'es' ? $start->format('D j M, H:i') : $start->format('D, M j \a\t g:i a');
    return sprintf(t_in($lang, 'book_sms_reminder'), $first, $b['service_title'], site_name(), $when);
}

/** Text the visitor through lib/features/sms.php; the outcome is kept with the booking, never shown as a crash. */
function appt_sms_send(array $b, string $text): array
{
    $to = function_exists('sms_number') ? sms_number((string) ($b['phone'] ?? '')) : null;
    if ($to === null) {
        return ['ok' => false, 'error' => 'The phone number cannot receive texts.'];
    }
    $r = sms_send($to, $text);
    if (empty($r['ok'])) {
        error_log('Appointments: text to ' . $to . ' failed: ' . (string) ($r['error'] ?? ''));
    }
    return $r;
}

function appt_admin(): void
{
    require_once ROOT . '/admin/appointments.php';
}

register_admin_screen('appointments', function (bool $post): void {
    appt_admin();
    appointments_screen($post);
}, 'Appointments', 'appointments');

register_dashboard_card(function (): string {
    if (!feature_on('appointments')) {
        return '';
    }
    $now = date('c');
    $week = date('c', time() + 7 * 86400);
    $n = count(array_filter(appt_bookings(), fn($b) => ($b['status'] ?? '') === 'booked' && $b['start'] >= $now && $b['start'] <= $week));
    $cal = appt_calendar()['type'];
    return '<li><h2><a href="' . h(admin_url('appointments')) . '">Appointments</a></h2><p>' . ($n === 0 ? 'None booked for the next 7 days.' : $n . ' booked for the next 7 days.')
        . ($cal === 'none' ? ' No calendar is connected yet.' : '') . '</p></li>';
});

register_item_hook('before_save', function (string $type, string $slug, array &$data): void {
    if ($type === 'services') {
        $data['duration'] = max(5, min(480, (int) ($data['duration'] ?? 30)));
    }
});
