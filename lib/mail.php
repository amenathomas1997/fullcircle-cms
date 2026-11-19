<?php
/**
 * Sending email. Settings > Email sending chooses one of three ways:
 *   php    the web server's own mail program, through PHP's mail() function
 *          (works on almost every shared host with no account to set up);
 *   graph  Microsoft 365, through Microsoft Graph (lib/graph-mail.php);
 *   smtp   an ordinary email account's SMTP server (Gmail, Outlook.com, the
 *          host's own mailbox), with a small SMTP client written here.
 * Everything that emails anyone calls send_mail_to() or send_mail_owner().
 */

declare(strict_types=1);

const SMTP_TIMEOUT = 20;

function mail_method(): string
{
    $method = (string) (config()['mail_method'] ?? 'php');
    return in_array($method, ['graph', 'smtp'], true) ? $method : 'php';
}

/** The address and name emails come from. For Graph it is always the sender mailbox; for SMTP the From address or the account name. */
function mail_from(): array
{
    $c = config();
    $address = match (mail_method()) {
        'graph' => (string) $c['graph_sender'],
        'smtp' => (string) (($c['smtp_from'] ?? '') !== '' ? $c['smtp_from'] : ($c['smtp_user'] ?? '')),
        default => (string) ($c['mail_from'] ?? ''),
    };
    return [$address, (string) ($c['mail_from_name'] ?: settings()['site_name'])];
}

/** Enough is filled in to send mail to visitors (confirmations, updates). */
function mail_ready(): bool
{
    return match (mail_method()) {
        'graph' => graph_app_configured() && config()['graph_sender'] !== '',
        'smtp' => (config()['smtp_host'] ?? '') !== '' && filter_var(mail_from()[0], FILTER_VALIDATE_EMAIL) !== false,
        default => filter_var(config()['mail_from'] ?? '', FILTER_VALIDATE_EMAIL) !== false,
    };
}

/** Enough is filled in to email the site owner too (contact form, reports). */
function mail_configured(): bool
{
    return mail_ready() && (config()['contact_recipients'] ?? '') !== '';
}

/** Send an HTML email to one or more people, each seeing only their own address. */
function send_mail_to(array $addresses, string $subject, string $html, ?string $replyToEmail = null, ?string $replyToName = null): void
{
    if (isset($GLOBALS['mail_mock'])) {
        ($GLOBALS['mail_mock'])($addresses, $subject, $html); // tools/selftest.php stands in for the mail transport
        return;
    }
    if (!mail_ready()) {
        throw new RuntimeException('The email sending settings are not filled in yet.');
    }
    $addresses = array_values(array_filter(array_map('trim', $addresses), fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL) !== false));
    if (!$addresses) {
        throw new RuntimeException('There is no valid address to send to.');
    }
    if (mail_method() === 'graph') {
        graph_send($addresses, $subject, $html, $replyToEmail, $replyToName, false);
        return;
    }
    foreach ($addresses as $address) {
        mail_method() === 'smtp'
            ? smtp_send([$address], $subject, $html, $replyToEmail, $replyToName)
            : php_send($address, $subject, $html, $replyToEmail, $replyToName);
    }
}

/** Send an HTML email to the site owner's addresses (Settings > Send messages to). */
function send_mail_owner(string $subject, string $html, ?string $replyToEmail = null, ?string $replyToName = null): void
{
    if (isset($GLOBALS['mail_mock'])) {
        ($GLOBALS['mail_mock'])(['owner'], $subject, $html);
        return;
    }
    if (!mail_configured()) {
        throw new RuntimeException('The email sending settings are not filled in yet.');
    }
    $to = preg_split('/[\s,;]+/', (string) config()['contact_recipients'], -1, PREG_SPLIT_NO_EMPTY);
    if (mail_method() === 'graph') {
        graph_send($to, $subject, $html, $replyToEmail, $replyToName, true);
        return;
    }
    if (mail_method() === 'smtp') {
        smtp_send($to, $subject, $html, $replyToEmail, $replyToName);
        return;
    }
    php_send(implode(', ', $to), $subject, $html, $replyToEmail, $replyToName);
}

/* ---------- Building the message (shared by PHP mail and SMTP) ---------- */

/** A header value with no line breaks, so nobody can add headers of their own. */
function mail_clean(string $value): string
{
    return trim((string) preg_replace('/[\r\n]+/', ' ', $value));
}

/** A display name for a header, quoted and encoded as needed. */
function mail_name(string $name): string
{
    $name = mail_clean($name);
    if ($name === '') {
        return '';
    }
    return preg_match('/[^\x20-\x7e]/', $name) ? mb_encode_mimeheader($name, 'UTF-8', 'B') : '"' . addcslashes($name, '"\\') . '"';
}

/**
 * The parts of a message both transports need: the headers (without To and Subject),
 * the multipart/alternative body (plain text and HTML) and the encoded subject.
 * @return array{array, string, string}
 */
