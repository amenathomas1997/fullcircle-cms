<?php
/**
 * The Appointments screen: bookings, services, hours and the calendar connection.
 * Loaded by lib/features/appointments.php only when the screen is opened.
 *   ?a=appointments                 upcoming bookings (and past ones folded away)
 *   ?a=appointments&view=services   the services; &new=1 or &slug= edits one; &delete=
 *   ?a=appointments&view=hours      the hours, notice, wording
 *   ?a=appointments&view=calendar   which calendar, its credentials, test and connect
 *   ?a=appointments&cancel=<id>     cancel a booking (asks first)
 */

declare(strict_types=1);

function appointments_screen(bool $post): never
{
    $view = (string) ($_GET['view'] ?? '');
    $do = (string) ($_GET['do'] ?? $_POST['do'] ?? '');
    if ($do === 'ms-connect' || $do === 'ms-callback' || $view === 'calendar' || $do === 'test' || $do === 'caldav-find') {
        if (!is_owner()) {
            flash('Only the owner can change the calendar connection.', 'error');
            go(admin_url('appointments'));
        }
    }
    if ($do === 'ms-connect') {
        appt_ms_connect_start();
    }
    if ($do === 'ms-callback') {
        appt_ms_connect_callback();
    }
    if (isset($_GET['cancel'])) {
        $post ? appt_admin_cancel((string) $_GET['cancel']) : appt_admin_cancel_confirm((string) $_GET['cancel']);
    }
    if ($view === 'services') {
        if (isset($_GET['delete'])) {
            $post ? appt_service_delete((string) $_GET['delete']) : appt_service_delete_confirm((string) $_GET['delete']);
        }
        if (isset($_GET['new']) || isset($_GET['slug'])) {
            $post ? appt_service_save() : appt_service_form();
        }
        appt_services_list();
    }
    if ($view === 'hours') {
        $post ? appt_hours_save() : appt_hours_form();
    }
    if ($view === 'calendar') {
        if ($post) {
            match ($do) {
                'test' => appt_calendar_run_test(),
                'caldav-find' => appt_caldav_find(),
                'disconnect' => appt_calendar_disconnect(),
                default => appt_calendar_save(),
            };
        }
        appt_calendar_form();
    }
    appt_bookings_list();
}

function appt_tabs(string $current): string
{
    $tabs = ['' => 'Bookings', 'services' => 'Services', 'hours' => 'Hours and wording', 'calendar' => 'Calendar'];
    $out = '<nav aria-label="Appointments sections"><ul class="tabs">';
    foreach ($tabs as $key => $label) {
        if ($key === 'calendar' && !is_owner()) {
            continue;
        }
        $out .= '<li><a href="' . h(admin_url('appointments', $key === '' ? [] : ['view' => $key])) . '"' . ($key === $current ? ' aria-current="page"' : '') . '>' . $label . '</a></li>';
    }
    return $out . '</ul></nav>';
}

/* ---------- Bookings ---------- */

