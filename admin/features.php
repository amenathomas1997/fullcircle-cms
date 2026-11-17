<?php
/**
 * Control panel screens for cheat sheets, testimonials, visitor counts and backups.
 * Loaded by index.php; uses the helpers in admin-lib.php.
 */

declare(strict_types=1);

const SITE_BACKUP_DIR = ROOT . '/../site-backups/content';

/* =====================================================================
   Cheat sheets
   ===================================================================== */

function resources_list(): never
{
    $rows = '';
    foreach (all_items('resources') as $r) {
        $slug = $r['slug'];
        $title = h($r['title']);
        $rows .= '<tr><th scope="row">' . $title . '</th><td>' . status_label($r) . '</td><td>' . h(format_time($r['updated'] ?? '')) . '</td>'
            . '<td class="actions"><a href="' . h(admin_url('resource', ['slug' => $slug])) . '">Edit<span class="visually-hidden"> ' . $title . '</span></a>'
            . ' <a href="/resources/' . h($slug) . '/">View<span class="visually-hidden"> ' . $title . '</span></a>'
            . ' <a href="' . h(admin_url('delete', ['type' => 'resources', 'slug' => $slug])) . '">Delete<span class="visually-hidden"> ' . $title . '</span></a></td></tr>';
    }
    $body = '<p>Cheat sheets appear at <a href="/resources/">/resources/</a>. Each one can also be downloaded as a Word document and as a braille-ready file (uncontracted UEB), made automatically from what you write here.</p>'
        . '<p><a class="button" href="' . h(admin_url('resource')) . '">Add a cheat sheet</a></p>';
    $body .= $rows === '' ? '<p>There are no cheat sheets yet.</p>'
        : '<div class="table-wrap"><table><caption>All cheat sheets</caption><thead><tr><th scope="col">Title</th><th scope="col">Status</th><th scope="col">Last changed</th><th scope="col">Actions</th></tr></thead>'
            . "<tbody>$rows</tbody></table></div>";
    admin_screen('Cheat sheets', $body, 'resources');
}

function resource_form(?array $values = null, array $errors = [], ?string $origSlug = null): never
{
    $origSlug ??= isset($_GET['slug']) ? (string) $_GET['slug'] : null;
    $existing = $origSlug !== null ? get_item('resources', $origSlug) : null;
    if ($origSlug !== null && !$existing && $values === null) {
        flash('That cheat sheet could not be found.', 'error');
        go(admin_url('resources'));
    }
    $v = $values ?? ($existing ?? ['title' => '', 'slug' => '', 'summary' => '', 'status' => 'draft',
        'body' => '<p>Introduction.</p><h2>Section name</h2><table><thead><tr><th scope="col">To do this</th><th scope="col">Press</th></tr></thead><tbody><tr><td>Action</td><td>Keys</td></tr></tbody></table>']);
    $isNew = $existing === null;

    $body = '<form method="post" action="' . h(admin_url('resource', $isNew ? [] : ['slug' => $origSlug])) . '" data-editor-form>' . csrf_field()
        . updated_field('resources', $isNew ? null : $origSlug)
        . text_field('title', 'Title', (string) $v['title'], ['required' => true, 'error' => $errors['title'] ?? null, 'hint' => 'For example “NVDA for Windows: keyboard shortcuts”.'])
        . textarea_field('summary', 'Summary', (string) ($v['summary'] ?? ''), ['rows' => 3, 'hint' => 'One or two sentences shown in the list of cheat sheets.'])
        . textarea_field('body', 'Cheat sheet content', (string) $v['body'], ['rows' => 24, 'class' => 'field-wide', 'id' => 'post-body', 'error' => $errors['post-body'] ?? null,
            'hint' => 'Use Heading 2 for each section and a two-column table of actions and keys. Write keys out in words, like “Control + Option + Space”, so they read well in braille. '
                . 'In the editor, Alt and F10 moves to the toolbar.'])
        . radio_field('status', 'Status', ['published' => 'Published', 'draft' => 'Draft, only you can see it'], (string) ($v['status'] ?? 'draft'))
        . text_field('slug', 'Address', (string) $v['slug'], ['prefix' => site_url('/resources/'), 'spellcheck' => 'false', 'autocomplete' => 'off', 'error' => $errors['slug'] ?? null,
            'hint' => 'Leave it empty to make one from the title.'])
        . '<div class="form-actions"><button type="submit" class="button" name="then" value="edit">Save cheat sheet</button>'
        . '<button type="submit" class="button button-secondary" name="then" value="view">Save and view</button>'
        . '<button type="submit" class="button button-secondary" formaction="' . h(admin_url('preview', ['type' => 'resources'])) . '" formtarget="_blank">Preview without saving<span class="visually-hidden"> (opens in a new tab)</span></button>'
        . '</div></form>';
    if (!$isNew) {
        $body .= '<h2>More options</h2><ul>'
            . '<li><a href="/resources/' . h($origSlug) . '/">View this cheat sheet</a></li>'
            . '<li><a href="/resources/' . h($origSlug) . '.docx">Download the Word version</a></li>'
            . '<li><a href="/resources/' . h($origSlug) . '.brf">Download the braille version</a></li>'
            . '<li><a href="' . h(admin_url('revisions', ['key' => "resources/$origSlug"])) . '">Earlier versions</a> (' . count(list_revisions("resources/$origSlug")) . ')</li>'
            . '<li><a href="' . h(admin_url('delete', ['type' => 'resources', 'slug' => $origSlug])) . '">Delete this cheat sheet</a></li></ul>';
    }
    admin_screen($isNew ? 'Add a cheat sheet' : 'Edit cheat sheet: ' . h($existing['title']), $body, 'resources', [
        'errors' => $errors,
        'head' => '<meta name="csrf-token" content="' . h($_SESSION['csrf']) . '">',
        'scripts' => editor_scripts(),
    ]);
}

