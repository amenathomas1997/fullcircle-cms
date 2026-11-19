<?php
/**
 * Text messages (SMS) through Twilio, for any part of the kit that wants to text a visitor who
 * asked for it. Today that is the appointment reminder the day before (lib/features/appointments.php).
 *
 * Off until the three values are filled in under Settings, Text messages, and kept in data/config.php:
 *   sms_account_sid   the Twilio account SID
 *   sms_auth_token    its auth token (a secret; shown as saved, never echoed)
 *   sms_from          the phone number texts come from, as +15555550100, or a Messaging Service SID (MG…)
 * Twilio honours STOP and START replies itself, and a number that replied STOP is refused with an error.
 * Texts carry a first name, a time and the site's name: never more than that.
 *
 * sms_send() hands the request to $GLOBALS['sms_mock'] while tools/selftest.php sets it, so the
 * reminder code can be run without a Twilio account.
 */

declare(strict_types=1);

const SMS_MAX_LENGTH = 320;

/** The texting service, or null until it is set up. */
function sms_cfg(): ?array
{
    $c = config();
    $sid = trim((string) ($c['sms_account_sid'] ?? ''));
    $token = trim((string) ($c['sms_auth_token'] ?? ''));
    $from = trim((string) ($c['sms_from'] ?? ''));
    if ($sid === '' || $token === '' || $from === '') {
        return null;
    }
    return ['sid' => $sid, 'token' => $token, 'from' => $from];
}

function sms_enabled(): bool
{
    return sms_cfg() !== null;
}

/** A phone number written any way ("(515) 555-0100", "515.555.0100", "+44 20 …") as +15155550100, or null when it cannot take a text. */
function sms_number(?string $raw): ?string
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }
    $digits = (string) preg_replace('/\D+/', '', $raw);
    if (str_starts_with($raw, '+')) {
        return strlen($digits) >= 8 && strlen($digits) <= 15 ? '+' . $digits : null;
    }
    if (strlen($digits) === 10) {
        return '+1' . $digits;
    }
    if (strlen($digits) === 11 && $digits[0] === '1') {
        return '+' . $digits;
    }
    return null;
}

/**
 * Send one text. Returns ['ok' => bool, 'id' => provider id or null, 'status' => queued|failed|unknown, 'error' => words or null].
 * "unknown" means the request went out but no answer came back in time: Twilio may have queued it, so it must not be sent again.
 */
