<?php
/**
 * Two-step sign-in by emailed code. On a browser an account has not signed in from before,
 * the control panel asks for a six-digit code after the password. The code is emailed to the
 * account's address, works for 10 minutes, and once it is typed the browser is remembered for
 * 30 days with a cookie, so the code is asked for again only on a new browser or device.
 *
 * On by default (settings()['signin_code']); Settings has the switch and an owner-only
 * "Forget all remembered browsers" button. It only works when the site can send email, so
 * the switch cannot be turned on until email sending is set up. An account with an
 * authenticator app (two-factor under My account) already has a second step, so it is not
 * asked for an emailed code as well.
 *
 * Limits: at most 3 codes per account in 15 minutes, 5 tries per code, and the usual
 * 15-minute pause after 5 wrong answers from one address (admin-lib.php). Codes are kept
 * hashed with the site secret, never in the clear, in data/signin-codes.json together with
 * the remembered browsers (hashed tokens). The decisions are pure functions of a state array
 * and the time, so tools/selftest.php can run the whole lifecycle without a browser or a clock.
 *
 * The sign-in screens call signin_code_needed() and signin_code_begin() from admin/people.php;
 * the code screen is ?a=signin_code, which works before anyone is signed in.
 */

declare(strict_types=1);

const SIGNIN_STATE_FILE = DATA_DIR . '/signin-codes.json';
const SIGNIN_CODE_LIFE = 600;            // seconds a code works
const SIGNIN_CODE_MAX_SENDS = 3;         // codes per account in one window
const SIGNIN_CODE_SEND_WINDOW = 900;     // seconds
const SIGNIN_CODE_MAX_TRIES = 5;         // wrong answers before a code is void
const SIGNIN_BROWSER_DAYS = 30;
const SIGNIN_COOKIE = 'fcat_browser';

/* ---------- The switch ---------- */

/** The setting as saved (on unless turned off), apart from whether email works. */
function signin_code_setting(): bool
{
    return array_key_exists('signin_code', settings()) ? !empty(settings()['signin_code']) : true;
}

/** Is the emailed code asked for? Only when the setting is on and the site can email. */
function signin_code_on(): bool
{
    return signin_code_setting() && mail_ready();
}

/* ---------- State: codes and remembered browsers, pure ---------- */

function signin_state(): array
{
    $s = read_json(SIGNIN_STATE_FILE) ?? [];
    return ['codes' => (array) ($s['codes'] ?? []), 'browsers' => (array) ($s['browsers'] ?? [])];
}

function signin_state_save(array $state): void
{
    write_json(SIGNIN_STATE_FILE, $state);
    @chmod(SIGNIN_STATE_FILE, 0600);
}

function signin_code_hash(string $userId, string $code): string
{
    return hash_hmac('sha256', $userId . '|' . $code, site_secret());
}

/**
 * Make a code for an account. Returns [state, code, why]: the code is null and $why says so when
 * too many have been sent lately. A new code replaces the old one.
 */
function signin_code_issue(array $state, string $userId, int $now): array
{
    $u = (array) ($state['codes'][$userId] ?? []);
    $sent = array_values(array_filter((array) ($u['sent'] ?? []), fn($t) => (int) $t > $now - SIGNIN_CODE_SEND_WINDOW));
    if (count($sent) >= SIGNIN_CODE_MAX_SENDS) {
        return [$state, null, 'Several codes have been sent to that address in the last few minutes. Check your inbox and junk folder for the newest one, or wait 15 minutes and sign in again.'];
    }
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $sent[] = $now;
    $state['codes'][$userId] = ['hash' => signin_code_hash($userId, $code), 'expires' => $now + SIGNIN_CODE_LIFE, 'tries' => 0, 'sent' => $sent];
    return [$state, $code, ''];
}

/**
 * Check a typed code. Returns [state, ok, why]. A right code is used up; a wrong one counts as a try;
 * an expired or overused code is void and $why says to ask for a new one.
 */