function resource_save(): never
{
    $origSlug = isset($_GET['slug']) ? (string) $_GET['slug'] : null;
    $existing = $origSlug !== null ? get_item('resources', $origSlug) : null;
    $v = [
        'title' => trim((string) ($_POST['title'] ?? '')),
        'slug' => strtolower(trim((string) ($_POST['slug'] ?? ''))),
        'summary' => trim((string) ($_POST['summary'] ?? '')),
        'status' => ($_POST['status'] ?? '') === 'published' ? 'published' : 'draft',
        'body' => str_replace("\r\n", "\n", (string) ($_POST['body'] ?? '')),
    ];
    if ($v['slug'] === '') {
        $v['slug'] = slugify($v['title']);
    }
    $errors = [];
    if ($v['title'] === '') {
        $errors['title'] = 'Enter a title for the cheat sheet.';
    }
    if (trim(strip_tags($v['body'])) === '') {
        $errors['post-body'] = 'Write the content of the cheat sheet.';
    }
    if ($v['slug'] !== '' && ($e = slug_error('resources', $v['slug'], $existing ? $origSlug : null))) {
        $errors['slug'] = $e;
    }
    if (stale_edit($existing)) {
        $errors['title'] = stale_edit_message($existing);
    }
    if ($errors) {
        resource_form($v, $errors, $existing ? $origSlug : null);
    }
    save_item('resources', $v['slug'], $v, $existing ? $origSlug : null);
    if (($_POST['then'] ?? '') === 'view') {
        go('/resources/' . $v['slug'] . '/');
    }
    flash(($existing ? 'Saved your changes to ' : 'Saved the new cheat sheet ') . '“' . $v['title'] . '”.' . ($v['status'] === 'draft' ? ' It is a draft, so only you can see it.' : ''));
    go(admin_url('resource', ['slug' => $v['slug']]));
}

/* =====================================================================
   Testimonials
   ===================================================================== */

function testimonials_screen(): never
{
    $pending = all_testimonials('pending');
    $approved = all_testimonials('approved');
    $hidden = all_testimonials('hidden');
    $body = '<p>Visitors send testimonials with the [testimonial-form] shortcode, on whichever page you put it. '
        . 'Nothing appears on the website until you approve it. You can fix typos before approving.</p>';

    $section = function (string $heading, array $items, string $empty) {
        $out = '<section aria-labelledby="h-' . slugify($heading) . '"><h2 id="h-' . slugify($heading) . '">' . h($heading) . ' (' . count($items) . ')</h2>';
        if (!$items) {
            return $out . '<p>' . h($empty) . '</p></section>';
        }
        foreach ($items as $t) {
            $id = h($t['id']);
            $out .= '<article class="message" aria-labelledby="t-' . $id . '"><h3 id="t-' . $id . '">' . h($t['name']) . ', received ' . h(format_time($t['received'] ?? '')) . '</h3>'
                . (($t['email'] ?? '') !== '' ? '<p>Email (private): <a href="mailto:' . h($t['email']) . '">' . h($t['email']) . '</a></p>' : '')
                . '<form method="post" action="' . h(admin_url('testimonials')) . '">' . csrf_field()
                . '<input type="hidden" name="id" value="' . $id . '">'
                . text_field('name', 'Name shown', (string) $t['name'], ['id' => "name-$id"])
                . text_field('role', 'About them', (string) ($t['role'] ?? ''), ['id' => "role-$id"])
                . textarea_field('text', 'Testimonial', (string) $t['text'], ['id' => "text-$id", 'rows' => 6])
                . '<div class="form-actions">'
                . ($t['status'] !== 'approved' ? '<button type="submit" class="button" name="do" value="approve">Save and show on the website<span class="visually-hidden">: ' . h($t['name']) . '</span></button>'
                    : '<button type="submit" class="button" name="do" value="save">Save changes<span class="visually-hidden">: ' . h($t['name']) . '</span></button>'
                        . '<button type="submit" class="button button-secondary" name="do" value="hide">Hide from the website<span class="visually-hidden">: ' . h($t['name']) . '</span></button>')
                . '<button type="submit" class="button button-danger" name="do" value="delete">Delete<span class="visually-hidden"> testimonial from ' . h($t['name']) . '</span></button>'
                . '</div></form></article>';
        }
        return $out . '</section>';
    };
    $body .= $section('Waiting for approval', $pending, 'No testimonials are waiting.')
        . $section('Showing on the website', $approved, 'None yet. Approved testimonials appear wherever a page has [testimonials] in its HTML.')
        . ($hidden ? $section('Hidden', $hidden, '') : '');
    admin_screen('Testimonials', $body, 'testimonials');
}