function mail_build(string $subject, string $html, ?string $replyToEmail, ?string $replyToName): array
{
    [$fromAddress, $fromName] = mail_from();
    $text = plain_text($html);
    $boundary = 'b' . bin2hex(random_bytes(12));
    $headers = [
        'From: ' . mail_name($fromName) . " <$fromAddress>",
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'X-Mailer: Full Circle CMS',
    ];
    if ($replyToEmail && filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . ($replyToName ? mail_name($replyToName) . ' ' : '') . "<$replyToEmail>";
    }
    $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
        . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html))
        . "--$boundary--\r\n";
    return [$headers, $body, mb_encode_mimeheader(mail_clean($subject), 'UTF-8', 'B')];
}

/* ---------- PHP mail() ---------- */

function php_send(string $to, string $subject, string $html, ?string $replyToEmail, ?string $replyToName): void
{
    [$fromAddress] = mail_from();
    [$headers, $body, $encodedSubject] = mail_build($subject, $html, $replyToEmail, $replyToName);
    // -f sets the envelope sender, which many hosts require to match a real mailbox on the account.
    $extra = preg_match('/^[a-z0-9._%+-]+@[a-z0-9.-]+$/i', $fromAddress) ? '-f' . $fromAddress : '';
    $ok = @mail($to, $encodedSubject, $body, implode("\r\n", $headers), $extra);
    if (!$ok) {
        $err = error_get_last();
        throw new RuntimeException('The server’s mail program refused the message' . ($err ? ': ' . $err['message'] : '.'));
    }
}

/* ---------- SMTP ---------- */

/** The SMTP settings with their defaults filled in. */
function smtp_settings(): array
{
    $c = config();
    $port = (int) ($c['smtp_port'] ?? 0);
    return [
        'host' => trim((string) ($c['smtp_host'] ?? '')),
        'port' => $port > 0 && $port <= 65535 ? $port : 587,
        'user' => trim((string) ($c['smtp_user'] ?? '')),
        'password' => (string) ($c['smtp_password'] ?? ''),
    ];
}

/**
 * Send one message to one or more addresses through the SMTP server in Settings.
 * Port 465 is implicit TLS; every other port starts plain and switches to TLS with
 * STARTTLS when the server offers it. A password is never sent over a plain
 * connection. Each step that can fail says which one did, in words the owner can act on.
 */
function smtp_send(array $to, string $subject, string $html, ?string $replyToEmail, ?string $replyToName): void
{
    $s = smtp_settings();
    if ($s['host'] === '') {
        throw new RuntimeException('The SMTP server name is missing from the email settings.');
    }
    [$fromAddress] = mail_from();
    [$headers, $body, $encodedSubject] = mail_build($subject, $html, $replyToEmail, $replyToName);
    $message = implode("\r\n", array_merge(
        ['To: ' . implode(', ', array_map(fn($a) => "<$a>", $to)), 'Subject: ' . $encodedSubject, 'Date: ' . date(DATE_RFC2822),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . site_host() . '>'],
        $headers
    )) . "\r\n\r\n" . $body;

    $conn = smtp_connect($s['host'], $s['port']);
    try {
        $features = smtp_hello($conn);
        if ($s['port'] !== 465) {
            if (in_array('STARTTLS', $features, true)) {
                smtp_command($conn, 'STARTTLS', [220], 'starting a secure connection (STARTTLS)');
                $crypto = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
                if (@stream_socket_enable_crypto($conn['stream'], true, $crypto) !== true) {
                    throw new RuntimeException("Could not start a secure connection to {$s['host']}. Check the server name and port (587 is usual for STARTTLS, 465 for TLS).");
                }
                $features = smtp_hello($conn);
            } elseif ($s['user'] !== '' && !in_array($s['host'], ['localhost', '127.0.0.1', '::1'], true)) {
                throw new RuntimeException("{$s['host']} does not offer a secure connection (STARTTLS) on port {$s['port']}, so the password was not sent. Try port 465 or 587.");
            }
        }
        if ($s['user'] !== '') {
            smtp_authenticate($conn, $features, $s['user'], $s['password']);
        }
        smtp_command($conn, "MAIL FROM:<$fromAddress>", [250], 'setting the From address');
        foreach ($to as $address) {
            smtp_command($conn, "RCPT TO:<$address>", [250, 251], "accepting the recipient $address");
        }
        smtp_command($conn, 'DATA', [354], 'starting the message');
        // Dot-stuffing: a line that starts with a dot gets a second one, so the lone dot below is the only end marker.
        $stuffed = preg_replace('/(^|\r\n)\./', '$1..', $message);
        smtp_write($conn, rtrim((string) $stuffed, "\r\n") . "\r\n.");
        smtp_expect($conn, [250], 'sending the message');
        try {
            smtp_command($conn, 'QUIT', [221], 'closing the connection');
        } catch (RuntimeException) {
            // The message was accepted; a rude goodbye doesn't matter.
        }
    } finally {
        @fclose($conn['stream']);
    }
}