function appt_bookings_list(): never
{
    $now = date('c');
    $upcoming = [];
    $past = [];
    foreach (appt_bookings() as $b) {
        ($b['end'] ?? '') >= $now ? $upcoming[] = $b : $past[] = $b;
    }
    $row = function (array $b): string {
        $who = h($b['name']) . '<br><a href="mailto:' . h($b['email']) . '">' . h($b['email']) . '</a>' . (($b['phone'] ?? '') !== '' ? '<br>' . h((string) $b['phone']) : '');
        $state = ($b['status'] ?? '') === 'cancelled' ? 'Cancelled' . (($b['cancelled_by'] ?? '') === 'visitor' ? ' by them' : '') : 'Booked';
        $calendar = match ((string) ($b['calendar']['type'] ?? 'none')) {
            'none' => 'Not in a calendar',
            default => (($b['calendar']['link'] ?? '') !== '' ? '<a href="' . h((string) $b['calendar']['link']) . '">In the calendar</a>' : 'In the calendar'),
        };
        if (($b['calendar_problem'] ?? '') !== '') {
            $calendar .= '<br>' . h((string) $b['calendar_problem']);
        }
        $failed = array_filter((array) ($b['emails'] ?? []), fn($e) => empty($e['ok']));
        if ($failed) {
            $calendar .= '<br>An email could not be sent: ' . h((string) (end($failed)['error'] ?? ''));
        }
        return '<tr><th scope="row">' . h(appt_start($b)->format('D, M j, Y g:i a')) . '<br><span class="hint">' . h(appt_length_words((int) $b['duration'])) . '</span></th>'
            . '<td>' . h($b['service_title']) . '</td><td>' . $who . '</td>'
            . '<td>' . (($b['notes'] ?? '') !== '' ? nl2br(h((string) $b['notes'])) : '') . '</td>'
            . '<td>' . $state . '<br>' . $calendar . '</td>'
            . '<td class="actions">' . (($b['status'] ?? '') === 'booked' ? '<a href="' . h(admin_url('appointments', ['cancel' => $b['id']])) . '">Cancel<span class="visually-hidden"> the appointment with ' . h($b['name']) . '</span></a>' : '') . '</td></tr>';
    };
    $table = fn(array $list, string $caption) => '<div class="table-wrap"><table><caption>' . $caption . '</caption><thead><tr><th scope="col">When</th><th scope="col">Service</th><th scope="col">Who</th><th scope="col">Notes</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead><tbody>'
        . implode('', array_map($row, $list)) . '</tbody></table></div>';
    $cal = appt_calendar();
    $services = appt_services();
    $notes = [];
    if (!feature_on('appointments')) {
        $notes[] = 'Appointments are turned off under <a href="' . h(admin_url('features')) . '">Features</a>, so /book/ shows nothing yet.';
    }
    if (!$services) {
        $notes[] = 'Add at least one <a href="' . h(admin_url('appointments', ['view' => 'services'])) . '">service</a> before visitors can book.';
    }
    if ($cal['type'] === 'none' && is_owner()) {
        $notes[] = 'No calendar is connected: openings are checked only against bookings made here. <a href="' . h(admin_url('appointments', ['view' => 'calendar'])) . '">Connect your calendar</a> so your other commitments are never offered.';
    }
    $body = appt_tabs('')
        . '<p>Visitors book at <a href="/book/">' . h(site_url('/book/')) . '</a>. Each booking goes into your connected calendar, the visitor gets a confirmation email with a cancel link, and a reminder the day before. '
        . 'Put <code>[book]</code> in any page to list the services there.</p>'
        . ($notes ? '<ul>' . implode('', array_map(fn($n) => '<li>' . $n . '</li>', $notes)) . '</ul>' : '')
        . ($upcoming ? $table($upcoming, 'Upcoming appointments, soonest first') : '<p>There are no upcoming appointments.</p>')
        . ($past ? '<details><summary>Past appointments (' . count($past) . ')</summary>' . $table(array_slice(array_reverse($past), 0, 100), 'Past appointments, most recent first') . '</details>' : '');
    admin_screen('Appointments', $body, 'appointments');
}

function appt_admin_cancel_confirm(string $id): never
{
    $b = appt_booking($id);
    if (!$b || ($b['status'] ?? '') !== 'booked') {
        flash('That appointment could not be found, or it is already cancelled.', 'error');
        go(admin_url('appointments'));
    }
    $body = '<p>Cancel the appointment with <strong>' . h($b['name']) . '</strong> for ' . h($b['service_title']) . ' on ' . h(appt_when_words($b, primary_lang())) . '?</p>'
        . '<p>The event is removed from the calendar and ' . h($b['name']) . ' is emailed that it is cancelled.</p>'
        . '<form method="post" action="' . h(admin_url('appointments', ['cancel' => $id])) . '">' . csrf_field()
        . '<button type="submit" class="button">Yes, cancel it</button> <a class="button button-outline" href="' . h(admin_url('appointments')) . '">Keep it</a></form>';
    admin_screen('Cancel an appointment', $body, 'appointments');
}

function appt_admin_cancel(string $id): never
{
    $b = appt_booking($id);
    if ($b && ($b['status'] ?? '') === 'booked') {
        $b = appt_cancel($b, 'owner');
        activity_log('cancelled appointment', $b['service_title'] . ' with ' . $b['name'] . ' on ' . appt_start($b)->format('Y-m-d H:i'));
        flash('Cancelled the appointment with ' . $b['name'] . '.' . (($b['calendar_problem'] ?? '') !== '' ? ' ' . $b['calendar_problem'] : ''), ($b['calendar_problem'] ?? '') !== '' ? 'error' : 'success');
    }
    go(admin_url('appointments'));
}

/* ---------- Services ---------- */

function appt_services_list(): never
{
    $rows = '';
    foreach (appt_services(true) as $s) {
        $title = h((string) $s['title']);
        $rows .= '<tr><th scope="row">' . $title . '</th><td>' . (int) $s['duration'] . ' min</td><td>' . h((string) ($s['price'] ?? '')) . '</td><td>' . status_label($s) . '</td>'
            . '<td class="actions"><a href="' . h(admin_url('appointments', ['view' => 'services', 'slug' => $s['slug']])) . '">Edit<span class="visually-hidden"> ' . $title . '</span></a>'
            . ' <a href="/book/' . h($s['slug']) . '/">View<span class="visually-hidden"> ' . $title . '</span></a>'
            . ' <a href="' . h(admin_url('appointments', ['view' => 'services', 'delete' => $s['slug']])) . '">Delete<span class="visually-hidden"> ' . $title . '</span></a></td></tr>';
    }
    $body = appt_tabs('services')
        . '<p>A service is something a visitor can book: its name, how long it takes and what it costs. Each one has its own page under /book/.</p>'
        . '<p><a class="button" href="' . h(admin_url('appointments', ['view' => 'services', 'new' => 1])) . '">Add a service</a></p>'
        . ($rows ? '<div class="table-wrap"><table><caption>Services</caption><thead><tr><th scope="col">Name</th><th scope="col">Length</th><th scope="col">Price</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
            : '<p>There are no services yet.</p>');
    admin_screen('Services', $body, 'appointments');
}

