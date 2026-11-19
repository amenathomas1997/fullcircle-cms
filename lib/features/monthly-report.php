<?php
/**
 * Monthly email report: on the 1st of each month the owner gets one plain email about last month.
 *   - visitors and page views, from the kit's own private counts (or "not counted" when there are none)
 *   - appointments made and cancelled (when Appointments is on)
 *   - form answers (when Forms is on), contact messages and any that could not be emailed
 *   - uptime of the other sites this site watches on the Sites screen (from the checks fleet alerts make)
 *   - the latest nightly backup, the days left on the SSL certificate
 *   - the kit version and what changed in the kit that month (from CHANGELOG.md)
 *
 * The switch is settings()['monthly_report'] (on by default); Settings also has a
 * "Send me this month's report now" button for trying it, which sends the month so far.
 * What was sent is noted in data/monthly-report.json so a month is never sent twice.
 * monthly_report_data() and monthly_report_html() are plain functions of a month, so
 * tools/selftest.php builds a report for a fixed month and checks the words.
 */

declare(strict_types=1);

const MONTHLY_REPORT_STATE = DATA_DIR . '/monthly-report.json';
const FLEET_UPTIME_FILE = DATA_DIR . '/fleet-uptime.json';

function monthly_report_on(): bool
{
    return array_key_exists('monthly_report', settings()) ? !empty(settings()['monthly_report']) : true;
}

/** "November 2026" for "2026-11". */
function monthly_report_label(string $month): string
{
    return date('F Y', (int) strtotime($month . '-01'));
}

/** Everything the report says about one month ("YYYY-MM"). Counts are integers; null means "not counted" or "not used here". */
function monthly_report_data(string $month): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        throw new InvalidArgumentException('A month looks like 2026-11.');
    }
    $prefix = $month . '-';
    $d = ['month' => $month, 'label' => monthly_report_label($month), 'site' => site_name(), 'url' => site_url('/')];

    // Visitors: one file per day in data/stats/ (lib/stats.php). No files for the month means nothing was counted.
    $visitors = null;
    $views = null;
    foreach (glob(STATS_DIR . '/' . $prefix . '??.json') ?: [] as $file) {
        $day = read_json($file) ?? [];
        $visitors = ($visitors ?? 0) + count((array) ($day['visitors'] ?? []));
        $views = ($views ?? 0) + (int) ($day['views'] ?? 0);
    }
    $d['visitors'] = $visitors;
    $d['views'] = $views;

    // Appointments made and cancelled in the month (by when they were made or cancelled, not when they happen).
    $d['bookings_made'] = null;
    $d['bookings_cancelled'] = null;
    if (feature_on('appointments') && function_exists('appt_bookings')) {
        $made = 0;
        $cancelled = 0;
        foreach (appt_bookings() as $b) {
            if (str_starts_with((string) ($b['created'] ?? ''), $prefix)) {
                $made++;
            }
            if (($b['status'] ?? '') === 'cancelled' && str_starts_with((string) ($b['cancelled'] ?? ''), $prefix)) {
                $cancelled++;
            }
        }
        $d['bookings_made'] = $made;
        $d['bookings_cancelled'] = $cancelled;
    }

    // Form answers: one file per answer under data/forms/<form>/, named by its date.
    $d['form_answers'] = null;
    if (feature_on('forms') && function_exists('form_submissions')) {
        $n = 0;
        foreach (all_items('forms') as $form) {
            foreach (form_submissions((string) $form['slug']) as $s) {
                if (str_starts_with((string) $s['id'], $prefix)) {
                    $n++;
                }
            }
        }
        $d['form_answers'] = $n;
    }

    // Contact messages received in the month, and how many could not be emailed.
    $messages = 0;
    $unsent = 0;
    foreach (glob(MESSAGE_DIR . '/*.json') ?: [] as $file) {
        $m = read_json($file) ?? [];
        if (str_starts_with((string) ($m['received'] ?? ''), $prefix)) {
            $messages++;
            if (empty($m['emailed'])) {
                $unsent++;
            }
        }
    }
    $d['messages'] = $messages;
    $d['messages_unsent'] = $unsent;

    // Uptime of the sites this one watches: checks every 10 minutes by fleet alerts, tallied per month.
    $d['uptime'] = null;
    if (function_exists('fleet_sites') && fleet_sites()) {
        $tally = (array) ((read_json(FLEET_UPTIME_FILE) ?? [])[$month] ?? []);
        $d['uptime'] = [];
        foreach (fleet_sites() as $site) {
            $t = (array) ($tally[$site['url']] ?? []);
            $checks = (int) ($t['checks'] ?? 0);
            $fails = (int) ($t['fails'] ?? 0);
            $d['uptime'][] = ['name' => $site['name'] !== '' ? $site['name'] : $site['url'], 'checks' => $checks, 'fails' => $fails,
                'percent' => $checks > 0 ? round(100 * ($checks - $fails) / $checks, $fails === 0 ? 0 : 1) : null];
        }
    }

    // Backups and the certificate, as the status report knows them (no network calls here).
    $backup = function_exists('backup_archives') ? (backup_archives()[0] ?? null) : null;
    $d['last_backup'] = $backup ? date('c', (int) $backup['time']) : null;
    $status = defined('UPDATE_STATUS') ? (read_json(UPDATE_STATUS) ?? []) : [];
    $d['ssl_days'] = isset($status['ssl_days']) && $status['ssl_days'] !== null ? (int) $status['ssl_days'] : null;

    $d['version'] = cms_version();
    $d['changes'] = monthly_report_changes($month);
    return $d;
}