function testimonial_action(): never
{
    $file = testimonial_file((string) ($_POST['id'] ?? ''));
    $t = $file ? read_json($file) : null;
    if (!$t) {
        flash('That testimonial could not be found.', 'error');
        go(admin_url('testimonials'));
    }
    $do = (string) ($_POST['do'] ?? '');
    if ($do === 'delete') {
        unlink($file);
        flash('Deleted the testimonial from ' . $t['name'] . '.');
        go(admin_url('testimonials'));
    }
    $t['name'] = trim((string) ($_POST['name'] ?? $t['name'])) ?: $t['name'];
    $t['role'] = trim((string) ($_POST['role'] ?? ''));
    $t['text'] = trim(str_replace("\r\n", "\n", (string) ($_POST['text'] ?? $t['text']))) ?: $t['text'];
    $t['status'] = match ($do) {
        'approve' => 'approved',
        'hide' => 'hidden',
        default => $t['status'],
    };
    write_json($file, $t);
    flash(match ($do) {
        'approve' => 'The testimonial from ' . $t['name'] . ' is now on the website.',
        'hide' => 'The testimonial from ' . $t['name'] . ' is hidden from the website.',
        default => 'Saved the testimonial from ' . $t['name'] . '.',
    });
    go(admin_url('testimonials'));
}

/* =====================================================================
   Visitors
   ===================================================================== */

function visitors_screen(): never
{
    $days = (int) ($_GET['days'] ?? 30);
    $days = in_array($days, [7, 30, 90, 365], true) ? $days : 30;
    $s = stats_summary($days);
    $periods = '';
    foreach ([7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days', 365 => 'Last year'] as $n => $label) {
        $periods .= '<li><a href="' . h(admin_url('visitors', ['days' => $n])) . '"' . ($n === $days ? ' aria-current="page"' : '') . '>' . $label . '</a></li>';
    }
    $body = '<p>Counted on your own server, with no cookies and nothing shared with other companies. A visitor is counted once per day. '
        . 'Your own visits are not counted while you are signed in.</p>'
        . '<nav aria-label="Time period"><ul class="period-list">' . $periods . '</ul></nav>'
        . '<h2 class="visually-hidden">Totals</h2>'
        . '<ul class="cards"><li><p class="stat-number">' . number_format(array_sum(array_column($s['days'], 'visitors'))) . '</p><p>visitors (each counted once a day) in the last ' . $days . ' days</p></li>'
        . '<li><p class="stat-number">' . number_format($s['views']) . '</p><p>pages viewed</p></li></ul>';

    $rows = '';
    $max = max(1, ...array_column($s['days'], 'visitors'));
    foreach ($s['days'] as $d) {
        $rows .= '<tr><th scope="row">' . h(date('D, M j', strtotime($d['day']))) . '</th><td>' . $d['visitors'] . '</td><td>' . $d['views'] . '</td>'
            . '<td aria-hidden="true"><span class="bar" style="width:' . round($d['visitors'] / $max * 100) . '%"></span></td></tr>';
    }
    $body .= '<h2>By day</h2><div class="table-wrap"><table class="stats"><caption>Visitors and page views each day, newest first</caption>'
        . '<thead><tr><th scope="col">Day</th><th scope="col">Visitors</th><th scope="col">Page views</th><th scope="col" aria-hidden="true"></th></tr></thead>'
        . "<tbody>$rows</tbody></table></div>";

    $list = function (array $items, string $caption, string $col) {
        if (!$items) {
            return '<p>None yet.</p>';
        }
        $out = '<div class="table-wrap"><table><caption>' . h($caption) . '</caption><thead><tr><th scope="col">' . h($col) . '</th><th scope="col">Views</th></tr></thead><tbody>';
        foreach (array_slice($items, 0, 20, true) as $k => $n) {
            $out .= '<tr><th scope="row">' . h((string) $k) . '</th><td>' . $n . '</td></tr>';
        }
        return $out . '</tbody></table></div>';
    };
    admin_screen('Visitors', $body, 'visitors');
}

/* =====================================================================
   Backups
   ===================================================================== */

function latest_backup(): ?array
{
    // The folder comes from the backup_dir setting in data/config.php when there is one (see lib/features/ops.php).
    $files = glob((function_exists('backup_dir') ? backup_dir() : SITE_BACKUP_DIR) . '/site-content-*.tgz') ?: [];
    if (!$files) {
        return null;
    }
    rsort($files);
    return ['name' => basename($files[0]), 'time' => filemtime($files[0]), 'size' => filesize($files[0]), 'count' => count($files)];
}

/** A zip of the site's content, uploads, stylesheet and messages, made now. Passwords and email secrets are left out. */
function backup_download(): never
{
    $tmp = tempnam(sys_get_temp_dir(), 'fcat');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('The backup could not be made.');
    }
    $add = function (string $dir, string $prefix) use ($zip) {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $name = $prefix . '/' . str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
            if (preg_match('#(^|/)(config\.php|graph-token\.php|deploy-key|deploy-key\.pub|deploy-known-hosts|salt-[^/]+|login-attempts\.json|contact-rate\.json|users\.json|password-tokens\.json|password-reset-rate\.json|cache/.*)$#', $name)) {
                continue;
            }
            $zip->addFile($f->getPathname(), $name);
        }
    };
    $add(CONTENT_DIR, 'content');
    $add(DATA_DIR, 'data');
    $add(UPLOAD_DIR, 'uploads');
    $zip->addFile(STYLESHEET, 'assets/css/site.css');
    $zip->addFromString('README.txt', "Backup of " . site_host() . " made " . date('F j, Y g:i a') . ".\r\n"
        . "To restore, copy these folders into the website folder on the server.\r\n"
        . "Your control panel password and email settings are not included, for security.\r\n");
    $zip->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9]+/', '-', strtolower(site_host())) . '-backup-' . date('Y-m-d') . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}