function appt_service_form(?array $values = null, array $errors = [], ?string $origSlug = null): never
{
    $origSlug ??= isset($_GET['slug']) ? (string) $_GET['slug'] : null;
    $existing = $origSlug !== null && valid_slug($origSlug) ? get_item('services', $origSlug) : null;
    if ($origSlug !== null && !$existing && $values === null) {
        flash('That service could not be found.', 'error');
        go(admin_url('appointments', ['view' => 'services']));
    }
    $v = $values ?? ($existing ?? ['title' => '', 'slug' => '', 'duration' => '30', 'price' => '', 'location' => '', 'description' => '', 'status' => 'published']);
    $isNew = $existing === null;
    $body = appt_tabs('services') . '<form method="post" action="' . h(admin_url('appointments', $isNew ? ['view' => 'services', 'new' => 1] : ['view' => 'services', 'slug' => $origSlug])) . '">' . csrf_field()
        . updated_field('services', $isNew ? null : $origSlug)
        . text_field('title', 'Name', (string) $v['title'], ['required' => true, 'error' => $errors['title'] ?? null, 'hint' => 'For example “30-minute distance Reiki session”.'])
        . text_field('duration', 'Length in minutes', (string) $v['duration'], ['type' => 'number', 'inputmode' => 'numeric', 'required' => true, 'error' => $errors['duration'] ?? null, 'hint' => 'Between 5 and 480. Openings are offered so the whole session fits inside your hours.'])
        . text_field('price', 'Price (optional)', (string) ($v['price'] ?? ''), ['hint' => 'Shown as written, for example “$45” or “Free”.'])
        . text_field('location', 'Where it happens (optional)', (string) ($v['location'] ?? ''), ['hint' => 'For example “Online, from your own home” or an address. Goes into the calendar event and the confirmation email.'])
        . textarea_field('description', 'Short description (optional)', (string) ($v['description'] ?? ''), ['rows' => 4, 'hint' => 'A sentence or two shown with the service. Plain text.'])
        . radio_field('status', 'Status', ['published' => 'Bookable', 'draft' => 'Hidden, only you can see it'], (string) ($v['status'] ?? 'published'))
        . text_field('slug', 'Address', (string) ($v['slug'] ?? ''), ['prefix' => site_url('/book/'), 'spellcheck' => 'false', 'autocomplete' => 'off', 'error' => $errors['slug'] ?? null, 'hint' => 'Leave it empty to make one from the name.'])
        . '<button type="submit" class="button">' . ($isNew ? 'Add the service' : 'Save changes') . '</button> <a href="' . h(admin_url('appointments', ['view' => 'services'])) . '">Cancel</a></form>';
    admin_screen($isNew ? 'Add a service' : 'Edit service', $body, 'appointments', ['errors' => $errors]);
}