function signin_code_check(array $state, string $userId, string $typed, int $now): array
{
    $u = (array) ($state['codes'][$userId] ?? []);
    $ask = ' Choose “Send me a new code”.';
    if (($u['hash'] ?? '') === '') {
        return [$state, false, 'There is no code waiting for this account.' . $ask];
    }
    if ((int) ($u['expires'] ?? 0) <= $now) {
        unset($state['codes'][$userId]['hash']);
        return [$state, false, 'That code has expired; codes work for 10 minutes.' . $ask];
    }
    if ((int) ($u['tries'] ?? 0) >= SIGNIN_CODE_MAX_TRIES) {
        unset($state['codes'][$userId]['hash']);
        return [$state, false, 'That code was tried too many times, so it no longer works.' . $ask];
    }
    $typed = (string) preg_replace('/\D+/', '', $typed);
    if (strlen($typed) !== 6 || !hash_equals((string) $u['hash'], signin_code_hash($userId, $typed))) {
        $state['codes'][$userId]['tries'] = (int) ($u['tries'] ?? 0) + 1;
        $left = SIGNIN_CODE_MAX_TRIES - $state['codes'][$userId]['tries'];
        return [$state, false, 'That code is not right. Type the six digits from the newest email.' . ($left <= 0 ? ' It no longer works.' . $ask : '')];
    }
    unset($state['codes'][$userId]['hash']);
    return [$state, true, ''];
}

/** Remember a browser for an account. Returns [state, token]; the token goes in the cookie, only its hash is kept. */
function signin_browser_remember(array $state, string $userId, int $now): array
{
    $token = bin2hex(random_bytes(32));
    $state['browsers'][hash('sha256', $token)] = ['user_id' => $userId, 'created' => $now, 'expires' => $now + SIGNIN_BROWSER_DAYS * 86400];
    return [signin_browsers_tidy($state, $now), $token];
}

/** Is this browser's token a live one for this account? */
function signin_browser_known(array $state, ?string $token, string $userId, int $now): bool
{
    if ($token === null || !preg_match('/^[0-9a-f]{64}$/', $token)) {
        return false;
    }
    $b = $state['browsers'][hash('sha256', $token)] ?? null;
    return is_array($b) && ($b['user_id'] ?? '') === $userId && (int) ($b['expires'] ?? 0) > $now;
}

/** Drop browsers past their 30 days and codes nobody used. */
function signin_browsers_tidy(array $state, int $now): array
{
    $state['browsers'] = array_filter((array) ($state['browsers'] ?? []), fn($b) => is_array($b) && (int) ($b['expires'] ?? 0) > $now);
    foreach ((array) ($state['codes'] ?? []) as $id => $u) {
        $recent = array_filter((array) ($u['sent'] ?? []), fn($t) => (int) $t > $now - SIGNIN_CODE_SEND_WINDOW);
        if (($u['hash'] ?? '') === '' && !$recent) {
            unset($state['codes'][$id]);
        }
    }
    return $state;
}

function signin_browser_count(): int
{
    return count(signin_browsers_tidy(signin_state(), time())['browsers']);
}

/* ---------- Signing in ---------- */

/** Does this sign-in need an emailed code? Not when the switch is off, the account uses an authenticator app, or the browser is remembered. */
function signin_code_needed(array $user): bool
{
    if (!signin_code_on() || ($user['totp_secret'] ?? '') !== '') {
        return false;
    }
    return !signin_browser_known(signin_state(), isset($_COOKIE[SIGNIN_COOKIE]) ? (string) $_COOKIE[SIGNIN_COOKIE] : null, (string) $user['id'], time());
}

/** Email a code to the account and send the browser to the code screen. */
function signin_code_begin(array $user, string $next): never
{
    $_SESSION['pending_code'] = ['id' => (string) $user['id'], 'at' => time(), 'next' => $next];
    $why = signin_code_send($user);
    if ($why !== '') {
        flash($why, 'error');
    }
    go(admin_url('signin_code'));
}

