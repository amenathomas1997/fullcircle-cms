<?php
/**
 * Fleet alerts: the site that watches the others (through the Sites screen) emails its owner
 * when something needs a person, instead of waiting for someone to open the screen.
 *
 * Every 10 minutes (the "often" cron slot) each listed site is asked for its status, and so is
 * this site itself. An email goes out when:
 *   - a site has not answered for two checks in a row (about 20 minutes), and again when it is back;
 *   - a site's SSL certificate has under 14 days left;
 *   - a site has no backup from the last two nights;
 *   - a site has contact messages it could not email.
 * Apart from "down" and "back", each concern is emailed at most once a day per site, so a known
 * problem does not fill the inbox. Several concerns found in one check go in one email.
 * State lives in data/fleet-alerts.json; the switch is settings()['fleet_alerts'] (on by default).
 */

declare(strict_types=1);

const FLEET_ALERTS = DATA_DIR . '/fleet-alerts.json';
const FLEET_ALERT_DOWN_AFTER = 2;        // failed checks in a row before "down" is sent
const FLEET_ALERT_REPEAT = 86400;        // seconds before the same concern is emailed again

function fleet_alerts_on(): bool
{
    return (bool) (settings()['fleet_alerts'] ?? true);
}

/**
 * Decide what to say given this check's results and what was said before. Pure, so it can be tested:
 * returns [alerts, newState]; each alert is ['site' => name, 'url' => url, 'kind' => 'down'|'back'|'ssl'|'backup'|'messages', 'text' => ...].
 */
function fleet_alert_decisions(array $results, array $state, int $now): array
{
    $alerts = [];
    $sites = (array) ($state['sites'] ?? []);
    $seen = [];
    foreach ($results as $r) {
        $url = (string) $r['url'];
        $name = (string) ($r['name'] ?: $url);
        $seen[$url] = true;
        $s = $sites[$url] ?? ['fails' => 0, 'down' => false, 'alerted' => []];
        if (empty($r['ok'])) {
            $s['fails'] = (int) $s['fails'] + 1;
            if (!$s['down'] && $s['fails'] >= FLEET_ALERT_DOWN_AFTER) {
                $s['down'] = true;
                $s['down_since'] = $now;
                $alerts[] = ['site' => $name, 'url' => $url, 'kind' => 'down', 'text' => "$name is not answering: " . (string) ($r['error'] ?: 'no status') . '. It has failed ' . $s['fails'] . ' checks in a row.'];
            }
            $sites[$url] = $s;
            continue;
        }
        if ($s['down']) {
            $mins = isset($s['down_since']) ? max(1, (int) round(($now - (int) $s['down_since']) / 60)) : null;
            $alerts[] = ['site' => $name, 'url' => $url, 'kind' => 'back', 'text' => "$name is answering again" . ($mins !== null ? " after about $mins minutes" : '') . '.'];
        }
        $s['fails'] = 0;
        $s['down'] = false;
        unset($s['down_since']);
        $status = (array) ($r['status'] ?? []);
        $concerns = [];
        if (array_key_exists('ssl_days', $status) && $status['ssl_days'] !== null && (int) $status['ssl_days'] < 14) {
            $concerns['ssl'] = 'The SSL certificate of ' . $name . ' expires in ' . (int) $status['ssl_days'] . ' days';
        }
        if (empty($status['last_backup'])) {
            if (!empty($status['timestamp']) && isset($s['first_seen']) && $now - (int) $s['first_seen'] > 2 * 86400) {
                $concerns['backup'] = $name . ' has never been backed up';
            }
        } elseif (strtotime((string) $status['last_backup']) < $now - 2 * 86400) {
            $concerns['backup'] = 'The last backup of ' . $name . ' was ' . date('F j', (int) strtotime((string) $status['last_backup']));
        }
        if (($status['unsent_messages'] ?? 0) > 0) {
            $n = (int) $status['unsent_messages'];
            $concerns['messages'] = $name . ' has ' . $n . ' contact ' . ($n === 1 ? 'message' : 'messages') . ' it could not email';
        }
        $s['first_seen'] ??= $now;
        $alerted = (array) ($s['alerted'] ?? []);
        foreach ($concerns as $kind => $text) {
            if (($alerted[$kind] ?? 0) < $now - FLEET_ALERT_REPEAT) {
                $alerts[] = ['site' => $name, 'url' => $url, 'kind' => $kind, 'text' => $text . '.'];
                $alerted[$kind] = $now;
            }
        }
        foreach (array_keys($alerted) as $kind) {
            if (!isset($concerns[$kind])) {
                unset($alerted[$kind]); // cleared: the next time it happens it is news again
            }
        }
        $s['alerted'] = $alerted;
        $sites[$url] = $s;
    }
    foreach (array_keys($sites) as $url) {
        if (!isset($seen[$url])) {
            unset($sites[$url]); // removed from the Sites screen
        }
    }
    return [$alerts, ['sites' => $sites, 'last_run' => $now]];
}

