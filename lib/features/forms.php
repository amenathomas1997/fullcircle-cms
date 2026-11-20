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
    'file' => 'File upload',
    'signature' => 'Signature',
    'heading' => 'Heading (no answer)',
    'paragraph' => 'Paragraph of explanatory text (no answer)',
];
// Files people may attach, by extension; a file is kept under data/forms/<name>/files/, which is never served.
const FORM_FILE_TYPES = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'heic' => 'image/heic',
    'pdf' => 'application/pdf', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'doc' => 'application/msword',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'txt' => 'text/plain', 'csv' => 'text/csv', 'rtf' => 'text/rtf',
];
const FORM_FILE_MAX = 10 * 1024 * 1024;
const FORM_DRAFT_DAYS = 30;                                 // a half-finished form can be come back to for this long
const FORM_CONDITION_TYPES = ['select', 'checkbox', 'checkboxes']; // the kinds of answer another field can depend on
const FORM_CHOICE_TYPES = ['select', 'checkboxes'];
const FORM_TEXT_ONLY_TYPES = ['heading', 'paragraph'];

register_feature('forms', 'Forms', 'Build your own forms (bookings, sign-ups, questionnaires). Each one gets a shortcode; the answers are kept in the control panel and emailed to you.', true);
register_admin_screen('forms', fn(bool $post) => $post ? forms_admin_post() : forms_admin_get(), 'Forms', 'forms');
register_dashboard_card(fn() => feature_on('forms') ? forms_dashboard_card() : '');
register_cron('daily', function (): void {
    forms_tidy_drafts();
});

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
        'show_if' => preg_match('/^\d+$/', (string) ($f['show_if'] ?? '')) ? (string) (int) $f['show_if'] : '', // index of the field this one depends on
        'show_if_value' => trim((string) ($f['show_if_value'] ?? '')),
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
    } elseif (!empty($form['resume']) && ($_GET['resume'] ?? '') !== '' && ($_GET['form'] ?? $slug) === $slug) {
        // Coming back to a half-finished form from its link.
        $draft = form_draft_load($slug, (string) $_GET['resume']);
        $state = $draft ? ['values' => (array) ($draft['values'] ?? []), 'resume_token' => (string) $_GET['resume'], 'resumed' => true] : ['resume_expired' => true];
    }
    return custom_form_html($form, $state);
}

/* ---------- Conditions: a field shown only when an earlier answer matches ---------- */

/** Does this field's "only show when" condition hold for the answers given so far (by field index)? */
function form_field_shown(array $field, array $fields, array $values): bool
{
    if (($field['show_if'] ?? '') === '') {
        return true;
    }
    $k = (int) $field['show_if'];
    if (!isset($fields[$k])) {
        return true; // the field it pointed at is gone: show rather than hide silently
    }
    $controller = form_field_defaults($fields[$k]);
    if (!form_field_shown($controller, $fields, $values)) {
        return false; // hidden because what it depends on is hidden
    }
    return form_condition_met((string) $controller['type'], $values[$k] ?? null, (string) $field['show_if_value']);
}

function form_condition_met(string $type, $value, string $want): bool
{
    $want = trim($want);
    switch ($type) {
        case 'checkbox':
            $ticked = $value === '1' || $value === true;
            return in_array(mb_strtolower($want), ['', 'yes', 'y', '1', 'true', 'sí', 'si', 'ticked', 'checked'], true) ? $ticked : !$ticked;
        case 'checkboxes':
            foreach ((array) $value as $v) {
                if (strcasecmp(trim((string) $v), $want) === 0) {
                    return true;
                }
            }
            return false;
        default:
            return strcasecmp(trim((string) (is_array($value) ? '' : $value)), $want) === 0;
    }
}

/* ---------- Reading what was sent ---------- */

/**
 * Read and check one form's answers from what was posted. Pure apart from reading the temporary
 * upload files, so the self-test can drive it. Returns ['errors' => [index => message], 'values' => [index => raw],
 * 'answers' => [[label, type, value], …], 'uploads' => [index => [tmp, name, ext, size]], 'signatures' => [index => png bytes|null]].
 * With $final false (saving a half-finished form) nothing is required and nothing is checked.
 */