/** The CHANGELOG.md entries dated in the month, newest first, as [version => ['date' => ..., 'text' => ...]]. */
function monthly_report_changes(string $month, ?string $file = null): array
{
    $text = (string) @file_get_contents($file ?? ROOT . '/CHANGELOG.md');
    preg_match_all('/^## (\d+\.\d+\.\d+)[^\n\d]*(\d{4}-\d{2}-\d{2})?[^\n]*\n(.*?)(?=^## \d|\z)/ms', $text, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $entry) {
        if (($entry[2] ?? '') !== '' && str_starts_with($entry[2], $month . '-')) {
            $out[$entry[1]] = ['date' => $entry[2], 'text' => trim($entry[3])];
        }
    }
    return $out;
}

/** The first line of a changelog entry that is not a heading or bullet marker: its one-line summary. */
function monthly_report_change_summary(string $text): string
{
    foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
        $line = trim(preg_replace('/^[#*\-\s]+/', '', $line) ?? '');
        if ($line !== '') {
            return strip_tags(preg_replace('/\*\*|`/', '', $line) ?? $line);
        }
    }
    return '';
}

/** The email body, plain HTML: short headings and one fact per line. */
function monthly_report_html(array $d, bool $partial = false): string
{
    $n = fn(?int $v, string $one, string $many) => $v === null ? 'not counted' : $v . ' ' . ($v === 1 ? $one : $many);
    $h = '<p>' . ($partial ? 'So far in ' : 'In ') . h($d['label']) . ' on <a href="' . h($d['url']) . '">' . h($d['site']) . '</a>:</p>';

    $h .= '<h2>Visitors</h2><p>';
    $h .= $d['visitors'] === null ? 'Visitors were not counted this month.' : h($n($d['visitors'], 'visitor', 'visitors')) . ' and ' . h($n($d['views'], 'page view', 'page views')) . '.';
    $h .= '</p>';

    $h .= '<h2>Appointments</h2><p>';
    $h .= $d['bookings_made'] === null ? 'Appointments are not turned on.'
        : h($n($d['bookings_made'], 'appointment', 'appointments')) . ' made and ' . (int) $d['bookings_cancelled'] . ' cancelled.';
    $h .= '</p>';

    $h .= '<h2>Forms and messages</h2><p>';
    $h .= $d['form_answers'] === null ? 'Forms are not turned on.' : h($n($d['form_answers'], 'form answer', 'form answers')) . '.';
    $h .= '<br>' . h($n($d['messages'], 'contact message', 'contact messages')) . '.';
    if ((int) $d['messages_unsent'] > 0) {
        $h .= ' <strong>' . (int) $d['messages_unsent'] . ' could not be emailed</strong>: read them under Messages.';
    }
    $h .= '</p>';

    if ($d['uptime'] !== null) {
        $h .= '<h2>Sites you watch</h2><ul>';
        foreach ($d['uptime'] as $s) {
            $h .= '<li>' . h($s['name']) . ': ' . ($s['percent'] === null ? 'no checks recorded yet' : h((string) $s['percent']) . '% up over ' . (int) $s['checks'] . ' checks'
                . ((int) $s['fails'] > 0 ? ', ' . (int) $s['fails'] . ' ' . ($s['fails'] === 1 ? 'miss' : 'misses') : '')) . '</li>';
        }
        $h .= '</ul>';
    }

    $h .= '<h2>Backups and certificate</h2><p>';
    $h .= $d['last_backup'] === null ? '<strong>No nightly backup has run yet.</strong>' : 'Latest backup: ' . h(format_date((string) $d['last_backup'])) . '.';
    $h .= '<br>';
    if ($d['ssl_days'] === null) {
        $h .= 'SSL certificate: not checked yet.';
    } else {
        $h .= 'SSL certificate: ' . (int) $d['ssl_days'] . ' days left' . ((int) $d['ssl_days'] < 14 ? ' — <strong>renew it soon</strong>' : '') . '.';
    }
    $h .= '</p>';

    $h .= '<h2>The kit</h2><p>Full Circle CMS ' . h($d['version']) . '.';
    if ($d['changes']) {
        $h .= ' Changes this month:</p><ul>';
        foreach ($d['changes'] as $version => $c) {
            $summary = monthly_report_change_summary((string) $c['text']);
            $h .= '<li>' . h($version) . ' (' . h(format_date((string) $c['date'])) . ')' . ($summary !== '' ? ': ' . h($summary) : '') . '</li>';
        }
        $h .= '</ul>';
    } else {
        $h .= ' Nothing changed in the kit this month.</p>';
    }
    $h .= '<p><a href="' . h(site_url('/admin/')) . '">Open the control panel</a>. This report is sent on the 1st of each month; turn it off under Settings, Monthly report.</p>';
    return $h;
}