/** @return array{stream: resource, host: string} */
function smtp_connect(string $host, int $port): array
{
    $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'SNI_enabled' => true]]);
    $address = ($port === 465 ? 'tls://' : 'tcp://') . $host . ':' . $port;
    $stream = @stream_socket_client($address, $errno, $errstr, SMTP_TIMEOUT, STREAM_CLIENT_CONNECT, $context);
    if (!$stream) {
        throw new RuntimeException("Could not connect to the SMTP server $host on port $port" . ($errstr ? " ($errstr)" : '') . '. Check the server name and port.');
    }
    stream_set_timeout($stream, SMTP_TIMEOUT);
    $conn = ['stream' => $stream, 'host' => $host];
    smtp_expect($conn, [220], 'connecting');
    return $conn;
}

/** EHLO, returning the features the server lists (STARTTLS, AUTH PLAIN LOGIN, ...). */
function smtp_hello(array $conn): array
{
    $name = preg_match('/^[a-z0-9.-]+$/i', site_host()) ? site_host() : 'localhost';
    $lines = smtp_command($conn, "EHLO $name", [250], 'introducing the website (EHLO)');
    $features = [];
    foreach (array_slice($lines, 1) as $line) {
        $features[] = strtoupper(trim(substr($line, 4)));
    }
    return $features;
}

function smtp_authenticate(array $conn, array $features, string $user, string $password): void
{
    $auth = '';
    foreach ($features as $f) {
        if (str_starts_with($f, 'AUTH ') || str_starts_with($f, 'AUTH=')) {
            $auth = substr($f, 5);
        }
    }
    $methods = preg_split('/[\s=]+/', $auth, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $step = 'signing in to the email account';
    try {
        if (in_array('PLAIN', $methods, true) || !in_array('LOGIN', $methods, true)) {
            smtp_command($conn, 'AUTH PLAIN ' . base64_encode("\0$user\0$password"), [235], $step);
        } else {
            smtp_command($conn, 'AUTH LOGIN', [334], $step);
            smtp_command($conn, base64_encode($user), [334], $step);
            smtp_command($conn, base64_encode($password), [235], $step);
        }
    } catch (RuntimeException $e) {
        throw new RuntimeException(rtrim($e->getMessage(), '.') . '. Check the user name and password. Gmail and Outlook.com need an app password, not the one you sign in with.');
    }
}

/** Send one command and check the reply code. Returns the reply lines. */
function smtp_command(array $conn, string $command, array $okCodes, string $step): array
{
    smtp_write($conn, $command);
    return smtp_expect($conn, $okCodes, $step);
}

function smtp_write(array $conn, string $line): void
{
    if (@fwrite($conn['stream'], $line . "\r\n") === false) {
        throw new RuntimeException("The connection to {$conn['host']} was lost while sending.");
    }
}

/** Read one (possibly multi-line) reply and insist on one of the expected codes. */
function smtp_expect(array $conn, array $okCodes, string $step): array
{
    $lines = [];
    do {
        $line = fgets($conn['stream'], 2048);
        if ($line === false) {
            $meta = stream_get_meta_data($conn['stream']);
            throw new RuntimeException(!empty($meta['timed_out'])
                ? "The SMTP server {$conn['host']} did not answer within " . SMTP_TIMEOUT . " seconds while $step."
                : "The SMTP server {$conn['host']} closed the connection while $step.");
        }
        $lines[] = rtrim($line, "\r\n");
    } while (strlen($line) >= 4 && $line[3] === '-');
    $code = (int) substr($lines[0], 0, 3);
    if (!in_array($code, $okCodes, true)) {
        $reply = mail_clean(preg_replace('/^\d{3}[ -]/', '', (string) end($lines)) ?? '');
        throw new RuntimeException("The SMTP server {$conn['host']} refused while $step: $code $reply");
    }
    return $lines;
}

/* ---------- Microsoft Graph ---------- */

function graph_send(array $to, string $subject, string $html, ?string $replyToEmail, ?string $replyToName, bool $keepCopy): void
{
    $message = [
        'subject' => $subject,
        'body' => ['contentType' => 'HTML', 'content' => $html],
        'toRecipients' => array_map(fn($a) => ['emailAddress' => ['address' => $a]], $to),
    ];
    if ($replyToEmail) {
        $message['replyTo'] = [['emailAddress' => array_filter(['address' => $replyToEmail, 'name' => $replyToName])]];
    }
    graph_request('POST', '/users/' . rawurlencode((string) config()['graph_sender']) . '/sendMail', ['message' => $message, 'saveToSentItems' => $keepCopy]);
}
