<?php
/**
 * Events: classes, workshops and meetings with dates, a place or an online link,
 * and a simple repeat rule. Items of type "events" in content/events/.
 *   /events/                 upcoming events by month, past ones folded away
 *   /events/<slug>/          one event, with an "Add to my calendar" link
 *   /events/<slug>.ics       that event as an iCalendar file
 *   /events/feed.ics         every published event, for calendar subscriptions
 *   /es/eventos/ ...         the same in Spanish (when the Spanish feature is on)
 *   [upcoming-events]        the next five, with a link to all; [events-list] the whole list
 * Occurrences of repeating events are worked out on the fly (at most 52 per event).
 */

declare(strict_types=1);

const EVENT_MAX_OCCURRENCES = 52;
const EVENT_REPEATS = ['none' => 'Does not repeat', 'weekly' => 'Every week on the same day', 'monthly' => 'Every month on the same date'];

register_feature('events', 'Events', 'An events page at /events/ with a calendar feed, one page per event with an “Add to my calendar” link, and the [upcoming-events] shortcode.');

register_route(function (string $path, array $parts): bool {
    if (!feature_on('events')) {
        return false;
    }
    $first = $parts[0] ?? '';
    if ($first === 'es' && ($parts[1] ?? '') === 'eventos' && feature_on('spanish')) {
        current_lang('es');
        $rest = array_slice($parts, 2);
    } elseif ($first === 'events') {
        $rest = array_slice($parts, 1);
    } else {
        return false;
    }
    if ($rest === []) {
        events_index();
    }
    if (count($rest) === 1 && $rest[0] === 'feed.ics') {
        events_feed();
    }
    if (count($rest) === 1 && preg_match('/^([a-z0-9-]+)\.ics$/', $rest[0], $m) && valid_slug($m[1])) {
        event_ics_download($m[1]);
    }
    if (count($rest) === 1 && valid_slug($rest[0])) {
        event_page($rest[0]);
    }
    not_found();
});

register_shortcode('[upcoming-events]', fn(string $path) => feature_on('events') ? upcoming_events_html(5) : '');
register_shortcode('[events-list]', fn(string $path) => feature_on('events') ? events_list_html() : '');
register_admin_screen('events', fn(bool $post) => $post ? events_admin_post() : events_admin_get(), 'Events', 'events');
register_dashboard_card(fn() => feature_on('events') ? events_dashboard_card() : '');

/* =====================================================================
   Dates and occurrences
   ===================================================================== */

/** The web address of the events list in a language. */
function events_base(?string $lang = null): string
{
    return ($lang ?? current_lang()) === 'es' && feature_on('spanish') ? '/es/eventos/' : '/events/';
}

function event_url(array $event, ?string $lang = null): string
{
    return events_base($lang) . $event['slug'] . '/';
}

/** Published events (and drafts for someone signed in to the control panel, so they can preview). */
function events_visible(): array
{
    $preview = function_exists('can_preview') && can_preview();
    return array_values(array_filter(all_items('events'), fn($e) => ($e['status'] ?? '') === 'published' || $preview));
}

/**
 * Every time an event happens, as ['start' => DateTimeImmutable, 'end' => ?DateTimeImmutable, 'all_day' => bool, 'event' => array],
 * in the site's time zone. Repeats are generated until the "until" date, or 52 times when there is none.
 */
