<?php
/**
 * Forms builder. The owner defines forms in the control panel (Forms): a name, a title,
 * a success message, who it emails and an ordered list of fields. Each form is an item
 * of type "forms" in content/forms/<name>.json and gets its own shortcode, [form <name>],
 * which renders it with the same accessible pattern as the contact form. Every answer
 * is saved in data/forms/<name>/ and emailed to the owner; a Submissions screen lists
 * them, downloads them as a CSV file and deletes them.
 */

declare(strict_types=1);

const FORM_SUBMISSION_DIR = DATA_DIR . '/forms';
const FORM_FIELD_TYPES = [
    'text' => 'Short text',
    'textarea' => 'Long text',
    'email' => 'Email address',
    'tel' => 'Phone number',
    'number' => 'Number',
    'date' => 'Date',
    'select' => 'Choice list (choose one)',
    'checkboxes' => 'Checkboxes (choose several)',
    'checkbox' => 'Yes/no checkbox',
    'heading' => 'Heading (no answer)',
    'paragraph' => 'Paragraph of explanatory text (no answer)',
];
const FORM_CHOICE_TYPES = ['select', 'checkboxes'];
const FORM_TEXT_ONLY_TYPES = ['heading', 'paragraph'];

register_feature('forms', 'Forms', 'Build your own forms (bookings, sign-ups, questionnaires). Each one gets a shortcode; the answers are kept in the control panel and emailed to you.', true);
register_admin_screen('forms', fn(bool $post) => $post ? forms_admin_post() : forms_admin_get(), 'Forms', 'forms');
register_dashboard_card(fn() => feature_on('forms') ? forms_dashboard_card() : '');

// One shortcode for every form that has been defined, such as [form booking].
if (feature_on('forms')) {
    foreach (all_items('forms') as $definedForm) {
        register_shortcode('[form ' . $definedForm['slug'] . ']', fn(string $path) => render_custom_form((string) $definedForm['slug'], $path));
    }
}

/* =====================================================================
   Shared helpers
   ===================================================================== */

/** A clean copy of one field definition, with every key present. */
function form_field_defaults(array $f): array
{
    $type = (string) ($f['type'] ?? 'text');
    return [
        'label' => trim((string) ($f['label'] ?? '')),
        'type' => isset(FORM_FIELD_TYPES[$type]) ? $type : 'text',
        'required' => !empty($f['required']),
        'hint' => trim((string) ($f['hint'] ?? '')),
        'options' => trim(str_replace("\r\n", "\n", (string) ($f['options'] ?? ''))),
    ];
}

/** The choices of a choice-list or checkboxes field, one per line. */
function form_field_options(array $field): array
{
    return array_values(array_filter(array_map('trim', explode("\n", (string) ($field['options'] ?? ''))), 'strlen'));
}

function form_submission_dir(string $slug): string
{
    if (!valid_slug($slug)) {
        throw new InvalidArgumentException('Invalid form name.');
    }
    return FORM_SUBMISSION_DIR . '/' . $slug;
}

/** Submissions of one form, newest first, each with its id. */
function form_submissions(string $slug): array
{
    $out = [];
    foreach (glob(form_submission_dir($slug) . '/*.json') ?: [] as $file) {
        if ($data = read_json($file)) {
            $data['id'] = basename($file, '.json');
            $out[] = $data;
        }
    }
    usort($out, fn($a, $b) => strcmp($b['id'], $a['id']));
    return $out;
}

function form_submission_file(string $slug, string $id): ?string
{
    if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}-[0-9a-f]{6}$/', $id)) {
        return null;
    }
    $file = form_submission_dir($slug) . "/$id.json";
    return is_file($file) ? $file : null;
}

function form_unread_count(string $slug): int
{
    return count(array_filter(form_submissions($slug), fn($s) => empty($s['read'])));
}

/* =====================================================================
   Public: rendering and handling a form
   ===================================================================== */

function render_custom_form(string $slug, string $path): string
{
    if (!feature_on('forms') || !($form = get_item('forms', $slug))) {
        return '';
    }
    $state = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_name'] ?? '') === $slug) {
        $state = handle_custom_form_post($form, $path);
    }
    return custom_form_html($form, $state);
}

