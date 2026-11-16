<?php
/**
 * Email updates: visitors sign up with [subscribe-form], confirm by email
 * (double opt-in), and can unsubscribe from any update. Updates are written in
 * the control panel and sent in the background by tools/send-updates.php
 * (run by cron), one email per person, with the email sending settings.
 *
 * Confirm and unsubscribe links open a page with a button, rather than acting
 * straight away, because email security scanners open every link in a message.
 */

declare(strict_types=1);

const SUBSCRIBER_FILE = DATA_DIR . '/subscribers.json';
const OUTBOX_DIR = DATA_DIR . '/outbox';

function subscribers(): array
{
    return read_json(SUBSCRIBER_FILE) ?? [];
}

function save_subscribers(array $list): void
{
    write_json(SUBSCRIBER_FILE, $list);
    @chmod(SUBSCRIBER_FILE, 0600);
}

function active_subscribers(): array
{
    return array_keys(array_filter(subscribers(), fn($s) => ($s['status'] ?? '') === 'active'));
}

function find_subscriber_by_token(string $token): ?string
{
    if (!preg_match('/^[0-9a-f]{40}$/', $token)) {
        return null;
    }
    foreach (subscribers() as $email => $s) {
        if (hash_equals((string) ($s['token'] ?? ''), $token)) {
            return $email;
        }
    }
    return null;
}

function updates_footer(string $token): string
{
    $address = trim((string) (settings()['mailing_address'] ?? ''));
    return '<hr><p style="font-size:14px;color:#444">You’re getting this because you signed up for updates at ' . h(site_host()) . '. '
        . '<a href="' . h(site_url('/updates/unsubscribe/?t=' . $token)) . '">Unsubscribe</a> at any time.</p>'
        . ($address !== '' ? '<p style="font-size:14px;color:#444">' . h(legal_name()) . ', ' . h($address) . '</p>' : '');
}

function handle_subscribe_post(string $pagePath): array
{
    $email = strtolower(trim((string) ($_POST['s_email'] ?? '')));
    if (($_POST['website'] ?? '') !== '' || !contact_token_ok((string) ($_POST['form_token'] ?? ''))) {
        header('Location: ' . $pagePath . '?subscribed=1#subscribe-status', true, 303);
        exit;
    }
    if ($email === '') {
        return ['error' => 'Enter your email address.', 'email' => $email];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        return ['error' => 'Enter an email address in the right format, like name@example.com.', 'email' => $email];
    }
    if (contact_rate_limited()) {
        return ['error' => 'You have sent several forms in the last hour. Please try again later.', 'email' => $email];
    }
    $list = subscribers();
    $existing = $list[$email] ?? null;
    // Someone already subscribed gets the same answer, so the form never reveals who is on the list.
    if (($existing['status'] ?? '') !== 'active') {
        $token = bin2hex(random_bytes(20));
        $list[$email] = ['status' => 'pending', 'token' => $token, 'created' => date('c')];
        save_subscribers($list);
        try {
            send_mail_to([$email], 'Please confirm your ' . site_name() . ' updates',
                '<p>Hello,</p><p>Someone, hopefully you, asked to get updates from ' . h(site_name()) . ' by email.</p>'
                . '<p><a href="' . h(site_url('/updates/confirm/?t=' . $token)) . '">Confirm your email address</a></p>'
                . '<p>If you didn’t ask for this, you can ignore this email and you won’t hear from us again.</p>'
                . '<p>' . h(site_name()) . (contact_phone() !== '' ? '<br>' . h(contact_phone()) : '') . '</p>');
        } catch (Throwable $e) {
            error_log('Subscription confirmation email failed: ' . $e->getMessage());
        }
    }
    header('Location: ' . $pagePath . '?subscribed=1#subscribe-status', true, 303);
    exit;
}

function render_subscribe_form(array $state = []): string
{
    if (isset($_GET['subscribed'])) {
        return '<div id="subscribe-status" class="notice notice-success" role="status" tabindex="-1" data-focus-on-load>'
            . '<h2>Check your email</h2><p>We sent you a link to confirm your address. Updates start once you confirm. '
            . 'If it doesn’t arrive in a few minutes, check your junk folder.</p></div>';
    }
    $error = $state['error'] ?? '';
    $out = '';
    if ($error !== '') {
        $out .= '<div id="subscribe-status" class="error-summary" role="alert" tabindex="-1" data-focus-on-load><h2>There is a problem</h2>'
            . '<ul><li><a href="#s_email">' . h($error) . '</a></li></ul></div>';
    }
    return $out . '<form class="contact-form subscribe-form" method="post" action="#subscribe-status" novalidate>'
        . '<div class="field' . ($error ? ' field-error' : '') . '"><label for="s_email">Your email address</label>'
        . '<p class="hint" id="s_email-hint">About one email a month, when there’s a new post or cheat sheet. Unsubscribe at any time.</p>'
        . ($error ? '<p class="error-message" id="s_email-error"><span class="visually-hidden">Error: </span>' . h($error) . '</p>' : '')
        . '<input type="email" id="s_email" name="s_email" autocomplete="email" required value="' . h((string) ($state['email'] ?? '')) . '"'
        . ' aria-describedby="s_email-hint' . ($error ? ' s_email-error" aria-invalid="true' : '') . '"></div>'
        . '<div class="hp-field" aria-hidden="true"><label for="s-website">Leave this empty</label><input id="s-website" name="website" type="text" tabindex="-1" autocomplete="off"></div>'
        . '<input type="hidden" name="form_token" value="' . h(contact_form_token()) . '">'
        . '<input type="hidden" name="subscribe_form" value="1">'
        . '<button type="submit" class="button">Sign up for updates</button></form>';
}