function form_collect_answers(array $form, array $posted, array $files = [], bool $final = true): array
{
    $values = [];
    $errors = [];
    $answers = [];
    $uploads = [];
    $signatures = [];
    $allFields = (array) ($form['fields'] ?? []);
    foreach ($allFields as $i => $raw) {
        $field = form_field_defaults($raw);
        if (in_array($field['type'], FORM_TEXT_ONLY_TYPES, true)) {
            continue;
        }
        $label = $field['label'];
        $options = form_field_options($field);
        $type = $field['type'];

        // Read the raw value first, so a hidden field's answer is still kept for a saved draft.
        if ($type === 'checkboxes') {
            $value = array_values(array_filter(array_map(fn($v) => trim((string) $v), (array) ($posted[$i] ?? [])), 'strlen'));
        } elseif ($type === 'signature') {
            $sig = is_array($posted[$i] ?? null) ? $posted[$i] : [];
            $value = ['name' => trim((string) ($sig['name'] ?? '')), 'agree' => ($sig['agree'] ?? '') === '1' ? '1' : '', 'drawn' => (string) ($sig['drawn'] ?? '')];
        } elseif ($type === 'file') {
            $value = ''; // a file is never carried in a value; it is read from $files below
        } elseif ($type === 'checkbox') {
            $value = ($posted[$i] ?? '') === '1' ? '1' : '';
        } else {
            $value = is_array($posted[$i] ?? null) ? '' : trim((string) ($posted[$i] ?? ''));
        }
        $values[$i] = $value;

        if (!form_field_shown($field, $allFields, $values)) {
            continue; // not shown, so not asked: no check, no answer recorded
        }
        if (!$final) {
            continue;
        }

        switch ($type) {
            case 'checkboxes':
                if ($field['required'] && !$value) {
                    $errors[$i] = sprintf(t('form_err_choose'), $label);
                } elseif (array_diff($value, $options)) {
                    $errors[$i] = sprintf(t('form_err_option'), $label);
                }
                $answers[] = ['label' => $label, 'type' => $type, 'value' => $value];
                break;
            case 'checkbox':
                if ($field['required'] && $value === '') {
                    $errors[$i] = sprintf(t('form_err_check'), $label);
                }
                $answers[] = ['label' => $label, 'type' => $type, 'value' => $value === '1' ? t('yes') : t('no')];
                break;
            case 'file':
                $f = $files[$i] ?? null;
                $err = $f === null || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? null : form_upload_problem($f);
                if ($f === null || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    if ($field['required']) {
                        $errors[$i] = sprintf(t('form_err_file_required'), $label);
                    }
                    $answers[] = ['label' => $label, 'type' => $type, 'value' => ''];
                } elseif ($err !== null) {
                    $errors[$i] = sprintf(t($err), $label, form_bytes(form_file_max()));
                    $answers[] = ['label' => $label, 'type' => $type, 'value' => ''];
                } else {
                    $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
                    $uploads[$i] = ['tmp' => (string) $f['tmp_name'], 'name' => form_clean_filename((string) $f['name']), 'ext' => $ext, 'size' => (int) $f['size']];
                    $answers[] = ['label' => $label, 'type' => $type, 'value' => ['name' => $uploads[$i]['name'], 'size' => $uploads[$i]['size'], 'file' => '']];
                }
                break;
            case 'signature':
                if ($value['name'] === '') {
                    if ($field['required']) {
                        $errors[$i] = sprintf(t('form_err_signature_name'), $label);
                    }
                } elseif (mb_strlen($value['name']) > 200) {
                    $errors[$i] = sprintf(t('form_err_long'), $label);
                } elseif ($value['agree'] !== '1') {
                    $errors[$i] = sprintf(t('form_err_signature_agree'), $label);
                }
                $png = null;
                if ($value['drawn'] !== '') {
                    $png = form_signature_png($value['drawn']);
                    if ($png === null) {
                        $errors[$i] = sprintf(t('form_err_signature_drawn'), $label);
                    }
                }
                $signatures[$i] = $png;
                $answers[] = ['label' => $label, 'type' => $type, 'value' => $value['name'] === '' ? '' : ['name' => $value['name'], 'signed' => date('c'), 'drawn' => '']];
                break;
            default:
                if ($value === '') {
                    if ($field['required']) {
                        $errors[$i] = sprintf(t($type === 'select' ? 'form_err_choose' : 'form_err_required'), $label);
                    }
                } elseif (mb_strlen($value) > 5000) {
                    $errors[$i] = sprintf(t('form_err_long'), $label);
                } else {
                    $problem = match ($type) {
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
                $answers[] = ['label' => $label, 'type' => $type, 'value' => $value];
        }
    }
    return ['errors' => $errors, 'values' => $values, 'answers' => $answers, 'uploads' => $uploads, 'signatures' => $signatures];
}

/* ---------- Files people attach ---------- */

function form_file_max(): int
{
    $toBytes = function (string $v): int {
        $n = (int) $v;
        return match (strtolower(substr(trim($v), -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
    };
    $server = min($toBytes((string) ini_get('upload_max_filesize')), $toBytes((string) ini_get('post_max_size')));
    return $server > 0 ? min(FORM_FILE_MAX, $server) : FORM_FILE_MAX;
}

function form_bytes(int $bytes): string
{
    return $bytes >= 1048576 ? round($bytes / 1048576, $bytes >= 10485760 ? 0 : 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
}

/** The translation key of what is wrong with an uploaded file, or null when it is fine. */
function form_upload_problem(array $f): ?string
{
    if (in_array($f['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int) ($f['size'] ?? 0) > form_file_max()) {
        return 'form_err_file_size';
    }
    if (($f['error'] ?? 0) !== UPLOAD_ERR_OK || !is_file((string) ($f['tmp_name'] ?? ''))) {
        return 'form_err_file_failed';
    }
    $ext = strtolower(pathinfo((string) ($f['name'] ?? ''), PATHINFO_EXTENSION));
    $okMime = FORM_FILE_TYPES[$ext] ?? null;
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file((string) $f['tmp_name']);
    if ($okMime === null || ($mime !== $okMime && !($ext === 'docx' && $mime === 'application/zip') && !(in_array($ext, ['txt', 'csv'], true) && str_starts_with($mime, 'text/')))) {
        return 'form_err_file_type';
    }
    if (str_starts_with($okMime, 'image/') && @getimagesize((string) $f['tmp_name']) === false) {
        return 'form_err_file_type';
    }
    return null;
}

/** The file name as the person named it, without anything a browser or file system could trip on. */
function form_clean_filename(string $name): string
{
    $name = preg_replace('/[\x00-\x1F\/\\\\:*?"<>|]+/', ' ', $name) ?? '';
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
    return mb_substr($name !== '' ? $name : 'file', 0, 120);
}

function form_files_dir(string $slug): string
{
    return form_submission_dir($slug) . '/files';
}

/** Keep an attached file with the submission; returns the stored name. The folder is under data/, never served. */
function form_store_file(string $slug, string $id, int $i, array $u): string
{
    $dir = form_files_dir($slug);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        throw new RuntimeException('Could not make the folder for attached files.');
    }
    $stored = $id . '-' . $i . '.' . $u['ext'];
    $moved = is_uploaded_file($u['tmp']) ? move_uploaded_file($u['tmp'], "$dir/$stored") : (PHP_SAPI === 'cli' && copy($u['tmp'], "$dir/$stored"));
    if (!$moved) {
        throw new RuntimeException('The attached file could not be saved.');
    }
    @chmod("$dir/$stored", 0600);
    return $stored;
}

/** The file of one answer of one submission, if it exists: [path, original name, mime]. */
function form_stored_file(string $slug, string $id, int $i): ?array
{
    $s = ($file = form_submission_file($slug, $id)) ? read_json($file) : null;
    $a = $s ? ((array) ($s['answers'] ?? [])) : [];
    $found = null;
    foreach ($a as $answer) {
        if (is_array($answer['value'] ?? null) && ($answer['value']['file'] ?? '') !== '' && str_starts_with((string) $answer['value']['file'], "$id-$i.")) {
            $found = $answer;
        }
    }
    if ($found === null) {
        return null;
    }
    $stored = (string) $found['value']['file'];
    if (!preg_match('/^[0-9_\-a-f]+\.[a-z0-9]+$/', $stored)) {
        return null;
    }
    $path = form_files_dir($slug) . '/' . $stored;
    if (!is_file($path)) {
        return null;
    }
    $ext = strtolower(pathinfo($stored, PATHINFO_EXTENSION));
    $name = $found['type'] === 'signature' ? 'signature-' . slugify((string) ($found['value']['name'] ?? 'signed')) . '.png' : (string) ($found['value']['name'] ?? $stored);
    return [$path, $name, FORM_FILE_TYPES[$ext] ?? 'application/octet-stream'];
}

/** The PNG bytes of a drawn signature sent as a data URL, or null if it is not a small PNG. */
function form_signature_png(string $dataUrl): ?string
{
    if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=\s]+)$#', $dataUrl, $m) || strlen($dataUrl) > 400000) {
        return null;
    }
    $png = base64_decode($m[1], true);
    if ($png === false || strlen($png) < 100 || !str_starts_with($png, "\x89PNG")) {
        return null;
    }
    $size = @getimagesizefromstring($png);
    return $size && ($size['mime'] ?? '') === 'image/png' && $size[0] <= 2000 && $size[1] <= 1000 ? $png : null;
}

/* ---------- Half-finished forms, saved to come back to ---------- */

function form_drafts_dir(string $slug): string
{
    return form_submission_dir($slug) . '/drafts';
}

function form_draft_token_ok(string $token): bool
{
    return (bool) preg_match('/^[0-9a-f]{32}$/', $token);
}

/** Save what was typed so far under a secret token (a new one, or the one the person came back with). Returns the token. */
function form_draft_save(string $slug, string $path, array $values, ?string $token = null): string
{
    $token = $token !== null && form_draft_token_ok($token) ? $token : bin2hex(random_bytes(16));
    foreach ($values as $i => $v) {
        if (is_array($v) && isset($v['drawn'])) {
            $values[$i]['drawn'] = ''; // a drawing is not kept in a draft; the typed name is
        }
    }
    write_json(form_drafts_dir($slug) . "/$token.json", ['page' => $path, 'saved' => date('c'), 'expires' => date('c', time() + FORM_DRAFT_DAYS * 86400), 'values' => $values]);
    @chmod(form_drafts_dir($slug) . "/$token.json", 0600);
    return $token;
}

function form_draft_load(string $slug, string $token): ?array
{
    if (!form_draft_token_ok($token)) {
        return null;
    }
    $d = read_json(form_drafts_dir($slug) . "/$token.json");
    return $d && strtotime((string) ($d['expires'] ?? '')) > time() ? $d : null;
}

function form_draft_delete(string $slug, string $token): void
{
    if (form_draft_token_ok($token)) {
        @unlink(form_drafts_dir($slug) . "/$token.json");
    }
}

/** Drafts nobody came back to within the time allowed are removed (daily). */
function forms_tidy_drafts(): int
{
    $n = 0;
    foreach (glob(FORM_SUBMISSION_DIR . '/*/drafts/*.json') ?: [] as $file) {
        $d = read_json($file);
        if (!$d || strtotime((string) ($d['expires'] ?? '')) <= time()) {
            @unlink($file);
            $n++;
        }
    }
    return $n;
}

/* ---------- Handling a post ---------- */

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
    $files = form_posted_files();
    $resumeToken = (string) ($_POST['resume_token'] ?? '');
    $saveLater = !empty($form['resume']) && isset($_POST['save_later']);

    $got = form_collect_answers($form, $posted, $files, !$saveLater);

    if ($saveLater) {
        if (rate_limited(DATA_DIR . '/forms-rate.json', hash('sha256', 'draft' . ($_SERVER['REMOTE_ADDR'] ?? '') . site_secret()), 10, 3600)) {
            return ['errors' => ['form' => t('err_rate')], 'values' => $got['values'], 'resume_token' => $resumeToken];
        }
        $token = form_draft_save($slug, $path, $got['values'], $resumeToken !== '' ? $resumeToken : null);
        $link = site_url($path) . '?form=' . rawurlencode($slug) . '&resume=' . $token;
        $emailed = '';
        foreach ((array) ($form['fields'] ?? []) as $i => $raw) {
            $f = form_field_defaults($raw);
            if ($f['type'] === 'email' && filter_var((string) ($got['values'][$i] ?? ''), FILTER_VALIDATE_EMAIL)) {
                try {
                    send_mail_to([(string) $got['values'][$i]], t('form_saved_subject') . ' ' . ($form['title'] ?: $slug),
                        '<p>' . h(t('form_saved_email')) . '</p><p><a href="' . h($link) . '">' . h($link) . '</a></p><p>' . h(sprintf(t('form_saved_days'), FORM_DRAFT_DAYS)) . '</p>');
                    $emailed = (string) $got['values'][$i];
                } catch (Throwable $e) {
                    error_log("Form “{$slug}” draft email failed: " . $e->getMessage());
                }
                break;
            }
        }
        header('Location: ' . $path . '?saved=' . rawurlencode($slug) . '&resume=' . $token . ($emailed !== '' ? '&emailed=1' : '') . '#' . $statusId, true, 303);
        exit;
    }

    if ($got['errors']) {
        return ['errors' => $got['errors'], 'values' => $got['values'], 'resume_token' => $resumeToken];
    }
    if (rate_limited(DATA_DIR . '/forms-rate.json', hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . site_secret()), 5, 3600)) {
        return ['errors' => ['form' => t('err_rate')], 'values' => $got['values'], 'resume_token' => $resumeToken];
    }

    // Save first, so an answer is never lost even if email fails.
    $id = date('Y-m-d_His') . '-' . bin2hex(random_bytes(3));
    $answers = form_attach_files($form, $id, $got);
    $record = ['received' => date('c'), 'page' => $path, 'language' => current_lang(), 'read' => false, 'emailed' => false, 'error' => '', 'answers' => $answers];
    $file = form_submission_dir($slug) . "/$id.json";
    write_json($file, $record);
    if ($resumeToken !== '') {
        form_draft_delete($slug, $resumeToken);
    }

    $replyEmail = null;
    $replyName = null;
    $rows = '';
    foreach ($answers as $a) {
        $shown = form_answer_text($a);
        $rows .= '<p><strong>' . h($a['label']) . ':</strong><br>' . nl2br(h($shown !== '' ? $shown : '—')) . '</p>';
        if ($replyEmail === null && $a['type'] === 'email' && $shown !== '') {
            $replyEmail = $shown;
        }
        if ($replyName === null && $a['type'] === 'text' && $shown !== '' && preg_match('/name|nombre/i', $a['label'])) {
            $replyName = $shown;
        }
    }
    $hasFiles = (bool) array_filter($answers, fn($a) => is_array($a['value']) && ($a['value']['file'] ?? '') !== '');
    $subject = (string) ($form['title'] ?: $slug) . ($replyName !== null ? ' — ' . $replyName : '');
    $html = '<p>Someone filled in the form “' . h((string) $form['title']) . '” on your website.</p>' . $rows
        . ($hasFiles ? '<p>Attached files and drawn signatures are kept with the submission in the control panel: <a href="' . h(site_url('/admin/?a=forms&view=' . rawurlencode($slug))) . '">open Submissions</a>.</p>' : '')
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

/** $_FILES['f'] turned back into one array per field index. */
function form_posted_files(): array
{
    $raw = $_FILES['f'] ?? null;
    if (!is_array($raw) || !isset($raw['name']) || !is_array($raw['name'])) {
        return [];
    }
    $out = [];
    foreach ($raw['name'] as $i => $name) {
        $out[$i] = ['name' => (string) $name, 'type' => (string) ($raw['type'][$i] ?? ''), 'tmp_name' => (string) ($raw['tmp_name'][$i] ?? ''), 'error' => (int) ($raw['error'][$i] ?? UPLOAD_ERR_NO_FILE), 'size' => (int) ($raw['size'][$i] ?? 0)];
    }
    return $out;
}

/** Move the attached files and drawn signatures of a checked submission into place; returns the answers with their stored names filled in. */
function form_attach_files(array $form, string $id, array $got): array
{
    $slug = (string) $form['slug'];
    $answers = $got['answers'];
    // Answers are in field order and skip text-only and hidden fields, so match them to indexes by walking the fields again.
    $k = 0;
    $fields = (array) ($form['fields'] ?? []);
    foreach ($fields as $i => $raw) {
        $f = form_field_defaults($raw);
        if (in_array($f['type'], FORM_TEXT_ONLY_TYPES, true) || !form_field_shown($f, $fields, $got['values'])) {
            continue;
        }
        if (!isset($answers[$k])) {
            break;
        }
        if ($f['type'] === 'file' && isset($got['uploads'][$i])) {
            $answers[$k]['value']['file'] = form_store_file($slug, $id, (int) $i, $got['uploads'][$i]);
        }
        if ($f['type'] === 'signature' && is_array($answers[$k]['value']) && ($got['signatures'][$i] ?? null) !== null) {
            $dir = form_files_dir($slug);
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
            $stored = "$id-$i.png";
            file_put_contents("$dir/$stored", $got['signatures'][$i]);
            @chmod("$dir/$stored", 0600);
            $answers[$k]['value']['drawn'] = $stored;
            $answers[$k]['value']['file'] = $stored;
        }
        $k++;
    }
    return $answers;
}

/** A time for people to read; the control panel helper when it is loaded, a plain date otherwise. */
function form_time(string $iso): string
{
    if (function_exists('format_time')) {
        return format_time($iso);
    }
    $t = strtotime($iso) ?: time();
    return date('F j, Y \\a\\t g:i a', $t);
}

/** One answer as plain text, for emails, lists and CSV. */
function form_answer_text(array $a): string
{
    $v = $a['value'];
    if (!is_array($v)) {
        return (string) $v;
    }
    if (($a['type'] ?? '') === 'file') {
        return ($v['name'] ?? '') !== '' ? $v['name'] . ' (' . form_bytes((int) ($v['size'] ?? 0)) . ')' : '';
    }
    if (($a['type'] ?? '') === 'signature') {
        return ($v['name'] ?? '') !== '' ? $v['name'] . ', ' . t('form_signed_on') . ' ' . form_time((string) ($v['signed'] ?? '')) . (($v['drawn'] ?? '') !== '' ? ' (' . t('form_signature_drawn') . ')' : '') : '';
    }
    return implode(', ', array_map('strval', $v));
}

/* ---------- Drawing the form ---------- */

function custom_form_html(array $form, array $state = []): string
{
    $slug = (string) $form['slug'];
    $statusId = "form-$slug-status";
    $errors = $state['errors'] ?? [];
    $values = $state['values'] ?? [];
    $rawFields = (array) ($form['fields'] ?? []);
    $fields = array_map('form_field_defaults', $rawFields);
    $canResume = !empty($form['resume']);

    if (($_GET['sent'] ?? '') === $slug) {
        $message = trim((string) ($form['success'] ?? ''));
        return '<div id="' . $statusId . '" class="notice notice-success" role="status" tabindex="-1" data-focus-on-load>'
            . '<h2>' . h(t('form_sent_title')) . '</h2><p>' . ($message !== '' ? nl2br(h($message)) : h(t('form_sent_body'))) . '</p></div>';
    }
    if ($canResume && ($_GET['saved'] ?? '') === $slug && form_draft_token_ok((string) ($_GET['resume'] ?? ''))) {
        $link = site_url((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH)) . '?form=' . rawurlencode($slug) . '&resume=' . h((string) $_GET['resume']);
        return '<div id="' . $statusId . '" class="notice notice-success" role="status" tabindex="-1" data-focus-on-load>'
            . '<h2>' . h(t('form_saved_title')) . '</h2><p>' . h(sprintf(t('form_saved_days'), FORM_DRAFT_DAYS)) . '</p>'
            . '<p><a href="' . $link . '">' . $link . '</a></p>'
            . (!empty($_GET['emailed']) ? '<p>' . h(t('form_saved_emailed')) . '</p>' : '<p>' . h(t('form_saved_copy')) . '</p>') . '</div>';
    }

    $out = '';
    if (!empty($state['resume_expired'])) {
        $out .= '<div id="' . $statusId . '" class="notice" role="status" tabindex="-1" data-focus-on-load><p>' . h(t('form_resume_expired')) . '</p></div>';
    } elseif (!empty($state['resumed'])) {
        $out .= '<div id="' . $statusId . '" class="notice notice-success" role="status" tabindex="-1" data-focus-on-load><p>' . h(t('form_resumed')) . '</p></div>';
    }
    if ($errors) {
        $out .= '<div id="' . $statusId . '" class="error-summary" role="alert" tabindex="-1" data-focus-on-load><h2>' . h(t('form_error_title')) . '</h2><ul>';
        foreach ($errors as $i => $msg) {
            $type = $fields[$i]['type'] ?? '';
            $target = $i === 'form' ? null : "form-$slug-$i" . ($type === 'checkboxes' ? '-0' : ($type === 'signature' ? '-name' : ''));
            $out .= $target === null ? '<li>' . h($msg) . '</li>' : '<li><a href="#' . $target . '">' . h($msg) . '</a></li>';
        }
        $out .= '</ul></div>';
    }
    $hasRequired = (bool) array_filter($fields, fn($f) => $f['required'] && !in_array($f['type'], FORM_TEXT_ONLY_TYPES, true));
    $hasFiles = (bool) array_filter($fields, fn($f) => $f['type'] === 'file');
    $out .= '<form class="contact-form custom-form" method="post" action="#' . $statusId . '" novalidate' . ($hasFiles ? ' enctype="multipart/form-data"' : '') . ' data-form-conditions>'
        . ($hasRequired ? '<p>' . h(t('required_note')) . '</p>' : '')
        . ($hasFiles ? '<input type="hidden" name="MAX_FILE_SIZE" value="' . form_file_max() . '">' : '');
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
        // A field shown only on an earlier answer: hidden now if that answer is not there, and switched by the site script as answers change.
        $cond = '';
        $shownNow = true;
        if ($f['show_if'] !== '' && isset($fields[(int) $f['show_if']])) {
            $k = (int) $f['show_if'];
            $cond = ' data-show-if="' . $k . '" data-show-if-type="' . h($fields[$k]['type']) . '" data-show-if-value="' . h($f['show_if_value']) . '"';
            $shownNow = form_field_shown($f, $rawFields, $values);
        }
        $wrapOpen = fn(string $tag, string $extra = '') => '<' . $tag . ' class="' . $class . $extra . '"' . $cond . ($shownNow ? '' : ' hidden') . ' data-field="' . $i . '">';
        switch ($f['type']) {
            case 'heading':
                $out .= '<div' . $cond . ($shownNow ? '' : ' hidden') . '><h2>' . h($f['label']) . '</h2>' . ($f['hint'] !== '' ? '<p>' . h($f['hint']) . '</p>' : '') . '</div>';
                break;
            case 'paragraph':
                $out .= '<div' . $cond . ($shownNow ? '' : ' hidden') . '><p>' . nl2br(h($f['label'] . ($f['hint'] !== '' ? "\n" . $f['hint'] : ''))) . '</p></div>';
                break;
            case 'textarea':
                $out .= $wrapOpen('div') . '<label for="' . $id . '">' . $label . '</label>' . $hint . $error
                    . '<textarea id="' . $id . '" name="f[' . $i . ']" rows="6"' . $attrs . '>' . h((string) $value) . '</textarea></div>';
                break;
            case 'select':
                $options = '<option value="">' . h(t('form_choose')) . '</option>';
                foreach (form_field_options($f) as $opt) {
                    $options .= '<option value="' . h($opt) . '"' . ($value === $opt ? ' selected' : '') . '>' . h($opt) . '</option>';
                }
                $out .= $wrapOpen('div') . '<label for="' . $id . '">' . $label . '</label>' . $hint . $error
                    . '<select id="' . $id . '" name="f[' . $i . ']"' . $attrs . '>' . $options . '</select></div>';
                break;
            case 'checkboxes':
                $boxes = '';
                foreach (form_field_options($f) as $k => $opt) {
                    $boxes .= '<div class="option"><input type="checkbox" id="' . $id . '-' . $k . '" name="f[' . $i . '][]" value="' . h($opt) . '"'
                        . (in_array($opt, (array) $value, true) ? ' checked' : '') . '><label for="' . $id . '-' . $k . '">' . h($opt) . '</label></div>';
                }
                $groupAttrs = ($describedBy ? ' aria-describedby="' . implode(' ', $describedBy) . '"' : '');
                $out .= '<fieldset class="' . $class . '"' . $groupAttrs . $cond . ($shownNow ? '' : ' hidden') . ' data-field="' . $i . '"><legend>' . $label . '</legend>' . $hint . $error . $boxes . '</fieldset>';
                break;
            case 'checkbox':
                $out .= $wrapOpen('div', ' checkbox-field') . '<input type="checkbox" id="' . $id . '" name="f[' . $i . ']" value="1"' . ($value === '1' ? ' checked' : '') . $attrs . '>'
                    . '<label for="' . $id . '">' . $label . '</label>' . $hint . $error . '</div>';
                break;
            case 'file':
                $accept = '.' . implode(',.', array_keys(FORM_FILE_TYPES));
                $fileHint = '<p class="hint" id="' . $id . '-types">' . h(sprintf(t('form_file_hint'), form_bytes(form_file_max()))) . '</p>';
                $attrs2 = ' aria-describedby="' . trim(implode(' ', $describedBy) . " $id-types") . '"' . ($err !== '' ? ' aria-invalid="true"' : '') . ($f['required'] ? ' required' : '');
                $out .= $wrapOpen('div') . '<label for="' . $id . '">' . $label . '</label>' . $hint . $fileHint . $error
                    . '<input type="file" id="' . $id . '" name="f[' . $i . ']" accept="' . h($accept) . '"' . $attrs2 . '></div>';
                break;
            case 'signature':
                $sig = is_array($value) ? $value : ['name' => '', 'agree' => '', 'drawn' => ''];
                $groupAttrs = ($describedBy ? ' aria-describedby="' . implode(' ', $describedBy) . '"' : '');
                $out .= '<fieldset class="' . $class . ' signature-field"' . $groupAttrs . $cond . ($shownNow ? '' : ' hidden') . ' data-field="' . $i . '" data-signature><legend>' . $label . '</legend>' . $hint . $error
                    . '<div class="field"><label for="' . $id . '-name">' . h(t('form_sig_name')) . '</label>'
                    . '<input type="text" id="' . $id . '-name" name="f[' . $i . '][name]" value="' . h((string) ($sig['name'] ?? '')) . '" autocomplete="name"' . ($err !== '' ? ' aria-invalid="true"' : '') . ($f['required'] ? ' required' : '') . '></div>'
                    . '<div class="field checkbox-field"><input type="checkbox" id="' . $id . '-agree" name="f[' . $i . '][agree]" value="1"' . (($sig['agree'] ?? '') === '1' ? ' checked' : '') . '>'
                    . '<label for="' . $id . '-agree">' . h(t('form_sig_agree')) . '</label></div>'
                    . '<input type="hidden" name="f[' . $i . '][drawn]" value="" data-signature-data>'
                    . '<div class="signature-draw" hidden data-signature-draw><p id="' . $id . '-draw-hint">' . h(t('form_sig_draw_hint')) . '</p>'
                    . '<canvas width="600" height="200" style="border:2px solid currentColor;max-width:100%;touch-action:none;background:#fff;color:#000" role="img" aria-label="' . h(t('form_sig_draw')) . '" aria-describedby="' . $id . '-draw-hint" tabindex="-1"></canvas>'
                    . '<p><button type="button" class="button button-secondary" data-signature-clear>' . h(t('form_sig_clear')) . '</button></p></div>'
                    . '</fieldset>';
                break;
            default:
                $type = in_array($f['type'], ['email', 'tel', 'number', 'date'], true) ? $f['type'] : 'text';
                $auto = match ($type) { 'email' => ' autocomplete="email"', 'tel' => ' autocomplete="tel"', default => '' };
                $step = $type === 'number' ? ' step="any"' : '';
                $out .= $wrapOpen('div') . '<label for="' . $id . '">' . $label . '</label>' . $hint . $error
                    . '<input type="' . $type . '" id="' . $id . '" name="f[' . $i . ']" value="' . h((string) $value) . '"' . $auto . $step . $attrs . '></div>';
        }
    }
    $out .= '<div class="hp-field" aria-hidden="true"><label for="' . h($slug) . '-website">Leave this empty</label>'
        . '<input id="' . h($slug) . '-website" name="website" type="text" tabindex="-1" autocomplete="off"></div>'
        . '<input type="hidden" name="form_token" value="' . h(contact_form_token()) . '">'
        . '<input type="hidden" name="form_name" value="' . h($slug) . '">'
        . ($canResume ? '<input type="hidden" name="resume_token" value="' . h((string) ($state['resume_token'] ?? '')) . '">' : '')
        . '<div class="form-actions"><button type="submit" class="button">' . h(t('form_send')) . '</button>'
        . ($canResume ? ' <button type="submit" class="button button-secondary" name="save_later" value="1" formnovalidate>' . h(t('form_save_later')) . '</button>' : '')
        . '</div>' . ($canResume ? '<p class="hint">' . h(t('form_save_later_hint')) . '</p>' : '') . '</form>';
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
        if (isset($_GET['file'])) {
            form_file_download((string) $_GET['view'], (string) $_GET['file'], (int) ($_GET['n'] ?? 0));
        }
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
        . 'Everything people send is kept under Submissions and emailed to you, with their email address as the reply address when the form asks for one. '
        . 'A form can ask for a file or a signature, show a field only after a particular answer, and let people save a half-finished form and come back to it.</p>'
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
            . form_condition_controls($fields, $i, $errors)
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
        . checkbox_field('resume', 'Let people save a half-finished form and come back to it', !empty($v['resume']), ['id' => 'resume',
            'hint' => 'Adds a “Save and finish later” button. The person gets a private link that brings their answers back for ' . FORM_DRAFT_DAYS . ' days, and the link is emailed to them when the form asks for their email address. Attached files are not kept in a saved form; they are added when it is finished.'])
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
        'resume' => !empty($_POST['resume']),
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
            $fields = form_fields_remap($fields, fn(int $k) => $k === $i ? $j : ($k === $j ? $i : $k));
            $name = $fields[$j]['label'] !== '' ? $fields[$j]['label'] : FORM_FIELD_TYPES[$fields[$j]['type']];
            form_editor($v, [], $existing ? $origSlug : null, "Moved “{$name}” $op. It is now field " . ($j + 1) . " of $count. Save the form to keep the new order.");
        }
    }
    if ($op === 'remove' && isset($fields[$i])) {
        $name = $fields[$i]['label'] !== '' ? $fields[$i]['label'] : FORM_FIELD_TYPES[$fields[$i]['type']];
        array_splice($fields, $i, 1);
        $fields = form_fields_remap($fields, fn(int $k) => $k === $i ? null : ($k > $i ? $k - 1 : $k));
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
        if ($f['show_if'] !== '') {
            $dep = (int) $f['show_if'];
            if ($dep >= $k || !isset($fields[$dep]) || !in_array($fields[$dep]['type'], FORM_CONDITION_TYPES, true)) {
                $errors["field-$k-show-if"] = 'Field ' . ($k + 1) . ' can only depend on an earlier choice list, checkboxes or yes/no field.';
            } elseif ($f['show_if_value'] === '' && $fields[$dep]['type'] !== 'checkbox') {
                $errors["field-$k-show-if-value"] = 'Enter the answer that shows field ' . ($k + 1) . '.';
            } elseif ($f['show_if_value'] !== '' && $fields[$dep]['type'] !== 'checkbox' && !in_array($f['show_if_value'], form_field_options($fields[$dep]), true)) {
                $errors["field-$k-show-if-value"] = 'The answer that shows field ' . ($k + 1) . ' must be one of the choices of field ' . ($dep + 1) . ', spelled the same way.';
            }
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

/** The "only show when" controls of one field in the editor: which earlier field, and which of its answers. */
function form_condition_controls(array $fields, int $i, array $errors): string
{
    $choices = ['' => 'Always'];
    foreach ($fields as $j => $g) {
        if ($j < $i && in_array($g['type'], FORM_CONDITION_TYPES, true)) {
            $choices[(string) $j] = 'Field ' . ($j + 1) . ': ' . ($g['label'] !== '' ? $g['label'] : FORM_FIELD_TYPES[$g['type']]);
        }
    }
    if (count($choices) === 1) {
        return '<input type="hidden" name="fields[' . $i . '][show_if]" value=""><input type="hidden" name="fields[' . $i . '][show_if_value]" value="">';
    }
    $f = $fields[$i];
    $current = $f['show_if'] !== '' && isset($choices[$f['show_if']]) ? $f['show_if'] : '';
    $dep = $current !== '' ? $fields[(int) $current] : null;
    $id = "field-$i-show-if";
    $err = $errors[$id] ?? null;
    $out = '<div class="field' . ($err ? ' field-error' : '') . '"><label for="' . $id . '">Show this field only when</label>'
        . '<p class="hint" id="' . $id . '-hint">Leave “Always” unless this field should appear only after a particular answer to an earlier choice list, checkboxes or yes/no field.</p>'
        . ($err ? '<p class="error-message" id="' . $id . '-error">' . h($err) . '</p>' : '')
        . '<select id="' . $id . '" name="fields[' . $i . '][show_if]" aria-describedby="' . $id . '-hint' . ($err ? " $id-error" : '') . '">';
    foreach ($choices as $val => $text) {
        $out .= '<option value="' . h((string) $val) . '"' . ((string) $val === $current ? ' selected' : '') . '>' . h($text) . '</option>';
    }
    $out .= '</select></div>';
    $valueHint = $dep !== null && $dep['type'] === 'checkbox' ? 'For a yes/no field, type yes (shown when ticked) or no (shown when not ticked).'
        : 'Type one of that field’s choices exactly as it is written there. The field appears when that choice is picked' . ($dep !== null && $dep['type'] === 'checkboxes' ? ' (among others, for checkboxes)' : '') . '.';
    $out .= text_field("fields[$i][show_if_value]", 'has the answer', $f['show_if_value'], ['id' => "field-$i-show-if-value", 'error' => $errors["field-$i-show-if-value"] ?? null, 'hint' => $valueHint, 'autocomplete' => 'off']);
    return $out;
}

/** After fields move or go, point every "only show when" at the right field again (null = the field it pointed at is gone). */
function form_fields_remap(array $fields, callable $map): array
{
    foreach ($fields as $i => $f) {
        if (($f['show_if'] ?? '') !== '') {
            $to = $map((int) $f['show_if']);
            $fields[$i]['show_if'] = $to === null ? '' : (string) $to;
            if ($to === null) {
                $fields[$i]['show_if_value'] = '';
            }
        }
    }
    return $fields;
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
        foreach ((array) ($s['answers'] ?? []) as $n => $a) {
            $value = form_answer_text($a);
            $shown = $a['type'] === 'email' && $value !== '' ? '<a href="mailto:' . h($value) . '">' . h($value) . '</a>' : nl2br(h($value !== '' ? $value : '—'));
            if (is_array($a['value']) && ($a['value']['file'] ?? '') !== '' && preg_match('/-(\d+)\.[a-z0-9]+$/', (string) $a['value']['file'], $fm)) {
                $shown .= ' <a href="' . h(admin_url('forms', ['view' => $slug, 'file' => $s['id'], 'n' => (int) $fm[1]])) . '">'
                    . ($a['type'] === 'signature' ? 'See the drawn signature' : 'Download the file') . '<span class="visually-hidden"> for ' . h((string) $a['label']) . '</span></a>';
            }
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

/** Send a file someone attached, or a drawn signature, to the signed-in person. Nothing under data/ is reachable any other way. */
function form_file_download(string $slug, string $id, int $n): never
{
    $found = valid_slug($slug) && form_submission_file($slug, $id) ? form_stored_file($slug, $id, $n) : null;
    if (!$found) {
        not_found_screen();
    }
    [$path, $name, $mime] = $found;
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($path));
    header('Content-Disposition: ' . (str_starts_with($mime, 'image/') || $mime === 'application/pdf' ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $name) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

function form_submission_delete(string $slug, string $id): never
{
    $file = valid_slug($slug) ? form_submission_file($slug, $id) : null;
    if (!$file) {
        not_found_screen();
    }
    foreach (glob(form_files_dir($slug) . '/' . $id . '-*') ?: [] as $attached) {
        @unlink($attached); // the files that came with it go too
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
            $byLabel[(string) $a['label']] = in_array($a['type'] ?? '', ['file', 'signature'], true) ? form_answer_text($a) : (is_array($a['value']) ? implode('; ', $a['value']) : (string) $a['value']);
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