/* =====================================================================
   Email updates
   ===================================================================== */

/** Recent published posts and cheat sheets, for choosing what an update is about. */
function update_choices(): array
{
    $choices = [];
    foreach (array_slice(posts_in_lang('en'), 0, 15) as $p) {
        $choices['posts/' . $p['slug']] = ['label' => 'Blog post: ' . $p['title'], 'title' => $p['title'], 'url' => post_url($p), 'summary' => post_excerpt($p, 50)];
    }
    foreach (published_resources('en') as $r) {
        $choices['resources/' . $r['slug']] = ['label' => 'Cheat sheet: ' . $r['title'], 'title' => $r['title'], 'url' => '/resources/' . $r['slug'] . '/', 'summary' => (string) ($r['summary'] ?? '')];
    }
    return $choices;
}

function update_email_html(string $message, ?array $item): string
{
    $html = '';
    foreach (preg_split('/\R{2,}/', trim($message)) ?: [] as $para) {
        if (trim($para) !== '') {
            $html .= '<p>' . nl2br(h(trim($para))) . '</p>';
        }
    }
    if ($item) {
        $html .= '<h2 style="font-size:20px"><a href="' . h(site_url($item['url'])) . '">' . h($item['title']) . '</a></h2>'
            . '<p>' . h($item['summary']) . '</p><p><a href="' . h(site_url($item['url'])) . '">Read it on the website</a></p>';
    }
    return '<div style="font-family:Arial,sans-serif;font-size:17px;line-height:1.5">' . $html . '<p>' . h(site_name()) . (contact_phone() !== '' ? '<br>' . h(contact_phone()) : '') . '</p></div>';
}

