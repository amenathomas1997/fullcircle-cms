<?php
/**
 * Accounts: named sign-ins for the control panel, each with a role.
 *
 *   data/users.json             one record per person: id, name, email (lowercase, unique),
 *                               password_hash, role, totp_secret, recovery_codes, created, last_sign_in
 *   data/password-tokens.json   one-hour, single-use links for setting a password (invites and resets)
 *   data/activity.jsonl         who did what and when, newest last, trimmed to 5,000 lines
 *
 * Roles: owner (everything), editor (every content screen; not Settings, Site template, People,
 * Activity or Backups), author (blog posts and images only, and always as drafts).
 *
 * The control panel screens (People, Activity, My account, and the sign-in screens for forgotten
 * passwords and two-factor codes) are in admin/people.php, loaded when a screen needs them.
 * Two-factor sign-in is TOTP (RFC 6238): 30-second, 6-digit, SHA-1 codes, in plain PHP.
 */

declare(strict_types=1);

const USERS_FILE = DATA_DIR . '/users.json';
const PASSWORD_TOKENS_FILE = DATA_DIR . '/password-tokens.json';
const ACTIVITY_FILE = DATA_DIR . '/activity.jsonl';
const ACTIVITY_KEEP = 5000;
const PASSWORD_TOKEN_LIFE = 3600;
const MIN_PASSWORD = 12;
const ROLES = ['owner' => 3, 'editor' => 2, 'author' => 1];
const ROLE_LABELS = ['owner' => 'Owner', 'editor' => 'Editor', 'author' => 'Author'];
const ROLE_HINTS = [
    'owner' => 'Everything, including Settings, the site template, People, Activity and backups.',
    'editor' => 'Every content screen: pages, posts, images, menu, messages and the rest. Not Settings or People.',
    'author' => 'Blog posts and images only. Their posts stay drafts until an editor or owner publishes them.',
];

/* =====================================================================
   People
   ===================================================================== */

/** Every account. The first time this runs on an older site, the Microsoft 365 allow-list becomes editor accounts. */
function users(bool $refresh = false): array
{
    static $users = null;
    if ($users !== null && !$refresh) {
        return $users;
    }
    $list = read_json(USERS_FILE);
    if ($list === null) {
        $list = [];
        foreach ((array) (config()['admin_people'] ?? []) as $p) {
            $email = normalize_email((string) ($p['email'] ?? ''));
            if ($email === '' || find_in_users($list, $email)) {
                continue;
            }
            $list[] = new_user_record((string) ($p['name'] ?: $email), $email, null, 'editor');
        }
        if (is_dir(DATA_DIR)) {
            write_json(USERS_FILE, $list);
            @chmod(USERS_FILE, 0600);
        }
    }
    return $users = array_values(array_filter($list, 'is_array'));
}

function save_users(array $list): void
{
    write_json(USERS_FILE, array_values($list));
    @chmod(USERS_FILE, 0600);
    users(true);
}

function accounts_exist(): bool
{
    return users() !== [];
}

function normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function find_in_users(array $list, string $email): ?array
{
    foreach ($list as $u) {
        if (($u['email'] ?? '') === $email) {
            return $u;
        }
    }
    return null;
}

function find_user(string $id): ?array
{
    foreach (users() as $u) {
        if (($u['id'] ?? '') === $id) {
            return $u;
        }
    }
    return null;
}

function find_user_by_email(string $email): ?array
{
    return find_in_users(users(), normalize_email($email));
}