/** Validate and store one submission; redirects on success, returns errors and values otherwise. */
function handle_custom_form_post(array $form, string $path): array
{
    $slug = (string) $form['slug'];
    $statusId = "form-$slug-status";
    // Bots fill in the hidden "website" field and send forms instantly; pretend it worked.
    if (($_POST['website'] ?? '') !== '' || !contact_token_ok((string) ($_POST['form_token'] ?? ''))) {
        header('Location: ' . $path . '?sent=' . rawurlencode($slug) . '#' . $statusId, true, 303);
        exit;
    }
    $posted = (array) ($_POST['f'] ?? []);
    $values = [];
    $errors = [];
    $answers = [];
    foreach ((array) ($form['fields'] ?? []) as $i => $raw) {
        $field = form_field_defaults($raw);
        if (in_array($field['type'], FORM_TEXT_ONLY_TYPES, true)) {
            continue;
        }
        $label = $field['label'];
        $options = form_field_options($field);
        if ($field['type'] === 'checkboxes') {
            $value = array_values(array_filter(array_map(fn($v) => trim((string) $v), (array) ($posted[$i] ?? [])), 'strlen'));
            $values[$i] = $value;
            if ($field['required'] && !$value) {
                $errors[$i] = sprintf(t('form_err_choose'), $label);
            } elseif (array_diff($value, $options)) {
                $errors[$i] = sprintf(t('form_err_option'), $label);
            }
            $answers[] = ['label' => $label, 'type' => $field['type'], 'value' => $value];
            continue;
        }
        $value = is_array($posted[$i] ?? null) ? '' : trim((string) ($posted[$i] ?? ''));
        $values[$i] = $value;
        if ($field['type'] === 'checkbox') {
            $value = $value === '1' ? '1' : '';
            $values[$i] = $value;
            if ($field['required'] && $value === '') {
                $errors[$i] = sprintf(t('form_err_check'), $label);
            }
            $answers[] = ['label' => $label, 'type' => $field['type'], 'value' => $value === '1' ? t('yes') : t('no')];
            continue;
        }
        if ($value === '') {
            if ($field['required']) {
                $errors[$i] = sprintf(t($field['type'] === 'select' ? 'form_err_choose' : 'form_err_required'), $label);
            }
        } elseif (mb_strlen($value) > 5000) {
            $errors[$i] = sprintf(t('form_err_long'), $label);
        } else {
            $problem = match ($field['type']) {
                'email' => filter_var($value, FILTER_VALIDATE_EMAIL) === false ? 'form_err_email' : null,
                'tel' => !preg_match('/^[0-9 +().-]{7,25}$/', $value) ? 'form_err_phone' : null,
                'number' => !is_numeric($value) ? 'form_err_number' : null,
                'date' => !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $d) || !checkdate((int) $d[2], (int) $d[3], (int) $d[1]) ? 'form_err_date' : null,
                'select' => !in_array($value, $options, true) ? 'form_err_option' : null,
                default => null,
            };
            if ($problem !== null) {
                $errors[$i] = sprintf(t($problem), $label);
            }
        }
        $answers[] = ['label' => $label, 'type' => $field['type'], 'value' => $value];
    }
    if ($errors) {
        return ['errors' => $errors, 'values' => $values];
    }
    if (rate_limited(DATA_DIR . '/forms-rate.json', hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . site_secret()), 5, 3600)) {
        return ['errors' => ['form' => t('err_rate')], 'values' => $values];
    }

    // Save first, so an answer is never lost even if email fails.
    $record = ['received' => date('c'), 'page' => $path, 'language' => current_lang(), 'read' => false, 'emailed' => false, 'error' => '', 'answers' => $answers];
    $file = form_submission_dir($slug) . '/' . date('Y-m-d_His') . '-' . bin2hex(random_bytes(3)) . '.json';
    write_json($file, $record);

    $replyEmail = null;
    $replyName = null;
    $rows = '';
    foreach ($answers as $a) {
        $shown = is_array($a['value']) ? implode(', ', $a['value']) : (string) $a['value'];
        $rows .= '<p><strong>' . h($a['label']) . ':</strong><br>' . nl2br(h($shown !== '' ? $shown : '—')) . '</p>';
        if ($replyEmail === null && $a['type'] === 'email' && $shown !== '') {
            $replyEmail = $shown;
        }
        if ($replyName === null && $a['type'] === 'text' && $shown !== '' && preg_match('/name|nombre/i', $a['label'])) {
            $replyName = $shown;
        }
    }
    $subject = (string) ($form['title'] ?: $slug) . ($replyName !== null ? ' — ' . $replyName : '');
    $html = '<p>Someone filled in the form “' . h((string) $form['title']) . '” on your website.</p>' . $rows
        . ($replyEmail !== null ? '<p>Reply to this email to answer them directly.</p>' : '');
    try {
        $recipients = preg_split('/[\s,;]+/', (string) ($form['recipients'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $recipients ? send_mail_to($recipients, $subject, $html, $replyEmail, $replyName) : send_mail_owner($subject, $html, $replyEmail, $replyName);
        $record['emailed'] = true;
    } catch (Throwable $e) {
        $record['error'] = $e->getMessage();
        error_log("Form “{$slug}” email failed: " . $e->getMessage());
    }
    write_json($file, $record);

    header('Location: ' . $path . '?sent=' . rawurlencode($slug) . '#' . $statusId, true, 303);
    exit;
}

function custom_form_html(array $form, array $state = []): string
{
    $slug = (string) $form['slug'];
    $statusId = "form-$slug-status";
    $errors = $state['errors'] ?? [];
    $values = $state['values'] ?? [];
    $fields = array_map('form_field_defaults', (array) ($form['fields'] ?? []));

    if (($_GET['sent'] ?? '') === $slug) {
        $message = trim((string) ($form['success'] ?? ''));
        return '<div id="' . $statusId . '" class="notice notice-success" role="status" tabindex="-1" data-focus-on-load>'
            . '<h2>' . h(t('form_sent_title')) . '</h2><p>' . ($message !== '' ? nl2br(h($message)) : h(t('form_sent_body'))) . '</p></div>';
    }

    $out = '';
    if ($errors) {
        $out .= '<div id="' . $statusId . '" class="error-summary" role="alert" tabindex="-1" data-focus-on-load><h2>' . h(t('form_error_title')) . '</h2><ul>';
        foreach ($errors as $i => $msg) {
            $target = $i === 'form' ? null : "form-$slug-$i" . (($fields[$i]['type'] ?? '') === 'checkboxes' ? '-0' : '');
            $out .= $target === null ? '<li>' . h($msg) . '</li>' : '<li><a href="#' . $target . '">' . h($msg) . '</a></li>';
        }
        $out .= '</ul></div>';
    }
    $hasRequired = (bool) array_filter($fields, fn($f) => $f['required'] && !in_array($f['type'], FORM_TEXT_ONLY_TYPES, true));
    $out .= '<form class="contact-form custom-form" method="post" action="#' . $statusId . '" novalidate>'
        . ($hasRequired ? '<p>' . h(t('required_note')) . '</p>' : '');
    foreach ($fields as $i => $f) {
        $id = "form-$slug-$i";
        $label = h($f['label']) . ($hasRequired && !$f['required'] && !in_array($f['type'], FORM_TEXT_ONLY_TYPES, true) ? ' ' . h(t('form_optional')) : '');
        $err = $errors[$i] ?? '';
        $describedBy = array_filter([$f['hint'] !== '' ? "$id-hint" : '', $err !== '' ? "$id-error" : '']);
        $attrs = ($describedBy ? ' aria-describedby="' . implode(' ', $describedBy) . '"' : '') . ($err !== '' ? ' aria-invalid="true"' : '') . ($f['required'] ? ' required' : '');
        $hint = $f['hint'] !== '' ? '<p class="hint" id="' . $id . '-hint">' . h($f['hint']) . '</p>' : '';
        $error = $err !== '' ? '<p class="error-message" id="' . $id . '-error"><span class="visually-hidden">' . h(t('error_prefix')) . '</span>' . h($err) . '</p>' : '';
        $value = $values[$i] ?? ($f['type'] === 'checkboxes' ? [] : '');
        $class = 'field' . ($err !== '' ? ' field-error' : '');
        switch ($f['type']) {
            case 'heading':
                $out .= '<h2>' . h($f['label']) . '</h2>' . ($f['hint'] !== '' ? '<p>' . h($f['hint']) . '</p>' : '');
                break;
            case 'paragraph':
                $out .= '<p>' . nl2br(h($f['label'] . ($f['hint'] !== '' ? "\n" . $f['hint'] : ''))) . '</p>';
                break;
            case 'textarea':
                $out .= '<div class="' . $class . '"><label for="' . $id . '">' . $label . '</label>' . $hint . $error
                    . '<textarea id="' . $id . '" name="f[' . $i . ']" rows="6"' . $attrs . '>' . h((string) $value) . '</textarea></div>';
                break;
            case 'select':
                $options = '<option value="">' . h(t('form_choose')) . '</option>';
                foreach (form_field_options($f) as $opt) {
                    $options .= '<option value="' . h($opt) . '"' . ($value === $opt ? ' selected' : '') . '>' . h($opt) . '</option>';
                }
                $out .= '<div class="' . $class . '"><label for="' . $id . '">' . $label . '</label>' . $hint . $error
                    . '<select id="' . $id . '" name="f[' . $i . ']"' . $attrs . '>' . $options . '</select></div>';
                break;
            case 'checkboxes':
                $boxes = '';
                foreach (form_field_options($f) as $k => $opt) {
                    $boxes .= '<div class="option"><input type="checkbox" id="' . $id . '-' . $k . '" name="f[' . $i . '][]" value="' . h($opt) . '"'
                        . (in_array($opt, (array) $value, true) ? ' checked' : '') . '><label for="' . $id . '-' . $k . '">' . h($opt) . '</label></div>';
                }
                $groupAttrs = ($describedBy ? ' aria-describedby="' . implode(' ', $describedBy) . '"' : '');
                $out .= '<fieldset class="' . $class . '"' . $groupAttrs . '><legend>' . $label . '</legend>' . $hint . $error . $boxes . '</fieldset>';
                break;
            case 'checkbox':
                $out .= '<div class="' . $class . ' checkbox-field"><input type="checkbox" id="' . $id . '" name="f[' . $i . ']" value="1"' . ($value === '1' ? ' checked' : '') . $attrs . '>'
                    . '<label for="' . $id . '">' . $label . '</label>' . $hint . $error . '</div>';
                break;
            default:
                $type = in_array($f['type'], ['email', 'tel', 'number', 'date'], true) ? $f['type'] : 'text';
                $auto = match ($type) { 'email' => ' autocomplete="email"', 'tel' => ' autocomplete="tel"', default => '' };
                $step = $type === 'number' ? ' step="any"' : '';
                $out .= '<div class="' . $class . '"><label for="' . $id . '">' . $label . '</label>' . $hint . $error
                    . '<input type="' . $type . '" id="' . $id . '" name="f[' . $i . ']" value="' . h((string) $value) . '"' . $auto . $step . $attrs . '></div>';
        }
    }
    $out .= '<div class="hp-field" aria-hidden="true"><label for="' . h($slug) . '-website">Leave this empty</label>'
        . '<input id="' . h($slug) . '-website" name="website" type="text" tabindex="-1" autocomplete="off"></div>'
        . '<input type="hidden" name="form_token" value="' . h(contact_form_token()) . '">'
        . '<input type="hidden" name="form_name" value="' . h($slug) . '">'
        . '<button type="submit" class="button">' . h(t('form_send')) . '</button></form>';
    return $out;
}

/* =====================================================================
   Control panel: Forms
   ===================================================================== */

function forms_dashboard_card(): string
{
    $forms = all_items('forms');
    $unread = array_sum(array_map(fn($f) => form_unread_count((string) $f['slug']), $forms));
    return '<li><h2><a href="' . h(admin_url('forms')) . '">Forms</a></h2><p>' . count($forms) . ' ' . (count($forms) === 1 ? 'form' : 'forms')
        . ($unread ? ", <strong>$unread new " . ($unread === 1 ? 'answer' : 'answers') . '</strong>' : ', no new answers') . '.</p></li>';
}

function forms_admin_get(): never
{
    if (isset($_GET['csv'])) {
        form_csv_download((string) $_GET['csv']);
    }
    if (isset($_GET['view'])) {
        isset($_GET['delete']) ? form_submission_delete_confirm((string) $_GET['view'], (string) $_GET['delete']) : form_submissions_screen((string) $_GET['view']);
    }
    if (isset($_GET['delete'])) {
        form_delete_confirm((string) $_GET['delete']);
    }
    if (isset($_GET['slug']) || isset($_GET['new'])) {
        form_editor();
    }
    forms_list();
}

function forms_admin_post(): never
{
    if (isset($_GET['view'])) {
        isset($_GET['delete']) ? form_submission_delete((string) $_GET['view'], (string) $_GET['delete']) : form_submissions_mark_read((string) $_GET['view']);
    }
    if (isset($_GET['delete'])) {
        form_delete((string) $_GET['delete']);
    }
    form_editor_post();
}

function forms_list(): never
{
    $rows = '';
    foreach (all_items('forms') as $f) {
        $slug = (string) $f['slug'];
        $title = h((string) $f['title']);
        $count = count(form_submissions($slug));
        $unread = form_unread_count($slug);
        $rows .= '<tr><th scope="row">' . $title . '</th><td><code>[form ' . h($slug) . ']</code></td><td>' . count((array) ($f['fields'] ?? [])) . '</td>'
            . '<td>' . $count . ($unread ? " (<strong>$unread new</strong>)" : '') . '</td>'
            . '<td class="actions"><a href="' . h(admin_url('forms', ['slug' => $slug])) . '">Edit<span class="visually-hidden"> ' . $title . '</span></a>'
            . ' <a href="' . h(admin_url('forms', ['view' => $slug])) . '">Submissions<span class="visually-hidden"> for ' . $title . '</span></a>'
            . ' <a href="' . h(admin_url('forms', ['delete' => $slug])) . '">Delete<span class="visually-hidden"> ' . $title . '</span></a></td></tr>';
    }
    $body = '<p>Build a form here, then put its shortcode (for example <code>[form booking]</code>) in the HTML of any page. '
        . 'Everything people send is kept under Submissions and emailed to you, with their email address as the reply address when the form asks for one.</p>'
        . '<p><a class="button" href="' . h(admin_url('forms', ['new' => 1])) . '">Add a form</a></p>'
        . ($rows === '' ? '<p>There are no forms yet.</p>'
            : '<div class="table-wrap"><table><caption>All forms</caption><thead><tr><th scope="col">Form</th><th scope="col">Shortcode</th><th scope="col">Fields</th><th scope="col">Submissions</th><th scope="col">Actions</th></tr></thead>'
                . "<tbody>$rows</tbody></table></div>");
    admin_screen('Forms', $body, 'forms');
}

/** The form editor, drawn from saved values or from the values just posted (after Add, Move or Remove). */
function form_editor(?array $values = null, array $errors = [], ?string $origSlug = null, string $notice = ''): never
{
    $origSlug ??= isset($_GET['slug']) ? (string) $_GET['slug'] : null;
    $existing = $origSlug !== null && valid_slug($origSlug) ? get_item('forms', $origSlug) : null;
    if ($origSlug !== null && !$existing && $values === null) {
        flash('That form could not be found.', 'error');
        go(admin_url('forms'));
    }
    $v = $values ?? ($existing ?? ['title' => '', 'slug' => '', 'success' => 'Thank you. We received your answers and will be in touch soon.', 'recipients' => '',
        'fields' => [['label' => 'Your name', 'type' => 'text', 'required' => true], ['label' => 'Email address', 'type' => 'email', 'required' => true]]]);
    $isNew = $existing === null;
    $fields = array_map('form_field_defaults', (array) ($v['fields'] ?? []));
    $count = count($fields);

    $typeOptions = function (string $current, string $name, string $id): string {
        $out = '<select id="' . $id . '" name="' . $name . '">';
        foreach (FORM_FIELD_TYPES as $k => $label) {
            $out .= '<option value="' . $k . '"' . ($k === $current ? ' selected' : '') . '>' . h($label) . '</option>';
        }
        return $out . '</select>';
    };
    $list = '';
    foreach ($fields as $i => $f) {
        $n = $i + 1;
        $name = $f['label'] !== '' ? $f['label'] : FORM_FIELD_TYPES[$f['type']];
        $isChoice = in_array($f['type'], FORM_CHOICE_TYPES, true);
        $isText = in_array($f['type'], FORM_TEXT_ONLY_TYPES, true);
        $list .= '<fieldset class="block"><legend>Field ' . $n . ' of ' . $count . ': ' . h($name) . '</legend>'
            . '<div class="field"><label for="field-' . $i . '-type">Type</label>' . $typeOptions($f['type'], "fields[$i][type]", "field-$i-type") . '</div>'
            . text_field("fields[$i][label]", $isText ? ($f['type'] === 'heading' ? 'Heading text' : 'Text to show') : 'Label (the question)', $f['label'], ['id' => "field-$i-label", 'error' => $errors["field-$i-label"] ?? null])
            . textarea_field("fields[$i][hint]", 'Hint (optional)', $f['hint'], ['id' => "field-$i-hint", 'rows' => 2, 'hint' => 'A short line of help shown under the label, for example “We only use this to confirm your booking.”'])
            . ($isChoice || $f['options'] !== '' ? textarea_field("fields[$i][options]", 'Choices, one per line', $f['options'], ['id' => "field-$i-options", 'rows' => 4, 'error' => $errors["field-$i-options"] ?? null,
                'hint' => 'Only for choice lists and checkboxes.']) : '<input type="hidden" name="fields[' . $i . '][options]" value="">')
            . ($isText ? '' : checkbox_field("fields[$i][required]", 'Required (people must answer it)', $f['required'], ['id' => "field-$i-required"]))
            . '<div class="form-actions">'
            . ($i > 0 ? '<button type="submit" class="button button-secondary" name="move" value="up:' . $i . '">Move up<span class="visually-hidden">: ' . h($name) . '</span></button>' : '')
            . ($i < $count - 1 ? '<button type="submit" class="button button-secondary" name="move" value="down:' . $i . '">Move down<span class="visually-hidden">: ' . h($name) . '</span></button>' : '')
            . '<button type="submit" class="button button-danger" name="move" value="remove:' . $i . '">Remove<span class="visually-hidden">: ' . h($name) . '</span></button>'
            . '</div></fieldset>';
    }

    $body = ($notice !== '' ? '<div id="notice" class="notice notice-success" role="status" tabindex="-1"><p>' . h($notice) . '</p></div>' : '')
        . '<form method="post" action="' . h(admin_url('forms', $isNew ? ['new' => 1] : ['slug' => $origSlug])) . '">' . csrf_field()
        . updated_field('forms', $isNew ? null : $origSlug)
        . text_field('title', 'Form title', (string) $v['title'], ['required' => true, 'error' => $errors['title'] ?? null, 'hint' => 'Used as the subject of the email you get, for example “Booking request”.'])
        . text_field('slug', 'Name used in the shortcode', (string) $v['slug'], ['prefix' => '[form ', 'spellcheck' => 'false', 'autocomplete' => 'off', 'error' => $errors['slug'] ?? null,
            'hint' => 'Lowercase letters, numbers and dashes, for example <code>booking</code>. Leave it empty to make one from the title. You then put <code>[form booking]</code> in a page.'])
        . textarea_field('success', 'Message shown after sending', (string) ($v['success'] ?? ''), ['rows' => 3, 'error' => $errors['success'] ?? null])
        . text_field('recipients', 'Email the answers to', (string) ($v['recipients'] ?? ''), ['autocomplete' => 'off', 'error' => $errors['recipients'] ?? null,
            'hint' => 'One or more addresses separated by commas. Leave empty to use the “Send messages to” addresses from Settings' . (config()['contact_recipients'] !== '' ? ' (' . h((string) config()['contact_recipients']) . ')' : '') . '.'])
        . '<h2>Fields</h2><p>Moving, adding or removing a field changes the form on this screen; press Save form when you are done.</p>'
        . ($list === '' ? '<p>No fields yet. Add one below.</p>' : $list)
        . '<h3>Add a field</h3><div class="field"><label for="add-type">Type of field to add</label>' . $typeOptions('text', 'add_type', 'add-type') . '</div>'
        . '<p><button type="submit" class="button button-secondary" name="move" value="add">Add this field to the end</button></p>'
        . '<div class="form-actions"><button type="submit" class="button" name="move" value="save">Save form</button></div></form>';
    if (!$isNew) {
        $body .= '<h2>More options</h2><ul>'
            . '<li><a href="' . h(admin_url('forms', ['view' => $origSlug])) . '">Submissions</a> (' . count(form_submissions($origSlug)) . ')</li>'
            . '<li><a href="' . h(admin_url('forms', ['delete' => $origSlug])) . '">Delete this form</a></li></ul>';
    }
    admin_screen($isNew ? 'Add a form' : 'Edit form: ' . h((string) $existing['title']), $body, 'forms', ['errors' => $errors]);
}

function form_editor_post(): never
{
    $origSlug = isset($_GET['slug']) ? (string) $_GET['slug'] : null;
    $existing = $origSlug !== null && valid_slug($origSlug) ? get_item('forms', $origSlug) : null;
    $v = [
        'title' => trim((string) ($_POST['title'] ?? '')),
        'slug' => strtolower(trim((string) ($_POST['slug'] ?? ''))),
        'success' => trim(str_replace("\r\n", "\n", (string) ($_POST['success'] ?? ''))),
        'recipients' => trim((string) ($_POST['recipients'] ?? '')),
        'fields' => array_values(array_map('form_field_defaults', array_filter((array) ($_POST['fields'] ?? []), 'is_array'))),
    ];
    [$op, $index] = array_pad(explode(':', (string) ($_POST['move'] ?? 'save')), 2, '0');
    $i = (int) $index;
    $fields = &$v['fields'];
    $count = count($fields);

    if ($op === 'add') {
        $type = (string) ($_POST['add_type'] ?? 'text');
        $fields[] = form_field_defaults(['type' => $type, 'required' => !in_array($type, FORM_TEXT_ONLY_TYPES, true) && $type !== 'checkbox']);
        form_editor($v, [], $existing ? $origSlug : null, 'Added a ' . strtolower(FORM_FIELD_TYPES[end($fields)['type']]) . ' field as field ' . count($fields) . ' of ' . count($fields) . '. Fill in its label, then save the form.');
    }
    if (($op === 'up' || $op === 'down') && isset($fields[$i])) {
        $j = $op === 'up' ? $i - 1 : $i + 1;
        if (isset($fields[$j])) {
            [$fields[$i], $fields[$j]] = [$fields[$j], $fields[$i]];
            $name = $fields[$j]['label'] !== '' ? $fields[$j]['label'] : FORM_FIELD_TYPES[$fields[$j]['type']];
            form_editor($v, [], $existing ? $origSlug : null, "Moved “{$name}” $op. It is now field " . ($j + 1) . " of $count. Save the form to keep the new order.");
        }
    }
    if ($op === 'remove' && isset($fields[$i])) {
        $name = $fields[$i]['label'] !== '' ? $fields[$i]['label'] : FORM_FIELD_TYPES[$fields[$i]['type']];
        array_splice($fields, $i, 1);
        form_editor($v, [], $existing ? $origSlug : null, "Removed “{$name}”. " . count($fields) . ' fields left. Save the form to keep the change.');
    }

    if ($v['slug'] === '') {
        $v['slug'] = slugify($v['title']);
    }
    $errors = [];
    if ($v['title'] === '') {
        $errors['title'] = 'Enter a title for the form.';
    }
    if (!valid_slug($v['slug'])) {
        $errors['slug'] = 'Use only lowercase letters, numbers and single dashes in the name, for example booking.';
    } elseif ($v['slug'] !== $origSlug && get_item('forms', $v['slug'])) {
        $errors['slug'] = "Another form already uses the name “{$v['slug']}”. Choose a different one.";
    }
    foreach (preg_split('/[\s,;]+/', $v['recipients'], -1, PREG_SPLIT_NO_EMPTY) as $addr) {
        if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
            $errors['recipients'] = "“{$addr}” isn’t a valid email address.";
        }
    }
    $answerable = 0;
    foreach ($fields as $k => $f) {
        if ($f['label'] === '') {
            $errors["field-$k-label"] = 'Enter the label for field ' . ($k + 1) . '.';
        }
        if (in_array($f['type'], FORM_CHOICE_TYPES, true) && count(form_field_options($f)) < 1) {
            $errors["field-$k-options"] = 'Enter the choices for field ' . ($k + 1) . ', one per line.';
        }
        if (!in_array($f['type'], FORM_TEXT_ONLY_TYPES, true)) {
            $answerable++;
        }
    }
    if ($answerable === 0) {
        $errors['add-type'] = 'Add at least one field that people can answer.';
    }
    if (stale_edit($existing)) {
        $errors['title'] = stale_edit_message($existing);
    }
    if ($errors) {
        form_editor($v, $errors, $existing ? $origSlug : null);
    }
    save_item('forms', $v['slug'], $v, $existing ? $origSlug : null);
    flash(($existing ? 'Saved your changes to ' : 'Saved the new form ') . '“' . $v['title'] . '”. Put [form ' . $v['slug'] . '] in a page to show it.');
    go(admin_url('forms', ['slug' => $v['slug']]));
}

function form_delete_confirm(string $slug): never
{
    $form = valid_slug($slug) ? get_item('forms', $slug) : null;
    if (!$form) {
        not_found_screen();
    }
    $count = count(form_submissions($slug));
    $body = '<p>Are you sure you want to delete the form “' . h((string) $form['title']) . '”? Pages that contain <code>[form ' . h($slug) . ']</code> will show nothing there. '
        . ($count ? "Its $count " . ($count === 1 ? 'submission stays' : 'submissions stay') . ' on the server until you delete them one by one. ' : '')
        . 'A copy of the form itself is kept, so you can ask for it to be brought back.</p>'
        . '<form method="post" action="' . h(admin_url('forms', ['delete' => $slug])) . '" class="form-actions">' . csrf_field()
        . '<button type="submit" class="button button-danger">Yes, delete this form</button>'
        . '<a class="button button-secondary" href="' . h(admin_url('forms', ['slug' => $slug])) . '">No, go back</a></form>';
    admin_screen('Delete form: ' . h((string) $form['title']), $body, 'forms');
}

function form_delete(string $slug): never
{
    $form = valid_slug($slug) ? get_item('forms', $slug) : null;
    if (!$form) {
        not_found_screen();
    }
    delete_item('forms', $slug);
    flash('Deleted the form “' . $form['title'] . '”.');
    go(admin_url('forms'));
}

/* ---------- Submissions ---------- */

function form_submissions_screen(string $slug): never
{
    $form = valid_slug($slug) ? get_item('forms', $slug) : null;
    if (!$form) {
        not_found_screen();
    }
    $subs = form_submissions($slug);
    $unread = count(array_filter($subs, fn($s) => empty($s['read'])));
    $body = '<p>Everything sent with the form “' . h((string) $form['title']) . '”, newest first. '
        . (count($subs) === 1 ? '1 submission' : count($subs) . ' submissions') . ($unread ? ", <strong>$unread new</strong>" : '') . '.</p>'
        . '<div class="form-actions"><a class="button button-secondary" href="' . h(admin_url('forms', ['csv' => $slug])) . '">Download all as a CSV file (opens in Excel)</a>'
        . ($unread ? '<form method="post" action="' . h(admin_url('forms', ['view' => $slug])) . '">' . csrf_field() . '<button type="submit" class="button button-secondary" name="do" value="read">Mark all as read</button></form>' : '')
        . '</div>';
    foreach ($subs as $s) {
        $id = h($s['id']);
        $rows = '';
        foreach ((array) ($s['answers'] ?? []) as $a) {
            $value = is_array($a['value']) ? implode(', ', $a['value']) : (string) $a['value'];
            $shown = $a['type'] === 'email' && $value !== '' ? '<a href="mailto:' . h($value) . '">' . h($value) . '</a>' : nl2br(h($value !== '' ? $value : '—'));
            $rows .= '<dt>' . h((string) $a['label']) . '</dt><dd>' . $shown . '</dd>';
        }
        $body .= '<article class="message" aria-labelledby="s-' . $id . '"><h2 id="s-' . $id . '">' . (empty($s['read']) ? 'New: ' : '') . 'Received ' . h(format_time((string) ($s['received'] ?? ''))) . '</h2>'
            . '<dl>' . $rows . '<dt>Sent from page</dt><dd>' . h((string) ($s['page'] ?? '')) . '</dd>'
            . '<dt>Emailed to you</dt><dd>' . (!empty($s['emailed']) ? 'Yes' : '<strong>No.</strong> ' . h((string) ($s['error'] ?? ''))) . '</dd></dl>'
            . '<p><a href="' . h(admin_url('forms', ['view' => $slug, 'delete' => $s['id']])) . '">Delete<span class="visually-hidden"> the submission received ' . h(format_time((string) ($s['received'] ?? ''))) . '</span></a></p></article>';
    }
    if (!$subs) {
        $body .= '<p>Nobody has sent this form yet.</p>';
    }
    $body .= '<p><a href="' . h(admin_url('forms', ['slug' => $slug])) . '">Edit this form</a> · <a href="' . h(admin_url('forms')) . '">All forms</a></p>';
    admin_screen('Submissions: ' . h((string) $form['title']), $body, 'forms');
}

function form_submissions_mark_read(string $slug): never
{
    if (!valid_slug($slug) || !get_item('forms', $slug)) {
        not_found_screen();
    }
    $n = 0;
    foreach (form_submissions($slug) as $s) {
        if (empty($s['read']) && ($file = form_submission_file($slug, $s['id']))) {
            unset($s['id']);
            $s['read'] = true;
            write_json($file, $s);
            $n++;
        }
    }
    flash($n ? "Marked $n " . ($n === 1 ? 'submission' : 'submissions') . ' as read.' : 'Nothing new to mark as read.');
    go(admin_url('forms', ['view' => $slug]));
}

function form_submission_delete_confirm(string $slug, string $id): never
{
    $form = valid_slug($slug) ? get_item('forms', $slug) : null;
    $file = $form ? form_submission_file($slug, $id) : null;
    $s = $file ? read_json($file) : null;
    if (!$s) {
        not_found_screen();
    }
    $body = '<p>Are you sure you want to delete the submission received ' . h(format_time((string) ($s['received'] ?? ''))) . '? This cannot be undone.</p>'
        . '<form method="post" action="' . h(admin_url('forms', ['view' => $slug, 'delete' => $id])) . '" class="form-actions">' . csrf_field()
        . '<button type="submit" class="button button-danger">Yes, delete this submission</button>'
        . '<a class="button button-secondary" href="' . h(admin_url('forms', ['view' => $slug])) . '">No, go back</a></form>';
    admin_screen('Delete a submission: ' . h((string) $form['title']), $body, 'forms');
}

function form_submission_delete(string $slug, string $id): never
{
    $file = valid_slug($slug) ? form_submission_file($slug, $id) : null;
    if (!$file) {
        not_found_screen();
    }
    unlink($file);
    flash('Deleted the submission.');
    go(admin_url('forms', ['view' => $slug]));
}

/** All submissions of a form as CSV (UTF-8 with a byte order mark, so Excel reads accents correctly). */
function form_csv_download(string $slug): never
{
    $form = valid_slug($slug) ? get_item('forms', $slug) : null;
    if (!$form) {
        not_found_screen();
    }
    $subs = form_submissions($slug);
    // Columns: the form's current fields first, then any label that only older submissions have.
    $labels = [];
    foreach ((array) ($form['fields'] ?? []) as $f) {
        $f = form_field_defaults($f);
        if (!in_array($f['type'], FORM_TEXT_ONLY_TYPES, true) && $f['label'] !== '') {
            $labels[$f['label']] = true;
        }
    }
    foreach ($subs as $s) {
        foreach ((array) ($s['answers'] ?? []) as $a) {
            $labels[(string) $a['label']] = true;
        }
    }
    $labels = array_keys($labels);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="form-' . $slug . '-submissions.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_merge(['Received', 'Page', 'Language', 'Emailed'], $labels), ',', '"', '\\', "\r\n");
    foreach ($subs as $s) {
        $byLabel = [];
        foreach ((array) ($s['answers'] ?? []) as $a) {
            $byLabel[(string) $a['label']] = is_array($a['value']) ? implode('; ', $a['value']) : (string) $a['value'];
        }
        $row = [format_time((string) ($s['received'] ?? '')), (string) ($s['page'] ?? ''), (string) ($s['language'] ?? ''), !empty($s['emailed']) ? 'yes' : 'no'];
        foreach ($labels as $label) {
            // A leading =, +, - or @ would be run as a formula by spreadsheets; a quote in front keeps it as text.
            $cell = $byLabel[$label] ?? '';
            $row[] = preg_match('/^[=+\-@\t\r]/', $cell) ? "'" . $cell : $cell;
        }
        fputcsv($out, $row, ',', '"', '\\', "\r\n");
    }
    fclose($out);
    exit;
}