function event_occurrences(array $e): array
{
    $allDay = !empty($e['all_day']);
    $startDate = (string) ($e['start_date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
        return [];
    }
    $startTime = $allDay ? '00:00' : (string) ($e['start_time'] ?: '00:00');
    try {
        $start = new DateTimeImmutable("$startDate $startTime");
    } catch (Exception) {
        return [];
    }
    $end = null;
    $endDate = (string) ($e['end_date'] ?? '');
    $endTime = (string) ($e['end_time'] ?? '');
    if ($endDate !== '' || ($endTime !== '' && !$allDay)) {
        try {
            $end = new DateTimeImmutable(($endDate !== '' ? $endDate : $startDate) . ' ' . ($allDay ? '00:00' : ($endTime !== '' ? $endTime : $startTime)));
        } catch (Exception) {
            $end = null;
        }
        if ($end !== null && $end < $start) {
            $end = null;
        }
    }
    $repeat = (string) ($e['repeat'] ?? 'none');
    $until = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($e['repeat_until'] ?? '')) ? new DateTimeImmutable($e['repeat_until'] . ' 23:59:59') : null;
    $occurrences = [];
    $day = (int) $start->format('j');
    for ($n = 0; count($occurrences) < EVENT_MAX_OCCURRENCES && $n < EVENT_MAX_OCCURRENCES * 3; $n++) {
        if ($repeat === 'weekly') {
            $when = $start->modify("+" . ($n * 7) . " days");
        } elseif ($repeat === 'monthly') {
            $month = (int) $start->format('n') + $n;
            $year = (int) $start->format('Y') + intdiv($month - 1, 12);
            $month = ($month - 1) % 12 + 1;
            if (!checkdate($month, $day, $year)) {
                continue;
            }
            $when = $start->setDate($year, $month, $day);
        } else {
            $when = $start;
        }
        if ($until !== null && $when > $until) {
            break;
        }
        $days = (int) $start->diff($when)->days;
        $occurrences[] = ['start' => $when, 'end' => $days > 0 ? $end?->modify("+$days days") : $end, 'all_day' => $allDay, 'event' => $e];
        if ($repeat !== 'weekly' && $repeat !== 'monthly') {
            break;
        }
    }
    return $occurrences;
}

/** When an occurrence is over: its end, or the end of its last day. */
function occurrence_over(array $o): DateTimeImmutable
{
    if ($o['all_day']) {
        return ($o['end'] ?? $o['start'])->setTime(23, 59, 59);
    }
    return $o['end'] ?? $o['start']->setTime(23, 59, 59);
}

/** Every occurrence of every visible event, soonest first. */
function all_occurrences(): array
{
    $all = [];
    foreach (events_visible() as $e) {
        foreach (event_occurrences($e) as $o) {
            $all[] = $o;
        }
    }
    usort($all, fn($a, $b) => $a['start'] <=> $b['start']);
    return $all;
}

function upcoming_occurrences(?int $limit = null): array
{
    $now = new DateTimeImmutable();
    $list = array_values(array_filter(all_occurrences(), fn($o) => occurrence_over($o) >= $now));
    return $limit !== null ? array_slice($list, 0, $limit) : $list;
}

/** "Saturday, November 21, 2026" or "sábado, 21 de noviembre de 2026". */
function event_date_words(DateTimeImmutable $d, bool $withYear = true): string
{
    $weekday = t('weekday_' . $d->format('w'));
    $month = t('month_' . $d->format('n'));
    if (current_lang() === 'es') {
        return $weekday . ', ' . $d->format('j') . ' de ' . $month . ($withYear ? ' de ' . $d->format('Y') : '');
    }
    return $weekday . ', ' . $month . ' ' . $d->format('j') . ($withYear ? ', ' . $d->format('Y') : '');
}

function event_time_words(DateTimeImmutable $d): string
{
    return current_lang() === 'es' ? $d->format('H:i') : $d->format('g:i a');
}

/** "November 2026" or "noviembre de 2026", for the month headings. */
function event_month_words(DateTimeImmutable $d): string
{
    return current_lang() === 'es' ? t('month_' . $d->format('n')) . ' de ' . $d->format('Y') : t('month_' . $d->format('n')) . ' ' . $d->format('Y');
}