function appt_service_save(): never
{
    $origSlug = isset($_GET['slug']) ? (string) $_GET['slug'] : null;
    $v = [];
    foreach (['title', 'slug', 'duration', 'price', 'location', 'description', 'status'] as $k) {
        $v[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $errors = [];
    if ($v['title'] === '') {
        $errors['title'] = 'Give the service a name.';
    }
    if (!ctype_digit($v['duration']) || (int) $v['duration'] < 5 || (int) $v['duration'] > 480) {
        $errors['duration'] = 'Enter the length as a number of minutes between 5 and 480.';
    }
    $slug = $v['slug'] !== '' ? slugify($v['slug']) : slugify($v['title']);
    if ($slug === '' || !valid_slug($slug) || in_array($slug, ['done', 'cancel'], true)) {
        $errors['slug'] = 'The address can only have lowercase letters, numbers and dashes, and cannot be “done” or “cancel”.';
    } elseif ($slug !== $origSlug && get_item('services', $slug)) {
        $errors['slug'] = 'Another service already uses that address.';
    }
    if ($errors) {
        appt_service_form($v, $errors, $origSlug);
    }
    $v['slug'] = $slug;
    $v['duration'] = (int) $v['duration'];
    $v['status'] = $v['status'] === 'draft' ? 'draft' : 'published';
    $v['updated'] = date('c');
    save_item('services', $slug, $v, $origSlug);
    activity_log($origSlug ? 'edited service' : 'added service', $v['title']);
    flash(($origSlug ? 'Saved ' : 'Added ') . '“' . $v['title'] . '”.');
    go(admin_url('appointments', ['view' => 'services']));
}

function appt_service_delete_confirm(string $slug): never
{
    $s = valid_slug($slug) ? get_item('services', $slug) : null;
    if (!$s) {
        go(admin_url('appointments', ['view' => 'services']));
    }
    $body = '<p>Delete the service “' . h((string) $s['title']) . '”? Appointments already booked for it stay on the Bookings list.</p>'
        . '<form method="post" action="' . h(admin_url('appointments', ['view' => 'services', 'delete' => $slug])) . '">' . csrf_field()
        . '<button type="submit" class="button">Yes, delete it</button> <a class="button button-outline" href="' . h(admin_url('appointments', ['view' => 'services'])) . '">Keep it</a></form>';
    admin_screen('Delete a service', $body, 'appointments');
}

function appt_service_delete(string $slug): never
{
    if (valid_slug($slug) && ($s = get_item('services', $slug))) {
        delete_item('services', $slug);
        activity_log('deleted service', (string) $s['title']);
        flash('Deleted “' . $s['title'] . '”.');
    }
    go(admin_url('appointments', ['view' => 'services']));
}

/* ---------- Hours and wording ---------- */

function appt_hours_form(array $errors = [], ?array $values = null): never
{
    $s = $values ?? appt_settings();
    $body = appt_tabs('hours') . '<form method="post" action="' . h(admin_url('appointments', ['view' => 'hours'])) . '">' . csrf_field()
        . '<h2>Hours you take appointments</h2>'
        . '<p>Times are in the site’s time zone, ' . h((string) settings()['timezone']) . ' (change it under Settings). Leave a day empty to take no appointments that day. '
        . 'For a break, use the second pair of times: for example 9:00 to 12:00 and 13:00 to 17:00.</p>';
    foreach (APPT_WEEKDAYS as $day => $name) {
        $r = $s['hours'][$day] ?? [];
        $body .= '<fieldset class="block"><legend>' . $name . '</legend><div class="field-inline">'
            . text_field("hours[$day][0][0]", 'From', (string) ($r[0][0] ?? ''), ['type' => 'time', 'id' => "hours-$day-0-from", 'error' => $errors["hours-$day"] ?? null])
            . text_field("hours[$day][0][1]", 'To', (string) ($r[0][1] ?? ''), ['type' => 'time', 'id' => "hours-$day-0-to"])
            . text_field("hours[$day][1][0]", 'And from', (string) ($r[1][0] ?? ''), ['type' => 'time', 'id' => "hours-$day-1-from"])
            . text_field("hours[$day][1][1]", 'To', (string) ($r[1][1] ?? ''), ['type' => 'time', 'id' => "hours-$day-1-to"])
            . '</div></fieldset>';
    }
    $body .= '<h2>How openings are offered</h2>'
        . radio_field('step', 'Start times offered', array_map(fn($l) => $l, APPT_STEPS), (string) $s['step'])
        . text_field('buffer', 'Gap between appointments, in minutes', (string) $s['buffer'], ['type' => 'number', 'inputmode' => 'numeric', 'error' => $errors['buffer'] ?? null, 'hint' => '0 to 120. Kept free before and after every busy time and booking.'])
        . text_field('notice_hours', 'Shortest notice, in hours', (string) $s['notice_hours'], ['type' => 'number', 'inputmode' => 'numeric', 'error' => $errors['notice_hours'] ?? null, 'hint' => 'For example 24: nothing sooner than a day from now is offered.'])
        . text_field('window_days', 'How far ahead people can book, in days', (string) $s['window_days'], ['type' => 'number', 'inputmode' => 'numeric', 'error' => $errors['window_days'] ?? null, 'hint' => '1 to 365.'])
        . '<h2>The booking form</h2>'
        . checkbox_field('ask_phone', 'Ask for a phone number (optional for the visitor)', $s['ask_phone'])
        . text_field('notes_label', 'Label for the notes box', $s['notes_label'], ['hint' => 'Leave empty for “Anything you would like me to know (optional)”. For example “Who is the session for, a person or a pet?”'])
        . textarea_field('intro', 'Introduction at the top of /book/', $s['intro'], ['rows' => 3, 'hint' => 'Shown above the list of services when there is more than one. Plain text.'])
        . textarea_field('after', 'What happens next', $s['after'], ['rows' => 4, 'hint' => 'Shown on the confirmation page and in the confirmation email. For example how you will get in touch, or how to pay.'])
        . checkbox_field('reminders', 'Email a reminder the day before', $s['reminders'])
        . checkbox_field('invite', 'Microsoft calendars only: add the visitor as a guest, so Outlook sends them a calendar invitation too', $s['invite'])
        . '<button type="submit" class="button">Save hours and wording</button></form>';
    admin_screen('Hours and wording', $body, 'appointments', ['errors' => $errors]);
}

function appt_hours_save(): never
{
    $errors = [];
    $hours = [];
    foreach (APPT_WEEKDAYS as $day => $name) {
        $ranges = [];
        foreach ((array) ($_POST['hours'][$day] ?? []) as $pair) {
            $from = trim((string) ($pair[0] ?? ''));
            $to = trim((string) ($pair[1] ?? ''));
            if ($from === '' && $to === '') {
                continue;
            }
            if (!appt_valid_clock($from) || !appt_valid_clock($to) || $from >= $to) {
                $errors["hours-$day"] = "$name: enter both a from and a to time, with the from time first.";
                continue;
            }
            $ranges[] = [$from, $to];
        }
        usort($ranges, fn($a, $b) => strcmp($a[0], $b[0]));
        $hours[$day] = $ranges;
    }
    $num = function (string $key, int $min, int $max, string $what) use (&$errors): int {
        $v = trim((string) ($_POST[$key] ?? ''));
        if (!ctype_digit($v) || (int) $v < $min || (int) $v > $max) {
            $errors[$key] = "Enter $what as a whole number from $min to $max.";
            return $min;
        }
        return (int) $v;
    };
    $new = [
        'hours' => $hours,
        'step' => isset(APPT_STEPS[(int) ($_POST['step'] ?? 30)]) ? (int) $_POST['step'] : 30,
        'buffer' => $num('buffer', 0, 120, 'the gap'),
        'notice_hours' => $num('notice_hours', 0, 720, 'the notice'),
        'window_days' => $num('window_days', 1, 365, 'the number of days'),
        'ask_phone' => !empty($_POST['ask_phone']),
        'notes_label' => trim((string) ($_POST['notes_label'] ?? '')),
        'intro' => trim(str_replace("\r\n", "\n", (string) ($_POST['intro'] ?? ''))),
        'after' => trim(str_replace("\r\n", "\n", (string) ($_POST['after'] ?? ''))),
        'reminders' => !empty($_POST['reminders']),
        'invite' => !empty($_POST['invite']),
    ];
    if ($errors) {
        appt_hours_form($errors, $new);
    }
    $all = settings();
    $all['appointments'] = $new;
    save_settings($all);
    @unlink(APPT_BUSY_CACHE);
    activity_log('changed appointment hours');
    flash('Saved the hours and wording.');
    go(admin_url('appointments', ['view' => 'hours']));
}

/* ---------- The calendar connection ---------- */

function appt_calendar_form(array $errors = []): never
{
    $cal = appt_calendar();
    $c = config();
    $type = $cal['type'];
    $status = match ($type) {
        'caldav' => ($cal['calendar_url'] ?? '') !== '' ? 'A CalDAV calendar is connected: ' . h((string) ($cal['calendar_name'] ?? $cal['calendar_url'])) . '.' : 'CalDAV is chosen, but no calendar has been picked yet.',
        'microsoft' => ($cal['refresh_token'] ?? '') !== '' ? 'A Microsoft calendar is connected' . (($cal['account'] ?? '') !== '' ? ' for ' . h((string) $cal['account']) : '') . (($cal['ms_calendar_name'] ?? '') !== '' ? ' (' . h((string) $cal['ms_calendar_name']) . ')' : '') . '.' : 'Microsoft is chosen, but the calendar is not connected yet.',
        'google' => ($cal['google_calendar_id'] ?? '') !== '' && !empty($cal['service_account']['client_email']) ? 'A Google calendar is set: ' . h((string) $cal['google_calendar_id']) . '.' : 'Google is chosen, but the key or calendar ID is missing.',
        default => 'No calendar is connected. Openings are checked only against bookings made on this website.',
    };
    $action = admin_url('appointments', ['view' => 'calendar']);
    $body = appt_tabs('calendar')
        . '<p>Your calendar is the source of truth: any time it shows as busy is never offered, and every booking is written into it. Only one calendar is used.</p>'
        . '<p>Status: <strong>' . $status . '</strong></p>'
        . '<form method="post" action="' . h($action) . '">' . csrf_field() . '<input type="hidden" name="do" value="test"><button type="submit" class="button button-outline">Test the calendar connection</button></form>'
        . '<form method="post" action="' . h($action) . '">' . csrf_field()
        . radio_field('type', 'Which calendar', APPT_CALENDAR_TYPES, $type);

    // CalDAV
    $calendars = $cal['found'] ?? [];
    $pick = '';
    if ($calendars) {
        $options = [];
        foreach ($calendars as [$url, $name]) {
            $options[$url] = $name . ' (' . $url . ')';
        }
        $pick = radio_field('calendar_url', 'Calendar to use', $options, (string) ($cal['calendar_url'] ?? array_key_first($options)));
    } elseif (($cal['calendar_url'] ?? '') !== '') {
        $pick = '<p>Calendar in use: <code>' . h((string) $cal['calendar_url']) . '</code></p>';
    }
    $body .= '<section id="caldav" aria-labelledby="caldav-heading"><h2 id="caldav-heading">cPanel or other CalDAV calendar</h2>'
        . '<p>For a calendar hosted on cPanel: the server address is your domain with port 2080, for example <code>https://example.com:2080</code>, the user name is an email account on that domain, and the password is that account’s password. '
        . 'The calendar itself is the one shown under Calendars and Contacts in cPanel’s webmail. For iCloud use <code>https://caldav.icloud.com</code> with an app-specific password; for Fastmail <code>https://caldav.fastmail.com</code>; for Nextcloud your Nextcloud address.</p>'
        . text_field('caldav_url', 'Server address', (string) ($cal['url'] ?? ''), ['type' => 'url', 'spellcheck' => 'false', 'autocomplete' => 'off', 'error' => $errors['caldav_url'] ?? null])
        . text_field('caldav_user', 'User name', (string) ($cal['user'] ?? ''), ['spellcheck' => 'false', 'autocomplete' => 'off', 'hint' => 'Usually the full email address.'])
        . text_field('caldav_password', 'Password', '', ['type' => 'password', 'autocomplete' => 'off', 'hint' => ($cal['password'] ?? '') !== '' ? 'A password is saved. Leave this empty to keep it.' : 'Kept in data/config.php, which is private.'])
        . $pick
        . '<p class="hint">Save first, then press “Find calendars” to list the calendars behind that address and pick one.</p></section>';

    // Microsoft
    $appReady = graph_app_configured();
    $body .= '<section id="microsoft" aria-labelledby="microsoft-heading"><h2 id="microsoft-heading">Microsoft 365 or Outlook.com</h2>'
        . '<p>Uses the Microsoft app registration from Settings, Email sending' . ($appReady ? '' : ' (<strong>not filled in yet</strong>)') . '. '
        . 'On that app registration, under Authentication, add this redirect address: <code>' . h(appt_ms_redirect_uri()) . '</code>. '
        . 'Under API permissions add the delegated permission <code>Calendars.ReadWrite</code> (no admin consent needed for your own calendar). '
        . 'For a personal Outlook.com calendar the app registration must allow personal Microsoft accounts.</p>'
        . radio_field('ms_tenant', 'Account kind', ['organizations' => 'A work or school (Microsoft 365) account', 'consumers' => 'A personal Outlook.com, Hotmail or Live account', 'common' => 'Either'], (string) ($cal['tenant'] ?? 'common'))
        . (($cal['refresh_token'] ?? '') !== '' && !empty($cal['ms_calendars'])
            ? radio_field('ms_calendar_id', 'Calendar to use', ['' => 'The default calendar'] + array_combine(array_column($cal['ms_calendars'], 0), array_column($cal['ms_calendars'], 1)), (string) ($cal['ms_calendar_id'] ?? ''))
            : '')
        . '<p>' . ($appReady ? '<a class="button" href="' . h(admin_url('appointments', ['do' => 'ms-connect'])) . '">' . (($cal['refresh_token'] ?? '') !== '' ? 'Connect a different Microsoft calendar' : 'Connect a Microsoft calendar') . '</a>' : 'Fill in the Microsoft app details under Settings first.') . '</p></section>';

    // Google
    $sa = (array) ($cal['service_account'] ?? []);
    $body .= '<section id="google" aria-labelledby="google-heading"><h2 id="google-heading">Google Calendar</h2>'
        . '<p>Google calendars are reached through a <em>service account</em>, a robot account you make once in Google Cloud and share your calendar with. Steps: '
        . 'in <a href="https://console.cloud.google.com/">Google Cloud Console</a> make a project, turn on the Google Calendar API, make a service account (IAM and admin, Service accounts), make a JSON key for it and download it. '
        . 'Then in Google Calendar, open your calendar’s settings, Share with specific people, and add the service account’s email address with “Make changes to events”. '
        . 'Paste the key file’s contents below and enter the calendar ID (your Gmail address, or the ID shown under Integrate calendar).</p>'
        . textarea_field('google_key', 'Service account key (JSON)', '', ['rows' => 4, 'spellcheck' => 'false', 'error' => $errors['google_key'] ?? null,
            'hint' => !empty($sa['client_email']) ? 'A key for ' . h((string) $sa['client_email']) . ' is saved. Leave this empty to keep it.' : 'The whole contents of the downloaded .json file.'])
        . text_field('google_calendar_id', 'Calendar ID', (string) ($cal['google_calendar_id'] ?? ''), ['spellcheck' => 'false', 'autocomplete' => 'off', 'error' => $errors['google_calendar_id'] ?? null]) . '</section>'
        . '<button type="submit" class="button">Save calendar settings</button></form>'
        . '<form method="post" action="' . h($action) . '">' . csrf_field() . '<input type="hidden" name="do" value="caldav-find"><button type="submit" class="button button-outline">Find calendars (CalDAV)</button></form>'
        . '<form method="post" action="' . h($action) . '">' . csrf_field() . '<input type="hidden" name="do" value="disconnect"><button type="submit" class="button button-outline">Forget the saved credentials and tokens</button></form>';
    admin_screen('Calendar', $body, 'appointments', ['errors' => $errors]);
}

function appt_calendar_save(): never
{
    $cal = appt_calendar();
    $errors = [];
    $type = (string) ($_POST['type'] ?? 'none');
    $changes = ['type' => isset(APPT_CALENDAR_TYPES[$type]) ? $type : 'none'];
    $url = trim((string) ($_POST['caldav_url'] ?? ''));
    if ($url !== '' && !preg_match('#^https?://[a-z0-9.-]+(:\d+)?(/.*)?$#i', $url)) {
        $errors['caldav_url'] = 'Enter the server address starting with https://, for example https://example.com:2080.';
    }
    $changes['url'] = rtrim($url, '/');
    $changes['user'] = trim((string) ($_POST['caldav_user'] ?? ''));
    if (($_POST['caldav_password'] ?? '') !== '') {
        $changes['password'] = (string) $_POST['caldav_password'];
    }
    if (isset($_POST['calendar_url']) && !empty($cal['found'])) {
        foreach ($cal['found'] as [$found, $name]) {
            if ($found === (string) $_POST['calendar_url']) {
                $changes['calendar_url'] = $found;
                $changes['calendar_name'] = $name;
            }
        }
    }
    if ($changes['url'] !== ($cal['url'] ?? '') || $changes['user'] !== ($cal['user'] ?? '')) {
        $changes['found'] = [];
        if ($changes['type'] === 'caldav') {
            $changes['calendar_url'] = '';
        }
    }
    $tenant = (string) ($_POST['ms_tenant'] ?? 'common');
    $changes['tenant'] = in_array($tenant, ['organizations', 'consumers', 'common'], true) ? $tenant : 'common';
    if (isset($_POST['ms_calendar_id'])) {
        $id = (string) $_POST['ms_calendar_id'];
        $changes['ms_calendar_id'] = '';
        $changes['ms_calendar_name'] = '';
        foreach ((array) ($cal['ms_calendars'] ?? []) as [$cid, $cname]) {
            if ($cid === $id) {
                $changes['ms_calendar_id'] = $id;
                $changes['ms_calendar_name'] = $cname;
            }
        }
    }
    $key = trim((string) ($_POST['google_key'] ?? ''));
    if ($key !== '') {
        $json = json_decode($key, true);
        if (!is_array($json) || empty($json['client_email']) || empty($json['private_key']) || !str_contains((string) $json['private_key'], 'PRIVATE KEY')) {
            $errors['google_key'] = 'That is not a service account key file. It is JSON with “client_email” and “private_key” in it.';
        } else {
            $changes['service_account'] = ['client_email' => (string) $json['client_email'], 'private_key' => (string) $json['private_key'], 'project_id' => (string) ($json['project_id'] ?? '')];
        }
    }
    $changes['google_calendar_id'] = trim((string) ($_POST['google_calendar_id'] ?? ''));
    if ($changes['type'] === 'google' && $changes['google_calendar_id'] === '') {
        $errors['google_calendar_id'] = 'Enter the calendar ID, usually your Gmail address.';
    }
    if ($errors) {
        appt_calendar_form($errors);
    }
    appt_save_calendar($changes);
    activity_log('changed the appointments calendar', APPT_CALENDAR_TYPES[$changes['type']]);
    flash('Saved the calendar settings.' . ($changes['type'] === 'caldav' && ($changes['calendar_url'] ?? $cal['calendar_url'] ?? '') === '' ? ' Now press “Find calendars (CalDAV)” and pick one.' : ''));
    go(admin_url('appointments', ['view' => 'calendar']));
}

function appt_caldav_find(): never
{
    try {
        $found = appt_caldav_discover(appt_calendar());
        $changes = ['found' => $found];
        if (count($found) === 1 || (appt_calendar()['calendar_url'] ?? '') === '') {
            $changes['calendar_url'] = $found[0][0];
            $changes['calendar_name'] = $found[0][1];
        }
        appt_save_calendar($changes);
        flash(count($found) === 1 ? 'Found one calendar, “' . $found[0][1] . '”, and chose it.' : 'Found ' . count($found) . ' calendars. Pick the one to use and save.');
    } catch (Throwable $e) {
        flash($e->getMessage(), 'error');
    }
    go(admin_url('appointments', ['view' => 'calendar']));
}

function appt_calendar_run_test(): never
{
    try {
        flash(appt_calendar_test());
    } catch (Throwable $e) {
        flash('The calendar connection did not work: ' . $e->getMessage(), 'error');
    }
    go(admin_url('appointments', ['view' => 'calendar']));
}

function appt_calendar_disconnect(): never
{
    save_config(['appt_calendar' => ['type' => 'none']]);
    @unlink(APPT_TOKEN_CACHE);
    @unlink(APPT_BUSY_CACHE);
    activity_log('disconnected the appointments calendar');
    flash('Forgot the calendar credentials and tokens. No calendar is connected now.');
    go(admin_url('appointments', ['view' => 'calendar']));
}

/** Send the owner to Microsoft to allow calendar access. */
function appt_ms_connect_start(): never
{
    if (!graph_app_configured()) {
        flash('Fill in the Microsoft app details under Settings, Email sending first.', 'error');
        go(admin_url('appointments', ['view' => 'calendar']));
    }
    $cal = appt_calendar();
    $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $_SESSION['appt_oauth'] = ['state' => bin2hex(random_bytes(16)), 'verifier' => $verifier, 'started' => time()];
    go('https://login.microsoftonline.com/' . rawurlencode(appt_ms_tenant($cal)) . '/oauth2/v2.0/authorize?' . http_build_query([
        'client_id' => config()['graph_client_id'],
        'response_type' => 'code',
        'redirect_uri' => appt_ms_redirect_uri(),
        'response_mode' => 'query',
        'scope' => APPT_MS_SCOPE,
        'state' => $_SESSION['appt_oauth']['state'],
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256',
        'prompt' => 'select_account',
    ]));
}

/** Microsoft sends the owner back with a code; swap it for tokens and remember the account. */
function appt_ms_connect_callback(): never
{
    $back = admin_url('appointments', ['view' => 'calendar']);
    $fail = function (string $why) use ($back): never {
        unset($_SESSION['appt_oauth']);
        flash($why, 'error');
        go($back);
    };
    $o = $_SESSION['appt_oauth'] ?? null;
    if (!$o || !hash_equals($o['state'], (string) ($_GET['state'] ?? '')) || time() - $o['started'] > 600) {
        $fail('That Microsoft connection had expired. Please try again.');
    }
    if (isset($_GET['error'])) {
        $fail('Microsoft did not complete the connection: ' . mb_substr((string) ($_GET['error_description'] ?? $_GET['error']), 0, 300));
    }
    $cal = appt_calendar();
    $c = config();
    [$status, $body] = appt_http('POST', 'https://login.microsoftonline.com/' . rawurlencode(appt_ms_tenant($cal)) . '/oauth2/v2.0/token',
        ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
            'client_id' => $c['graph_client_id'], 'client_secret' => $c['graph_client_secret'], 'grant_type' => 'authorization_code',
            'code' => (string) ($_GET['code'] ?? ''), 'redirect_uri' => appt_ms_redirect_uri(), 'code_verifier' => $o['verifier'], 'scope' => APPT_MS_SCOPE,
        ]));
    $json = json_decode($body, true) ?: [];
    if ($status !== 200 || empty($json['refresh_token']) || empty($json['access_token'])) {
        $fail('Microsoft did not give a lasting connection: ' . mb_substr((string) ($json['error_description'] ?? "HTTP $status"), 0, 300)
            . (empty($json['refresh_token']) && $status === 200 ? ' (no refresh token: check that offline_access is allowed)' : ''));
    }
    unset($_SESSION['appt_oauth']);
    $account = '';
    $parts = explode('.', (string) ($json['id_token'] ?? ''));
    $claims = json_decode((string) base64_decode(strtr($parts[1] ?? '', '-_', '+/')), true);
    if (is_array($claims)) {
        $account = (string) ($claims['preferred_username'] ?? $claims['email'] ?? '');
    }
    appt_save_calendar(['type' => 'microsoft', 'refresh_token' => (string) $json['refresh_token'], 'account' => $account, 'ms_calendar_id' => '', 'ms_calendar_name' => '', 'ms_calendars' => []]);
    appt_store_token('microsoft', (string) $json['access_token'], (int) ($json['expires_in'] ?? 3000));
    try {
        $list = appt_ms_calendars(appt_calendar());
        appt_save_calendar(['ms_calendars' => array_map(fn($c) => [$c[0], $c[1]], $list)]);
        appt_store_token('microsoft', (string) $json['access_token'], (int) ($json['expires_in'] ?? 3000));
    } catch (Throwable $e) {
        error_log('Appointments: could not list Microsoft calendars: ' . $e->getMessage());
    }
    activity_log('connected a Microsoft calendar', $account);
    flash('Connected the Microsoft calendar' . ($account !== '' ? ' of ' . $account : '') . '. Press “Test the calendar connection” to check it.');
    go($back);
}