/** Make and email a code. Returns '' or why it could not be sent, in plain words. */
function signin_code_send(array $user): string
{
    [$state, $code, $why] = signin_code_issue(signin_state(), (string) $user['id'], time());
    if ($code === null) {
        return $why;
    }
    $html = '<p>Hello ' . h((string) $user['name']) . ',</p>'
        . '<p>Your sign-in code for the ' . h(site_name()) . ' control panel is:</p>'
        . '<p style="font-size:2rem;letter-spacing:.3em;font-weight:700">' . h($code) . '</p>'
        . '<p>Type it on the sign-in screen within 10 minutes. After that the browser you are using is remembered for 30 days.</p>'
        . '<p>If you did not just sign in, someone else has your password: change it under My account as soon as you can.</p>';
    try {
        send_mail_to([(string) $user['email']], 'Your sign-in code for ' . site_name() . ': ' . $code, $html);
    } catch (Throwable $e) {
        error_log('Sign-in code email failed: ' . $e->getMessage());
        return 'The code could not be emailed: ' . $e->getMessage();
    }
    signin_state_save($state);
    if (function_exists('activity_log')) {
        activity_log('was sent a sign-in code', 'new browser', $user);
    }
    return '';
}

/** Set the cookie that remembers this browser for the account. */
function signin_browser_set_cookie(array $user): void
{
    [$state, $token] = signin_browser_remember(signin_state(), (string) $user['id'], time());
    signin_state_save($state);
    $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    setcookie(SIGNIN_COOKIE, $token, ['expires' => time() + SIGNIN_BROWSER_DAYS * 86400, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
}

/** Forget every remembered browser: everyone is asked for a code at their next sign-in. */
function signin_browsers_forget_all(): void
{
    $state = signin_state();
    $state['browsers'] = [];
    signin_state_save($state);
}

/** The second step: type the emailed code. Works before sign-in (admin/people.php sends ?a=signin_code here). */
function signin_code_screen(bool $post): never
{
    $pending = $_SESSION['pending_code'] ?? null;
    $user = $pending ? find_user((string) $pending['id']) : null;
    if (!$user || time() - (int) ($pending['at'] ?? 0) > SIGNIN_CODE_LIFE * 2) {
        unset($_SESSION['pending_code']);
        flash('Please sign in again, starting with your email address and password.', 'error');
        go(ADMIN_URL);
    }
    $errors = [];
    if ($post) {
        if (!guest_csrf_ok()) {
            $errors['code'] = 'That form had expired. Please try again.';
        } elseif (($_POST['do'] ?? '') === 'resend') {
            $why = signin_code_send($user);
            flash($why === '' ? 'A new code is on its way to ' . $user['email'] . '. The older code no longer works.' : $why, $why === '' ? 'success' : 'error');
            go(admin_url('signin_code'));
        } elseif (login_locked()) {
            $errors['code'] = 'Too many wrong codes. For security, sign-in is paused for 15 minutes.';
        } else {
            [$state, $ok, $why] = signin_code_check(signin_state(), (string) $user['id'], (string) ($_POST['code'] ?? ''), time());
            signin_state_save($state);
            if ($ok) {
                accounts_sign_in($user);
                unset($_SESSION['pending_code']);
                signin_browser_set_cookie($user);
                activity_log('confirmed a new browser with an emailed code');
                flash('You are signed in. This browser is remembered for 30 days, so you will not be asked for a code here again until then.');
                go(safe_next((string) ($pending['next'] ?? ADMIN_URL)));
            }
            record_failed_login();
            activity_log('failed sign-in', 'wrong emailed code', $user);
            $errors['code'] = $why;
        }
    }
    $body = '<p>Hello ' . h((string) $user['name']) . '. You have not signed in from this browser before, so a six-digit code has been emailed to <strong>' . h((string) $user['email']) . '</strong>. '
        . 'It works for 10 minutes. Once you type it, this browser is remembered for 30 days.</p>'
        . '<form method="post" action="' . h(admin_url('signin_code')) . '" class="narrow">' . csrf_field()
        . text_field('code', 'Code from the email', '', ['required' => true, 'autocomplete' => 'one-time-code', 'inputmode' => 'numeric', 'spellcheck' => 'false', 'maxlength' => 7,
            'error' => $errors['code'] ?? null, 'hint' => 'Six digits. Check the junk folder if it has not arrived after a minute.'])
        . '<button type="submit" class="button">Finish signing in</button></form>'
        . '<form method="post" action="' . h(admin_url('signin_code')) . '" class="narrow">' . csrf_field() . '<input type="hidden" name="do" value="resend">'
        . '<button type="submit" class="button button-secondary">Send me a new code</button></form>'
        . '<p><a href="' . h(ADMIN_URL) . '">Start over</a></p>';
    admin_screen('Enter your sign-in code', $body, '', ['errors' => $errors]);
}

/* ---------- Settings ---------- */

function signin_code_settings_html(array $errors): string
{
    $canMail = mail_ready();
    $status = !$canMail ? 'Cannot be turned on yet: the site cannot send email. Fill in Email sending above first.'
        : (signin_code_setting() ? 'On. People are asked for a code the first time they sign in from a browser.' : 'Off.');
    $n = signin_browser_count();
    $hint = 'The code goes to the email address the person signs in with. It is not asked of anyone who uses an authenticator app under My account, or on a browser remembered in the last 30 days.'
        . ($canMail ? '' : ' This cannot be turned on until the site can send email.');
    $out = '<section aria-labelledby="signin-code-heading" id="signin-code"><h2 id="signin-code-heading">Two-step sign-in by email</h2>'
        . '<p>After their password, a person signing in from a new browser is emailed a six-digit code to type. It keeps the control panel safe if a password leaks. '
        . 'Codes work for 10 minutes; a browser that typed one is remembered for 30 days.</p>'
        . '<p>Status: <strong>' . h($status) . '</strong></p>'
        . '<form method="post" action="' . h(admin_url('settings')) . '#signin-code">' . csrf_field() . '<input type="hidden" name="part" value="signin_code">'
        . checkbox_field('signin_code', 'Ask for an emailed code on a new browser', signin_code_setting() && $canMail, ['hint' => $hint, 'error' => $errors['signin_code'] ?? null])
        . '<button type="submit" class="button">Save</button></form>';
    if (function_exists('is_owner') && is_owner()) {
        $out .= '<form method="post" action="' . h(admin_url('settings')) . '#signin-code">' . csrf_field() . '<input type="hidden" name="part" value="signin_code"><input type="hidden" name="do" value="forget">'
            . '<p>' . ($n === 0 ? 'No browsers are remembered at the moment.' : $n . ' remembered ' . ($n === 1 ? 'browser' : 'browsers') . '. Forgetting them means everyone, including you, is asked for a code at their next sign-in. Use it if a device was lost or shared.') . '</p>'
            . '<button type="submit" class="button button-secondary"' . ($n === 0 ? ' disabled' : '') . '>Forget all remembered browsers</button></form>';
    }
    return $out . '</section>';
}

function signin_code_settings_save(): never
{
    if (($_POST['do'] ?? '') === 'forget') {
        if (!function_exists('is_owner') || !is_owner()) {
            require_role('owner');
        }
        signin_browsers_forget_all();
        activity_log('forgot all remembered browsers');
        flash('Forgot every remembered browser. Everyone is asked for an emailed code at their next sign-in, including you.');
        go(admin_url('settings') . '#signin-code');
    }
    $want = !empty($_POST['signin_code']);
    if ($want && !mail_ready()) {
        flash('Two-step sign-in cannot be turned on until the site can send email. Fill in Email sending first, then come back.', 'error');
        go(admin_url('settings') . '#signin-code');
    }
    save_settings(array_merge(settings(), ['signin_code' => $want]));
    flash($want ? 'Two-step sign-in by email is on. From now on a new browser is asked for a code after the password.' : 'Two-step sign-in by email is off. People sign in with their password alone (or their authenticator app, if they set one up).');
    go(admin_url('settings') . '#signin-code');
}

register_settings_section('signin_code_settings_html', 'signin_code', 'signin_code_settings_save');