/** One occurrence's date and time in words, wrapped in <time>. */
function event_when_html(array $o): string
{
    $s = $o['start'];
    $e = $o['end'];
    $to = ' ' . t('time_to') . ' ';
    if ($o['all_day']) {
        $text = $e !== null && $e->format('Y-m-d') !== $s->format('Y-m-d')
            ? event_date_words($s, $e->format('Y') !== $s->format('Y')) . $to . event_date_words($e)
            : event_date_words($s);
        return '<time datetime="' . $s->format('Y-m-d') . '">' . h($text) . '</time> (' . h(t('all_day')) . ')';
    }
    $text = event_date_words($s) . ', ' . event_time_words($s);
    if ($e !== null) {
        $text .= $to . ($e->format('Y-m-d') === $s->format('Y-m-d') ? event_time_words($e) : event_date_words($e) . ', ' . event_time_words($e));
    }
    return '<time datetime="' . $s->format('Y-m-d\TH:i') . '">' . h($text) . '</time>';
}

/* =====================================================================
   Public pages
   ===================================================================== */

function event_place_html(array $e): string
{
    $out = '';
    if (($e['location'] ?? '') !== '') {
        $out .= '<dt>' . h(t('event_where')) . '</dt><dd>' . nl2br(h((string) $e['location'])) . '</dd>';
    }
    if (($e['online_url'] ?? '') !== '') {
        $out .= '<dt>' . h(t('event_online')) . '</dt><dd><a href="' . h((string) $e['online_url']) . '">' . h(t('join_online')) . '</a></dd>';
    }
    return $out;
}

function events_list_html(): string
{
    $now = new DateTimeImmutable();
    $upcoming = [];
    $past = [];
    foreach (all_occurrences() as $o) {
        occurrence_over($o) >= $now ? $upcoming[] = $o : $past[] = $o;
    }
    $grouped = function (array $list): string {
        $out = '';
        $month = '';
        foreach ($list as $o) {
            $m = $o['start']->format('Y-m');
            if ($m !== $month) {
                $out .= ($month !== '' ? '</ul>' : '') . '<h3>' . h(event_month_words($o['start'])) . '</h3><ul class="post-list">';
                $month = $m;
            }
            $e = $o['event'];
            $out .= '<li><article><h4><a href="' . h(event_url($e)) . '">' . h((string) $e['title']) . '</a></h4>'
                . '<p class="post-date">' . event_when_html($o) . '</p>'
                . (($e['location'] ?? '') !== '' ? '<p>' . h((string) $e['location']) . '</p>' : (($e['online_url'] ?? '') !== '' ? '<p>' . h(t('event_online')) . '</p>' : ''))
                . '</article></li>';
        }
        return $out . ($month !== '' ? '</ul>' : '');
    };
    $html = '<section aria-labelledby="upcoming-heading"><h2 id="upcoming-heading">' . h(t('upcoming_events')) . '</h2>'
        . ($upcoming ? $grouped($upcoming) : '<p>' . h(t('no_upcoming_events')) . '</p>')
        . '<p><a href="' . h(events_base('en')) . 'feed.ics">' . h(t('events_feed')) . '</a></p></section>';
    if ($past) {
        $html .= '<details><summary>' . h(t('past_events')) . ' (' . count($past) . ')</summary>' . $grouped(array_slice(array_reverse($past), 0, 50)) . '</details>';
    }
    return $html;
}

function events_index(): never
{
    $other = feature_on('spanish') ? (current_lang() === 'es' ? '/events/' : '/es/eventos/') : null;
    send_html(render_layout(t('events'), '<h1>' . h(t('events')) . '</h1>' . events_list_html(), '', events_base(), $other));
}