/** A fresh record. With no password, a random one is set that nobody knows; the person sets theirs from an emailed link. */
function new_user_record(string $name, string $email, ?string $password, string $role): array
{
    return [
        'id' => bin2hex(random_bytes(8)),
        'name' => trim($name),
        'email' => normalize_email($email),
        'password_hash' => password_hash($password ?? bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
        'role' => isset(ROLES[$role]) ? $role : 'author',
        'totp_secret' => null,
        'recovery_codes' => [],
        'created' => date('c'),
        'last_sign_in' => null,
    ];
}

/** Add a person. Throws a RuntimeException with a plain-words message when something is wrong. */
function create_user(string $name, string $email, ?string $password, string $role): array
{
    $email = normalize_email($email);
    if (trim($name) === '') {
        throw new RuntimeException('Enter the person’s name.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter the email address in the right format, like name@example.com.');
    }
    if (find_user_by_email($email)) {
        throw new RuntimeException("$email already has an account.");
    }
    if ($password !== null && ($why = password_problem($password)) !== null) {
        throw new RuntimeException($why);
    }
    $user = new_user_record($name, $email, $password, $role);
    $list = users();
    $list[] = $user;
    save_users($list);
    return $user;
}

function update_user(string $id, array $changes): ?array
{
    $list = users();
    foreach ($list as $i => $u) {
        if ($u['id'] === $id) {
            $list[$i] = array_merge($u, $changes);
            save_users($list);
            return $list[$i];
        }
    }
    return null;
}

function delete_user(string $id): void
{
    save_users(array_filter(users(), fn($u) => $u['id'] !== $id));
}

function owner_count(): int
{
    return count(array_filter(users(), fn($u) => ($u['role'] ?? '') === 'owner'));
}

/** Why a password can't be used, or null when it can. */
function password_problem(string $password): ?string
{
    return strlen($password) < MIN_PASSWORD ? 'Choose a password that is at least 12 characters long. A short sentence you will remember works well.' : null;
}

/** Give a person a new password. Everyone signed in as them (except the current browser) is signed out. */
function set_user_password(string $id, string $password): void
{
    update_user($id, ['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'sessions_valid_from' => time()]);
}

/** End every session of one person; they have to sign in again. */
function end_user_sessions(string $id): void
{
    update_user($id, ['sessions_valid_from' => time()]);
}

/* ---------- The signed-in person ---------- */

/** The account of the person signed in, read fresh each request so a role change applies at their next click. */
function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $id = (string) ($_SESSION['user_id'] ?? '');
        $user = $id !== '' && !empty($_SESSION['admin']) ? find_user($id) : null;
    }
    return $user ?: null;
}

function current_role(): string
{
    return (string) (current_user()['role'] ?? '');
}

function role_label(string $role): string
{
    return ROLE_LABELS[$role] ?? ucfirst($role);
}

/** Does the signed-in person have at least this role? */
/** Owners see the System, Sites, Backups, People and Activity screens. */
function is_owner(): bool
{
    return current_role() === 'owner';
}

function role_at_least(string $role): bool
{
    return (ROLES[current_role()] ?? 0) >= (ROLES[$role] ?? PHP_INT_MAX);
}

/** Stop here with a plain explanation unless the signed-in person has at least this role. */
function require_role(string $role): void
{
    if (role_at_least($role)) {
        return;
    }
    http_response_code(403);
    admin_screen('You can’t open that screen', '<p>Your account is ' . h(strtolower(role_label(current_role()))) . ', and that part of the control panel is for '
        . ($role === 'owner' ? 'the site owner' : 'editors and the site owner') . '. If you need it, ask the site owner to change your role on the People screen.</p>'
        . '<p><a href="' . h(ADMIN_URL) . '">Go back to the dashboard</a></p>');
}

/** Can the signed-in person open a control panel action? Uses the role map at the top of admin/index.php. */
function can_open(string $action): bool
{
    $action = $action === 'dashboard' ? '' : $action;
    return role_at_least(defined('ACTION_ROLES') ? (ACTION_ROLES[$action] ?? 'editor') : 'editor');
}

/** Authors save drafts only; editors and owners publish. */
function can_publish(): bool
{
    return role_at_least('editor');
}

/** Everything a sign-in sets once the password (and code, if any) checked out. $how is "password" or "Microsoft 365". */
function accounts_sign_in(array $user, string $how = 'password'): void
{
    begin_signed_in_session((string) $user['name'], (string) $user['email']);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['via_m365'] = $how === 'Microsoft 365';
    unset($_SESSION['pending_2fa'], $_SESSION['legacy_ok']);
    update_user((string) $user['id'], ['last_sign_in' => date('c')]);
    activity_log('signed in', $how === 'password' ? '' : "with $how", $user);
}

/**
 * Why a signed-in session is no longer valid, or null if it still is: the account was removed,
 * their password was changed (or an owner ended their sessions), or Microsoft 365 sign-in was turned off.
 */
function accounts_session_revoked(): ?string
{
    if (($_SESSION['login_at'] ?? 0) < (int) (config()['sessions_valid_from'] ?? 0)) {
        return 'The site password was changed, so you were signed out. Please sign in again.';
    }
    if (empty($_SESSION['user_id'])) {
        return 'The control panel now uses named accounts instead of the shared site password, so you were signed out. Please sign in again.';
    }
    $user = find_user((string) $_SESSION['user_id']);
    if (!$user) {
        return 'Your account was removed, so you were signed out.';
    }
    if (($_SESSION['login_at'] ?? 0) < (int) ($user['sessions_valid_from'] ?? 0)) {
        return 'Your password was changed, so you were signed out. Please sign in again.';
    }
    if (!empty($_SESSION['via_m365']) && empty(config()['m365_signin'])) {
        return 'Microsoft 365 sign-in was turned off, so you were signed out. Sign in with your email address and password instead.';
    }
    return null;
}

/* =====================================================================
   Set-password links (invitations and resets)
   ===================================================================== */

/** Make a one-hour, single-use link for a person to set their password. Returns the full address. */
function create_password_link(string $userId, string $purpose = 'reset'): string
{
    $token = bin2hex(random_bytes(24));
    $tokens = password_tokens();
    // One live link per person: a new one replaces the old.
    $tokens = array_filter($tokens, fn($t) => $t['user_id'] !== $userId);
    $tokens[hash('sha256', $token)] = ['user_id' => $userId, 'purpose' => $purpose, 'expires' => time() + PASSWORD_TOKEN_LIFE];
    write_json(PASSWORD_TOKENS_FILE, $tokens);
    @chmod(PASSWORD_TOKENS_FILE, 0600);
    return site_url('/admin/?a=set_password&t=' . $token);
}

/** The live links, with expired ones dropped. */
function password_tokens(): array
{
    $now = time();
    return array_filter(read_json(PASSWORD_TOKENS_FILE) ?? [], fn($t) => is_array($t) && ($t['expires'] ?? 0) > $now);
}

/** The record behind a link, or null when it is unknown, used or more than an hour old. */
function password_token_record(string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{48}$/', $token)) {
        return null;
    }
    return password_tokens()[hash('sha256', $token)] ?? null;
}