function updates_screen(array $errors = [], array $v = []): never
{
    $list = subscribers();
    $count = fn(string $s) => count(array_filter($list, fn($x) => ($x['status'] ?? '') === $s));
    $active = $count('active');
    $address = trim((string) (settings()['mailing_address'] ?? ''));
    $body = '<p>People sign up on the <a href="/updates/">Get updates by email</a> page and confirm their address before they get anything. '
        . 'Each update goes to each person separately, a few at a time, using the email sending settings.</p>'
        . '<ul class="cards"><li><p class="stat-number">' . $active . '</p><p>confirmed ' . ($active === 1 ? 'subscriber' : 'subscribers') . '</p></li>'
        . '<li><p class="stat-number">' . $count('pending') . '</p><p>waiting to confirm</p></li>'
        . '<li><p class="stat-number">' . $count('unsubscribed') . '</p><p>unsubscribed</p></li></ul>';

    if ($address === '') {
        $body .= '<div class="notice notice-error"><h2>Add a mailing address before sending</h2><p>The law (CAN-SPAM) requires every update email to include a postal address, such as a PO box. '
            . '<a href="' . h(admin_url('settings')) . '#mailing_address">Add it in Settings</a>.</p></div>';
    }

    $options = '<option value="">Nothing, just my message</option>';
    foreach (update_choices() as $key => $c) {
        $options .= '<option value="' . h($key) . '"' . (($v['item'] ?? '') === $key ? ' selected' : '') . '>' . h($c['label']) . '</option>';
    }
    $body .= '<h2>Write an update</h2><form method="post" action="' . h(admin_url('updates')) . '">' . csrf_field()
        . '<div class="field"><label for="item">What’s it about?</label><p class="hint" id="item-hint">A link and summary are added for you.</p>'
        . '<select id="item" name="item" aria-describedby="item-hint">' . $options . '</select></div>'
        . text_field('subject', 'Email subject', (string) ($v['subject'] ?? ''), ['error' => $errors['subject'] ?? null, 'hint' => 'Leave empty to use “New from ' . h(site_name()) . ':” and the title.'])
        . textarea_field('message', 'Your message (optional)', (string) ($v['message'] ?? ''), ['rows' => 6, 'error' => $errors['message'] ?? null,
            'hint' => 'Plain text. Leave a blank line between paragraphs.'])
        . '<div class="form-actions"><button type="submit" class="button button-secondary" name="do" value="test">Send a test to me</button>'
        . '<button type="submit" class="button" name="do" value="review"' . ($address === '' || $active === 0 ? ' disabled' : '') . '>Send to ' . $active . ' ' . ($active === 1 ? 'subscriber' : 'subscribers') . '…</button></div>'
        . ($active === 0 ? '<p class="hint">Sending opens once someone has confirmed their sign-up.</p>' : '')
        . '</form>';

    $jobs = '';
    foreach (array_merge(glob(OUTBOX_DIR . '/*.json') ?: [], array_slice(array_reverse(glob(DATA_DIR . '/outbox-done/*.json') ?: []), 0, 10)) as $f) {
        $j = read_json($f) ?? [];
        $jobs .= '<tr><th scope="row">' . h($j['subject'] ?? '') . '</th><td>' . h(format_time($j['created'] ?? '')) . '</td><td>'
            . count($j['sent'] ?? []) . ' of ' . count($j['to'] ?? []) . '</td><td>' . (isset($j['finished']) ? 'Done' : 'Sending') . (count($j['failed'] ?? []) ? ', ' . count($j['failed']) . ' failed' : '') . '</td></tr>';
    }
    if ($jobs !== '') {
        $body .= '<h2>Sent updates</h2><div class="table-wrap"><table><caption>Updates, newest first</caption><thead><tr><th scope="col">Subject</th><th scope="col">Queued</th><th scope="col">Sent</th><th scope="col">Status</th></tr></thead><tbody>' . $jobs . '</tbody></table></div>';
    }

    $rows = '';
    foreach ($list as $email => $s) {
        $rows .= '<tr><th scope="row">' . h($email) . '</th><td>' . h(['active' => 'Confirmed', 'pending' => 'Waiting to confirm', 'unsubscribed' => 'Unsubscribed'][$s['status']] ?? $s['status']) . '</td>'
            . '<td>' . h(format_time($s['confirmed'] ?? $s['created'] ?? '')) . '</td><td><form method="post" action="' . h(admin_url('updates')) . '" class="inline">' . csrf_field()
            . '<input type="hidden" name="do" value="remove"><input type="hidden" name="email" value="' . h($email) . '">'
            . '<button type="submit" class="button-link">Remove<span class="visually-hidden"> ' . h($email) . '</span></button></form></td></tr>';
    }
    $body .= '<h2>Subscribers</h2>' . ($rows === '' ? '<p>No one has signed up yet.</p>'
        : '<div class="table-wrap"><table><caption>Everyone who has signed up</caption><thead><tr><th scope="col">Email</th><th scope="col">Status</th><th scope="col">Since</th><th scope="col">Actions</th></tr></thead><tbody>' . $rows . '</tbody></table></div>');
    admin_screen('Email updates', $body, 'updates', ['errors' => $errors]);
}

