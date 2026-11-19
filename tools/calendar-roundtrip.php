<?php
/**
 * Live calendar round trip, from the command line on a site whose calendar is connected:
 *
 *   php tools/calendar-roundtrip.php            run it
 *   php tools/calendar-roundtrip.php --at "2026-12-03 03:00"   choose the test time (site time zone)
 *
 * Whichever calendar kind is connected (CalDAV, Microsoft or Google), it
 *   1. runs the same connection test as the Appointments screen;
 *   2. makes one short event, "Full Circle CMS round trip", 15 minutes long, at 3 am two days from now
 *      (or --at), when nobody is likely to be booking;
 *   3. reads the event back from the calendar by its id or address;
 *   4. asks the calendar for its busy times over that hour and checks the event is among them;
 *   5. deletes the event;
 *   6. reads the busy times again and checks the event is gone.
 * Each step prints ok or FAIL with the reason; the exit code is 1 when anything failed. If a step
 * after 2 fails, the tool still tries to delete the event so nothing is left in the calendar.
 * Nothing is written to data/appointments/ and no emails are sent.
 *
 * GOOGLE CALENDAR, before running this the first time (no Google account or key is made by the kit):
 *   a. In Google Cloud Console (console.cloud.google.com) make a project, or pick one.
 *   b. APIs and services, Library: turn on the "Google Calendar API".
 *   c. IAM and admin, Service accounts: make a service account (any name). Open it, Keys, Add key,
 *      Create new key, JSON. A .json file downloads: that is the key.
 *   d. In Google Calendar (calendar.google.com), open the calendar's Settings and sharing,
 *      "Share with specific people or groups", Add people, and paste the service account's email
 *      address (it ends in .iam.gserviceaccount.com) with the permission "Make changes to events".
 *   e. In the control panel, Appointments, Calendar: choose "A Google Calendar", paste the whole
 *      key file into "Service account key (JSON)", enter the calendar ID (the Gmail address, or the
 *      ID under "Integrate calendar" for a secondary calendar), and save.
 *   f. Run this tool. The first run proves the share and the key work end to end.
 * For Microsoft, connect the calendar on the Appointments screen first (it signs in once). For
 * CalDAV, save the server, user name and password there and press "Find calendars".
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this from the command line.');
}
require __DIR__ . '/../lib/bootstrap.php';

$opts = getopt('', ['at:']);
$failed = 0;
$say = function (string $step, bool $ok, string $detail = '') use (&$failed): void {
    echo $ok ? 'ok   ' : 'FAIL ', $step, $detail !== '' ? ' — ' . $detail : '', "\n";
    if (!$ok) {
        $failed++;
    }
};

$cal = appt_calendar();
echo 'Calendar kind: ' . APPT_CALENDAR_TYPES[$cal['type']] . "\n";
if ($cal['type'] === 'none') {
    echo "No calendar is connected. Connect one under Appointments, Calendar, then run this again.\n";
    exit(1);
}

// 1. The connection test.
try {
    $say('connection test', true, appt_calendar_test());
} catch (Throwable $e) {
    $say('connection test', false, $e->getMessage());
    exit(1);
}

// The test event: 15 minutes at 3 am two days from now, or --at.
$zone = appt_zone();
try {
    $start = isset($opts['at']) ? new DateTimeImmutable((string) $opts['at'], $zone) : appt_now()->modify('+2 days')->setTime(3, 0);
} catch (Throwable $e) {
    echo "The --at time could not be read: use a form like 2026-12-03 03:00.\n";
    exit(1);
}
$end = $start->modify('+15 minutes');
$id = $start->format('Ymd') . '-' . date('His') . '-' . bin2hex(random_bytes(3));
$booking = ['id' => $id, 'uid' => $id . '@' . site_host(), 'start' => $start->format('c'), 'end' => $end->format('c'), 'service_title' => 'Full Circle CMS round trip',
    'duration' => 15, 'name' => 'Round trip test', 'email' => contact_email() ?: 'test@example.com', 'phone' => '', 'notes' => 'Made by tools/calendar-roundtrip.php; it deletes itself.',
    'location' => '', 'status' => 'booked', 'language' => 'en'];
echo 'Test event: ' . appt_when_words($booking, 'en') . "\n";

// 2. Make it.
$ref = null;
try {
    $ref = appt_calendar_create($booking);
    $say('event made', ($ref['event_id'] ?? $ref['href'] ?? '') !== '', (string) ($ref['event_id'] ?? $ref['href'] ?? ''));
} catch (Throwable $e) {
    $say('event made', false, $e->getMessage());
    exit(1);
}

$busyHas = function () use ($start, $end, $cal): bool {
    $from = $start->modify('-30 minutes');
    $to = $end->modify('+30 minutes');
    $found = match ($cal['type']) {
        'caldav' => appt_caldav_busy($cal, $from, $to),
        'microsoft' => appt_ms_busy($cal, $from, $to),
        'google' => appt_google_busy($cal, $from, $to),
    };
    foreach ($found as [$s, $e]) {
        if ($s->getTimestamp() === $start->getTimestamp() && $e->getTimestamp() === $end->getTimestamp()) {
            return true;
        }
    }
    return false;
};

// 3. Read it back.
try {
    $read = match ($cal['type']) {
        'microsoft' => (string) (appt_ms_request($cal, 'GET', '/me/events/' . rawurlencode((string) $ref['event_id']) . '?$select=subject')['subject'] ?? ''),
        'google' => (string) (appt_google_request($cal, 'GET', '/calendars/' . rawurlencode(appt_google_calendar_id($cal)) . '/events/' . rawurlencode((string) $ref['event_id']))['summary'] ?? ''),
        'caldav' => (function () use ($cal, $ref): string {
            [$status, $body] = appt_http('GET', (string) $ref['href'], [], '', appt_caldav_auth($cal));
            return $status === 200 && str_contains($body, 'Full Circle CMS round trip') ? 'Full Circle CMS round trip' : "status $status";
        })(),
    };
    $say('event read back', str_contains($read, 'Full Circle CMS round trip'), $read);
} catch (Throwable $e) {
    $say('event read back', false, $e->getMessage());
}

// 4. The busy check sees it.
try {
    $say('busy check sees the event', $busyHas());
} catch (Throwable $e) {
    $say('busy check sees the event', false, $e->getMessage());
}

// 5. Delete it.
try {
    match ($cal['type']) {
        'caldav' => appt_caldav_delete($cal, $ref),
        'microsoft' => appt_ms_delete($cal, $ref),
        'google' => appt_google_delete($cal, $ref),
    };
    $say('event deleted', true);
} catch (Throwable $e) {
    $say('event deleted', false, $e->getMessage() . ' — remove "Full Circle CMS round trip" from the calendar by hand.');
}

// 6. It is gone.
try {
    sleep(2); // some services take a moment to drop a deleted event from busy times
    $say('busy check no longer sees the event', !$busyHas());
} catch (Throwable $e) {
    $say('busy check no longer sees the event', false, $e->getMessage());
}

echo $failed === 0 ? "\nThe calendar round trip passed.\n" : "\n$failed step(s) failed.\n";
exit($failed === 0 ? 0 : 1);
