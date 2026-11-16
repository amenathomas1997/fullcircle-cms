<?php
// Website health jobs, run by cron every hour:
//   15 * * * * /usr/local/bin/php /home/YOURUSER/public_html/tools/check-links.php
// Once a day (after 6 am): SSL certificates and PHP error count.
// Once a month, or soon after "Check now" in the control panel: every link on the site.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require __DIR__ . '/../lib/bootstrap.php';
@set_time_limit(1800);

// A full link check can take a while; don't start a second one while the first is still running.
$lock = fopen(LINK_REPORT . '.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo date('Y-m-d H:i'), " the previous run is still going; nothing done\n";
    exit(0);
}

$state = read_json(HEALTH_STATE) ?? [];
if ((int) date('G') >= 6 && substr((string) ($state['last_daily'] ?? ''), 0, 10) !== date('Y-m-d')) {
    $warnings = daily_health_check();
    echo date('Y-m-d H:i'), ' daily health check: ', $warnings ? implode(' ', $warnings) : 'all good', "\n";
    run_cron('daily');
}
run_cron('hourly');

$report = read_json(LINK_REPORT);
$due = !$report || strtotime((string) ($report['checked'] ?? '1970-01-01')) < strtotime('-30 days');
if (is_file(LINK_REQUEST) || $due) {
    $report = run_link_check();
    echo date('Y-m-d H:i'), " link check: {$report['total']} links, ", count($report['problems']), " to look at\n";
    try {
        link_report_email($report);
    } catch (Throwable $e) {
        error_log('Link check email failed: ' . $e->getMessage());
    }
}
