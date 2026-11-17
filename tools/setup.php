<?php
/**
 * Set up a brand-new site from the command line:
 *   php tools/setup.php
 * It asks for the site's name and details, the owner's account (name, email
 * and password) and which optional features to turn on, then creates the starter pages.
 * Every answer can also be given as an environment variable (SETUP_SITE_NAME,
 * SETUP_SITE_URL, SETUP_EMAIL, SETUP_PHONE, SETUP_TIMEZONE, SETUP_PALETTE,
 * SETUP_SPANISH=yes, SETUP_OWNER_NAME, SETUP_OWNER_EMAIL (defaults to SETUP_EMAIL),
 * SETUP_PASSWORD, SETUP_LEGAL_NAME, SETUP_TAGLINE), and
 * `php tools/setup.php --yes` accepts the defaults for anything not given.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this from the command line.');
}
require __DIR__ . '/../lib/bootstrap.php';

$yes = in_array('--yes', $argv, true);

function ask_setup(string $envKey, string $prompt, string $default = '', bool $secret = false): string
{
    global $yes;
    $env = getenv($envKey);
    if ($env !== false && $env !== '') {
        return trim($env);
    }
    if ($yes) {
        return $default;
    }
    echo $prompt, $default !== '' && !$secret ? " [$default]" : '', ': ';
    if ($secret && DIRECTORY_SEPARATOR === '/' && stream_isatty(STDIN)) {
        system('stty -echo');
        $value = trim((string) fgets(STDIN));
        system('stty echo');
        echo "\n";
    } else {
        $value = trim((string) fgets(STDIN));
    }
    return $value !== '' ? $value : $default;
}

if ((config()['password_hash'] !== '' || accounts_exist()) && all_items('pages')) {
    fwrite(STDERR, "This site is already set up. Use the control panel to change it, or php tools/set-password.php to reset a password.\n");
    exit(1);
}

echo "Full Circle CMS setup\n\n";
$name = ask_setup('SETUP_SITE_NAME', 'Site name (shown in the header and browser tab)', 'My website');
$legal = ask_setup('SETUP_LEGAL_NAME', 'Full or legal name for the copyright line (Enter to use the site name)', '');
$tagline = ask_setup('SETUP_TAGLINE', 'Tagline, one short sentence (optional)', '');
$url = rtrim(ask_setup('SETUP_SITE_URL', 'Site address', 'https://example.com'), '/');
$email = ask_setup('SETUP_EMAIL', 'Public contact email (optional)', '');
$phone = ask_setup('SETUP_PHONE', 'Public contact phone (optional)', '');
$tz = ask_setup('SETUP_TIMEZONE', 'Time zone', 'America/New_York');
$palette = strtolower(ask_setup('SETUP_PALETTE', 'Color set (' . implode(', ', array_keys(PALETTES)) . ')', 'purple'));
$spanish = in_array(strtolower(ask_setup('SETUP_SPANISH', 'Add Spanish pages too? (yes/no)', 'no')), ['y', 'yes', 'sí', 'si'], true);

$problems = [];
if ($name === '') {
    $problems[] = 'The site name can’t be empty.';
}
if (!preg_match('#^https?://[a-z0-9.-]+(:\d+)?$#i', $url)) {
    $problems[] = 'The site address should look like https://example.com, with nothing after the domain.';
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $problems[] = 'The contact email doesn’t look like an email address.';
}
if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
    $problems[] = "“$tz” isn’t a time zone PHP knows. Use a name like America/Chicago or Europe/London.";
}
if (!isset(PALETTES[$palette])) {
    $problems[] = 'Choose one of these color sets: ' . implode(', ', array_keys(PALETTES)) . '.';
}
if ($problems) {
    fwrite(STDERR, "Nothing was changed:\n- " . implode("\n- ", $problems) . "\n");
    exit(1);
}

$password = '';
$ownerName = '';
$ownerEmail = '';
if (!accounts_exist()) {
    echo "\nYour owner account, for signing in to the control panel.\n";
    $ownerName = ask_setup('SETUP_OWNER_NAME', 'Your name (shown next to the changes you make)', 'Site owner');
    $ownerEmail = strtolower(ask_setup('SETUP_OWNER_EMAIL', 'Your sign-in email address', $email !== '' ? $email : 'owner@' . (parse_url($url, PHP_URL_HOST) ?: 'example.com')));
    if ($ownerName === '' || !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
        fwrite(STDERR, "The owner needs a name and an email address in the right format, like name@example.com. Nothing was changed.\n");
        exit(1);
    }
    $password = ask_setup('SETUP_PASSWORD', 'Your password (at least 12 characters)', '', true);
    if (strlen($password) < 12) {
        fwrite(STDERR, "The password must be at least 12 characters. Nothing was changed.\n");
        exit(1);
    }
    if (!$yes && getenv('SETUP_PASSWORD') === false && ask_setup('SETUP_PASSWORD_AGAIN', 'Type the password again', '', true) !== $password) {
        fwrite(STDERR, "The passwords did not match. Nothing was changed.\n");
        exit(1);
    }
}

save_settings(array_merge(settings(), [
    'site_name' => $name,
    'legal_name' => $legal,
    'tagline' => $tagline,
    'description' => $tagline !== '' ? "$name: $tagline" : $name,
    'site_url' => $url,
    'contact_email' => $email,
    'contact_phone' => $phone,
    'timezone' => $tz,
    'palette' => $palette,
    'features' => array_merge(FEATURE_DEFAULTS, ['spanish' => $spanish]),
]));
// Dates from here on (the first post, the revisions) use the site's own time zone.
date_default_timezone_set($tz);
save_config(['contact_recipients' => $email]);
if ($password !== '') {
    create_user($ownerName, $ownerEmail, $password, 'owner');
}
foreach ([DATA_DIR . '/cache', DATA_DIR . '/stats', UPLOAD_DIR . '/audio'] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}
create_starter_content($spanish);

echo "\nDone. $name is set up with ", count(all_items('pages')), " pages and one blog post.\n";
echo "Next:\n";
echo "  1. Sign in at $url/admin/ as $ownerEmail and look around Settings. Add other people on the People screen.\n";
echo "  2. Under Settings > Email sending, choose PHP mail, an SMTP account or Microsoft 365 and send yourself a test.\n";
echo "  3. Set up the three cron jobs in README.md: email updates, the link check and the nightly backup.\n";
echo "To try it on this computer first: php -S localhost:8000 router.php\n";