function delete_password_token(string $token): void
{
    $tokens = password_tokens();
    unset($tokens[hash('sha256', $token)]);
    write_json(PASSWORD_TOKENS_FILE, $tokens);
}

/**
 * Email a set-password link. $purpose is "invite" or "reset". Returns the link, so the owner can pass it on
 * by hand when email isn't working; throws a RuntimeException when sending fails.
 */
function send_password_link(array $user, string $purpose, string $fromName = ''): string
{
    $link = create_password_link((string) $user['id'], $purpose);
    $site = h(site_name());
    $safeLink = h($link);
    $intro = $purpose === 'invite'
        ? '<p>' . ($fromName !== '' ? h($fromName) . ' has' : 'You have been') . " given you an account on the $site control panel, as "
            . h(strtolower(role_label((string) $user['role']))) . '.</p><p>Choose your password here:</p>'
        : "<p>Someone asked for a new password for your account on the $site control panel. If that was you, set it here:</p>";
    $html = "<p>Hello " . h((string) $user['name']) . ",</p>$intro<p><a href=\"$safeLink\">$safeLink</a></p>"
        . '<p>The link works once and for one hour. After that, choose “Forgot your password?” on the sign-in screen to get another.</p>'
        . ($purpose === 'reset' ? '<p>If you didn’t ask for this, you can ignore this email; your password hasn’t changed.</p>' : '')
        . '<p>Your sign-in email address is ' . h((string) $user['email']) . '.</p>';
    send_mail_to([(string) $user['email']], ($purpose === 'invite' ? 'Your account on ' : 'Set a new password for ') . site_name(), $html);
    return $link;
}

/* =====================================================================
   Two-factor sign-in: TOTP (RFC 6238) in plain PHP
   ===================================================================== */

const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function base32_encode(string $bytes): string
{
    $bits = '';
    foreach (str_split($bytes) as $byte) {
        $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0'))];
    }
    return $out;
}