function event_page(string $slug): never
{
    $e = get_item('events', $slug);
    if (!$e || (($e['status'] ?? '') !== 'published' && !can_preview())) {
        not_found();
    }
    $occurrences = event_occurrences($e);
    $now = new DateTimeImmutable();
    $next = array_values(array_filter($occurrences, fn($o) => occurrence_over($o) >= $now));
    $shown = $next ?: array_slice($occurrences, -1);
    $when = $shown ? '<p>' . event_when_html($shown[0]) . '</p>' : '';
    if (count($shown) > 1) {
        $when .= '<p>' . h(t('next_dates')) . ':</p><ul>';
        foreach (array_slice($shown, 1, 4) as $o) {
            $when .= '<li>' . event_when_html($o) . '</li>';
        }
        $when .= '</ul>';
    }
    $repeat = match ((string) ($e['repeat'] ?? 'none')) {
        'weekly' => t('event_repeats_weekly'),
        'monthly' => t('event_repeats_monthly'),
        default => '',
    };
    if ($repeat !== '' && ($e['repeat_until'] ?? '') !== '') {
        $repeat .= ' ' . t('event_until') . ' ' . event_date_words(new DateTimeImmutable((string) $e['repeat_until']));
    }
    $content = '<h1>' . h((string) $e['title']) . '</h1>'
        . '<dl class="event-details"><dt>' . h(t('event_when')) . '</dt><dd>' . $when . ($repeat !== '' ? '<p>' . h($repeat) . '.</p>' : '') . '</dd>'
        . event_place_html($e) . '</dl>'
        . '<p><a class="button button-outline" href="' . h('/events/' . $slug . '.ics') . '">' . h(t('add_to_calendar')) . '</a></p>'
        . (string) ($e['body'] ?? '')
        . '<p><a href="' . h(events_base()) . '">' . h(t('back_to_events')) . '</a></p>';
    $other = feature_on('spanish') ? event_url($e, current_lang() === 'es' ? 'en' : 'es') : null;
    send_html(render_layout((string) $e['title'], $content, mb_substr(trim(plain_text((string) ($e['body'] ?? ''))), 0, 160), event_url($e), $other));
}

/** The next few events, for the [upcoming-events] shortcode. */
function upcoming_events_html(int $limit): string
{
    $list = upcoming_occurrences($limit);
    if (!$list) {
        return '<p>' . h(t('no_upcoming_events')) . '</p>';
    }
    $out = '<ul class="post-list upcoming-events">';
    foreach ($list as $o) {
        $e = $o['event'];
        $out .= '<li><article><h3><a href="' . h(event_url($e)) . '">' . h((string) $e['title']) . '</a></h3><p class="post-date">' . event_when_html($o) . '</p></article></li>';
    }
    return $out . '</ul><p><a href="' . h(events_base()) . '">' . h(t('all_events')) . '</a></p>';
}

/* =====================================================================
   iCalendar
   ===================================================================== */

/** Text for an iCalendar property: backslashes, commas, semicolons and line breaks escaped. */
function ics_text(string $s): string
{
    return str_replace(["\\", ';', ',', "\r\n", "\n", "\r"], ["\\\\", '\;', '\,', '\n', '\n', '\n'], $s);
}

/** Lines longer than 75 bytes continue on the next line after a space (RFC 5545 folding). */
function ics_fold(string $line): string
{
    $out = '';
    while (strlen($line) > 75) {
        $cut = 75;
        // Never split inside a multi-byte character.
        while ($cut > 1 && (ord($line[$cut]) & 0xC0) === 0x80) {
            $cut--;
        }
        $out .= substr($line, 0, $cut) . "\r\n ";
        $line = substr($line, $cut);
        // Continuation lines hold 74 bytes plus the leading space.
        while (strlen($line) > 74) {
            $cut = 74;
            while ($cut > 1 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }
            $out .= substr($line, 0, $cut) . "\r\n ";
            $line = substr($line, $cut);
        }
    }
    return $out . $line;
}

function ics_utc(DateTimeImmutable $d): string
{
    return $d->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
}

