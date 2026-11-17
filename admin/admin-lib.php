<?php
/**
 * Control panel helpers: sign-in, security tokens, messages and form fields.
 * Every form field has a visible label, hints and errors are tied to their
 * field with aria-describedby, and errors are listed in a summary at the top.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

const ADMIN_URL = '/admin/';
const LOGIN_LOCK_FILE = DATA_DIR . '/login-attempts.json';

// Files that can be uploaded, by extension.
const UPLOAD_TYPES = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
    'webp' => 'image/webp', 'pdf' => 'application/pdf', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];
const UPLOAD_MAX = 15 * 1024 * 1024;
const AUDIO_MAX = 60 * 1024 * 1024;

set_exception_handler(function (Throwable $e): void {
    error_log('Control panel error: ' . $e);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Something went wrong – Control panel</title>'
        . '<link rel="stylesheet" href="/admin/admin.css"></head><body><main><h1>Something went wrong</h1>'
        . '<p>Your last action may not have been saved. The details were written to the server’s error log.</p>'
        . '<p><a href="/admin/">Go back to the dashboard</a></p></main></body></html>';
});

/* ---------- Sessions ---------- */

function start_admin_session(): void
{
    $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_name('fcat_admin');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    ini_set('session.gc_maxlifetime', '28800');
    ini_set('session.use_strict_mode', '1');
    session_start();
    // Sign out after 8 hours without activity.
    if (!empty($_SESSION['admin']) && time() - ($_SESSION['last_seen'] ?? 0) > 28800) {
        $_SESSION = [];
        session_regenerate_id(true);
        flash('You were signed out after 8 hours without activity. Please sign in again.', 'error');
    }
    // Access can be taken away while someone is signed in: end their session the next time they click.
    if (!empty($_SESSION['admin']) && ($why = session_revoked()) !== null) {
        $_SESSION = [];
        session_regenerate_id(true);
        flash($why, 'error');
    }
    $_SESSION['last_seen'] = time();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

/**
 * Why a signed-in session is no longer valid, or null if it still is: the password was changed
 * after they signed in, Microsoft 365 sign-in was turned off, or they were removed from the people list.
 */
function session_revoked(): ?string
{
    if (accounts_exist()) {
        return accounts_session_revoked();
    }
    $c = config();
    if (($_SESSION['login_at'] ?? 0) < (int) ($c['sessions_valid_from'] ?? 0)) {
        return 'The site password was changed, so you were signed out. Please sign in again.';
    }
    if (($email = (string) ($_SESSION['admin_email'] ?? '')) !== '') {
        $people = array_map('strtolower', array_column((array) ($c['admin_people'] ?? []), 'email'));
        if (empty($c['m365_signin']) || !in_array($email, $people, true)) {
            return 'Your Microsoft 365 access to the control panel was removed, so you were signed out.';
        }
    }
    return null;
}

/** Everything a fresh sign-in sets, after the password or Microsoft 365 check passed. */
function begin_signed_in_session(string $name, string $email = ''): void
{
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    $_SESSION['admin_name'] = $name;
    $_SESSION['admin_email'] = $email;
    $_SESSION['login_at'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function signed_in(): bool
{
    return !empty($_SESSION['admin']);
}

/**
 * A hidden copy of when the item was last saved. If someone else saves it while this form is open,
 * stale_edit() notices and the save is refused once, so nobody's work is overwritten unknowingly.
 */
function updated_field(string $type, ?string $origSlug): string
{
    $existing = $origSlug !== null ? get_item($type, $origSlug) : null;
    return '<input type="hidden" name="updated" value="' . h((string) ($existing['updated'] ?? '')) . '">';
}

function stale_edit(?array $existing): bool
{
    return $existing !== null && isset($_POST['updated']) && (string) $_POST['updated'] !== (string) ($existing['updated'] ?? '');
}

function stale_edit_message(array $existing): string
{
    $who = ($existing['updated_by'] ?? '') !== '' ? $existing['updated_by'] : 'Someone else';
    return "$who saved this at " . format_time((string) ($existing['updated'] ?? '')) . ', after you opened it. '
        . 'Nothing was saved. Open it in a new tab to see their version first, or press Save again to replace it with yours.';
}

/** The scripts every content editing screen loads: TinyMCE, the Blocks editor and the switch between them. */
function editor_scripts(): string
{
    // The ?v= changes when a script changes, so browsers never run a week-old cached copy.
    $v = fn(string $f) => '/admin/' . $f . '?v=' . (@filemtime(ROOT . '/admin/' . $f) ?: 1);
    $langs = json_encode(['primary' => primary_lang(), 'second' => second_lang()]);
    return '<script>window.fcLanguages=' . $langs . '</script><script src="/admin/vendor/tinymce/tinymce.min.js"></script><script src="' . $v('blocks-editor.js') . '" defer></script><script src="' . $v('editor.js') . '" defer></script>';
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">';
}

function check_csrf(): void
{
    $sent = (string) ($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(400);
        flash('That form had expired, so nothing was saved. Please try again.', 'error');
        go(back_url());
    }
}

/** The control panel screen the request came from, or the dashboard. */
function back_url(): string
{
    $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $path = (string) parse_url($ref, PHP_URL_PATH);
    $query = (string) parse_url($ref, PHP_URL_QUERY);
    return str_starts_with($path, ADMIN_URL) ? $path . ($query !== '' ? "?$query" : '') : ADMIN_URL;
}

function is_local_request(): bool
{
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

/* ---------- Sign-in limits: 5 wrong passwords locks sign-in for 15 minutes ---------- */

function login_attempts(): array
{
    $log = read_json(LOGIN_LOCK_FILE) ?? [];
    $cutoff = time() - 900;
    return array_filter(array_map(fn($t) => array_values(array_filter($t, fn($x) => $x > $cutoff)), $log));
}

function login_locked(): bool
{
    $key = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
    return count(login_attempts()[$key] ?? []) >= 5;
}

function record_failed_login(): void
{
    // Read and write under a lock, so attempts made at the same moment are all counted.
    $fh = fopen(LOGIN_LOCK_FILE, 'c+');
    if (!$fh) {
        return;
    }
    flock($fh, LOCK_EX);
    $log = json_decode((string) stream_get_contents($fh), true);
    $cutoff = time() - 900;
    $log = array_filter(array_map(fn($t) => array_values(array_filter((array) $t, fn($x) => $x > $cutoff)), is_array($log) ? $log : []));
    $log[hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '')][] = time();
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($log));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    @chmod(LOGIN_LOCK_FILE, 0600);
}

/* ---------- Navigation and messages ---------- */

function go(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

function admin_url(string $action = '', array $params = []): string
{
    $q = $action !== '' ? ['a' => $action] + $params : $params;
    return ADMIN_URL . ($q ? '?' . http_build_query($q) : '');
}

/** A message shown once on the next screen. $type is 'success' or 'error'. */
function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function take_flash(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- Screen layout ---------- */

function admin_nav(): array
{
    $items = [
        'dashboard' => 'Dashboard',
        'pages' => 'Pages',
        'posts' => 'Blog posts',
        'resources' => 'Cheat sheets',
        'testimonials' => 'Testimonials',
        'videos' => 'Videos',
        'stories' => 'Success stories',
        'media' => 'Images and files',
        'menu' => 'Menu',
        'messages' => 'Messages',
        'updates' => 'Email updates',
        'visitors' => 'Visitors',
        'links' => 'Link check',
        'template' => 'Site template',
        'settings' => 'Settings',
    ];
    // Only the parts of the site that are turned on in Settings > Features.
    foreach (['resources', 'testimonials', 'videos', 'stories', 'updates'] as $feature) {
        if (!feature_on($feature)) {
            unset($items[$feature]);
        }
    }
    // Screens added by features and this site's own code go before Settings.
    $extra = [];
    foreach (registered('admin') as $action => $screen) {
        if ($screen['label'] !== null && ($screen['feature'] === null || feature_on($screen['feature']))) {
            $extra[$action] = $screen['label'];
        }
    }
    $settings = ['template' => $items['template'], 'settings' => $items['settings']];
    unset($items['template'], $items['settings']);
    // Only the screens this person's role can open (lib/features/accounts.php).
    return array_filter($items + $extra + $settings, 'can_open', ARRAY_FILTER_USE_KEY);
}

/** The names and one-line descriptions of the optional features, for Settings and setup. */
function feature_labels(): array
{
    return [
        'search' => ['Site search', 'A search link in the header that searches pages, posts and cheat sheets.'],
        'resources' => ['Cheat sheets', 'Reference sheets with Word, large print and braille downloads and a practice quiz. Shown as Resources.'],
        'testimonials' => ['Testimonials', 'Visitors can send a testimonial for you to approve; the [testimonials] shortcode shows them.'],
        'updates' => ['Email updates', 'A sign-up form, double opt-in, and updates sent in the background. Needs email sending set up.'],
        'videos' => ['Videos', 'A videos page with transcripts, at /videos/.'],
        'stories' => ['Success stories', 'Stories from the people you work with, at /success-stories/, with a consent check.'],
        'assistant' => ['Ask a question (Claude)', 'Visitors ask a question and get an answer written from your website. Needs an Anthropic API key.'],
        'bookings' => ['Microsoft Bookings next opening', 'The [next-opening] shortcode shows the next free time in a Bookings calendar.'],
        'spanish' => ['Second language pages (' . second_lang_name() . ')', 'A ' . second_lang_name() . ' side of the site at ' . (second_lang() !== '' ? home_path(second_lang()) : '/<code>/')
            . ', with its own menu, footer, blog and language switch.' . ((string) (settings()['second_language'] ?? '') === '' ? ' Choose the language under Site details; until you do, it is Spanish.' : '')],
    ] + array_map(fn($f) => [$f[0], $f[1]], registered('features'));
}

/** A time zone drop-down grouped by region. */
function timezone_select(string $name, string $label, string $current, string $hint = ''): string
{
    $groups = [];
    foreach (DateTimeZone::listIdentifiers() as $tz) {
        [$region] = explode('/', $tz, 2) + [''];
        $groups[$region][] = $tz;
    }
    $out = '<div class="field"><label for="' . h($name) . '">' . h($label) . '</label>'
        . ($hint !== '' ? '<p class="hint" id="' . h($name) . '-hint">' . h($hint) . '</p>' : '')
        . '<select id="' . h($name) . '" name="' . h($name) . '"' . ($hint !== '' ? ' aria-describedby="' . h($name) . '-hint"' : '') . '>';
    foreach ($groups as $region => $zones) {
        $out .= '<optgroup label="' . h($region) . '">';
        foreach ($zones as $tz) {
            $out .= '<option value="' . h($tz) . '"' . ($tz === $current ? ' selected' : '') . '>' . h(str_replace('_', ' ', $tz)) . '</option>';
        }
        $out .= '</optgroup>';
    }
    return $out . '</select></div>';
}

/** A language drop-down: every supported language by its own name, with its English name after it. */
function language_select(string $name, string $label, string $current, string $hint = '', ?string $noneLabel = null): string
{
    $out = '<div class="field"><label for="' . h($name) . '">' . h($label) . '</label>'
        . ($hint !== '' ? '<p class="hint" id="' . h($name) . '-hint">' . h($hint) . '</p>' : '')
        . '<select id="' . h($name) . '" name="' . h($name) . '"' . ($hint !== '' ? ' aria-describedby="' . h($name) . '-hint"' : '') . '>'
        . ($noneLabel !== null ? '<option value=""' . ($current === '' ? ' selected' : '') . '>' . h($noneLabel) . '</option>' : '');
    foreach (SUPPORTED_LANGUAGES as $code => [$native, $english]) {
        $text = $native === $english ? $native : "$native ($english)";
        $out .= '<option value="' . $code . '"' . ($code === $current ? ' selected' : '') . ' lang="' . $code . '">' . h($text) . '</option>';
    }
    return $out . '</select></div>';
}

/**
 * Print a full control panel screen.
 * $title is the screen's heading; $section is the menu item to mark as current.
 */
function admin_screen(string $title, string $body, string $section = '', array $opts = []): never
{
    $flash = take_flash();
    $errors = $opts['errors'] ?? [];
    $docTitle = ($errors ? 'Error: ' : ($flash && $flash['type'] === 'error' ? 'Problem: ' : '')) . $title . ' – Control panel – ' . h(settings()['site_name']);

    header('Content-Type: text/html; charset=utf-8');
    header('X-Frame-Options: SAMEORIGIN');
    header('Cache-Control: no-store');
    header('Referrer-Policy: same-origin');

    $nav = '';
    if (signed_in()) {
        // The menu is long, so it is read as a few named groups rather than one list of thirty links.
        $groups = [
            'Content' => ['dashboard', 'pages', 'posts', 'resources', 'media', 'menu', 'footer', 'events', 'forms', 'products', 'testimonials', 'videos', 'stories'],
            'People and messages' => ['messages', 'updates', 'orders', 'people', 'activity', 'account'],
            'Checks and tools' => ['visitors', 'links', 'a11y', 'redirects', 'import', 'translations', 'system', 'sites', 'backups'],
            'Site' => ['template', 'settings'],
        ];
        $all = admin_nav();
        $placed = [];
        $nav = '<nav class="admin-nav" aria-label="Control panel">';
        foreach ($groups as $groupTitle => $keys) {
            // Anything a feature added that isn't listed above goes under Content.
            if ($groupTitle === 'Content') {
                $keys = array_merge($keys, array_diff(array_keys($all), array_merge(...array_values($groups))));
            }
            $items = '';
            foreach ($keys as $key) {
                if (!isset($all[$key]) || isset($placed[$key])) {
                    continue;
                }
                $placed[$key] = true;
                $current = $key === $section ? ' aria-current="page"' : '';
                $items .= '<li><a href="' . h(admin_url($key === 'dashboard' ? '' : $key)) . "\"$current>" . h($all[$key]) . '</a></li>';
            }
            if ($groupTitle === 'Site') {
                $items .= '<li><a href="/">View website</a></li>';
            }
            if ($items !== '') {
                $id = 'nav-' . preg_replace('/[^a-z]+/', '-', strtolower($groupTitle));
                $nav .= '<div class="nav-group"><p class="nav-group-title" id="' . $id . '">' . h($groupTitle) . '</p><ul aria-labelledby="' . $id . '">' . $items . '</ul></div>';
            }
        }
        $nav .= '</nav>'
            . '<form class="signout" method="post" action="' . h(admin_url('logout')) . '">' . csrf_field()
            . '<button type="submit" class="button-link">Sign out</button></form>';
    }

    $notice = '';
    if ($flash) {
        $role = $flash['type'] === 'error' ? 'alert' : 'status';
        $notice = '<div id="notice" class="notice notice-' . h($flash['type']) . '" role="' . $role . '" tabindex="-1"><p>' . h($flash['message']) . '</p></div>';
    }
    $summary = $errors ? error_summary($errors) : '';
    $head = $opts['head'] ?? '';
    $logo = trim((string) (settings()['logo'] ?? ''));
    $icon = $logo !== '' ? '<link rel="icon" href="' . h($logo) . '">' : '';
    $brandLogo = $logo !== '' ? '<img src="' . h($logo) . '" alt="" height="38"> ' : '';
    $brandName = h(settings()['site_name']);
    $scripts = $opts['scripts'] ?? '';
    $version = function_exists('cms_version') ? '<footer class="admin-footer"><p>Full Circle CMS ' . h(cms_version()) . '</p></footer>' : '';

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{$docTitle}</title>
<link rel="stylesheet" href="/admin/admin.css">
{$icon}
{$head}
</head>
<body>
<a class="skip-link" href="#main">Skip to main content</a>
<header class="admin-header">
  <p class="admin-brand">{$brandLogo}{$brandName} control panel</p>
  {$nav}
</header>
<main id="main" tabindex="-1">
{$notice}
{$summary}
<h1>{$title}</h1>
{$body}
</main>
{$version}
<script src="/admin/admin.js" defer></script>
{$scripts}
</body>
</html>
HTML;
    exit;
}

/* ---------- Form fields ---------- */

function error_summary(array $errors): string
{
    $items = '';
    foreach ($errors as $field => $message) {
        $items .= '<li><a href="#' . h($field) . '">' . h($message) . '</a></li>';
    }
    return '<div id="error-summary" class="error-summary" role="alert" tabindex="-1" aria-labelledby="error-summary-title">'
        . '<h2 id="error-summary-title">There ' . (count($errors) === 1 ? 'is a problem' : 'are ' . count($errors) . ' problems') . '</h2>'
        . "<ul>$items</ul></div>";
}

/** Shared wiring for hint text and error messages. */
function field_parts(string $id, array $o): array
{
    $describedBy = [];
    $extra = '';
    if (!empty($o['hint'])) {
        $describedBy[] = "$id-hint";
        $extra .= '<p class="hint" id="' . h($id) . '-hint">' . $o['hint'] . '</p>';
    }
    if (!empty($o['error'])) {
        $describedBy[] = "$id-error";
        $extra .= '<p class="error-message" id="' . h($id) . '-error"><span class="visually-hidden">Error: </span>' . h($o['error']) . '</p>';
    }
    $attrs = $describedBy ? ' aria-describedby="' . h(implode(' ', $describedBy)) . '"' : '';
    if (!empty($o['error'])) {
        $attrs .= ' aria-invalid="true"';
    }
    if (!empty($o['required'])) {
        $attrs .= ' required';
    }
    foreach (['autocomplete', 'spellcheck', 'inputmode', 'pattern', 'maxlength', 'accept'] as $a) {
        if (isset($o[$a])) {
            $attrs .= ' ' . $a . '="' . h((string) $o[$a]) . '"';
        }
    }
    $class = 'field' . (!empty($o['error']) ? ' field-error' : '') . (!empty($o['class']) ? ' ' . $o['class'] : '');
    return [$attrs, $extra, $class];
}

/** Hint text may contain trusted HTML (it is written in this code, never by visitors). */
function text_field(string $name, string $label, string $value = '', array $o = []): string
{
    $id = $o['id'] ?? $name;
    [$attrs, $extra, $class] = field_parts($id, $o);
    $type = $o['type'] ?? 'text';
    $prefix = isset($o['prefix']) ? '<span class="input-prefix" aria-hidden="true">' . h($o['prefix']) . '</span>' : '';
    $input = '<input type="' . h($type) . '" id="' . h($id) . '" name="' . h($name) . '" value="' . h($value) . '"' . $attrs . '>';
    if ($prefix) {
        $input = '<div class="input-with-prefix">' . $prefix . $input . '</div>';
    }
    return '<div class="' . $class . '"><label for="' . h($id) . '">' . h($label) . '</label>' . $extra . $input . '</div>';
}

function textarea_field(string $name, string $label, string $value = '', array $o = []): string
{
    $id = $o['id'] ?? $name;
    [$attrs, $extra, $class] = field_parts($id, $o);
    $rows = (int) ($o['rows'] ?? 6);
    $code = !empty($o['code']) ? ' class="code" spellcheck="false" autocapitalize="off" autocorrect="off" wrap="soft"' : '';
    return '<div class="' . $class . '"><label for="' . h($id) . '">' . h($label) . '</label>' . $extra
        . '<textarea id="' . h($id) . '" name="' . h($name) . '" rows="' . $rows . '"' . $code . $attrs . '>' . h($value) . '</textarea></div>';
}

function checkbox_field(string $name, string $label, bool $checked, array $o = []): string
{
    $id = $o['id'] ?? $name;
    [$attrs, $extra, $class] = field_parts($id, $o);
    return '<div class="' . $class . ' checkbox"><input type="checkbox" id="' . h($id) . '" name="' . h($name) . '" value="1"'
        . ($checked ? ' checked' : '') . $attrs . '><label for="' . h($id) . '">' . h($label) . '</label>' . $extra . '</div>';
}

/** A group of radio buttons in a fieldset. $options is value => label. */
function radio_field(string $name, string $legend, array $options, string $value, array $o = []): string
{
    $hint = !empty($o['hint']) ? '<p class="hint" id="' . h($name) . '-hint">' . $o['hint'] . '</p>' : '';
    $describedBy = $hint ? ' aria-describedby="' . h($name) . '-hint"' : '';
    $out = '<fieldset class="field radios"' . $describedBy . '><legend>' . h($legend) . '</legend>' . $hint;
    $first = true;
    foreach ($options as $v => $label) {
        $id = $first ? $name : $name . '-' . $v;
        $first = false;
        $out .= '<div class="radio"><input type="radio" id="' . h($id) . '" name="' . h($name) . '" value="' . h((string) $v) . '"'
            . ((string) $v === $value ? ' checked' : '') . '><label for="' . h($id) . '">' . h($label) . '</label></div>';
    }
    return $out . '</fieldset>';
}

function format_bytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    return max(1, (int) round($bytes / 1024)) . ' KB';
}

function format_time(int|string $t): string
{
    $ts = is_int($t) ? $t : strtotime($t);
    return $ts ? date('F j, Y \a\t g:i a', $ts) : '';
}
