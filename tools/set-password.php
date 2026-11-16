<?php
// Set or reset the control panel password from the command line:
//   php tools/set-password.php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this from the command line.');
}
require __DIR__ . '/../lib/core.php';
require __DIR__ . '/../lib/config.php';

function ask(string $prompt): string
{
    echo $prompt;
    if (DIRECTORY_SEPARATOR === '/' && stream_isatty(STDIN)) {
        system('stty -echo');
        $value = trim((string) fgets(STDIN));
        system('stty echo');
        echo "\n";
        return $value;
    }
    return trim((string) fgets(STDIN));
}

$password = ask('New control panel password (at least 12 characters): ');
if (strlen($password) < 12) {
    fwrite(STDERR, "That password is too short. Nothing was changed.\n");
    exit(1);
}
if (ask('Type it again: ') !== $password) {
    fwrite(STDERR, "The passwords did not match. Nothing was changed.\n");
    exit(1);
}
save_config(['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
@unlink(DATA_DIR . '/login-attempts.json');
echo "Password saved. Sign in at /admin/\n";