/** One VEVENT per occurrence, so dates stay right across daylight-saving changes. */
function event_vevents(array $e): string
{
    $out = '';
    $stamp = ics_utc(new DateTimeImmutable((string) ($e['updated'] ?? 'now')));
    $description = trim(plain_text((string) ($e['body'] ?? '')));
    foreach (event_occurrences($e) as $o) {
        $lines = ['BEGIN:VEVENT',
            'UID:' . $e['slug'] . '-' . $o['start']->format('Ymd\THis') . '@' . site_host(),
            'DTSTAMP:' . $stamp];
        if ($o['all_day']) {
            $lines[] = 'DTSTART;VALUE=DATE:' . $o['start']->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:' . ($o['end'] ?? $o['start'])->modify('+1 day')->format('Ymd');
        } else {
            $lines[] = 'DTSTART:' . ics_utc($o['start']);
            $lines[] = 'DTEND:' . ics_utc($o['end'] ?? $o['start']->modify('+1 hour'));
        }
        $lines[] = 'SUMMARY:' . ics_text((string) $e['title']);
        if ($description !== '') {
            $lines[] = 'DESCRIPTION:' . ics_text($description);
        }
        if (($e['location'] ?? '') !== '') {
            $lines[] = 'LOCATION:' . ics_text((string) $e['location'] . (($e['online_url'] ?? '') !== '' ? "\n" . $e['online_url'] : ''));
        } elseif (($e['online_url'] ?? '') !== '') {
            $lines[] = 'LOCATION:' . ics_text((string) $e['online_url']);
        }
        $lines[] = 'URL:' . ics_text(site_url('/events/' . $e['slug'] . '/'));
        $lines[] = 'END:VEVENT';
        $out .= implode("\r\n", array_map('ics_fold', $lines)) . "\r\n";
    }
    return $out;
}

function events_ics(array $events, string $name): string
{
    $head = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Full Circle CMS//Events//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'X-WR-CALNAME:' . ics_text($name)];
    $body = '';
    foreach ($events as $e) {
        $body .= event_vevents($e);
    }
    return implode("\r\n", array_map('ics_fold', $head)) . "\r\n" . $body . "END:VCALENDAR\r\n";
}

function send_ics(string $ics, string $filename): never
{
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $ics;
    exit;
}

function events_feed(): never
{
    $published = array_values(array_filter(all_items('events'), fn($e) => ($e['status'] ?? '') === 'published'));
    send_ics(events_ics($published, site_name() . ' – ' . t('events')), 'events.ics');
}

function event_ics_download(string $slug): never
{
    $e = get_item('events', $slug);
    if (!$e || (($e['status'] ?? '') !== 'published' && !can_preview())) {
        not_found();
    }
    send_ics(events_ics([$e], (string) $e['title']), $slug . '.ics');
}

/* =====================================================================
   Control panel: Events
   ===================================================================== */

function events_dashboard_card(): string
{
    $events = all_items('events');
    $upcoming = count(array_unique(array_map(fn($o) => $o['event']['slug'], array_filter(upcoming_occurrences(), fn($o) => ($o['event']['status'] ?? '') === 'published'))));
    return '<li><h2><a href="' . h(admin_url('events')) . '">Events</a></h2><p>' . count($events) . ' ' . (count($events) === 1 ? 'event' : 'events') . ", $upcoming coming up.</p></li>";
}

function events_admin_get(): never
{
    if (isset($_GET['delete'])) {
        event_delete_confirm((string) $_GET['delete']);
    }
    if (isset($_GET['slug']) || isset($_GET['new'])) {
        event_form();
    }
    events_list();
}

function events_admin_post(): never
{
    if (isset($_GET['delete'])) {
        event_delete((string) $_GET['delete']);
    }
    event_save();
}

