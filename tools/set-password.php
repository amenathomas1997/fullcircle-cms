<?php
/**
 * Set or reset a person's control panel password from the command line, when email
 * isn't working or nobody can sign in:
 *   php tools/set-password.php name@example.com
 * With no accounts yet (a brand-new copy, or an older site that only had a shared
 * site password), it creates the first owner instead: name, email and password.
 * `--owner` creates a new owner account for an address that has none.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this from the command line.');
}
require __DIR__ . '/../lib/bootstrap.php';

function ask(string $prompt, bool $secret = false): string
{
    echo $prompt;
    if ($secret && DIRECTORY_SEPARATOR === '/' && stream_isatty(STDIN)) {
        system('stty -echo');
        $value = trim((string) fgets(STDIN));
        system('stty echo');
        echo "\n";
        return $value;
    }
    return trim((string) fgets(STDIN));
}

function ask_new_password(): string
{
    $password = ask('New password (at least 12 characters): ', true);
    if (($why = password_problem($password)) !== null) {
        fwrite(STDERR, "$why Nothing was changed.\n");
        exit(1);
    }
    if (ask('Type it again: ', true) !== $password) {
        fwrite(STDERR, "The passwords did not match. Nothing was changed.\n");
        exit(1);
    }
    return $password;
}

$args = array_values(array_filter(array_slice($argv, 1), fn($a) => $a !== '--owner'));
$makeOwner = in_array('--owner', $argv, true);
$email = normalize_email((string) ($args[0] ?? ''));

if (owner_count() === 0 || ($makeOwner && $email !== '' && !find_user_by_email($email))) {
    echo owner_count() > 0 ? "Creating a new owner account.\n" : "There is no owner account yet, so this creates it.\n";
    $name = ask('Owner’s name: ');
    if ($email === '') {
        $email = normalize_email(ask('Owner’s sign-in email address: '));
    }
    try {
        $user = create_user($name, $email, ask_new_password(), 'owner');
    } catch (RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . " Nothing was changed.\n");
        exit(1);
    }
    if (config()['password_hash'] !== '') {
        // The shared site password is retired; everyone signs in with their own account now.
        save_config(['password_hash' => '']);
    }
    @unlink(DATA_DIR . '/login-attempts.json');
    activity_log('created an owner account', 'from the command line', $user);
    echo "Owner account created for {$user['email']}. Sign in at /admin/\n";
    exit(0);
}

if ($email === '') {
    $email = normalize_email(ask('Email address of the person whose password to reset: '));
}
$user = find_user_by_email($email);
if (!$user) {
    fwrite(STDERR, "No account has the address $email. The people with accounts are:\n");
    foreach (users() as $u) {
        fwrite(STDERR, "  {$u['email']} ({$u['name']}, {$u['role']})\n");
    }
    fwrite(STDERR, "Run again with one of those, or add --owner to create a new owner account with that address.\n");
    exit(1);
}
set_user_password((string) $user['id'], ask_new_password());
@unlink(DATA_DIR . '/login-attempts.json');
activity_log('reset a password from the command line', '', $user);
echo "Password saved for {$user['name']} ({$user['email']}). Anyone signed in as them has been signed out. Sign in at /admin/\n";
