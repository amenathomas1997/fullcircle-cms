<?php
/**
 * Testimonials sent in by clients with [testimonial-form].
 * Each one is saved in content/testimonials/ as "pending" and only appears
 * on the website, through [testimonials], after it is approved in the control panel.
 */

declare(strict_types=1);

const TESTIMONIAL_DIR = CONTENT_DIR . '/testimonials';

function all_testimonials(?string $status = null): array
{
    $items = [];
    foreach (glob(TESTIMONIAL_DIR . '/*.json') ?: [] as $file) {
        $t = read_json($file);
        if ($t && ($status === null || ($t['status'] ?? '') === $status)) {
            $t['id'] = basename($file, '.json');
            $items[] = $t;
        }
    }
    usort($items, fn($a, $b) => strcmp($b['received'] ?? '', $a['received'] ?? ''));
    return $items;
}

function testimonial_file(string $id): ?string
{
    return preg_match('/^[0-9_-]+-[0-9a-f]{6}$/', $id) ? TESTIMONIAL_DIR . "/$id.json" : null;
}

function render_testimonials(): string
{
    $items = all_testimonials('approved');
    if (!$items) {
        return '';
    }
    $out = '<ul class="testimonial-list">';
    foreach ($items as $t) {
        $who = $t['name'] . (($t['role'] ?? '') !== '' ? ', ' . $t['role'] : '');
        $out .= '<li><blockquote><p>' . nl2br(h($t['text'])) . '</p><cite>— ' . h($who) . '</cite></blockquote></li>';
    }
    return $out . '</ul>';
}

function handle_testimonial_post(string $pagePath): array
{
    $v = [
        'name' => trim((string) ($_POST['t_name'] ?? '')),
        'role' => trim((string) ($_POST['t_role'] ?? '')),
        'text' => trim((string) ($_POST['t_text'] ?? '')),
        'email' => trim((string) ($_POST['t_email'] ?? '')),
        'consent' => !empty($_POST['t_consent']),
    ];
    if (($_POST['website'] ?? '') !== '' || !contact_token_ok((string) ($_POST['form_token'] ?? ''))) {
        header('Location: ' . $pagePath . '?shared=1#testimonial-status', true, 303);
        exit;
    }
    $errors = [];
    if ($v['name'] === '') {
        $errors['t_name'] = t('err_name');
    } elseif (mb_strlen($v['name']) > 120) {
        $errors['t_name'] = t('err_name_long');
    }
    if ($v['text'] === '') {
        $errors['t_text'] = t('err_testi_text');
    } elseif (mb_strlen($v['text']) > 3000) {
        $errors['t_text'] = t('err_testi_long');
    }
    if ($v['email'] !== '' && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['t_email'] = t('err_email_format');
    }
    if (!$v['consent']) {
        $errors['t_consent'] = t('err_consent');
    }
    if ($errors) {
        return ['errors' => $errors, 'values' => $v];
    }
    if (contact_rate_limited()) {
        return ['errors' => ['form' => t('err_rate')], 'values' => $v];
    }

    $id = date('Y-m-d_His') . '-' . bin2hex(random_bytes(3));
    write_json(TESTIMONIAL_DIR . "/$id.json", [
        'name' => mb_substr($v['name'], 0, 120),
        'role' => mb_substr($v['role'], 0, 160),
        'text' => $v['text'],
        'email' => $v['email'],
        'status' => 'pending',
        'received' => date('c'),
    ]);
    try {
        send_mail_owner(
            'New testimonial to review — ' . $v['name'],
            '<p>' . h($v['name']) . ' sent a testimonial. It won’t appear on the website until you approve it in the control panel under Testimonials.</p>'
                . '<blockquote>' . nl2br(h($v['text'])) . '</blockquote>'
                . '<p><a href="' . h(site_url('/admin/?a=testimonials')) . '">Review testimonials</a></p>',
            $v['email'] ?: null,
            $v['name']
        );
    } catch (Throwable $e) {
        error_log('Testimonial notification email failed: ' . $e->getMessage());
    }
    header('Location: ' . $pagePath . '?shared=1#testimonial-status', true, 303);
    exit;
}