/** Spaces, dashes and case are ignored, so a secret typed by hand still decodes. Returns null for bad characters. */
function base32_decode(string $text): ?string
{
    $text = strtoupper((string) preg_replace('/[\s=-]+/', '', $text));
    if ($text === '' || preg_match('/[^A-Z2-7]/', $text)) {
        return null;
    }
    $bits = '';
    foreach (str_split($text) as $char) {
        $bits .= str_pad(decbin(strpos(BASE32_ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) === 8) {
            $out .= chr(bindec($chunk));
        }
    }
    return $out;
}

/** A new 160-bit secret, as 32 base32 letters and digits. */
function new_totp_secret(): string
{
    return base32_encode(random_bytes(20));
}

/** The secret in groups of four, which is how people read it out and type it into an authenticator app. */
function totp_secret_groups(string $secret): string
{
    return implode(' ', str_split($secret, 4));
}

/** The six-digit code for a moment in time (HOTP over the 30-second step, SHA-1, dynamic truncation). */
function totp_code(string $secret, ?int $time = null, int $step = 30, int $digits = 6): string
{
    $key = base32_decode($secret) ?? '';
    $counter = intdiv($time ?? time(), $step);
    $hash = hash_hmac('sha1', pack('J', $counter), $key, true);
    $offset = ord($hash[19]) & 0x0f;
    $number = ((ord($hash[$offset]) & 0x7f) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
    return str_pad((string) ($number % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

/** Does a typed code match now, the step before or the step after (one step of drift each way)? */
function totp_verify(string $secret, string $code, int $drift = 1): bool
{
    $code = (string) preg_replace('/\D+/', '', $code);
    if (strlen($code) !== 6) {
        return false;
    }
    $now = time();
    for ($i = -$drift; $i <= $drift; $i++) {
        if (hash_equals(totp_code($secret, $now + $i * 30), $code)) {
            return true;
        }
    }
    return false;
}

/** The address an authenticator app understands, also typed by hand. */
function totp_uri(string $secret, string $email): string
{
    $issuer = site_name();
    return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($email) . '?' . http_build_query([
        'secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => 6, 'period' => 30,
    ], '', '&', PHP_QUERY_RFC3986);
}

/** Ten one-time recovery codes, like "k7mp-3xqa-2hzr". Returned in the clear; store the hashes. */
function new_recovery_codes(): array
{
    $codes = [];
    $letters = 'abcdefghjkmnpqrstuvwxyz23456789';
    for ($n = 0; $n < 10; $n++) {
        $code = '';
        for ($i = 0; $i < 12; $i++) {
            $code .= ($i > 0 && $i % 4 === 0 ? '-' : '') . $letters[random_int(0, strlen($letters) - 1)];
        }
        $codes[] = $code;
    }
    return $codes;
}

function recovery_code_hash(string $code): string
{
    return hash('sha256', strtolower((string) preg_replace('/[\s-]+/', '', $code)));
}

/** Accept a recovery code once: true when it matched, in which case it is used up. */
function use_recovery_code(array $user, string $code): bool
{
    $hash = recovery_code_hash($code);
    $left = (array) ($user['recovery_codes'] ?? []);
    foreach ($left as $i => $stored) {
        if (hash_equals((string) $stored, $hash)) {
            unset($left[$i]);
            update_user((string) $user['id'], ['recovery_codes' => array_values($left)]);
            return true;
        }
    }
    return false;
}

/** Does a code typed at sign-in open the account? A six-digit code from the app or an unused recovery code. */
function second_factor_ok(array $user, string $code): bool
{
    $secret = (string) ($user['totp_secret'] ?? '');
    if ($secret === '') {
        return true;
    }
    return totp_verify($secret, $code) || use_recovery_code($user, $code);
}

/* =====================================================================
   Activity log
   ===================================================================== */

/**
 * Write one line: who (name, email), when, what, and a detail such as the item's address.
 * $who defaults to the signed-in person; sign-in attempts pass the account (or null for an unknown email).
 */
function activity_log(string $what, string $detail = '', ?array $who = null, string $email = ''): void
{
    $who ??= function_exists('current_user') ? current_user() : null;
    $entry = [
        'time' => date('c'),
        'user_id' => (string) ($who['id'] ?? ''),
        'name' => (string) ($who['name'] ?? ($_SESSION['admin_name'] ?? '')),
        'email' => (string) ($who['email'] ?? $email),
        'what' => $what,
        'detail' => $detail,
    ];
    $fh = @fopen(ACTIVITY_FILE, 'c+');
    if (!$fh) {
        return;
    }
    try {
        flock($fh, LOCK_EX);
        fseek($fh, 0, SEEK_END);
        fwrite($fh, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        fflush($fh);
        // Keep the file to the last 5,000 lines. Lines are short, so this is only looked at past half a megabyte.
        if (ftell($fh) > 600000) {
            rewind($fh);
            $lines = explode("\n", rtrim((string) stream_get_contents($fh), "\n"));
            if (count($lines) > ACTIVITY_KEEP) {
                $keep = implode("\n", array_slice($lines, -ACTIVITY_KEEP)) . "\n";
                ftruncate($fh, 0);
                rewind($fh);
                fwrite($fh, $keep);
                fflush($fh);
            }
        }
        flock($fh, LOCK_UN);
    } finally {
        fclose($fh);
    }
    @chmod(ACTIVITY_FILE, 0600);
}

/** The newest entries first, at most $limit, optionally for one person. */
function activity_entries(int $limit = 200, string $userId = ''): array
{
    if (!is_file(ACTIVITY_FILE)) {
        return [];
    }
    $out = [];
    foreach (array_reverse(file(ACTIVITY_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) as $line) {
        $entry = json_decode($line, true);
        if (!is_array($entry) || ($userId !== '' && ($entry['user_id'] ?? '') !== $userId)) {
            continue;
        }
        $out[] = $entry;
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

/** Plain words for an item type, for log entries. */
function item_noun(string $type): string
{
    return ['pages' => 'page', 'posts' => 'post', 'resources' => 'cheat sheet', 'videos' => 'video', 'stories' => 'success story'][$type] ?? rtrim($type, 's');
}

// Saves and deletes of pages, posts and the other item types, from whoever is signed in.
register_item_hook('before_save', function (string $type, string $slug, array &$data): void {
    $GLOBALS['accounts_item_existed'] = is_file(item_file($type, $slug));
    // Authors write drafts; an editor or owner publishes.
    if (!empty($_SESSION['admin']) && function_exists('current_user') && current_user() && !can_publish() && ($data['status'] ?? '') === 'published') {
        $data['status'] = 'draft';
    }
});
register_item_hook('after_save', function (string $type, string $slug, array &$data): void {
    if (empty($_SESSION['admin'])) {
        return;
    }
    $title = trim((string) ($data['title'] ?? '')) ?: $slug;
    activity_log((!empty($GLOBALS['accounts_item_existed']) ? 'edited ' : 'created ') . item_noun($type), "“{$title}” ($type/$slug)" . (($data['status'] ?? '') === 'draft' ? ', draft' : ''));
});
register_item_hook('after_delete', function (string $type, string $slug, array &$data): void {
    if (!empty($_SESSION['admin'])) {
        activity_log('deleted ' . item_noun($type), "$type/$slug");
    }
});

/** Settings saves live in admin/index.php; this notes the part that was saved once the save succeeded. */
function activity_watch_settings(string $part): void
{
    register_shutdown_function(function () use ($part): void {
        if (($_SESSION['flash']['type'] ?? '') === 'success') {
            activity_log('changed settings', $part);
        }
    });
}

/* =====================================================================
   Control panel screens
   ===================================================================== */

/** Load the screens in admin/people.php (they need the form helpers from admin-lib.php, so only in the control panel). */
function accounts_admin(): void
{
    require_once ROOT . '/admin/people.php';
}

register_admin_screen('people', function (bool $post): void {
    accounts_admin();
    $post ? people_action() : people_screen();
}, 'People');
register_admin_screen('activity', function (bool $post): void {
    accounts_admin();
    activity_screen();
}, 'Activity');
register_admin_screen('account', function (bool $post): void {
    accounts_admin();
    $post ? account_action() : account_screen();
}, 'My account');