function updates_action(): never
{
    $do = (string) ($_POST['do'] ?? '');
    if ($do === 'remove') {
        $list = subscribers();
        $email = (string) ($_POST['email'] ?? '');
        unset($list[$email]);
        save_subscribers($list);
        flash("Removed $email from the list.");
        go(admin_url('updates'));
    }
    $choices = update_choices();
    $v = ['item' => (string) ($_POST['item'] ?? ''), 'subject' => trim((string) ($_POST['subject'] ?? '')), 'message' => trim((string) ($_POST['message'] ?? ''))];
    $item = $choices[$v['item']] ?? null;
    $subject = $v['subject'] !== '' ? $v['subject'] : ($item ? 'New from ' . site_name() . ': ' . $item['title'] : '');
    $errors = [];
    if ($subject === '') {
        $errors['subject'] = 'Enter a subject, or choose what the update is about.';
    }
    if (!$item && $v['message'] === '') {
        $errors['message'] = 'Write a message, or choose what the update is about.';
    }
    if ($errors) {
        updates_screen($errors, $v);
    }
    $html = update_email_html($v['message'], $item);

    if ($do === 'test') {
        $to = (string) ($_SESSION['admin_email'] ?? '') ?: (preg_split('/[\s,;]+/', (string) config()['contact_recipients'], -1, PREG_SPLIT_NO_EMPTY)[0] ?? mail_from()[0]);
        try {
            send_mail_to([$to], '[Test] ' . $subject, $html . updates_footer(str_repeat('0', 40)));
            flash("Sent a test to $to. The unsubscribe link in a test doesn’t work.");
        } catch (Throwable $e) {
            flash('The test couldn’t be sent. ' . $e->getMessage(), 'error');
        }
        go(admin_url('updates'));
    }
    if ($do === 'review') {
        $n = count(active_subscribers());
        $body = '<p>This will send “' . h($subject) . '” to <strong>' . $n . ' ' . ($n === 1 ? 'person' : 'people') . '</strong>. It can’t be undone. Emails go out a few at a time over the next few minutes.</p>'
            . '<h2>Preview</h2><div class="message">' . $html . '</div>'
            . '<form method="post" action="' . h(admin_url('updates')) . '" class="form-actions">' . csrf_field()
            . '<input type="hidden" name="item" value="' . h($v['item']) . '"><input type="hidden" name="subject" value="' . h($v['subject']) . '">'
            . '<input type="hidden" name="message" value="' . h($v['message']) . '">'
            . '<button type="submit" class="button" name="do" value="send">Yes, send it</button>'
            . '<a class="button button-secondary" href="' . h(admin_url('updates')) . '">No, go back</a></form>';
        admin_screen('Send this update?', $body, 'updates');
    }
    if ($do === 'send') {
        if (trim((string) (settings()['mailing_address'] ?? '')) === '') {
            flash('Add a mailing address in Settings before sending.', 'error');
            go(admin_url('updates'));
        }
        $n = queue_update($subject, $html);
        flash($n ? "Your update is on its way to $n " . ($n === 1 ? 'person' : 'people') . '. It goes out a few at a time; check progress below.' : 'There’s no one to send it to yet.');
        go(admin_url('updates'));
    }
    not_found_screen();
}

/* =====================================================================
   Videos and success stories
   ===================================================================== */

const COLLECTIONS = [
    'videos' => ['plural' => 'Videos', 'singular' => 'video', 'public' => '/videos/'],
    'stories' => ['plural' => 'Success stories', 'singular' => 'success story', 'public' => '/success-stories/'],
];

function collection_list(string $type): never
{
    $c = COLLECTIONS[$type];
    $rows = '';
    foreach (all_items($type) as $i) {
        $title = h($i['title']);
        $state = status_label($i) . ($type === 'stories' && empty($i['consent']) ? ' (waiting for permission)' : '');
        $rows .= '<tr><th scope="row">' . $title . '</th><td>' . h($state) . '</td><td>' . h(format_time($i['updated'] ?? '')) . (($i['updated_by'] ?? '') !== '' ? ', by ' . h($i['updated_by']) : '') . '</td>'
            . '<td class="actions"><a href="' . h(admin_url($type, ['slug' => $i['slug']])) . '">Edit<span class="visually-hidden"> ' . $title . '</span></a>'
            . ' <a href="' . h($c['public'] . $i['slug'] . '/') . '">View<span class="visually-hidden"> ' . $title . '</span></a>'
            . ' <a href="' . h(admin_url('delete', ['type' => $type, 'slug' => $i['slug']])) . '">Delete<span class="visually-hidden"> ' . $title . '</span></a></td></tr>';
    }
    $intro = $type === 'videos'
        ? '<p>Videos appear at <a href="/videos/">/videos/</a>. Upload the video to YouTube or Vimeo with captions turned on, then add it here with a full transcript.</p>'
        : '<p>Success stories appear at <a href="/success-stories/">/success-stories/</a>. A story is shown only when it’s published <strong>and</strong> you’ve confirmed the person gave permission to share it.</p>';
    $body = $intro . '<p><a class="button" href="' . h(admin_url($type, ['new' => 1])) . '">Add a ' . h($c['singular']) . '</a></p>'
        . ($rows === '' ? '<p>None yet.</p>'
            : '<div class="table-wrap"><table><caption>All ' . h(strtolower($c['plural'])) . '</caption><thead><tr><th scope="col">Title</th><th scope="col">Status</th><th scope="col">Last changed</th><th scope="col">Actions</th></tr></thead><tbody>' . $rows . '</tbody></table></div>');
    admin_screen($c['plural'], $body, $type);
}