function render_testimonial_form(array $state = []): string
{
    if (isset($_GET['shared'])) {
        return '<div id="testimonial-status" class="notice notice-success" role="status" tabindex="-1" data-focus-on-load>'
            . '<h2>' . h(t('testi_sent_title')) . '</h2><p>' . h(t('testi_sent_body')) . '</p></div>';
    }
    $errors = $state['errors'] ?? [];
    $v = $state['values'] ?? [];
    $out = '';
    if ($errors) {
        $out .= '<div id="testimonial-status" class="error-summary" role="alert" tabindex="-1" data-focus-on-load><h2>' . h(t('testi_error_title')) . '</h2><ul>';
        foreach ($errors as $field => $msg) {
            $out .= $field === 'form' ? '<li>' . h($msg) . '</li>' : '<li><a href="#' . h($field) . '">' . h($msg) . '</a></li>';
        }
        $out .= '</ul></div>';
    }
    $field = function (string $name, string $label, string $type, array $o = []) use ($errors, $v): string {
        $id = "t_$name";
        $err = $errors[$id] ?? '';
        $hintId = !empty($o['hint']) ? "$id-hint" : '';
        $describedBy = trim($hintId . ($err ? " $id-error" : ''));
        $attrs = ($describedBy ? ' aria-describedby="' . $describedBy . '"' : '') . ($err ? ' aria-invalid="true"' : '') . (!empty($o['required']) ? ' required' : '')
            . (isset($o['autocomplete']) ? ' autocomplete="' . $o['autocomplete'] . '"' : '');
        $html = '<div class="field' . ($err ? ' field-error' : '') . '"><label for="' . $id . '">' . h($label) . '</label>'
            . ($hintId ? '<p class="hint" id="' . $hintId . '">' . h($o['hint']) . '</p>' : '')
            . ($err ? '<p class="error-message" id="' . $id . '-error"><span class="visually-hidden">' . h(t('error_prefix')) . '</span>' . h($err) . '</p>' : '');
        $value = (string) ($v[$name] ?? '');
        $html .= $type === 'textarea'
            ? '<textarea id="' . $id . '" name="' . $id . '" rows="8"' . $attrs . '>' . h($value) . '</textarea>'
            : '<input type="' . $type . '" id="' . $id . '" name="' . $id . '" value="' . h($value) . '"' . $attrs . '>';
        return $html . '</div>';
    };
    $consentErr = $errors['t_consent'] ?? '';
    $out .= '<form class="contact-form testimonial-form" method="post" action="#testimonial-status" novalidate>'
        . '<p>' . h(t('required_note')) . '</p>'
        . $field('name', t('testi_name'), 'text', ['required' => true, 'hint' => t('testi_name_hint'), 'autocomplete' => 'name'])
        . $field('role', t('testi_role'), 'text', ['hint' => t('testi_role_hint'), 'autocomplete' => 'off'])
        . $field('text', t('testi_text'), 'textarea', ['required' => true])
        . $field('email', t('testi_email'), 'email', ['hint' => t('testi_email_hint'), 'autocomplete' => 'email'])
        . '<div class="field checkbox-field' . ($consentErr ? ' field-error' : '') . '">'
        . '<input type="checkbox" id="t_consent" name="t_consent" value="1"' . (!empty($v['consent']) ? ' checked' : '')
        . ($consentErr ? ' aria-invalid="true" aria-describedby="t_consent-error"' : '') . ' required>'
        . '<div><label for="t_consent">' . h(t('testi_consent')) . '</label>'
        . ($consentErr ? '<p class="error-message" id="t_consent-error"><span class="visually-hidden">' . h(t('error_prefix')) . '</span>' . h($consentErr) . '</p>' : '')
        . '</div></div>'
        . '<div class="hp-field" aria-hidden="true"><label for="t-website">Leave this empty</label><input id="t-website" name="website" type="text" tabindex="-1" autocomplete="off"></div>'
        . '<input type="hidden" name="form_token" value="' . h(contact_form_token()) . '">'
        . '<input type="hidden" name="testimonial_form" value="1">'
        . '<button type="submit" class="button">' . h(t('testi_send')) . '</button></form>';
    return $out;
}
