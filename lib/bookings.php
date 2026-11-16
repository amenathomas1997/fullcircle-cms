<?php
/**
 * [next-opening]: the next open time in the Microsoft Bookings calendar, read
 * through Microsoft Graph and checked at most every 30 minutes.
 * Needs the Bookings.Read.All application permission (with admin consent) on
 * the same Microsoft app as the contact form, and the Bookings page's address
 * in Settings. Until then it shows nothing.
 */

declare(strict_types=1);

const BOOKINGS_CACHE = DATA_DIR . '/cache/next-opening.json';


function bookings_configured(): bool
{
    return graph_app_configured() && (config()['bookings_business'] ?? '') !== '';
}

/** Turn a Graph date and its time zone label into a moment in time. */
function graph_datetime(array $dt): ?DateTimeImmutable
{
    $zone = (string) ($dt['timeZone'] ?? 'UTC');
    // Graph may send a display label ("Central Time (US & Canada)"), a Windows name ("Central Standard Time") or an IANA name.
    $named = ['Eastern Standard Time' => 'America/New_York', 'Eastern Time' => 'America/New_York',
        'Central Standard Time' => 'America/Chicago', 'Central Time' => 'America/Chicago',
        'Mountain Standard Time' => 'America/Denver', 'Mountain Time' => 'America/Denver',
        'US Mountain Standard Time' => 'America/Phoenix', 'Arizona' => 'America/Phoenix',
        'Pacific Standard Time' => 'America/Los_Angeles', 'Pacific Time' => 'America/Los_Angeles',
        'Alaskan Standard Time' => 'America/Anchorage', 'Alaska' => 'America/Anchorage',
        'Hawaiian Standard Time' => 'Pacific/Honolulu', 'Hawaii' => 'Pacific/Honolulu',
        'UTC' => 'UTC', 'GMT Standard Time' => 'Europe/London'];
    $tz = null;
    if (in_array($zone, DateTimeZone::listIdentifiers(), true)) {
        $tz = new DateTimeZone($zone);
    }
    foreach ($tz ? [] : $named as $label => $iana) {
        if ($label === 'UTC' ? strtoupper($zone) === 'UTC' : str_contains($zone, $label)) {
            $tz = new DateTimeZone($iana);
            break;
        }
    }
    if (!$tz && preg_match('/UTC([+-]\d{2}):(\d{2})/', $zone, $m)) {
        $tz = new DateTimeZone($m[1] . $m[2]);
    }
    if (!$tz) {
        // Better to show no opening than one at the wrong time.
        error_log('Bookings: unknown time zone "' . $zone . '" from Microsoft Graph; add it to graph_datetime().');
        return null;
    }
    try {
        return new DateTimeImmutable(substr((string) ($dt['dateTime'] ?? ''), 0, 19), $tz);
    } catch (Throwable) {
        return null;
    }
}

/** Ask Microsoft for the first time a session of the set length fits, after the set notice. */
function fetch_next_opening(): ?DateTimeImmutable
{
    $c = config();
    $business = rawurlencode((string) $c['bookings_business']);
    $notice = max(0, (int) ($c['bookings_notice_hours'] ?? 24));
    $length = max(15, (int) ($c['bookings_length'] ?? 60));
    $staff = graph_request('GET', "/solutions/bookingBusinesses/$business/staffMembers");
    $ids = array_values(array_filter(array_column($staff['value'] ?? [], 'id')));
    if (!$ids) {
        throw new RuntimeException('The Bookings calendar has no staff members.');
    }
    $utc = new DateTimeZone('UTC');
    $earliest = (new DateTimeImmutable('now', $utc))->modify("+$notice hours");
    $answer = graph_request('POST', "/solutions/bookingBusinesses/$business/getStaffAvailability", [
        'staffIds' => $ids,
        'startDateTime' => ['dateTime' => $earliest->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
        'endDateTime' => ['dateTime' => $earliest->modify('+21 days')->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
    ]);
    $best = null;
    foreach ($answer['staffAvailabilityItem'] ?? [] as $person) {
        foreach ($person['availabilityItems'] ?? [] as $item) {
            if (strtolower((string) ($item['status'] ?? '')) !== 'available') {
                continue;
            }
            $start = graph_datetime($item['startDateTime'] ?? []);
            $end = graph_datetime($item['endDateTime'] ?? []);
            if (!$start || !$end) {
                continue;
            }
            $start = max($start, $earliest);
            // Start on the hour or half hour, like the booking page.
            $minutes = (int) $start->format('i');
            if ($minutes % 30 !== 0 || (int) $start->format('s') !== 0) {
                $start = $start->setTime((int) $start->format('H'), $minutes < 30 ? 30 : 0)->modify($minutes < 30 ? '+0 minutes' : '+1 hour');
            }
            if ($start->modify("+$length minutes") <= $end && ($best === null || $start < $best)) {
                $best = $start;
            }
        }
    }
    return $best;
}

/** The cached answer: ['time' => ISO or null, 'checked' => unix time, 'error' => text]. */
function next_opening_status(bool $refresh = false): array
{
    $cache = read_json(BOOKINGS_CACHE);
    if (!$refresh && $cache && ($cache['checked'] ?? 0) > time() - 1800) {
        return $cache;
    }
    $status = ['time' => null, 'checked' => time(), 'error' => ''];
    try {
        $next = fetch_next_opening();
        $status['time'] = $next?->format('c');
    } catch (Throwable $e) {
        $status['error'] = $e->getMessage();
        // Keep showing the last known opening (if it's still ahead) rather than nothing.
        $last = (string) ($cache['time'] ?? '');
        if ($last !== '' && strtotime($last) > time()) {
            $status['time'] = $last;
        }
    }
    try {
        write_json(BOOKINGS_CACHE, $status);
    } catch (Throwable) {
    }
    return $status;
}

function render_next_opening(): string
{
    if (!bookings_configured()) {
        return '';
    }
    $status = next_opening_status();
    if (empty($status['time'])) {
        return '';
    }
    $t = (new DateTimeImmutable($status['time']))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    $es = current_lang() === 'es';
    if ($es) {
        $days = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        $when = $days[(int) $t->format('w')] . ' ' . $t->format('j') . ' de ' . $months[(int) $t->format('n') - 1] . ' a las ' . $t->format('g:i a') . ' (' . $t->format('T') . ')';
        return '<p class="next-opening"><strong>Próximo horario disponible:</strong> ' . h($when) . '. Reserve abajo o ' . contact_html('es') . '.</p>';
    }
    $when = $t->format('l, F j \a\t g:i a T');
    return '<p class="next-opening"><strong>Next opening:</strong> ' . h($when) . '. Book it below, or ' . contact_html('en') . '.</p>';
}