/** This site's own report, in the same shape as a fleet result, so it is watched too. */
function fleet_self_result(): array
{
    try {
        $status = site_status();
        return ['name' => site_name() . ' (this site)', 'url' => rtrim(site_url('/'), '/'), 'ok' => true, 'status' => $status, 'error' => ''];
    } catch (Throwable $e) {
        return ['name' => site_name() . ' (this site)', 'url' => rtrim(site_url('/'), '/'), 'ok' => false, 'status' => null, 'error' => $e->getMessage()];
    }
}

/** One check of every site plus this one; emails the owner when there is news. Returns the alerts sent. */
function fleet_alerts_run(bool $force = false): array
{
    if (!$force && (!fleet_alerts_on() || !mail_configured())) {
        return [];
    }
    $results = fleet_sites() ? check_fleet()['sites'] : [];
    $results[] = fleet_self_result();
    // Each round of checks is tallied per month for the monthly report's uptime figures (lib/features/monthly-report.php).
    if (function_exists('fleet_uptime_record')) {
        fleet_uptime_record(array_slice($results, 0, -1));
    }
    $state = read_json(FLEET_ALERTS) ?? [];
    [$alerts, $newState] = fleet_alert_decisions($results, $state, time());
    if ($alerts) {
        try {
            fleet_alerts_email($alerts);
            $newState['last_sent'] = date('c');
        } catch (Throwable $e) {
            error_log('Fleet alerts: could not email: ' . $e->getMessage());
            // Forget these so they are tried again next time rather than lost.
            return [];
        }
    }
    write_json(FLEET_ALERTS, $newState);
    return $alerts;
}

function fleet_alerts_email(array $alerts): void
{
    $downs = array_filter($alerts, fn($a) => $a['kind'] === 'down');
    $subject = count($alerts) === 1 ? 'Site alert: ' . $alerts[0]['text'] : ($downs ? count($downs) . ' site' . (count($downs) === 1 ? ' is' : 's are') . ' not answering' : count($alerts) . ' things to look at on your sites');
    $items = '';
    foreach ($alerts as $a) {
        $items .= '<li>' . h($a['text']) . ' <a href="' . h($a['url'] . '/') . '">' . h(preg_replace('#^https?://#', '', $a['url'])) . '</a></li>';
    }
    $html = '<p>From the site checks on ' . h(site_name()) . ' at ' . h(date('g:i a, F j')) . ':</p><ul>' . $items . '</ul>'
        . '<p><a href="' . h(site_url('/admin/?a=sites')) . '">Open the Sites screen</a> for the full picture. '
        . 'A site that is down is checked every 10 minutes and you are told when it is back; other concerns are repeated at most once a day.</p>';
    send_mail_owner(mb_substr($subject, 0, 150), $html);
}

register_cron('often', function (): void {
    fleet_alerts_run();
});