function events_list(): never
{
    $now = new DateTimeImmutable();
    $rows = [];
    foreach (all_items('events') as $e) {
        $next = null;
        $last = null;
        foreach (event_occurrences($e) as $o) {
            $last = $o;
            if ($next === null && occurrence_over($o) >= $now) {
                $next = $o;
            }
        }
        $pick = $next ?? $last;
        $sortKey = $pick !== null ? $pick['start']->format('Y-m-d H:i') : '';
        $title = h((string) $e['title']);
        $rows[] = [$next !== null ? '0' . $sortKey : '1' . $sortKey, '<tr><th scope="row">' . $title . '</th>'
            . '<td>' . ($next !== null ? event_when_html($next) : ($last !== null ? 'Past (' . event_when_html($last) . ')' : 'No date')) . '</td>'
            . '<td>' . h(EVENT_REPEATS[$e['repeat'] ?? 'none'] ?? 'Does not repeat') . '</td><td>' . status_label($e) . '</td>'
            . '<td class="actions"><a href="' . h(admin_url('events', ['slug' => $e['slug']])) . '">Edit<span class="visually-hidden"> ' . $title . '</span></a>'
            . ' <a href="/events/' . h($e['slug']) . '/">View<span class="visually-hidden"> ' . $title . '</span></a>'
            . ' <a href="' . h(admin_url('events', ['delete' => $e['slug']])) . '">Delete<span class="visually-hidden"> ' . $title . '</span></a></td></tr>'];
    }
    usort($rows, fn($a, $b) => strcmp($a[0], $b[0]));
    $body = '<p>Events appear at <a href="/events/">/events/</a>, newest first by date, with past ones folded away. Each event has its own page and an “Add to my calendar” link, '
        . 'and <a href="/events/feed.ics">/events/feed.ics</a> lets people subscribe to all of them. Put <code>[upcoming-events]</code> in any page to show the next five.</p>'
        . '<p><a class="button" href="' . h(admin_url('events', ['new' => 1])) . '">Add an event</a></p>'
        . ($rows ? '<div class="table-wrap"><table><caption>All events, soonest first</caption><thead><tr><th scope="col">Title</th><th scope="col">Next date</th><th scope="col">Repeats</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead><tbody>'
            . implode('', array_column($rows, 1)) . '</tbody></table></div>' : '<p>There are no events yet.</p>');
    admin_screen('Events', $body, 'events');
}

function event_form(?array $values = null, array $errors = [], ?string $origSlug = null): never
{
    $origSlug ??= isset($_GET['slug']) ? (string) $_GET['slug'] : null;
    $existing = $origSlug !== null && valid_slug($origSlug) ? get_item('events', $origSlug) : null;
    if ($origSlug !== null && !$existing && $values === null) {
        flash('That event could not be found.', 'error');
        go(admin_url('events'));
    }
    $v = $values ?? ($existing ?? ['title' => '', 'slug' => '', 'start_date' => '', 'start_time' => '', 'end_date' => '', 'end_time' => '', 'all_day' => false,
        'location' => '', 'online_url' => '', 'body' => '<p>What the event is about, who it is for and how to take part.</p>', 'repeat' => 'none', 'repeat_until' => '', 'status' => 'draft']);
    $isNew = $existing === null;
    $body = '<form method="post" action="' . h(admin_url('events', $isNew ? ['new' => 1] : ['slug' => $origSlug])) . '" data-editor-form>' . csrf_field()
        . updated_field('events', $isNew ? null : $origSlug)
        . text_field('title', 'Title', (string) $v['title'], ['required' => true, 'error' => $errors['title'] ?? null])
        . '<h2>When</h2>'
        . text_field('start_date', 'Start date', (string) $v['start_date'], ['type' => 'date', 'required' => true, 'error' => $errors['start_date'] ?? null, 'hint' => 'Year, month, day. For example 2026-11-21.'])
        . text_field('start_time', 'Start time', (string) $v['start_time'], ['type' => 'time', 'error' => $errors['start_time'] ?? null, 'hint' => 'In the site’s time zone (' . h((string) settings()['timezone']) . '). Leave empty for an all-day event.'])
        . text_field('end_date', 'End date (optional)', (string) ($v['end_date'] ?? ''), ['type' => 'date', 'error' => $errors['end_date'] ?? null, 'hint' => 'Only for events that last more than one day.'])
        . text_field('end_time', 'End time (optional)', (string) ($v['end_time'] ?? ''), ['type' => 'time', 'error' => $errors['end_time'] ?? null])
        . checkbox_field('all_day', 'All day (no start or end time)', !empty($v['all_day']))
        . radio_field('repeat', 'Repeats', EVENT_REPEATS, (string) ($v['repeat'] ?? 'none'), ['hint' => 'Repeating events are shown up to 52 times.'])
        . text_field('repeat_until', 'Repeat until (optional)', (string) ($v['repeat_until'] ?? ''), ['type' => 'date', 'error' => $errors['repeat_until'] ?? null, 'hint' => 'The last date it can happen. Leave empty to repeat 52 times.'])
        . '<h2>Where</h2>'
        . textarea_field('location', 'Place (optional)', (string) ($v['location'] ?? ''), ['rows' => 2, 'hint' => 'The name of the place and its address, for example “Public Library, 100 Main Street, Room 2”.'])
        . text_field('online_url', 'Online link (optional)', (string) ($v['online_url'] ?? ''), ['type' => 'url', 'spellcheck' => 'false', 'error' => $errors['online_url'] ?? null, 'hint' => 'A Zoom, Teams or other meeting address, shown as “Join online”.'])
        . '<h2>Description</h2>'
        . textarea_field('body', 'Description', (string) ($v['body'] ?? ''), ['rows' => 16, 'class' => 'field-wide', 'id' => 'post-body', 'error' => $errors['post-body'] ?? null,
            'hint' => 'What the event is about, who it is for, cost and how to sign up. In the editor, Alt and F10 moves to the toolbar.'])
        . radio_field('status', 'Status', ['published' => 'Published', 'draft' => 'Draft, only you can see it'], (string) ($v['status'] ?? 'draft'))
        . text_field('slug', 'Address', (string) $v['slug'], ['prefix' => site_url('/events/'), 'spellcheck' => 'false', 'autocomplete' => 'off', 'error' => $errors['slug'] ?? null, 'hint' => 'Leave it empty to make one from the title.'])
        . '<div class="form-actions"><button type="submit" class="button" name="then" value="edit">Save event</button>'
        . '<button type="submit" class="button button-secondary" name="then" value="view">Save and view</button></div></form>';
    if (!$isNew) {
        $body .= '<h2>More options</h2><ul><li><a href="/events/' . h($origSlug) . '/">View this event</a></li>'
            . '<li><a href="/events/' . h($origSlug) . '.ics">Download the calendar file</a></li>'
            . '<li><a href="' . h(admin_url('events', ['delete' => $origSlug])) . '">Delete this event</a></li></ul>';
    }
    admin_screen($isNew ? 'Add an event' : 'Edit event: ' . h((string) $existing['title']), $body, 'events', [
        'errors' => $errors,
        'head' => '<meta name="csrf-token" content="' . h($_SESSION['csrf']) . '">',
        'scripts' => editor_scripts(),
    ]);
}