function collection_form(string $type, ?array $values = null, array $errors = [], ?string $origSlug = null): never
{
    $c = COLLECTIONS[$type];
    $origSlug ??= isset($_GET['slug']) ? (string) $_GET['slug'] : null;
    $existing = $origSlug !== null ? get_item($type, $origSlug) : null;
    $v = $values ?? ($existing ?? ['title' => '', 'slug' => '', 'status' => 'draft']);
    $isNew = $existing === null;
    $body = '<form method="post" action="' . h(admin_url($type, $isNew ? [] : ['slug' => $origSlug])) . '" data-editor-form>' . csrf_field()
        . updated_field($type, $isNew ? null : $origSlug)
        . text_field('title', 'Title', (string) $v['title'], ['required' => true, 'error' => $errors['title'] ?? null])
        . textarea_field('summary', 'Summary', (string) ($v['summary'] ?? ''), ['rows' => 3, 'hint' => 'One or two sentences shown in the list.']);
    if ($type === 'videos') {
        $body .= text_field('video_url', 'YouTube or Vimeo address', (string) ($v['video_url'] ?? ''), ['type' => 'url', 'spellcheck' => 'false', 'error' => $errors['video_url'] ?? null,
                'hint' => 'For example https://www.youtube.com/watch?v=… Turn on captions on YouTube or Vimeo, and check they’re accurate.'])
            . text_field('described_url', 'Version with audio description (optional)', (string) ($v['described_url'] ?? ''), ['type' => 'url', 'spellcheck' => 'false',
                'hint' => 'If you made a second version that describes what’s on screen, add its address.'])
            . textarea_field('transcript', 'Transcript', (string) ($v['transcript'] ?? ''), ['rows' => 18, 'class' => 'field-wide', 'id' => 'post-body', 'error' => $errors['post-body'] ?? null,
                'hint' => 'Everything that’s said, plus a description of anything important on screen. Shown below the video.']);
    } else {
        $options = '';
        foreach (STORY_OUTCOMES as $k => $label) {
            $options .= '<option value="' . $k . '"' . (($v['outcome'] ?? '') === $k ? ' selected' : '') . '>' . h($label) . '</option>';
        }
        $body .= text_field('person', 'Name as shown', (string) ($v['person'] ?? ''), ['hint' => 'For example “Tasha W.” First name and last initial is usually enough.'])
            . '<div class="field"><label for="outcome">Outcome</label><select id="outcome" name="outcome">' . $options . '</select></div>'
            . textarea_field('body', 'Their story', (string) ($v['body'] ?? ''), ['rows' => 18, 'class' => 'field-wide', 'id' => 'post-body', 'error' => $errors['post-body'] ?? null])
            . checkbox_field('consent', 'I have this person’s permission to share their story and name on the website', !empty($v['consent']), [
                'hint' => 'The story stays hidden until this is ticked.']);
    }
    $body .= radio_field('status', 'Status', ['published' => 'Published', 'draft' => 'Draft, only you can see it'], (string) ($v['status'] ?? 'draft'))
        . text_field('slug', 'Address', (string) $v['slug'], ['prefix' => site_url($c['public']), 'spellcheck' => 'false', 'autocomplete' => 'off', 'error' => $errors['slug'] ?? null,
            'hint' => 'Leave it empty to make one from the title.'])
        . '<div class="form-actions"><button type="submit" class="button">Save ' . h($c['singular']) . '</button></div></form>';
    if (!$isNew) {
        $body .= '<h2>More options</h2><ul><li><a href="' . h($c['public'] . $origSlug . '/') . '">View on the website</a></li>'
            . '<li><a href="' . h(admin_url('revisions', ['key' => "$type/$origSlug"])) . '">Earlier versions</a></li>'
            . '<li><a href="' . h(admin_url('delete', ['type' => $type, 'slug' => $origSlug])) . '">Delete</a></li></ul>';
    }
    admin_screen(($isNew ? 'Add a ' : 'Edit ') . $c['singular'] . ($isNew ? '' : ': ' . h($existing['title'])), $body, $type, [
        'errors' => $errors,
        'head' => '<meta name="csrf-token" content="' . h($_SESSION['csrf']) . '">',
        'scripts' => editor_scripts(),
    ]);
}

