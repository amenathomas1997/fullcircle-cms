<?php
/**
 * The contact form. Put [contact-form] anywhere in a page's HTML to show it (see shortcodes.php).
 * Every message is saved in data/messages (viewable in the control panel)
 * and emailed through the email sending settings.
 */

declare(strict_types=1);

const MESSAGE_DIR = DATA_DIR . '/messages';

function site_secret(): string
{
    $secret = config()['site_secret'] ?? '';
    if ($secret === '') {
        $secret = bin2hex(random_bytes(32));
        save_config(['site_secret' => $secret]);
    }
    return $secret;
}

/**
 * The token is tied to the browser that was given it (by its User-Agent string), so one token
 * can't be collected once and used from many different clients. The address isn't included,
 * because phones often change address between opening a form and sending it.
 */
function form_token_client(): string
{
    return substr(hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 16);
}

function contact_form_token(): string
{
    $t = (string) time();
    return $t . '.' . hash_hmac('sha256', $t . '|' . form_token_client(), site_secret());
}

/** Rejects forged tokens, forms older than a day, and forms sent within 3 seconds (bots). */
function contact_token_ok(string $token): bool
{
    [$t, $sig] = array_pad(explode('.', $token, 2), 2, '');
    if (!ctype_digit($t) || !hash_equals(hash_hmac('sha256', $t . '|' . form_token_client(), site_secret()), $sig)) {
        return false;
    }
    $age = time() - (int) $t;
    return $age >= 3 && $age <= 86400;
}

/** At most 5 messages per hour from one visitor. */
function contact_rate_limited(): bool
{
    return rate_limited(DATA_DIR . '/contact-rate.json', hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . site_secret()), 5, 3600);
}

function contact_fields(): array
{
    return [
        'name' => ['label' => t('contact_name'), 'type' => 'text', 'required' => true, 'autocomplete' => 'name'],
        'email' => ['label' => t('contact_email'), 'type' => 'email', 'required' => true, 'autocomplete' => 'email'],
        'phone' => ['label' => t('contact_phone'), 'type' => 'tel', 'required' => false, 'autocomplete' => 'tel'],
        'message' => ['label' => t('contact_message'), 'type' => 'textarea', 'required' => true, 'autocomplete' => 'off'],
    ];
}

/**
 * Handle a POST to a page that contains the form.
 * Returns ['errors' => [...], 'values' => [...]] or redirects on success.
 */
function handle_contact_post(string $pagePath): array
{
    $values = [];
    foreach (contact_fields() as $name => $f) {
        $values[$name] = trim((string) ($_POST[$name] ?? ''));
    }
    $errors = [];

    // Bots fill in the hidden "website" field and send forms instantly; pretend it worked.
    if (($_POST['website'] ?? '') !== '' || !contact_token_ok((string) ($_POST['form_token'] ?? ''))) {
        header('Location: ' . $pagePath . '?sent=1#contact-status', true, 303);
        exit;
    }

    if ($values['name'] === '') {
        $errors['name'] = t('err_name');
    } elseif (mb_strlen($values['name']) > 200) {
        $errors['name'] = t('err_name_long');
    }
    if ($values['email'] === '') {
        $errors['email'] = t('err_email');
    } elseif (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = t('err_email_format');
    }
    if ($values['phone'] !== '' && !preg_match('/^[0-9 +().-]{7,25}$/', $values['phone'])) {
        $errors['phone'] = t('err_phone');
    }
    if ($values['message'] === '') {
        $errors['message'] = t('err_message');
    } elseif (mb_strlen($values['message']) > 5000) {
        $errors['message'] = t('err_message_long');
    }
    if ($errors) {
        return ['errors' => $errors, 'values' => $values];
    }
    if (contact_rate_limited()) {
        return ['errors' => ['form' => t('err_rate')], 'values' => $values];
    }

    // Save first, so a message is never lost even if email fails.
    $record = $values + ['received' => date('c'), 'page' => $pagePath, 'language' => current_lang(), 'emailed' => false, 'error' => ''];
    $file = MESSAGE_DIR . '/' . date('Y-m-d_His') . '-' . bin2hex(random_bytes(3)) . '.json';
    write_json($file, $record);

    $rows = '';
    foreach (contact_fields() as $name => $f) {
        $rows .= '<p><strong>' . h($f['label']) . ':</strong><br>' . nl2br(h($values[$name] ?: '—')) . '</p>';
    }
    try {
        send_mail_owner(
            config()['contact_subject'] . ' — ' . $values['name'],
            "<p>Someone sent a message using the contact form on your website.</p>$rows<p>Reply to this email to answer them directly.</p>",
            $values['email'],
            $values['name']
        );
        $record['emailed'] = true;
    } catch (Throwable $e) {
        $record['error'] = $e->getMessage();
        error_log('Contact form email failed: ' . $e->getMessage());
    }
    write_json($file, $record);

    header('Location: ' . $pagePath . '?sent=1#contact-status', true, 303);
    exit;
}

function render_contact_form(array $state = []): string
{
    $errors = $state['errors'] ?? [];
    $values = $state['values'] ?? [];
    $out = '';

    if (isset($_GET['sent'])) {
        return '<div id="contact-status" class="notice notice-success" role="status" tabindex="-1" data-focus-on-load>'
            . '<h2>' . h(t('contact_sent_title')) . '</h2>'
            . '<p>' . h(t('contact_sent_body')) . '</p></div>';
    }

    if ($errors) {
        $out .= '<div id="contact-status" class="error-summary" role="alert" tabindex="-1" data-focus-on-load>'
            . '<h2>' . h(t('error_title')) . '</h2><ul>';
        foreach ($errors as $field => $msg) {
            $out .= $field === 'form' ? '<li>' . h($msg) . '</li>' : '<li><a href="#contact-' . h($field) . '">' . h($msg) . '</a></li>';
        }
        $out .= '</ul></div>';
    }

    $out .= '<form class="contact-form" method="post" action="#contact-status" novalidate>'
        . '<p>' . h(t('required_note')) . '</p>';
    foreach (contact_fields() as $name => $f) {
        $id = "contact-$name";
        $err = $errors[$name] ?? '';
        $describedBy = $err ? " aria-describedby=\"$id-error\" aria-invalid=\"true\"" : '';
        $required = $f['required'] ? ' required' : '';
        $out .= '<div class="field' . ($err ? ' field-error' : '') . '">'
            . '<label for="' . $id . '">' . h($f['label']) . '</label>';
        if ($err) {
            $out .= '<p class="error-message" id="' . $id . '-error"><span class="visually-hidden">' . h(t('error_prefix')) . '</span>' . h($err) . '</p>';
        }
        if ($f['type'] === 'textarea') {
            $out .= '<textarea id="' . $id . '" name="' . $name . '" rows="8"' . $required . $describedBy . '>' . h($values[$name] ?? '') . '</textarea>';
        } else {
            $out .= '<input id="' . $id . '" name="' . $name . '" type="' . $f['type'] . '" autocomplete="' . $f['autocomplete'] . '" value="' . h($values[$name] ?? '') . '"' . $required . $describedBy . '>';
        }
        $out .= '</div>';
    }
    $out .= '<div class="hp-field" aria-hidden="true"><label for="contact-website">Leave this empty</label>'
        . '<input id="contact-website" name="website" type="text" tabindex="-1" autocomplete="off"></div>'
        . '<input type="hidden" name="form_token" value="' . h(contact_form_token()) . '">'
        . '<input type="hidden" name="contact_form" value="1">'
        . '<button type="submit" class="button">' . h(t('contact_send')) . '</button></form>';
    return $out;
}