function sms_send(string $to, string $body): array
{
    $body = mb_substr(trim($body), 0, SMS_MAX_LENGTH);
    if (isset($GLOBALS['sms_mock'])) {
        return ($GLOBALS['sms_mock'])($to, $body); // tools/selftest.php stands in for Twilio
    }
    $cfg = sms_cfg();
    if (!$cfg) {
        return ['ok' => false, 'id' => null, 'status' => 'failed', 'error' => 'Text messages are not set up (Settings, Text messages).'];
    }
    $fields = ['To' => $to, 'Body' => $body];
    if (str_starts_with($cfg['from'], 'MG')) {
        $fields['MessagingServiceSid'] = $cfg['from'];
    } else {
        $fields['From'] = $cfg['from'];
    }
    $ch = curl_init('https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($cfg['sid']) . '/Messages.json');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields), CURLOPT_USERPWD => $cfg['sid'] . ':' . $cfg['token'], CURLOPT_USERAGENT => 'Full Circle CMS']);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $uploaded = (float) curl_getinfo($ch, CURLINFO_SIZE_UPLOAD);
    $curlError = curl_error($ch);
    curl_close($ch);
    $data = is_string($resp) ? json_decode($resp, true) : null;
    if ($code >= 200 && $code < 300 && is_array($data) && !empty($data['sid'])) {
        return ['ok' => true, 'id' => (string) $data['sid'], 'status' => (string) ($data['status'] ?? 'queued'), 'error' => null];
    }
    if ($code >= 500 || ($code === 0 && $uploaded > 0)) {
        return ['ok' => false, 'id' => null, 'status' => 'unknown', 'error' => 'The texting service did not confirm it in time; it may still arrive, so it is not sent again.'];
    }
    $why = is_array($data) ? (string) ($data['message'] ?? 'Refused') : ($curlError !== '' ? $curlError : 'No answer from the texting service');
    return ['ok' => false, 'id' => null, 'status' => 'failed', 'error' => mb_substr($why, 0, 255)];
}

/* ---------- Settings ---------- */

function sms_settings_html(array $errors): string
{
    $c = config();
    $saved = ($c['sms_auth_token'] ?? '') !== '';
    return '<section aria-labelledby="sms-heading" id="sms-settings"><h2 id="sms-heading">Text messages</h2>'
        . '<p>Lets the site text a visitor who asks for it: today, a reminder the day before an appointment, to visitors who tick the box when they book. '
        . 'Texts go through <a href="https://www.twilio.com/">Twilio</a>, which charges per message: make an account there, buy a number (or set up a messaging service), and copy the account SID, auth token and number from its console. '
        . 'Leave these empty to send no texts.</p>'
        . '<p>Status: <strong>' . (sms_enabled() ? 'Set up. Texts come from ' . h((string) $c['sms_from']) . '.' : 'Not set up, so no texts are sent.') . '</strong></p>'
        . '<form method="post" action="' . h(admin_url('settings')) . '#sms-settings">' . csrf_field() . '<input type="hidden" name="part" value="sms">'
        . text_field('sms_account_sid', 'Twilio account SID', (string) ($c['sms_account_sid'] ?? ''), ['spellcheck' => 'false', 'autocomplete' => 'off', 'error' => $errors['sms_account_sid'] ?? null, 'hint' => 'Starts with AC.'])
        . text_field('sms_auth_token', 'Twilio auth token', '', ['type' => 'password', 'autocomplete' => 'off', 'hint' => $saved ? 'A token is saved. Leave this empty to keep it, or paste a new one to replace it.' : 'From the Twilio console. Kept in data/config.php, which is private.'])
        . text_field('sms_from', 'Send texts from', (string) ($c['sms_from'] ?? ''), ['spellcheck' => 'false', 'autocomplete' => 'off', 'error' => $errors['sms_from'] ?? null,
            'hint' => 'The Twilio phone number, written like +15555550100, or a messaging service SID starting with MG.'])
        . text_field('sms_test_to', 'Phone number for a test text (optional)', '', ['type' => 'tel', 'autocomplete' => 'off', 'error' => $errors['sms_test_to'] ?? null, 'hint' => 'Your own mobile. Used only by the test button; not saved.'])
        . '<div class="form-actions"><button type="submit" class="button" name="then" value="save">Save text message settings</button>'
        . '<button type="submit" class="button button-secondary" name="then" value="test">Save and send a test text</button></div></form></section>';
}

function sms_settings_save(): never
{
    $v = ['sms_account_sid' => trim((string) ($_POST['sms_account_sid'] ?? '')), 'sms_from' => trim((string) ($_POST['sms_from'] ?? ''))];
    $errors = [];
    if ($v['sms_account_sid'] !== '' && !preg_match('/^AC[0-9a-f]{32}$/i', $v['sms_account_sid'])) {
        $errors['sms_account_sid'] = 'The account SID is AC followed by 32 letters and digits.';
    }
    if ($v['sms_from'] !== '' && !preg_match('/^MG[0-9a-f]{32}$/i', $v['sms_from']) && sms_number($v['sms_from']) === null) {
        $errors['sms_from'] = 'Enter the number like +15555550100, or a messaging service SID starting with MG.';
    } elseif ($v['sms_from'] !== '' && !str_starts_with($v['sms_from'], 'MG')) {
        $v['sms_from'] = (string) sms_number($v['sms_from']);
    }
    $testTo = trim((string) ($_POST['sms_test_to'] ?? ''));
    if (($_POST['then'] ?? '') === 'test' && sms_number($testTo) === null) {
        $errors['sms_test_to'] = 'Enter the mobile number to text, like 515-555-0100.';
    }
    if ($errors) {
        settings_form($errors);
    }
    if (($_POST['sms_auth_token'] ?? '') !== '') {
        $v['sms_auth_token'] = trim((string) $_POST['sms_auth_token']);
    }
    save_config($v);
    if (function_exists('activity_log')) {
        activity_log('changed settings', 'text messages');
    }
    if (($_POST['then'] ?? '') === 'test') {
        if (!sms_enabled()) {
            flash('Saved, but no test was sent: fill in all three Twilio values first.', 'error');
        } else {
            $r = sms_send((string) sms_number($testTo), 'Test text from ' . site_name() . '. Texts are set up.');
            flash($r['ok'] ? 'Saved. A test text was sent to ' . sms_number($testTo) . '.' : 'Saved, but the test text could not be sent: ' . (string) $r['error'], $r['ok'] ? 'success' : 'error');
        }
    } else {
        flash('Saved the text message settings.' . (sms_enabled() ? '' : ' Texts stay off until all three values are filled in.'));
    }
    go(admin_url('settings') . '#sms-settings');
}

register_settings_section('sms_settings_html', 'sms', 'sms_settings_save');