function event_valid_date(string $d): bool
{
    return (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

function event_save(): never
{
    $origSlug = isset($_GET['slug']) ? (string) $_GET['slug'] : null;
    $existing = $origSlug !== null && valid_slug($origSlug) ? get_item('events', $origSlug) : null;
    $time = fn(string $k) => preg_match('/^(\d{1,2}):(\d{2})/', trim((string) ($_POST[$k] ?? '')), $m) ? sprintf('%02d:%s', (int) $m[1], $m[2]) : '';
    $v = [
        'title' => trim((string) ($_POST['title'] ?? '')),
        'slug' => strtolower(trim((string) ($_POST['slug'] ?? ''))),
        'start_date' => trim((string) ($_POST['start_date'] ?? '')),
        'start_time' => $time('start_time'),
        'end_date' => trim((string) ($_POST['end_date'] ?? '')),
        'end_time' => $time('end_time'),
        'all_day' => !empty($_POST['all_day']),
        'location' => trim(str_replace("\r\n", "\n", (string) ($_POST['location'] ?? ''))),
        'online_url' => trim((string) ($_POST['online_url'] ?? '')),
        'body' => str_replace("\r\n", "\n", (string) ($_POST['body'] ?? '')),
        'repeat' => isset(EVENT_REPEATS[$_POST['repeat'] ?? '']) ? (string) $_POST['repeat'] : 'none',
        'repeat_until' => trim((string) ($_POST['repeat_until'] ?? '')),
        'status' => ($_POST['status'] ?? '') === 'published' ? 'published' : 'draft',
    ];
    if ($v['slug'] === '') {
        $v['slug'] = slugify($v['title']);
    }
    if ($v['start_time'] === '' && $v['end_time'] === '') {
        $v['all_day'] = true;
    }
    if ($v['all_day']) {
        $v['start_time'] = $v['end_time'] = '';
    }
    $errors = [];
    if ($v['title'] === '') {
        $errors['title'] = 'Enter a title for the event.';
    }
    if (!event_valid_date($v['start_date'])) {
        $errors['start_date'] = 'Enter the start date as year-month-day, for example 2026-11-21.';
    }
    if ($v['end_date'] !== '' && !event_valid_date($v['end_date'])) {
        $errors['end_date'] = 'Enter the end date as year-month-day, or leave it empty.';
    } elseif ($v['end_date'] !== '' && $v['start_date'] !== '' && $v['end_date'] < $v['start_date']) {
        $errors['end_date'] = 'The end date is before the start date.';
    } elseif (!$v['all_day'] && $v['end_time'] !== '' && ($v['end_date'] === '' || $v['end_date'] === $v['start_date']) && $v['end_time'] <= $v['start_time']) {
        $errors['end_time'] = 'The end time must be after the start time.';
    }
    if ($v['repeat_until'] !== '' && !event_valid_date($v['repeat_until'])) {
        $errors['repeat_until'] = 'Enter the last date as year-month-day, or leave it empty.';
    } elseif ($v['repeat_until'] !== '' && $v['repeat_until'] < $v['start_date']) {
        $errors['repeat_until'] = 'The last date is before the start date.';
    }
    if ($v['online_url'] !== '' && !preg_match('#^https?://#i', $v['online_url'])) {
        $errors['online_url'] = 'Enter the online link as a full address starting with https://';
    }
    if ($v['slug'] !== '' && !valid_slug($v['slug'])) {
        $errors['slug'] = 'Use only lowercase letters, numbers and single dashes in the address, for example spring-workshop.';
    } elseif ($v['slug'] === 'feed' || ($v['slug'] !== $origSlug && get_item('events', $v['slug']))) {
        $errors['slug'] = "Another event already uses the address “{$v['slug']}”. Choose a different one.";
    }
    if (stale_edit($existing)) {
        $errors['title'] = stale_edit_message($existing);
    }
    if ($errors) {
        event_form($v, $errors, $existing ? $origSlug : null);
    }
    save_item('events', $v['slug'], $v, $existing ? $origSlug : null);
    if (($_POST['then'] ?? '') === 'view') {
        go('/events/' . $v['slug'] . '/');
    }
    flash(($existing ? 'Saved your changes to ' : 'Saved the new event ') . '“' . $v['title'] . '”.' . ($v['status'] === 'draft' ? ' It is a draft, so only you can see it.' : ''));
    go(admin_url('events', ['slug' => $v['slug']]));
}

function event_delete_confirm(string $slug): never
{
    $e = valid_slug($slug) ? get_item('events', $slug) : null;
    if (!$e) {
        not_found_screen();
    }
    $body = '<p>Are you sure you want to delete the event “' . h((string) $e['title']) . '”? It will disappear from the website and the calendar feed straight away. A copy is kept, so you can ask for it to be brought back.</p>'
        . '<form method="post" action="' . h(admin_url('events', ['delete' => $slug])) . '" class="form-actions">' . csrf_field()
        . '<button type="submit" class="button button-danger">Yes, delete this event</button>'
        . '<a class="button button-secondary" href="' . h(admin_url('events', ['slug' => $slug])) . '">No, go back</a></form>';
    admin_screen('Delete event: ' . h((string) $e['title']), $body, 'events');
}

function event_delete(string $slug): never
{
    $e = valid_slug($slug) ? get_item('events', $slug) : null;
    if (!$e) {
        not_found_screen();
    }
    delete_item('events', $slug);
    flash('Deleted the event “' . $e['title'] . '”.');
    go(admin_url('events'));
}