/** Build and email one month's report to the owner. $partial marks a report for the month in progress. */
function monthly_report_send(string $month, bool $partial = false): void
{
    $d = monthly_report_data($month);
    send_mail_owner('Monthly report for ' . site_name() . ': ' . $d['label'] . ($partial ? ' so far' : ''), monthly_report_html($d, $partial));
}

/** Once a month, from the daily cron slot: last month's report, on the 1st (or the first daily run after it, within the first 5 days). */
function monthly_report_cron(): void
{
    if (!monthly_report_on() || !mail_configured() || (int) date('j') > 5) {
        return;
    }
    $month = date('Y-m', (int) strtotime('first day of last month'));
    $state = read_json(MONTHLY_REPORT_STATE) ?? ['sent' => []];
    if (isset($state['sent'][$month])) {
        return;
    }
    monthly_report_send($month);
    $state['sent'][$month] = date('c');
    $state['sent'] = array_slice($state['sent'], -24, null, true);
    write_json(MONTHLY_REPORT_STATE, $state);
}

register_cron('daily', 'monthly_report_cron');

/* ---------- Fleet uptime: how many checks each watched site answered, per month ---------- */

/** Add one round of checks to the tally. Pure: [month => [url => ['name', 'checks', 'fails']]], the last three months kept. */
function fleet_uptime_tally(array $tally, array $results, int $now): array
{
    $month = date('Y-m', $now);
    foreach ($results as $r) {
        $url = (string) ($r['url'] ?? '');
        if ($url === '') {
            continue;
        }
        $t = (array) ($tally[$month][$url] ?? ['name' => '', 'checks' => 0, 'fails' => 0]);
        $t['name'] = (string) ($r['name'] ?? $t['name']);
        $t['checks'] = (int) $t['checks'] + 1;
        $t['fails'] = (int) $t['fails'] + (empty($r['ok']) ? 1 : 0);
        $tally[$month][$url] = $t;
    }
    krsort($tally);
    return array_slice($tally, 0, 3, true);
}

function fleet_uptime_record(array $results): void
{
    write_json(FLEET_UPTIME_FILE, fleet_uptime_tally(read_json(FLEET_UPTIME_FILE) ?? [], $results, time()));
}

/* ---------- Settings ---------- */

function monthly_report_settings_html(array $errors): string
{
    $sent = (array) ((read_json(MONTHLY_REPORT_STATE) ?? [])['sent'] ?? []);
    $last = $sent ? array_key_last($sent) : null;
    return '<section aria-labelledby="monthly-report-heading" id="monthly-report"><h2 id="monthly-report-heading">Monthly report</h2>'
        . '<p>On the 1st of each month, one email to the addresses under Email sending: visitors, appointments, form answers and messages, the sites you watch, backups, the certificate, and what changed in the kit.</p>'
        . '<p>Status: <strong>' . (!mail_configured() ? 'Email sending is not set up, so no report can go out.' : (monthly_report_on() ? 'On.' : 'Off.'))
        . ($last !== null ? ' Last sent: the report for ' . h(monthly_report_label((string) $last)) . '.' : '') . '</strong></p>'
        . '<form method="post" action="' . h(admin_url('settings')) . '#monthly-report">' . csrf_field() . '<input type="hidden" name="part" value="monthly_report">'
        . checkbox_field('monthly_report', 'Email me a report on the 1st of each month', monthly_report_on())
        . '<div class="form-actions"><button type="submit" class="button">Save</button>'
        . '<button type="submit" class="button button-secondary" name="do" value="send_now"' . (mail_configured() ? '' : ' disabled') . '>Send me this month’s report now</button></div></form></section>';
}

function monthly_report_settings_save(): never
{
    if (($_POST['do'] ?? '') === 'send_now') {
        try {
            monthly_report_send(date('Y-m'), true);
            flash('Sent the report for ' . monthly_report_label(date('Y-m')) . ' so far to ' . config()['contact_recipients'] . '.');
        } catch (Throwable $e) {
            flash('The report could not be sent: ' . $e->getMessage(), 'error');
        }
        go(admin_url('settings') . '#monthly-report');
    }
    $on = !empty($_POST['monthly_report']);
    save_settings(array_merge(settings(), ['monthly_report' => $on]));
    flash($on ? 'The monthly report is on. It goes out on the 1st of each month.' : 'The monthly report is off.');
    go(admin_url('settings') . '#monthly-report');
}

register_settings_section('monthly_report_settings_html', 'monthly_report', 'monthly_report_settings_save');
