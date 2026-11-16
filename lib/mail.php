<?php
/**
 * Sending email. Settings > Email sending chooses one of two ways:
 *   php    the web server's own mail program, through PHP's mail() function
 *          (works on almost every shared host with no account to set up);
 *   graph  Microsoft 365, through Microsoft Graph (lib/graph-mail.php).
 * Everything that emails anyone calls send_mail_to() or send_mail_owner().
 */

declare(strict_types=1);

function mail_method(): string
{
    return (config()['mail_method'] ?? 'php') === 'graph' ? 'graph' : 'php';
}

/** The address and name emails come from. For Graph it is always the sender mailbox. */
function mail_from(): array
{
    $c = config();
    $address = mail_method() === 'graph' ? (string) $c['graph_sender'] : (string) ($c['mail_from'] ?? '');
    return [$address, (string) ($c['mail_from_name'] ?: settings()['site_name'])];
}

/** Enough is filled in to send mail to visitors (confirmations, updates). */
function mail_ready(): bool
{
    return mail_method() === 'graph' ? graph_app_configured() && config()['graph_sender'] !== '' : filter_var(config()['mail_from'] ?? '', FILTER_VALIDATE_EMAIL) !== false;
}

/** Enough is filled in to email the site owner too (contact form, reports). */
function mail_configured(): bool
{
    return mail_ready() && (config()['contact_recipients'] ?? '') !== '';
}

/** Send an HTML email to one or more people, each seeing only their own address. */
function send_mail_to(array $addresses, string $subject, string $html, ?string $replyToEmail = null, ?string $replyToName = null): void
{
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
        php_send($address, $subject, $html, $replyToEmail, $replyToName);
    }
}

/** Send an HTML email to the site owner's addresses (Settings > Send messages to). */
function send_mail_owner(string $subject, string $html, ?string $replyToEmail = null, ?string $replyToName = null): void
{
    if (!mail_configured()) {
        throw new RuntimeException('The email sending settings are not filled in yet.');
    }
    $to = preg_split('/[\s,;]+/', (string) config()['contact_recipients'], -1, PREG_SPLIT_NO_EMPTY);
    if (mail_method() === 'graph') {
        graph_send($to, $subject, $html, $replyToEmail, $replyToName, true);
        return;
    }
    php_send(implode(', ', $to), $subject, $html, $replyToEmail, $replyToName);
}

/* ---------- PHP mail() ---------- */

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

function php_send(string $to, string $subject, string $html, ?string $replyToEmail, ?string $replyToName): void
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
    $encodedSubject = mb_encode_mimeheader(mail_clean($subject), 'UTF-8', 'B');
    // -f sets the envelope sender, which many hosts require to match a real mailbox on the account.
    $extra = preg_match('/^[a-z0-9._%+-]+@[a-z0-9.-]+$/i', $fromAddress) ? '-f' . $fromAddress : '';
    $ok = @mail($to, $encodedSubject, $body, implode("\r\n", $headers), $extra);
    if (!$ok) {
        $err = error_get_last();
        throw new RuntimeException('The server’s mail program refused the message' . ($err ? ': ' . $err['message'] : '.'));
    }
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