function collection_save(string $type): never
{
    $origSlug = isset($_GET['slug']) ? (string) $_GET['slug'] : null;
    $existing = $origSlug !== null ? get_item($type, $origSlug) : null;
    $v = [
        'title' => trim((string) ($_POST['title'] ?? '')),
        'slug' => strtolower(trim((string) ($_POST['slug'] ?? ''))),
        'summary' => trim((string) ($_POST['summary'] ?? '')),
        'status' => ($_POST['status'] ?? '') === 'published' ? 'published' : 'draft',
    ];
    if ($type === 'videos') {
        $v += ['video_url' => trim((string) ($_POST['video_url'] ?? '')), 'described_url' => trim((string) ($_POST['described_url'] ?? '')),
            'transcript' => str_replace("\r\n", "\n", (string) ($_POST['transcript'] ?? ''))];
    } else {
        $v += ['person' => trim((string) ($_POST['person'] ?? '')), 'outcome' => isset(STORY_OUTCOMES[$_POST['outcome'] ?? '']) ? $_POST['outcome'] : 'employment',
            'body' => str_replace("\r\n", "\n", (string) ($_POST['body'] ?? '')), 'consent' => !empty($_POST['consent'])];
    }
    if ($v['slug'] === '') {
        $v['slug'] = slugify($v['title']);
    }
    $errors = [];
    if ($v['title'] === '') {
        $errors['title'] = 'Enter a title.';
    }
    if ($type === 'videos' && $v['video_url'] !== '' && !video_embed_url($v['video_url'])) {
        $errors['video_url'] = 'Enter a YouTube or Vimeo video address.';
    }
    if ($type === 'videos' && trim(strip_tags($v['transcript'])) === '' && $v['status'] === 'published') {
        $errors['post-body'] = 'Add a transcript before publishing, so everyone can get the content.';
    }
    if ($v['slug'] !== '' && ($e = slug_error($type, $v['slug'], $existing ? $origSlug : null))) {
        $errors['slug'] = $e;
    }
    if (stale_edit($existing)) {
        $errors['title'] = stale_edit_message($existing);
    }
    if ($errors) {
        collection_form($type, $v, $errors, $existing ? $origSlug : null);
    }
    save_item($type, $v['slug'], $v, $existing ? $origSlug : null);
    $hidden = $v['status'] === 'draft' ? ' It is a draft, so only you can see it.'
        : ($type === 'stories' && !$v['consent'] ? ' It stays hidden until you confirm you have permission to share it.' : '');
    flash('Saved “' . $v['title'] . '”.' . $hidden);
    go(admin_url($type, ['slug' => $v['slug']]));
}

/* =====================================================================
   Link check and site health
   ===================================================================== */

function link_check_screen(): never
{
    $report = read_json(LINK_REPORT);
    $health = read_json(HEALTH_STATE) ?? [];
    $requested = is_file(LINK_REQUEST);
    $body = '<p>Once a month the server checks every link on the website and emails you anything broken or moved. '
        . 'Every day it also checks that the SSL certificate for ' . h(site_host()) . ' isn’t about to expire.</p>'
        . '<form method="post" action="' . h(admin_url('links')) . '">' . csrf_field()
        . '<button type="submit" class="button"' . ($requested ? ' disabled' : '') . '>' . ($requested ? 'A check is waiting to run' : 'Check all links now') . '</button>'
        . '<p class="hint">The check runs within the hour and takes a few minutes. Come back to this screen to see the results.</p></form>';

    $body .= '<h2>Daily health check</h2>';
    if (empty($health['last_daily'])) {
        $body .= '<p>Hasn’t run yet. It runs every morning after 6 am.</p>';
    } else {
        $body .= '<p>Last checked ' . h(format_time($health['last_daily'])) . ': '
            . (empty($health['warnings']) ? 'all good.</p>' : '</p><ul><li>' . implode('</li><li>', array_map('h', $health['warnings'])) . '</li></ul>');
    }
    $body .= '<p>To be told if the whole website goes down, add <code>' . h(site_url('/health.txt')) . '</code> to a free uptime monitor such as UptimeRobot, as a keyword check for “OK”.</p>';

    $body .= '<h2>Link check results</h2>';
    if (!$report) {
        $body .= '<p>No link check has run yet.</p>';
    } else {
        $c = $report['counts'];
        $body .= '<p>Checked ' . h(format_time($report['checked'])) . ': ' . $report['total'] . ' links. ' . $c['ok'] . ' fine, '
            . ($c['broken'] + $c['error'] + $c['no-answer']) . ' broken or not answering, ' . $c['moved'] . ' moved, and ' . $c['blocked'] . ' that block automatic checks.</p>';
        $rows = '';
        foreach ($report['problems'] as $p) {
            $pages = implode(', ', array_map(fn($pg) => '<a href="' . h($pg) . '">' . h($pg) . '</a>', $p['pages']));
            $rows .= '<tr><th scope="row"><a href="' . h($p['url']) . '">' . h($p['url']) . '</a></th><td>' . h(link_verdict_words($p)) . '</td><td>' . $pages . '</td></tr>';
        }
        $body .= $rows === '' ? '<p>Every link works.</p>'
            : '<div class="table-wrap"><table><caption>Links to look at, most serious first</caption><thead><tr><th scope="col">Link</th><th scope="col">What happened</th><th scope="col">Found on</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
;
    }
    admin_screen('Link check', $body, 'links');
}

function link_check_request(): never
{
    write_file(LINK_REQUEST, date('c'));
    flash('A link check will run within the hour. The results appear on this screen when it’s done.');
    go(admin_url('links'));
}