/** /updates/confirm/ and /updates/unsubscribe/: a page with a button; the button does the change. */
function subscription_link(string $action): never
{
    $token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
    $email = find_subscriber_by_token($token);
    // A confirm link only works while the sign-up is waiting (or already confirmed); it can't undo an unsubscribe.
    if ($email !== null && $action === 'confirm' && !in_array(subscribers()[$email]['status'] ?? '', ['pending', 'active'], true)) {
        $email = null;
    }
    $done = false;
    if ($email !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $list = subscribers();
        if ($action === 'confirm') {
            if ($list[$email]['status'] !== 'active') {
                $list[$email]['status'] = 'active';
                $list[$email]['confirmed'] = date('c');
            }
        } else {
            $list[$email]['status'] = 'unsubscribed';
            $list[$email]['left'] = date('c');
            // Old links in past emails stop working.
            $list[$email]['token'] = bin2hex(random_bytes(20));
        }
        save_subscribers($list);
        $done = true;
    }
    if ($email === null) {
        $title = 'That link has expired';
        $body = '<p>This link isn’t valid any more. You may have already used it. <a href="/updates/">Sign up again</a> or <a href="/contact-us/">contact us</a>.</p>';
    } elseif ($done) {
        $title = $action === 'confirm' ? 'You’re signed up' : 'You’re unsubscribed';
        $body = $action === 'confirm'
            ? '<p>Thank you. You’ll get an email when there’s a new blog post or cheat sheet.</p><p><a href="/blog/">Read the blog</a> or <a href="/resources/">browse the cheat sheets</a>.</p>'
            : '<p>You won’t get any more update emails from us. Changed your mind? <a href="/updates/">Sign up again</a>.</p>';
    } else {
        $title = $action === 'confirm' ? 'Confirm your email updates' : 'Unsubscribe from email updates';
        $body = '<form method="post"><input type="hidden" name="t" value="' . h($token) . '"><p>'
            . ($action === 'confirm' ? 'Press the button to start getting new blog posts and cheat sheets by email.' : 'Press the button to stop getting update emails.')
            . '</p><button type="submit" class="button">' . ($action === 'confirm' ? 'Confirm my email updates' : 'Unsubscribe') . '</button></form>';
    }
    header('X-Robots-Tag: noindex');
    send_html(render_layout($title, '<h1 tabindex="-1" data-focus-on-load>' . h($title) . '</h1>' . $body));
}

/** Put an update in the queue for every active subscriber. Returns how many it will go to. */
function queue_update(string $subject, string $html): int
{
    $recipients = active_subscribers();
    if (!$recipients) {
        return 0;
    }
    $id = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
    archive_update($subject, $html);
    write_json(OUTBOX_DIR . "/$id.json", ['subject' => $subject, 'html' => $html, 'to' => $recipients, 'sent' => [], 'failed' => [], 'created' => date('c')]);
    return count($recipients);
}

/** Sign-ups that were never confirmed are forgotten after a week. */
function purge_pending_subscribers(): void
{
    $list = subscribers();
    $cutoff = time() - 7 * 86400;
    $kept = array_filter($list, fn($s) => ($s['status'] ?? '') !== 'pending' || strtotime((string) ($s['created'] ?? '')) > $cutoff);
    if (count($kept) !== count($list)) {
        save_subscribers($kept);
    }
}

const OUTBOX_TRIES = 3;

/** A failed recipient is tried again later; after OUTBOX_TRIES tries it's given up. */
function outbox_gave_up(array $failure): bool
{
    return (int) ($failure['attempts'] ?? OUTBOX_TRIES) >= OUTBOX_TRIES;
}

/**
 * Send up to $limit queued emails. Called by tools/send-updates.php every 10 minutes.
 * Only one copy runs at a time, so a slow run can't overlap the next and send anyone two copies.
 * A send that fails (Microsoft down, for instance) is tried again after 30 minutes, then 2 hours.
 */
function process_outbox(int $limit = 40): array
{
    $sent = 0;
    $failed = 0;
    if (!is_dir(OUTBOX_DIR)) {
        return ['sent' => 0, 'failed' => 0];
    }
    $lock = fopen(OUTBOX_DIR . '/.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return ['sent' => 0, 'failed' => 0, 'busy' => true];
    }
    $list = subscribers();
    foreach (glob(OUTBOX_DIR . '/*.json') ?: [] as $file) {
        $job = read_json($file);
        if (!$job) {
            continue;
        }
        foreach ($job['to'] as $email) {
            if ($sent + $failed >= $limit) {
                break 2;
            }
            $failure = $job['failed'][$email] ?? null;
            if (in_array($email, $job['sent'], true) || ($failure && (outbox_gave_up($failure) || ($failure['next'] ?? 0) > time()))) {
                continue;
            }
            if (($list[$email]['status'] ?? '') !== 'active') {
                $job['sent'][] = $email;
                continue;
            }
            try {
                send_mail_to([$email], $job['subject'], $job['html'] . updates_footer((string) $list[$email]['token']));
                $job['sent'][] = $email;
                unset($job['failed'][$email]);
                $sent++;
            } catch (Throwable $e) {
                $attempts = (int) ($failure['attempts'] ?? 0) + 1;
                $job['failed'][$email] = ['error' => $e->getMessage(), 'attempts' => $attempts, 'next' => time() + ($attempts === 1 ? 1800 : 7200), 'at' => date('c')];
                $failed++;
            }
            write_json($file, $job);
        }
        $givenUp = count(array_filter($job['failed'], 'outbox_gave_up'));
        if (count($job['sent']) + $givenUp >= count($job['to'])) {
            $job['finished'] = date('c');
            write_json(DATA_DIR . '/outbox-done/' . basename($file), $job);
            unlink($file);
        }
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    return ['sent' => $sent, 'failed' => $failed];
}
