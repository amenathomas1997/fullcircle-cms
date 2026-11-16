<?php
/**
 * Full Circle CMS control panel. Every screen is chosen by ?a=<action>.
 */

declare(strict_types=1);

require __DIR__ . '/admin-lib.php';
require __DIR__ . '/features.php';
require __DIR__ . '/accounts.php';

start_admin_session();

$action = (string) ($_GET['a'] ?? '');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';

if (config()['password_hash'] === '') {
    setup_screen($post);
}
if ($action === 'oauth_start') {
    m365_start();
}
if ($action === 'oauth') {
    m365_callback();
}
if (!signed_in()) {
    login_screen($post);
}
if ($post) {
    check_csrf();
}
// Changes are recorded with the name of the person who made them.
$GLOBALS['current_editor'] = (string) ($_SESSION['admin_name'] ?? 'Site password');

// Screens for features that are turned off don't exist.
$needsFeature = ['resources' => 'resources', 'resource' => 'resources', 'testimonials' => 'testimonials', 'videos' => 'videos', 'stories' => 'stories', 'updates' => 'updates'];
if (isset($needsFeature[$action]) && !feature_on($needsFeature[$action])) {
    not_found_screen();
}

try {
    match ($action) {
        '' => dashboard(),
        'pages' => pages_list(),
        'page' => $post ? page_save() : page_form(),
        'posts' => posts_list(),
        'post' => $post ? post_save() : post_form(),
        'resources' => resources_list(),
        'resource' => $post ? resource_save() : resource_form(),
        'testimonials' => $post ? testimonial_action() : testimonials_screen(),
        'visitors' => visitors_screen(),
        'updates' => $post ? updates_action() : updates_screen(),
        'videos', 'stories' => $post ? collection_save($action) : (isset($_GET['slug']) || isset($_GET['new']) ? collection_form($action) : collection_list($action)),
        'links' => $post ? link_check_request() : link_check_screen(),
        'backup' => backup_download(),
        'delete' => $post ? item_delete() : delete_confirm(),
        'preview' => preview(),
        'revisions' => $post ? revision_restore() : revisions_list(),
        'media' => $post ? media_upload() : media_list(),
        'media_delete' => $post ? media_delete() : media_delete_confirm(),
        'upload_image' => editor_image_upload(),
        'menu' => $post ? menu_save() : menu_form(),
        'messages' => $post ? message_delete() : messages_list(),
        'template' => $post ? template_save() : template_form(),
        'settings' => $post ? settings_save() : settings_form(),
        'logout' => $post ? logout() : go(ADMIN_URL),
        default => not_found_screen(),
    };
} catch (RuntimeException $e) {
    flash($e->getMessage(), 'error');
    go(back_url());
}

/* =====================================================================
   Sign in
   ===================================================================== */

/**
 * First run: choose the password, name the site and create the starter pages.
 * From the computer the site runs on this just works. From anywhere else it needs
 * the one-time code in data/setup-code.txt (put there by whoever installed the files),
 * so a fresh copy can't be claimed by a stranger who finds /admin/ first.
 */
function setup_screen(bool $post): never
{
    $codeFile = DATA_DIR . '/setup-code.txt';
    $needsCode = !is_local_request();
    if ($needsCode && !is_file($codeFile)) {
        admin_screen('Set up the control panel', '<p>No password has been set yet. For security, the first set-up can only be done from the computer the site runs on, or with a one-time code.</p>'
            . '<p>Either run this in the website folder on the server:</p><pre><code>php tools/setup.php</code></pre>'
            . '<p>Or create a file called <code>data/setup-code.txt</code> containing any code of 12 or more characters, then reload this page and type the code.</p>');
    }
    $v = ['site_name' => trim((string) ($_POST['site_name'] ?? '')), 'site_url' => rtrim(trim((string) ($_POST['site_url'] ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? 'example.com')))), '/'),
        'contact_email' => trim((string) ($_POST['contact_email'] ?? '')), 'spanish' => !empty($_POST['spanish'])];
    $errors = [];
    if ($post) {
        $pw = (string) ($_POST['password'] ?? '');
        if ($needsCode && (strlen(trim((string) file_get_contents($codeFile))) < 12 || !hash_equals(trim((string) file_get_contents($codeFile)), trim((string) ($_POST['code'] ?? ''))))) {
            $errors['code'] = 'That code doesn’t match data/setup-code.txt.';
        }
        if ($v['site_name'] === '') {
            $errors['site_name'] = 'Enter the site name.';
        }
        if (!preg_match('#^https?://[a-z0-9.-]+(:\d+)?$#i', $v['site_url'])) {
            $errors['site_url'] = 'Enter the site address starting with https://, with nothing after the domain.';
        }
        if ($v['contact_email'] !== '' && !filter_var($v['contact_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['contact_email'] = 'Enter the email in the right format, like name@example.com.';
        }
        if (strlen($pw) < 12) {
            $errors['password'] = 'Choose a password that is at least 12 characters long.';
        } elseif ($pw !== ($_POST['confirm'] ?? '')) {
            $errors['confirm'] = 'The two passwords do not match. Type the same password in both boxes.';
        }
        if (!$errors) {
            save_settings(array_merge(settings(), ['site_name' => $v['site_name'], 'site_url' => $v['site_url'], 'contact_email' => $v['contact_email'],
                'features' => array_merge(FEATURE_DEFAULTS, ['spanish' => $v['spanish']])]));
            save_config(['password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'contact_recipients' => $v['contact_email']]);
            create_starter_content($v['spanish']);
            if (is_file($codeFile)) {
                @unlink($codeFile);
            }
            begin_signed_in_session('Site password');
            flash('Your site is set up and you are signed in. Start with Settings to add your details, then edit the starter pages.');
            go(admin_url('settings'));
        }
    }
    $body = '<p>Welcome. Answer these once to create your site; everything can be changed later in Settings.</p>'
        . '<form method="post" action="' . h(admin_url()) . '" class="narrow">'
        . ($needsCode ? text_field('code', 'One-time set-up code', '', ['required' => true, 'autocomplete' => 'off', 'error' => $errors['code'] ?? null, 'hint' => 'From the file data/setup-code.txt on the server.']) : '')
        . text_field('site_name', 'Site name', $v['site_name'], ['required' => true, 'error' => $errors['site_name'] ?? null])
        . text_field('site_url', 'Site address', $v['site_url'], ['type' => 'url', 'required' => true, 'spellcheck' => 'false', 'error' => $errors['site_url'] ?? null])
        . text_field('contact_email', 'Public contact email (optional)', $v['contact_email'], ['type' => 'email', 'error' => $errors['contact_email'] ?? null])
        . checkbox_field('spanish', 'Add Spanish pages too', $v['spanish'])
        . '<input type="text" name="username" value="admin" autocomplete="username" aria-label="Username" hidden>'
        . text_field('password', 'Control panel password', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'hint' => 'At least 12 characters. A short sentence you will remember works well.', 'error' => $errors['password'] ?? null])
        . text_field('confirm', 'Type the password again', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'error' => $errors['confirm'] ?? null])
        . '<button type="submit" class="button">Create my site</button></form>';
    admin_screen('Set up your site', $body, '', ['errors' => $errors]);
}

/** A drop-down of the uploaded images, for choosing the site logo. */
function logo_select(string $current): string
{
    $images = array_filter(uploaded_files(), fn($f) => $f['image']);
    $out = '<div class="field"><label for="logo">Logo</label><p class="hint" id="logo-hint">An image you have uploaded under Images and files. It appears in the header next to the site name and as the browser tab icon. Choose “No logo” to show the name only.</p>'
        . '<select id="logo" name="logo" aria-describedby="logo-hint"><option value="">No logo (site name only)</option>';
    foreach ($images as $f) {
        $out .= '<option value="' . h($f['url']) . '"' . ($f['url'] === $current ? ' selected' : '') . '>' . h($f['name']) . '</option>';
    }
    return $out . '</select></div>';
}

function login_screen(bool $post): never
{
    $errors = [];
    if ($post && ($_GET['a'] ?? '') === 'login') {
        if (login_locked()) {
            $errors['password'] = 'Too many wrong passwords. For security, sign-in is paused for 15 minutes.';
        } elseif (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
            $errors['password'] = 'That sign-in form had expired. Please try again.';
        } elseif (password_verify((string) ($_POST['password'] ?? ''), config()['password_hash'])) {
            begin_signed_in_session('Site password');
            $next = (string) ($_POST['next'] ?? '');
            go(str_starts_with($next, ADMIN_URL) ? $next : ADMIN_URL);
        } else {
            record_failed_login();
            $errors['password'] = 'That password is not right. Check it and try again.';
        }
    }
    $next = $_POST['next'] ?? ($_SERVER['REQUEST_URI'] ?? ADMIN_URL);
    $body = m365_enabled() ? '<p><a class="button" href="' . h(admin_url('oauth_start')) . '">Sign in with Microsoft 365</a></p>'
        . '<h2>Or sign in with the site password</h2>' : '';
    $body .= '<form method="post" action="' . h(admin_url('login')) . '" class="narrow">' . csrf_field()
        . '<input type="text" name="username" value="admin" autocomplete="username" aria-label="Username" hidden>'
        . '<input type="hidden" name="next" value="' . h((string) $next) . '">'
        . text_field('password', 'Password', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'current-password', 'error' => $errors['password'] ?? null])
        . '<button type="submit" class="button">Sign in</button></form>'
        . '<p>Forgot your password? Run <code>php tools/set-password.php</code> in the website folder to set a new one.</p>';
    admin_screen('Sign in', $body, '', ['errors' => $errors]);
}

function logout(): never
{
    $_SESSION = [];
    session_regenerate_id(true);
    flash('You are signed out.');
    go(ADMIN_URL);
}

function not_found_screen(): never
{
    http_response_code(404);
    admin_screen('Screen not found', '<p>That control panel screen doesn’t exist. <a href="' . h(ADMIN_URL) . '">Go to the dashboard</a>.</p>');
}

/* =====================================================================
   Dashboard
   ===================================================================== */

function dashboard(): never
{
    $pages = all_items('pages');
    $posts = all_items('posts');
    $drafts = count(array_filter($posts, fn($p) => ($p['status'] ?? '') !== 'published'));
    $messages = message_files();
    $unsent = count(array_filter($messages, fn($f) => !(read_json($f)['emailed'] ?? false)));
    $pendingTestimonials = count(all_testimonials('pending'));
    $week = stats_summary(7);
    $backup = latest_backup();

    $body = '<ul class="cards">'
        . '<li><h2><a href="' . h(admin_url('post')) . '">Write a blog post</a></h2><p>Use the visual editor to write and publish a new post.</p></li>'
        . '<li><h2><a href="' . h(admin_url('page')) . '">Add a page</a></h2><p>Create a new page and write its HTML.</p></li>'
        . '<li><h2><a href="' . h(admin_url('pages')) . '">Edit pages</a></h2><p>' . count($pages) . ' pages.</p></li>'
        . '<li><h2><a href="' . h(admin_url('posts')) . '">Blog posts</a></h2><p>' . count($posts) . ' posts, ' . $drafts . ' ' . ($drafts === 1 ? 'draft' : 'drafts') . '.</p></li>'
        . '<li><h2><a href="' . h(admin_url('messages')) . '">Messages</a></h2><p>' . count($messages) . ' from the contact form'
        . ($unsent ? ", <strong>$unsent not emailed</strong>" : '') . '.</p></li>'
        . (feature_on('testimonials') ? '<li><h2><a href="' . h(admin_url('testimonials')) . '">Testimonials</a></h2><p>'
            . ($pendingTestimonials ? "<strong>$pendingTestimonials waiting for your approval.</strong>" : 'None waiting for approval.') . '</p></li>' : '')
        . '<li><h2><a href="' . h(admin_url('visitors')) . '">Visitors</a></h2><p>' . number_format(array_sum(array_column($week['days'], 'visitors')))
            . ' visitors and ' . number_format($week['views']) . ' page views in the last 7 days.</p></li>'
        . (feature_on('resources') ? '<li><h2><a href="' . h(admin_url('resources')) . '">Cheat sheets</a></h2><p>' . count(all_items('resources')) . ' cheat sheets, with Word and braille downloads.</p></li>' : '')
        . '<li><h2><a href="' . h(admin_url('menu')) . '">Menu</a></h2><p>Choose the links in the main menu.</p></li>'
        . '</ul>'
        . '<h2>Backups</h2>'
        . ($backup ? '<p>The website’s pages, posts, uploads and messages are backed up on the server every night. Latest backup: ' . h(format_time($backup['time'])) . ' (' . $backup['count'] . ' kept).</p>'
            : '<p>No nightly backup has run yet.</p>')
        . '<p><a class="button button-secondary" href="' . h(admin_url('backup')) . '">Download a backup now</a> (a zip file of your content, uploads and messages; passwords and email settings are left out).</p>';

    if (!mail_configured()) {
        $body .= '<div class="notice notice-error"><h2>The contact form can’t send email yet</h2>'
            . '<p>Messages are being saved in <a href="' . h(admin_url('messages')) . '">Messages</a>, but not emailed. '
            . '<a href="' . h(admin_url('settings')) . '#email-settings">Choose how email is sent</a> to turn it on.</p></div>';
    }
    admin_screen('Dashboard', $body, 'dashboard');
}

/* =====================================================================
   Pages
   ===================================================================== */

function status_label(array $item): string
{
    $status = $item['status'] ?? 'draft';
    if ($status === 'published' && isset($item['date']) && $item['date'] > date('Y-m-d\TH:i')) {
        return 'Scheduled';
    }
    return $status === 'published' ? 'Published' : 'Draft';
}

function pages_list(): never
{
    $rows = '';
    foreach (all_items('pages') as $p) {
        $slug = $p['slug'];
        $title = h($p['title']);
        $actions = '<a href="' . h(admin_url('page', ['slug' => $slug])) . '">Edit<span class="visually-hidden"> ' . $title . '</span></a>'
            . ' <a href="' . h(page_path($slug)) . '">View<span class="visually-hidden"> ' . $title . '</span></a>';
        if ($slug !== 'home') {
            $actions .= ' <a href="' . h(admin_url('delete', ['type' => 'pages', 'slug' => $slug])) . '">Delete<span class="visually-hidden"> ' . $title . '</span></a>';
        }
        $rows .= '<tr><th scope="row">' . $title . ($slug === 'home' ? ' <span class="tag">Home page</span>' : '') . '</th>'
            . '<td>' . h(page_path($slug)) . '</td><td>' . status_label($p) . '</td>'
            . '<td>' . h(format_time($p['updated'] ?? '')) . (($p['updated_by'] ?? '') !== '' ? ', by ' . h($p['updated_by']) : '') . '</td><td class="actions">' . $actions . '</td></tr>';
    }
    $body = '<p><a class="button" href="' . h(admin_url('page')) . '">Add a new page</a></p>'
        . '<div class="table-wrap"><table><caption>All pages, in alphabetical order</caption>'
        . '<thead><tr><th scope="col">Title</th><th scope="col">Address</th><th scope="col">Status</th><th scope="col">Last changed</th><th scope="col">Actions</th></tr></thead>'
        . "<tbody>$rows</tbody></table></div>"
        . '<p>To put the contact form on a page, type <code>[contact-form]</code> where you want it in the page’s HTML.</p>';
    admin_screen('Pages', $body, 'pages');
}

function page_form(?array $values = null, array $errors = [], ?string $origSlug = null): never
{
    $origSlug ??= isset($_GET['slug']) ? (string) $_GET['slug'] : null;
    $existing = $origSlug !== null ? get_item('pages', $origSlug) : null;
    if ($origSlug !== null && !$existing && $values === null) {
        flash('That page could not be found. It may have been deleted.', 'error');
        go(admin_url('pages'));
    }
    $v = $values ?? ($existing ?? ['title' => '', 'slug' => '', 'status' => 'published', 'hide_title' => false, 'description' => '', 'body' => '']);
    $isNew = $existing === null;
    $isHome = $origSlug === 'home';

    $body = '<form method="post" action="' . h(admin_url('page', $isNew ? [] : ['slug' => $origSlug])) . '" data-editor-form>'
        . csrf_field() . updated_field('pages', $isNew ? null : $origSlug)
        . text_field('title', 'Page title', (string) $v['title'], ['required' => true, 'error' => $errors['title'] ?? null,
            'hint' => 'Shown in the browser tab and, unless you turn it off below, as the main heading of the page.']);
    if ($isHome) {
        $body .= '<input type="hidden" name="slug" value="home"><p class="hint">This is the home page, so its address is always <code>/</code>.</p>';
    } else {
        $body .= text_field('slug', 'Page address', (string) $v['slug'], ['prefix' => site_url('/'), 'spellcheck' => 'false', 'autocomplete' => 'off',
            'error' => $errors['slug'] ?? null,
            'hint' => 'Lowercase letters, numbers and dashes, for example <code>about-us</code>. Leave it empty to make one from the title. '
                . 'For a Spanish page, start it with <code>es-</code>, for example <code>es-contacto</code>; it will appear at <code>/es/contacto/</code>.'
                . ($isNew ? '' : ' If you change it, links to the old address will stop working.')]);
    }
    $body .= textarea_field('body', 'Page HTML', (string) $v['body'], ['rows' => 28, 'code' => true, 'class' => 'field-wide',
            'hint' => 'The HTML for the page content, between the menu and the footer. Use <code>&lt;h2&gt;</code> for section headings. '
                . 'Button links: <code>&lt;a class="button" href="…"&gt;</code>. Contact form: <code>[contact-form]</code>.'])
        . translation_field($v, $origSlug)
        . checkbox_field('hide_title', 'Don’t show the page title as a heading', !empty($v['hide_title']), [
            'hint' => 'Only tick this if the page HTML starts with its own <code>&lt;h1&gt;</code> heading. Every page needs exactly one.'])
        . textarea_field('description', 'Search engine description (optional)', (string) ($v['description'] ?? ''), ['rows' => 3,
            'hint' => 'One or two sentences shown under the page title in search results.'])
        . radio_field('status', 'Status', ['published' => 'Published, anyone can see it', 'draft' => 'Draft, only you can see it'], (string) ($v['status'] ?? 'published'));
    if ($isNew) {
        $body .= checkbox_field('add_to_menu', 'Add this page to the main menu', !empty($v['add_to_menu']));
    }
    $body .= '<div class="form-actions">'
        . '<button type="submit" class="button" name="then" value="edit">Save page</button>'
        . '<button type="submit" class="button button-secondary" name="then" value="view">Save and view page</button>'
        . '<button type="submit" class="button button-secondary" formaction="' . h(admin_url('preview', ['type' => 'pages'])) . '" formtarget="_blank">Preview without saving<span class="visually-hidden"> (opens in a new tab)</span></button>'
        . '</div><p class="hint">Keyboard shortcut: press Command and S (or Control and S) to save.</p></form>';

    if (!$isNew) {
        $body .= '<h2>More options</h2><ul>'
            . '<li><a href="' . h(page_path($origSlug)) . '">View this page on the website</a></li>'
            . '<li><a href="' . h(admin_url('revisions', ['key' => "pages/$origSlug"])) . '">Earlier versions of this page</a> (' . count(list_revisions("pages/$origSlug")) . ')</li>';
        if (!$isHome) {
            $body .= '<li><a href="' . h(admin_url('delete', ['type' => 'pages', 'slug' => $origSlug])) . '">Delete this page</a></li>';
        }
        $body .= '</ul>';
    }
    admin_screen($isNew ? 'Add a page' : 'Edit page: ' . h($existing['title']), $body, 'pages', ['errors' => $errors]);
}

/** Check a submitted address. Returns an error message or null. */
function slug_error(string $type, string $slug, ?string $origSlug): ?string
{
    if (!valid_slug($slug)) {
        return 'Use only lowercase letters, numbers and single dashes in the address, for example about-us.';
    }
    if ($type === 'pages' && in_array($slug, RESERVED_SLUGS, true) || ($type === 'pages' && $slug === 'home' && $origSlug !== 'home')) {
        return "The address “{$slug}” is used by the website itself. Choose a different one.";
    }
    if ($slug !== $origSlug && get_item($type, $slug)) {
        return 'Another ' . ($type === 'pages' ? 'page' : 'post') . " already uses the address “{$slug}”. Choose a different one.";
    }
    return null;
}

function page_save(): never
{
    $origSlug = isset($_GET['slug']) ? (string) $_GET['slug'] : null;
    $existing = $origSlug !== null ? get_item('pages', $origSlug) : null;
    $v = [
        'title' => trim((string) ($_POST['title'] ?? '')),
        'slug' => $origSlug === 'home' ? 'home' : strtolower(trim((string) ($_POST['slug'] ?? ''))),
        'status' => ($_POST['status'] ?? '') === 'draft' ? 'draft' : 'published',
        'hide_title' => !empty($_POST['hide_title']),
        'description' => trim((string) ($_POST['description'] ?? '')),
        'body' => str_replace("\r\n", "\n", (string) ($_POST['body'] ?? '')),
        'add_to_menu' => !empty($_POST['add_to_menu']),
        'translation' => (string) ($_POST['translation'] ?? ''),
    ];
    if ($v['translation'] !== '' && (!valid_slug($v['translation']) || !get_item('pages', $v['translation']))) {
        $v['translation'] = '';
    }
    if ($v['slug'] === '') {
        $v['slug'] = slugify($v['title']);
    }
    $errors = [];
    if ($v['title'] === '') {
        $errors['title'] = 'Enter a title for the page.';
    }
    if ($v['slug'] !== '' && ($e = slug_error('pages', $v['slug'], $existing ? $origSlug : null))) {
        $errors['slug'] = $e;
    } elseif ($v['slug'] === '' && $v['title'] !== '') {
        $errors['slug'] = 'Enter an address for the page using letters or numbers.';
    }
    if (stale_edit($existing)) {
        $errors['title'] = stale_edit_message($existing);
    }
    if ($errors) {
        page_form($v, $errors, $existing ? $origSlug : null);
    }

    $addToMenu = $v['add_to_menu'];
    unset($v['add_to_menu']);
    if ($v['translation'] === '') {
        unset($v['translation']);
    }
    save_item('pages', $v['slug'], $v, $existing ? $origSlug : null);
    link_translation($v['slug'], $v['translation'] ?? '', $existing ? $origSlug : null);

    $s = settings();
    $menuKey = slug_lang($v['slug']) === 'es' ? 'menu_es' : 'menu';
    if ($addToMenu) {
        $s[$menuKey][] = ['label' => $v['title'], 'url' => page_path($v['slug'])];
        save_settings($s);
    } elseif ($existing && $origSlug !== $v['slug']) {
        // Keep menu links working when an address changes.
        foreach (['menu', 'menu_es'] as $key) {
            foreach ($s[$key] as &$link) {
                if ($link['url'] === page_path($origSlug)) {
                    $link['url'] = page_path($v['slug']);
                }
            }
            unset($link);
        }
        save_settings($s);
    }

    if (($_POST['then'] ?? '') === 'view') {
        go(page_path($v['slug']));
    }
    flash(($existing ? 'Saved your changes to ' : 'Created the page ') . '“' . $v['title'] . '”.' . ($v['status'] === 'draft' ? ' It is a draft, so only you can see it.' : ''));
    go(admin_url('page', ['slug' => $v['slug']]));
}

/** Choose the same page in the other language, so visitors can switch between them. */
function translation_field(array $v, ?string $origSlug): string
{
    if (!feature_on('spanish')) {
        return '';
    }
    $lang = slug_lang((string) ($v['slug'] ?: ($origSlug ?? '')));
    $other = $lang === 'es' ? 'English' : 'Spanish';
    $options = '<option value="">None</option>';
    foreach (all_items('pages') as $p) {
        if (slug_lang($p['slug']) !== $lang) {
            $options .= '<option value="' . h($p['slug']) . '"' . (($v['translation'] ?? '') === $p['slug'] ? ' selected' : '') . '>'
                . h($p['title']) . ' (' . h(page_path($p['slug'])) . ')</option>';
        }
    }
    return '<div class="field"><label for="translation">Same page in ' . $other . '</label>'
        . '<p class="hint" id="translation-hint">Visitors get a link to switch between the two, and search engines learn they match.</p>'
        . '<select id="translation" name="translation" aria-describedby="translation-hint">' . $options . '</select></div>';
}

/** Keep translations linked both ways, and unlink a page that is no longer chosen. */
function link_translation(string $slug, string $other, ?string $oldSlug): void
{
    foreach (all_items('pages') as $p) {
        $points = $p['translation'] ?? '';
        if ($p['slug'] === $slug) {
            continue;
        }
        $shouldPoint = $p['slug'] === $other;
        if ($shouldPoint && $points !== $slug) {
            $p['translation'] = $slug;
            save_item('pages', $p['slug'], $p);
        } elseif (!$shouldPoint && ($points === $slug || ($oldSlug !== null && $points === $oldSlug))) {
            unset($p['translation']);
            save_item('pages', $p['slug'], $p);
        }
    }
}

/* =====================================================================
   Blog posts
   ===================================================================== */

function posts_list(): never
{
    $rows = '';
    foreach (all_items('posts') as $p) {
        $slug = $p['slug'];
        $title = h($p['title']);
        $rows .= '<tr><th scope="row">' . $title . '</th><td>' . h(format_date($p['date'] ?? '')) . '</td><td>' . status_label($p) . '</td>'
            . '<td class="actions"><a href="' . h(admin_url('post', ['slug' => $slug])) . '">Edit<span class="visually-hidden"> ' . $title . '</span></a>'
            . ' <a href="' . h(post_url($p)) . '">View<span class="visually-hidden"> ' . $title . '</span></a>'
            . ' <a href="' . h(admin_url('delete', ['type' => 'posts', 'slug' => $slug])) . '">Delete<span class="visually-hidden"> ' . $title . '</span></a></td></tr>';
    }
    $body = '<p><a class="button" href="' . h(admin_url('post')) . '">Write a new post</a></p>';
    $body .= $rows === '' ? '<p>There are no blog posts yet.</p>'
        : '<div class="table-wrap"><table><caption>All blog posts, newest first</caption>'
            . '<thead><tr><th scope="col">Title</th><th scope="col">Date</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead>'
            . "<tbody>$rows</tbody></table></div>";
    admin_screen('Blog posts', $body, 'posts');
}

function post_form(?array $values = null, array $errors = [], ?string $origSlug = null): never
{
    $origSlug ??= isset($_GET['slug']) ? (string) $_GET['slug'] : null;
    $existing = $origSlug !== null ? get_item('posts', $origSlug) : null;
    if ($origSlug !== null && !$existing && $values === null) {
        flash('That post could not be found. It may have been deleted.', 'error');
        go(admin_url('posts'));
    }
    $v = $values ?? ($existing ?? ['title' => '', 'slug' => '', 'status' => 'draft', 'date' => date('Y-m-d\TH:i'), 'excerpt' => '', 'body' => '']);
    $isNew = $existing === null;

    $known = implode(', ', array_column(all_categories(), 'name'));
    $audio = $isNew ? null : post_audio((string) $origSlug);
    $body = '<form method="post" action="' . h(admin_url('post', $isNew ? [] : ['slug' => $origSlug])) . '" enctype="multipart/form-data" data-editor-form>'
        . csrf_field() . updated_field('posts', $isNew ? null : $origSlug)
        . text_field('title', 'Post title', (string) $v['title'], ['required' => true, 'error' => $errors['title'] ?? null])
        . textarea_field('body', 'Post content', (string) $v['body'], ['rows' => 24, 'class' => 'field-wide', 'id' => 'post-body',
            'error' => $errors['post-body'] ?? null,
            'hint' => 'Choose an editor above: Visual (Alt and F10 for its toolbar, Alt and 0 for shortcuts), Blocks (each paragraph and heading is its own form field, best with a screen reader) or HTML. '
                . 'The post title is the main heading, so start sections with Heading 2.'])
        . radio_field('status', 'Status', ['published' => 'Published', 'draft' => 'Draft, only you can see it'], (string) ($v['status'] ?? 'draft'))
        . text_field('date', 'Publish date and time', substr((string) $v['date'], 0, 16), ['type' => 'datetime-local', 'required' => true, 'error' => $errors['date'] ?? null,
            'hint' => 'Pick a future date to schedule the post. It appears on the blog at that time.'])
        . text_field('slug', 'Post address', (string) $v['slug'], ['prefix' => site_url('/blog/'), 'spellcheck' => 'false', 'autocomplete' => 'off',
            'error' => $errors['slug'] ?? null, 'hint' => 'Leave it empty to make one from the title. For a Spanish post, start it with <code>es-</code>; it appears on the Spanish blog at <code>/es/blog/</code>.'])
        . textarea_field('excerpt', 'Summary (optional)', (string) ($v['excerpt'] ?? ''), ['rows' => 3,
            'hint' => 'Shown on the blog page and in search results. If you leave it empty, the first few sentences of the post are used.'])
        . text_field('categories', 'Topics (optional)', implode(', ', (array) ($v['categories'] ?? [])), ['autocomplete' => 'off',
            'hint' => 'Separate topics with commas. Readers can browse the blog by topic.' . ($known !== '' ? ' Topics you’ve used: ' . h($known) . '.' : '')])
        . '<fieldset class="field"><legend>Audio version (optional)</legend>'
        . '<p class="hint">' . ($audio ? 'This post has an audio version: <a href="' . h($audio['url']) . '">listen</a> (' . format_bytes($audio['size']) . ').'
            : 'Add a recording of this post and it appears as a player at the top of the post and in the podcast feed. '
                . 'You can record it yourself, or whoever hosts the site can make one with a Mac’s voice using <code>sh tools/make-audio.sh ' . h($origSlug ?? 'post-address') . '</code>.') . '</p>'
        . text_field('audio', $audio ? 'Replace the audio file' : 'Audio file', '', ['type' => 'file', 'accept' => '.m4a,.mp3,audio/mp4,audio/mpeg', 'error' => $errors['audio'] ?? null,
            'hint' => 'An M4A or MP3 file, up to ' . format_bytes(min(AUDIO_MAX, upload_limit())) . '.'])
        . ($audio ? checkbox_field('remove_audio', 'Remove the audio version', false) : '')
        . '</fieldset>'
        . '<div class="form-actions">'
        . '<button type="submit" class="button" name="then" value="edit">Save post</button>'
        . '<button type="submit" class="button button-secondary" name="then" value="view">Save and view post</button>'
        . '<button type="submit" class="button button-secondary" formaction="' . h(admin_url('preview', ['type' => 'posts'])) . '" formtarget="_blank">Preview without saving<span class="visually-hidden"> (opens in a new tab)</span></button>'
        . '</div><p class="hint">Keyboard shortcut: press Command and S (or Control and S) to save.</p></form>';

    if (!$isNew) {
        $body .= '<h2>More options</h2><ul>'
            . '<li><a href="/blog/' . h($origSlug) . '/">View this post on the website</a></li>'
            . '<li><a href="' . h(admin_url('revisions', ['key' => "posts/$origSlug"])) . '">Earlier versions of this post</a> (' . count(list_revisions("posts/$origSlug")) . ')</li>'
            . '<li><a href="' . h(admin_url('delete', ['type' => 'posts', 'slug' => $origSlug])) . '">Delete this post</a></li></ul>';
    }

    $editorScript = '<script src="/admin/vendor/tinymce/tinymce.min.js"></script><script src="/admin/blocks-editor.js" defer></script><script src="/admin/editor.js" defer></script>';
    admin_screen($isNew ? 'Write a blog post' : 'Edit post: ' . h($existing['title']), $body, 'posts', [
        'errors' => $errors,
        'head' => '<meta name="csrf-token" content="' . h($_SESSION['csrf']) . '">',
        'scripts' => $editorScript,
    ]);
}

function post_save(): never
{
    $origSlug = isset($_GET['slug']) ? (string) $_GET['slug'] : null;
    $existing = $origSlug !== null ? get_item('posts', $origSlug) : null;
    $v = [
        'title' => trim((string) ($_POST['title'] ?? '')),
        'slug' => strtolower(trim((string) ($_POST['slug'] ?? ''))),
        'status' => ($_POST['status'] ?? '') === 'published' ? 'published' : 'draft',
        'date' => (string) ($_POST['date'] ?? ''),
        'excerpt' => trim((string) ($_POST['excerpt'] ?? '')),
        'categories' => array_values(array_unique(array_filter(array_map('trim', explode(',', (string) ($_POST['categories'] ?? '')))))),
        'body' => str_replace("\r\n", "\n", (string) ($_POST['body'] ?? '')),
    ];
    if ($v['slug'] === '') {
        $v['slug'] = slugify($v['title']);
    }
    $errors = [];
    if ($v['title'] === '') {
        $errors['title'] = 'Enter a title for the post.';
    }
    $audioUpload = $_FILES['audio'] ?? null;
    if ($audioUpload && ($audioUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE && ($e = audio_upload_error($audioUpload))) {
        $errors['audio'] = $e;
    }
    if (trim(strip_tags($v['body'], '<img><iframe><video><audio>')) === '') {
        $errors['post-body'] = 'Write some content for the post.';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', substr($v['date'], 0, 16))) {
        $errors['date'] = 'Enter the publish date and time, for example 06/09/2025 08:42 PM.';
    } else {
        $v['date'] = substr($v['date'], 0, 16);
    }
    if ($v['slug'] !== '' && ($e = slug_error('posts', $v['slug'], $existing ? $origSlug : null))) {
        $errors['slug'] = $e;
    } elseif ($v['slug'] === '' && $v['title'] !== '') {
        $errors['slug'] = 'Enter an address for the post using letters or numbers.';
    }
    if (stale_edit($existing)) {
        $errors['title'] = stale_edit_message($existing);
    }
    if ($errors) {
        post_form($v, $errors, $existing ? $origSlug : null);
    }
    save_item('posts', $v['slug'], $v, $existing ? $origSlug : null);

    // Audio follows the post to its new address, and can be replaced or removed.
    if ($existing && $origSlug !== $v['slug'] && ($old = post_audio($origSlug))) {
        rename($old['path'], AUDIO_DIR . '/' . $v['slug'] . '.' . pathinfo($old['path'], PATHINFO_EXTENSION));
    }
    if (!empty($_POST['remove_audio']) && ($old = post_audio($v['slug']))) {
        unlink($old['path']);
    }
    if ($audioUpload && ($audioUpload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        store_post_audio($audioUpload, $v['slug']);
    }

    if (($_POST['then'] ?? '') === 'view') {
        go('/blog/' . $v['slug'] . '/');
    }
    $state = $v['status'] === 'draft' ? ' It is a draft, so only you can see it.'
        : ($v['date'] > date('Y-m-d\TH:i') ? ' It will appear on the blog on ' . format_time($v['date']) . '.' : ' It is live on the blog.');
    flash(($existing ? 'Saved your changes to ' : 'Saved the new post ') . '“' . $v['title'] . '”.' . $state);
    go(admin_url('post', ['slug' => $v['slug']]));
}

function audio_upload_error(array $file): ?string
{
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > AUDIO_MAX) {
        return 'That audio file is too big. Audio files can be up to ' . format_bytes(min(AUDIO_MAX, upload_limit())) . '.';
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return 'The audio upload didn’t finish. Please try again.';
    }
    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($ext, ['m4a', 'mp3'], true) || !preg_match('#^(audio/|video/mp4|application/octet-stream)#', (string) $mime)) {
        return 'Choose an M4A or MP3 audio file.';
    }
    return null;
}

function store_post_audio(array $file, string $slug): void
{
    if (!is_dir(AUDIO_DIR)) {
        mkdir(AUDIO_DIR, 0755, true);
    }
    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $target = AUDIO_DIR . "/$slug.$ext";
    // Save the new file first, then drop the old one, so a failed upload never loses the old recording.
    $tmp = AUDIO_DIR . "/.$slug-" . bin2hex(random_bytes(4)) . ".$ext";
    if (!move_uploaded_file($file['tmp_name'], $tmp)) {
        throw new RuntimeException('The audio file couldn’t be saved. Check that the uploads folder is writable.');
    }
    if (($old = post_audio($slug)) && $old['path'] !== $target) {
        unlink($old['path']);
    }
    rename($tmp, $target);
    @chmod($target, 0644);
}

/** The largest upload the server accepts, in bytes. */
function upload_limit(): int
{
    $toBytes = function (string $v): int {
        $n = (int) $v;
        return match (strtolower(substr(trim($v), -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
    };
    return min($toBytes((string) ini_get('upload_max_filesize')), $toBytes((string) ini_get('post_max_size')));
}

/* =====================================================================
   Shared: delete, preview, revisions
   ===================================================================== */

function item_type(): string
{
    $type = (string) ($_GET['type'] ?? '');
    if (!in_array($type, ITEM_TYPES, true)) {
        not_found_screen();
    }
    return $type;
}

function delete_confirm(): never
{
    $type = item_type();
    $slug = (string) ($_GET['slug'] ?? '');
    $item = get_item($type, $slug);
    if (!$item || ($type === 'pages' && $slug === 'home')) {
        not_found_screen();
    }
    $noun = ['pages' => 'page', 'posts' => 'post', 'resources' => 'cheat sheet', 'videos' => 'video', 'stories' => 'success story'][$type];
    $editAction = ['pages' => 'page', 'posts' => 'post', 'resources' => 'resource', 'videos' => 'videos', 'stories' => 'stories'][$type];
    $body = '<p>Are you sure you want to delete the ' . $noun . ' “' . h($item['title']) . '”? '
        . 'It will disappear from the website straight away. A copy is kept, so you can ask for it to be brought back.</p>'
        . '<form method="post" action="' . h(admin_url('delete', ['type' => $type, 'slug' => $slug])) . '" class="form-actions">' . csrf_field()
        . '<button type="submit" class="button button-danger">Yes, delete this ' . $noun . '</button>'
        . '<a class="button button-secondary" href="' . h(admin_url($editAction, ['slug' => $slug])) . '">No, go back</a></form>';
    admin_screen('Delete ' . $noun . ': ' . h($item['title']), $body, $type);
}

function item_delete(): never
{
    $type = item_type();
    $slug = (string) ($_GET['slug'] ?? '');
    $item = get_item($type, $slug);
    if (!$item || ($type === 'pages' && $slug === 'home')) {
        not_found_screen();
    }
    delete_item($type, $slug);
    if ($type === 'pages') {
        // Take the page out of both menus.
        $s = settings();
        $changed = false;
        foreach (['menu', 'menu_es'] as $key) {
            $before = count($s[$key]);
            $s[$key] = array_values(array_filter($s[$key], fn($l) => ($l['url'] ?? '') !== page_path($slug)));
            $changed = $changed || count($s[$key]) !== $before;
        }
        if ($changed) {
            save_settings($s);
        }
    }
    if ($type === 'posts' && ($audio = post_audio($slug))) {
        // The recording goes with the post.
        @unlink($audio['path']);
    }
    flash('Deleted “' . $item['title'] . '”.');
    go(admin_url($type));
}

/** Show unsaved changes inside the site template, in a new tab. */
function preview(): never
{
    $type = item_type();
    $title = trim((string) ($_POST['title'] ?? '')) ?: 'Untitled';
    $bodyHtml = (string) ($_POST['body'] ?? '');
    if ($type === 'pages') {
        current_lang(slug_lang((string) ($_POST['slug'] ?? '')));
        $bodyHtml = render_shortcodes($bodyHtml, '/');
        $content = empty($_POST['hide_title']) ? '<h1>' . h($title) . '</h1>' . $bodyHtml : $bodyHtml;
    } elseif ($type === 'resources') {
        $content = '<article><h1>' . h($title) . '</h1><p class="lede">' . h((string) ($_POST['summary'] ?? '')) . '</p><div class="resource-body">' . $bodyHtml . '</div></article>';
    } else {
        $content = '<article class="post"><h1>' . h($title) . '</h1><p class="post-date">Posted <time>' . h(format_date((string) ($_POST['date'] ?? ''))) . '</time></p>'
            . '<div class="post-body">' . $bodyHtml . '</div></article>';
    }
    $banner = '<p class="preview-banner" role="note" style="background:#ffee58;color:#111;padding:.75rem 1rem;border:3px solid #111;border-radius:8px;font-weight:700">'
        . 'Preview: these changes are not saved yet. Close this tab to go back to the editor.</p>';
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    echo render_layout('Preview: ' . $title, $banner . $content);
    exit;
}

function revision_key(): string
{
    $key = (string) ($_GET['key'] ?? '');
    if (!preg_match('#^(pages|posts|resources|videos|stories)/[a-z0-9-]+$|^template/(layout|css|footer|footer-es)$#', $key)) {
        not_found_screen();
    }
    return $key;
}

function revision_label(string $key): array
{
    [$type, $slug] = explode('/', $key);
    return match ($type) {
        'pages' => ['the page “' . (get_item('pages', $slug)['title'] ?? $slug) . '”', admin_url('page', ['slug' => $slug])],
        'posts' => ['the post “' . (get_item('posts', $slug)['title'] ?? $slug) . '”', admin_url('post', ['slug' => $slug])],
        'resources' => ['the cheat sheet “' . (get_item('resources', $slug)['title'] ?? $slug) . '”', admin_url('resource', ['slug' => $slug])],
        'videos', 'stories' => [COLLECTIONS[$type]['singular'] . ' “' . (get_item($type, $slug)['title'] ?? $slug) . '”', admin_url($type, ['slug' => $slug])],
        default => [['layout' => 'the site layout', 'css' => 'the stylesheet', 'footer' => 'the footer', 'footer-es' => 'the Spanish footer'][$slug], admin_url('template')],
    };
}

function revisions_list(): never
{
    $key = revision_key();
    [$label, $back] = revision_label($key);
    $revs = list_revisions($key);
    $view = (string) ($_GET['view'] ?? '');

    $body = '<p>A copy is saved automatically each time you save ' . h($label) . '. The newest ' . MAX_REVISIONS . ' are kept.</p>'
        . '<p><a href="' . h($back) . '">Back to ' . h($label) . '</a></p>';
    if ($view !== '' && ($contents = read_revision($key, $view)) !== null) {
        $json = json_decode($contents, true);
        $text = is_array($json) ? (string) ($json['body'] ?? '') : $contents;
        $time = format_time(current(array_filter($revs, fn($r) => $r['id'] === $view))['time'] ?? 0);
        $body .= '<section aria-labelledby="viewing"><h2 id="viewing">Version saved ' . h($time) . '</h2>'
            . (is_array($json) ? '<p>Title: ' . h($json['title'] ?? '') . '</p>' : '')
            . textarea_field('revision-view', 'Contents of this version (read only)', $text, ['rows' => 18, 'code' => true, 'class' => 'field-wide'])
            . '<form method="post" action="' . h(admin_url('revisions', ['key' => $key])) . '">' . csrf_field()
            . '<input type="hidden" name="id" value="' . h($view) . '"><button type="submit" class="button">Restore this version</button></form></section>';
        $body = str_replace('<textarea id="revision-view"', '<textarea readonly id="revision-view"', $body);
    }
    if (!$revs) {
        $body .= '<p>There are no earlier versions yet.</p>';
    } else {
        $rows = '';
        foreach ($revs as $r) {
            $when = h(format_time($r['time']));
            $by = json_decode((string) read_revision($key, $r['id']), true)['updated_by'] ?? '';
            $rows .= '<tr><th scope="row">' . $when . '</th><td>' . h($by ?: '—') . '</td><td>' . format_bytes($r['size']) . '</td><td class="actions">'
                . '<a href="' . h(admin_url('revisions', ['key' => $key, 'view' => $r['id']])) . '">View<span class="visually-hidden"> version from ' . $when . '</span></a> '
                . '<form method="post" action="' . h(admin_url('revisions', ['key' => $key])) . '" class="inline">' . csrf_field()
                . '<input type="hidden" name="id" value="' . h($r['id']) . '"><button type="submit" class="button-link">Restore<span class="visually-hidden"> version from ' . $when . '</span></button></form>'
                . '</td></tr>';
        }
        $body .= '<div class="table-wrap"><table><caption>Earlier versions, newest first</caption><thead><tr><th scope="col">Replaced on</th><th scope="col">Version saved by</th><th scope="col">Size</th><th scope="col">Actions</th></tr></thead>'
            . "<tbody>$rows</tbody></table></div>";
    }
    admin_screen('Earlier versions of ' . h($label), $body, '');
}

function revision_restore(): never
{
    $key = revision_key();
    [$label, $back] = revision_label($key);
    $contents = read_revision($key, (string) ($_POST['id'] ?? ''));
    if ($contents === null) {
        flash('That version could not be found.', 'error');
        go(admin_url('revisions', ['key' => $key]));
    }
    [$type, $slug] = explode('/', $key);
    if (in_array($type, ITEM_TYPES, true)) {
        $data = json_decode($contents, true);
        if (!is_array($data)) {
            throw new RuntimeException('That version is damaged and can’t be restored.');
        }
        save_item($type, $slug, $data);
    } elseif ($slug === 'footer' || $slug === 'footer-es') {
        $field = $slug === 'footer' ? 'footer_html' : 'footer_html_es';
        $s = settings();
        save_revision('template', $slug, $s[$field]);
        $s[$field] = $contents;
        save_settings($s);
    } else {
        $file = $slug === 'layout' ? CONTENT_DIR . '/layout.html' : STYLESHEET;
        save_revision('template', $slug, (string) file_get_contents($file));
        write_file($file, $contents);
    }
    flash('Restored the earlier version of ' . $label . '. The version it replaced was kept, so you can undo this.');
    go($back);
}

/* =====================================================================
   Images and files
   ===================================================================== */

/** Validate and store an uploaded file. Returns its web address. */
function store_upload(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Choose a file to upload.');
    }
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE || $file['size'] > UPLOAD_MAX) {
        throw new RuntimeException('That file is too big. Files can be up to ' . format_bytes(UPLOAD_MAX) . '.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('The upload didn’t finish. Please try again.');
    }
    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $okMime = UPLOAD_TYPES[$ext] ?? null;
    if ($okMime === null || ($mime !== $okMime && !($ext === 'docx' && $mime === 'application/zip'))) {
        throw new RuntimeException('That type of file can’t be uploaded. Use a JPEG, PNG, GIF or WebP image, a PDF, or a Word document.');
    }
    if (str_starts_with($okMime, 'image/') && @getimagesize($file['tmp_name']) === false) {
        throw new RuntimeException('That image file seems to be damaged.');
    }
    $base = slugify(pathinfo((string) $file['name'], PATHINFO_FILENAME)) ?: 'file';
    $dir = UPLOAD_DIR . '/' . date('Y/m');
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $name = "$base.$ext";
    for ($i = 2; is_file("$dir/$name"); $i++) {
        $name = "$base-$i.$ext";
    }
    if (!move_uploaded_file($file['tmp_name'], "$dir/$name")) {
        throw new RuntimeException('The file couldn’t be saved. Check that the uploads folder is writable.');
    }
    @chmod("$dir/$name", 0644);
    return '/uploads/' . date('Y/m') . '/' . $name;
}

function uploaded_files(): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(UPLOAD_DIR, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $ext = strtolower($f->getExtension());
        if (isset(UPLOAD_TYPES[$ext])) {
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen(UPLOAD_DIR)));
            $files[] = ['url' => '/uploads' . $rel, 'name' => $f->getFilename(), 'size' => $f->getSize(), 'time' => $f->getMTime(), 'image' => str_starts_with(UPLOAD_TYPES[$ext], 'image/')];
        }
    }
    usort($files, fn($a, $b) => $b['time'] <=> $a['time']);
    return $files;
}

function media_list(): never
{
    $body = '<form method="post" action="' . h(admin_url('media')) . '" enctype="multipart/form-data" class="upload-form">' . csrf_field()
        . text_field('file', 'Choose a file to upload', '', ['type' => 'file', 'required' => true, 'accept' => '.jpg,.jpeg,.png,.gif,.webp,.pdf,.docx',
            'hint' => 'Images (JPEG, PNG, GIF, WebP), PDFs or Word documents, up to ' . format_bytes(min(UPLOAD_MAX, upload_limit())) . '.'])
        . '<button type="submit" class="button">Upload file</button></form>'
        . '<p>To use a file, copy its address and paste it into a link (<code>&lt;a href="…"&gt;</code>) or an image (<code>&lt;img src="…" alt="Describe the image"&gt;</code>). '
        . 'In the blog editor you can also upload images straight from the Insert image button.</p>'
        . '<p id="copy-status" class="visually-hidden" role="status"></p>';
    $files = uploaded_files();
    if (!$files) {
        $body .= '<p>No files uploaded yet.</p>';
    } else {
        $rows = '';
        foreach ($files as $f) {
            $name = h($f['name']);
            $preview = $f['image'] ? '<img src="' . h($f['url']) . '" alt="" class="thumb">' : '';
            $rows .= '<tr><td>' . $preview . '</td><th scope="row"><a href="' . h($f['url']) . '">' . $name . '</a></th>'
                . '<td><code>' . h($f['url']) . '</code></td><td>' . format_bytes($f['size']) . '</td><td>' . h(format_time($f['time'])) . '</td>'
                . '<td class="actions"><button type="button" class="button-link" data-copy="' . h($f['url']) . '">Copy address<span class="visually-hidden"> of ' . $name . '</span></button> '
                . '<a href="' . h(admin_url('media_delete', ['file' => $f['url']])) . '">Delete<span class="visually-hidden"> ' . $name . '</span></a></td></tr>';
        }
        $body .= '<div class="table-wrap"><table><caption>Uploaded files, newest first</caption><thead><tr><th scope="col"><span class="visually-hidden">Preview</span></th><th scope="col">File</th><th scope="col">Address</th><th scope="col">Size</th><th scope="col">Uploaded</th><th scope="col">Actions</th></tr></thead>'
            . "<tbody>$rows</tbody></table></div>";
    }
    admin_screen('Images and files', $body, 'media');
}

function media_upload(): never
{
    $url = store_upload($_FILES['file'] ?? []);
    flash('Uploaded the file. Its address is ' . $url);
    go(admin_url('media'));
}

/** Map a /uploads/... address to a real file, refusing anything outside uploads. */
function upload_path(string $url): ?string
{
    // No dot-files: uploads/.htaccess is what stops uploaded files being run as code.
    if (!preg_match('#^/uploads/[A-Za-z0-9/_.-]+$#', $url) || str_contains($url, '..') || preg_match('#/\.#', $url)) {
        return null;
    }
    $path = realpath(ROOT . $url);
    $root = realpath(UPLOAD_DIR);
    return $path && $root && str_starts_with($path, $root . DIRECTORY_SEPARATOR) && is_file($path) ? $path : null;
}

function media_delete_confirm(): never
{
    $url = (string) ($_GET['file'] ?? '');
    if (!upload_path($url)) {
        not_found_screen();
    }
    $body = '<p>Are you sure you want to delete <code>' . h($url) . '</code>? Any page or post that uses it will show a broken link or image. This can’t be undone.</p>'
        . '<form method="post" action="' . h(admin_url('media_delete')) . '" class="form-actions">' . csrf_field()
        . '<input type="hidden" name="file" value="' . h($url) . '">'
        . '<button type="submit" class="button button-danger">Yes, delete this file</button>'
        . '<a class="button button-secondary" href="' . h(admin_url('media')) . '">No, go back</a></form>';
    admin_screen('Delete file: ' . h(basename($url)), $body, 'media');
}

function media_delete(): never
{
    $url = (string) ($_POST['file'] ?? '');
    $path = upload_path($url);
    if (!$path) {
        not_found_screen();
    }
    unlink($path);
    flash('Deleted ' . basename($url) . '.');
    go(admin_url('media'));
}

/** Image uploads from the blog editor. Responds with JSON for TinyMCE. */
function editor_image_upload(): never
{
    header('Content-Type: application/json');
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new RuntimeException('Upload with POST.');
        }
        $file = $_FILES['file'] ?? [];
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!str_starts_with(UPLOAD_TYPES[$ext] ?? '', 'image/')) {
            throw new RuntimeException('Only images can be added here. Use JPEG, PNG, GIF or WebP.');
        }
        echo json_encode(['location' => store_upload($file)]);
    } catch (RuntimeException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

/* =====================================================================
   Menu
   ===================================================================== */

function menu_lang(): string
{
    return ($_GET['lang'] ?? '') === 'es' ? 'es' : 'en';
}

function menu_form(array $errors = [], ?array $menu = null): never
{
    $lang = menu_lang();
    $menu ??= settings()[$lang === 'es' ? 'menu_es' : 'menu'];
    $count = count($menu);
    $rows = '';
    foreach ($menu as $i => $link) {
        $n = $i + 1;
        $label = h($link['label']);
        $rows .= '<fieldset class="menu-item"><legend>Menu item ' . $n . ' of ' . $count . ': ' . $label . '</legend><div class="menu-fields">'
            . text_field("menu[$i][label]", 'Link text', (string) $link['label'], ['id' => "menu-$i-label", 'error' => $errors["menu-$i-label"] ?? null])
            . text_field("menu[$i][url]", 'Link address', (string) $link['url'], ['id' => "menu-$i-url", 'spellcheck' => 'false', 'error' => $errors["menu-$i-url"] ?? null])
            . '</div><div class="menu-buttons">'
            . ($i > 0 ? '<button type="submit" class="button button-secondary" name="move" value="up:' . $i . '">Move up<span class="visually-hidden">: ' . $label . '</span></button>' : '')
            . ($i < $count - 1 ? '<button type="submit" class="button button-secondary" name="move" value="down:' . $i . '">Move down<span class="visually-hidden">: ' . $label . '</span></button>' : '')
            . '<button type="submit" class="button button-danger" name="move" value="remove:' . $i . '">Remove<span class="visually-hidden">: ' . $label . '</span></button>'
            . '</div></fieldset>';
    }
    $options = '<option value="">Choose a page</option>';
    foreach (all_items('pages') as $p) {
        if (slug_lang($p['slug']) !== $lang) {
            continue;
        }
        $options .= '<option value="' . h(page_path($p['slug'])) . '">' . h($p['title']) . '</option>';
    }
    $options .= '<option value="/blog/">Blog</option>';

    $body = '<p>These links appear in the main menu at the top of every page, in this order. Moving or removing an item saves the menu straight away.</p>'
        . '<form method="post" action="' . h(admin_url('menu', ['lang' => $lang])) . '">' . csrf_field() . $rows
        . '<div class="form-actions"><button type="submit" class="button" name="move" value="save">Save menu</button></div>'
        . '<h2>Add a link</h2>'
        . '<div class="field"><label for="add-page">Link to one of your pages</label><select id="add-page" name="add_page">' . $options . '</select></div>'
        . '<p>Or link to any address:</p>'
        . text_field('add_label', 'Link text', '', ['error' => $errors['add_label'] ?? null])
        . text_field('add_url', 'Link address', '', ['spellcheck' => 'false', 'hint' => 'For example <code>/contact-us/</code> or <code>https://example.com/page/</code>', 'error' => $errors['add_url'] ?? null])
        . '<button type="submit" class="button" name="move" value="add">Add to menu</button></form>';
    $switch = '<nav aria-label="Which menu"><ul class="period-list">'
        . '<li><a href="' . h(admin_url('menu')) . '"' . ($lang === 'en' ? ' aria-current="page"' : '') . '>English menu</a></li>'
        . '<li><a href="' . h(admin_url('menu', ['lang' => 'es'])) . '"' . ($lang === 'es' ? ' aria-current="page"' : '') . '>Spanish menu</a></li></ul></nav>'
        . ($lang === 'es' ? '<p>This menu is shown on Spanish pages (addresses starting with /es/).</p>' : '');
    admin_screen($lang === 'es' ? 'Spanish menu' : 'Menu', $switch . $body, 'menu', ['errors' => $errors]);
}

function menu_save(): never
{
    $menu = [];
    foreach ((array) ($_POST['menu'] ?? []) as $link) {
        $menu[] = ['label' => trim((string) ($link['label'] ?? '')), 'url' => trim((string) ($link['url'] ?? ''))];
    }
    [$op, $index] = array_pad(explode(':', (string) ($_POST['move'] ?? 'save')), 2, '0');
    $i = (int) $index;
    $message = 'Saved the menu.';
    $errors = [];

    if ($op === 'add') {
        $pageUrl = (string) ($_POST['add_page'] ?? '');
        $label = trim((string) ($_POST['add_label'] ?? ''));
        $url = trim((string) ($_POST['add_url'] ?? ''));
        if ($pageUrl !== '') {
            $title = 'Blog';
            foreach (all_items('pages') as $p) {
                if (page_path($p['slug']) === $pageUrl) {
                    $title = $p['title'];
                }
            }
            $menu[] = ['label' => $label !== '' ? $label : $title, 'url' => $pageUrl];
            $message = 'Added “' . end($menu)['label'] . '” to the end of the menu.';
        } elseif ($label === '' || $url === '') {
            if ($label === '') {
                $errors['add_label'] = 'Enter the link text, or choose one of your pages.';
            }
            if ($url === '') {
                $errors['add_url'] = 'Enter the link address, or choose one of your pages.';
            }
        } else {
            $menu[] = ['label' => $label, 'url' => $url];
            $message = "Added “{$label}” to the end of the menu.";
        }
    } elseif (($op === 'up' || $op === 'down') && isset($menu[$i])) {
        $j = $op === 'up' ? $i - 1 : $i + 1;
        if (isset($menu[$j])) {
            [$menu[$i], $menu[$j]] = [$menu[$j], $menu[$i]];
            $message = 'Moved “' . $menu[$j]['label'] . '” ' . $op . '. It is now item ' . ($j + 1) . ' of ' . count($menu) . '.';
        }
    } elseif ($op === 'remove' && isset($menu[$i])) {
        $message = 'Removed “' . $menu[$i]['label'] . '” from the menu. The page itself was not deleted.';
        array_splice($menu, $i, 1);
    }

    foreach ($menu as $k => $link) {
        if ($link['label'] === '') {
            $errors["menu-$k-label"] = 'Enter the link text for menu item ' . ($k + 1) . '.';
        }
        if ($link['url'] === '' || !preg_match('#^(/|https?://|mailto:|tel:)#', $link['url'])) {
            $errors["menu-$k-url"] = 'Enter an address for menu item ' . ($k + 1) . ' that starts with /, https://, mailto: or tel:';
        }
    }
    if ($errors) {
        menu_form($errors, $menu);
    }
    $s = settings();
    $s[menu_lang() === 'es' ? 'menu_es' : 'menu'] = $menu;
    save_settings($s);
    flash($message);
    go(admin_url('menu', menu_lang() === 'es' ? ['lang' => 'es'] : []));
}

/* =====================================================================
   Contact form messages
   ===================================================================== */

function message_files(): array
{
    $files = glob(MESSAGE_DIR . '/*.json') ?: [];
    rsort($files);
    return $files;
}

function messages_list(): never
{
    $files = message_files();
    $body = '<p>Every message sent with the contact form is kept here, even if the email couldn’t be sent.</p>';
    if (!$files) {
        $body .= '<p>No messages yet.</p>';
    }
    foreach ($files as $file) {
        $m = read_json($file) ?? [];
        $id = basename($file, '.json');
        $body .= '<article class="message" aria-labelledby="m-' . h($id) . '"><h2 id="m-' . h($id) . '">' . h($m['name'] ?? '') . ', ' . h(format_time($m['received'] ?? '')) . '</h2>'
            . '<dl><dt>Email</dt><dd><a href="mailto:' . h($m['email'] ?? '') . '">' . h($m['email'] ?? '') . '</a></dd>'
            . (($m['phone'] ?? '') !== '' ? '<dt>Phone</dt><dd><a href="tel:' . h(preg_replace('/[^0-9+]/', '', $m['phone'])) . '">' . h($m['phone']) . '</a></dd>' : '')
            . '<dt>Message</dt><dd>' . nl2br(h($m['message'] ?? '')) . '</dd>'
            . '<dt>Emailed to you</dt><dd>' . (!empty($m['emailed']) ? 'Yes' : '<strong>No.</strong> ' . h($m['error'] ?? '')) . '</dd></dl>'
            . '<form method="post" action="' . h(admin_url('messages')) . '">' . csrf_field()
            . '<input type="hidden" name="id" value="' . h($id) . '"><button type="submit" class="button-link">Delete message from ' . h($m['name'] ?? '') . '</button></form></article>';
    }
    admin_screen('Messages', $body, 'messages');
}

function message_delete(): never
{
    $id = (string) ($_POST['id'] ?? '');
    if (preg_match('/^[0-9_-]+-[0-9a-f]{6}$/', $id) && is_file(MESSAGE_DIR . "/$id.json")) {
        unlink(MESSAGE_DIR . "/$id.json");
        flash('Deleted the message.');
    }
    go(admin_url('messages'));
}

/* =====================================================================
   Site template: layout HTML, footer and stylesheet
   ===================================================================== */

function template_form(array $errors = [], array $values = []): never
{
    $layout = $values['layout'] ?? layout_template();
    $footer = $values['footer'] ?? settings()['footer_html'];
    $footerEs = $values['footer_es'] ?? settings()['footer_html_es'];
    $css = $values['css'] ?? (string) file_get_contents(STYLESHEET);
    $placeholders = '<code>{{content}}</code> (required: the page content), <code>{{menu}}</code>, <code>{{footer}}</code>, <code>{{title}}</code>, <code>{{page_title}}</code>, '
        . '<code>{{site_name}}</code>, <code>{{tagline}}</code>, <code>{{description}}</code>, <code>{{canonical}}</code>, <code>{{site_url}}</code>, <code>{{year}}</code>, '
        . '<code>{{lang}}</code> (en or es), <code>{{home_url}}</code>, <code>{{skip_link}}</code>, <code>{{menu_label}}</code>, <code>{{display_options}}</code> (the Display options button), '
        . '<code>{{language_switch}}</code> (link to the other language) and <code>{{alternates}}</code> (language links for search engines)';

    $body = '<p>The template is the HTML wrapped around every page: the head, header, menu and footer. Changes show on every page as soon as you save.</p>'
        . '<nav aria-label="On this screen"><ul><li><a href="#layout-section">Layout HTML</a></li><li><a href="#footer-section">Footer HTML</a></li><li><a href="#footer-es-section">Spanish footer HTML</a></li><li><a href="#css-section">Stylesheet</a></li></ul></nav>'

        . '<section id="layout-section" aria-labelledby="layout-heading"><h2 id="layout-heading">Layout HTML</h2>'
        . '<form method="post" action="' . h(admin_url('template')) . '" data-editor-form>' . csrf_field()
        . '<input type="hidden" name="part" value="layout">'
        . textarea_field('layout', 'Layout HTML', $layout, ['rows' => 30, 'code' => true, 'class' => 'field-wide', 'error' => $errors['layout'] ?? null,
            'hint' => "Placeholders filled in for each page: $placeholders."])
        . '<div class="form-actions"><button type="submit" class="button">Save layout</button>'
        . '<a href="' . h(admin_url('revisions', ['key' => 'template/layout'])) . '">Earlier versions of the layout</a></div></form></section>'

        . '<section id="footer-section" aria-labelledby="footer-heading"><h2 id="footer-heading">Footer HTML</h2>'
        . '<form method="post" action="' . h(admin_url('template')) . '" data-editor-form>' . csrf_field()
        . '<input type="hidden" name="part" value="footer">'
        . textarea_field('footer', 'Footer HTML', $footer, ['rows' => 16, 'code' => true, 'class' => 'field-wide',
            'hint' => 'Fills the <code>{{footer}}</code> placeholder at the bottom of every page.'])
        . '<div class="form-actions"><button type="submit" class="button">Save footer</button>'
        . '<a href="' . h(admin_url('revisions', ['key' => 'template/footer'])) . '">Earlier versions of the footer</a></div></form></section>'

        . '<section id="footer-es-section" aria-labelledby="footer-es-heading"><h2 id="footer-es-heading">Spanish footer HTML</h2>'
        . '<form method="post" action="' . h(admin_url('template')) . '" data-editor-form>' . csrf_field()
        . '<input type="hidden" name="part" value="footer_es">'
        . textarea_field('footer_es', 'Spanish footer HTML', $footerEs, ['rows' => 16, 'code' => true, 'class' => 'field-wide',
            'hint' => 'Used instead of the footer above on Spanish pages. Leave it empty to use the English footer.'])
        . '<div class="form-actions"><button type="submit" class="button">Save Spanish footer</button>'
        . '<a href="' . h(admin_url('revisions', ['key' => 'template/footer-es'])) . '">Earlier versions of the Spanish footer</a></div></form></section>'

        . '<section id="css-section" aria-labelledby="css-heading"><h2 id="css-heading">Stylesheet</h2>'
        . '<form method="post" action="' . h(admin_url('template')) . '" data-editor-form>' . csrf_field()
        . '<input type="hidden" name="part" value="css">'
        . textarea_field('css', 'Stylesheet (CSS)', $css, ['rows' => 30, 'code' => true, 'class' => 'field-wide',
            'hint' => 'Colors are set at the top, under <code>:root</code>. Check that text keeps a contrast ratio of at least 4.5 to 1.'])
        . '<div class="form-actions"><button type="submit" class="button">Save stylesheet</button>'
        . '<a href="' . h(admin_url('revisions', ['key' => 'template/css'])) . '">Earlier versions of the stylesheet</a></div></form></section>';
    admin_screen('Site template', $body, 'template', ['errors' => $errors]);
}

function template_save(): never
{
    $part = (string) ($_POST['part'] ?? '');
    $text = str_replace("\r\n", "\n", (string) ($_POST[$part] ?? ''));
    if ($part === 'layout') {
        if (!str_contains($text, '{{content}}')) {
            template_form(['layout' => 'The layout must include {{content}}, where each page’s content goes.'], ['layout' => $text]);
        }
        save_revision('template', 'layout', layout_template());
        write_file(CONTENT_DIR . '/layout.html', $text);
        flash('Saved the layout. It is now used on every page.');
        go(admin_url('template') . '#layout-section');
    }
    if ($part === 'footer') {
        $s = settings();
        save_revision('template', 'footer', $s['footer_html']);
        $s['footer_html'] = $text;
        save_settings($s);
        flash('Saved the footer.');
        go(admin_url('template') . '#footer-section');
    }
    if ($part === 'footer_es') {
        $s = settings();
        save_revision('template', 'footer-es', $s['footer_html_es']);
        $s['footer_html_es'] = $text;
        save_settings($s);
        flash('Saved the Spanish footer.');
        go(admin_url('template') . '#footer-es-section');
    }
    if ($part === 'css') {
        save_revision('template', 'css', (string) file_get_contents(STYLESHEET));
        write_file(STYLESHEET, $text);
        flash('Saved the stylesheet. If the website still looks the same, reload the page to see the change.');
        go(admin_url('template') . '#css-section');
    }
    not_found_screen();
}

/* =====================================================================
   Settings: site details, contact form email, password
   ===================================================================== */

function settings_form(array $errors = [], array $values = []): never
{
    $s = $values + settings();
    $c = $values + config();
    $secretHint = config()['graph_client_secret'] !== ''
        ? 'A secret is saved. Leave this empty to keep it, or paste a new one to replace it.'
        : 'From Certificates and secrets in your app registration. Copy the secret’s Value, not its ID.';

    $body = '<nav aria-label="On this screen"><ul><li><a href="#site-heading">Site details</a></li><li><a href="#features">Features</a></li><li><a href="#email-settings">Email sending</a></li><li><a href="#assistant-heading">Ask a question assistant</a></li><li><a href="#bookings-heading">Next opening on Book Services</a></li><li><a href="#people-heading">Microsoft 365 sign-in</a></li><li><a href="#password-heading">Change password</a></li></ul></nav>'

        . '<section aria-labelledby="site-heading"><h2 id="site-heading">Site details</h2>'
        . '<form method="post" action="' . h(admin_url('settings')) . '">' . csrf_field() . '<input type="hidden" name="part" value="site">'
        . text_field('site_name', 'Site name', (string) $s['site_name'], ['required' => true, 'error' => $errors['site_name'] ?? null])
        . text_field('tagline', 'Tagline', (string) $s['tagline'], ['hint' => 'Shown in the browser tab on the home page.'])
        . textarea_field('description', 'Site description', (string) $s['description'], ['rows' => 3, 'hint' => 'Used in search results for pages that don’t have their own description.'])
        . text_field('site_url', 'Site address', (string) $s['site_url'], ['type' => 'url', 'spellcheck' => 'false', 'error' => $errors['site_url'] ?? null,
            'hint' => 'The main address of the website, used in search engine links. For example https://example.com'])
        . textarea_field('mailing_address', 'Mailing address for email updates', (string) ($s['mailing_address'] ?? ''), ['rows' => 2,
            'hint' => 'Required by law (CAN-SPAM) at the bottom of every update email. A PO box works. It isn’t shown on the website.'])
        . text_field('posts_per_page', 'Blog posts per page', (string) $s['posts_per_page'], ['type' => 'number', 'inputmode' => 'numeric', 'error' => $errors['posts_per_page'] ?? null])
        . '<h3>Contact details</h3><p>Shown wherever the site tells people how to reach you: error messages, email footers, downloads and the contact page link.</p>'
        . text_field('legal_name', 'Full or legal name', (string) ($s['legal_name'] ?? ''), ['hint' => 'For the copyright line and emails, for example “Example Services LLC”. Leave empty to use the site name.'])
        . text_field('contact_email', 'Public contact email', (string) ($s['contact_email'] ?? ''), ['type' => 'email', 'error' => $errors['contact_email'] ?? null])
        . text_field('contact_phone', 'Public phone number', (string) ($s['contact_phone'] ?? ''), ['type' => 'tel', 'hint' => 'Written the way you want it read out, for example 555-010-0100.'])
        . text_field('contact_page', 'Contact page address', (string) ($s['contact_page'] ?? '/contact/'), ['error' => $errors['contact_page'] ?? null, 'hint' => 'For example /contact/'])
        . (feature_on('spanish') ? text_field('contact_page_es', 'Spanish contact page address', (string) ($s['contact_page_es'] ?? '/es/contacto/'), ['error' => $errors['contact_page_es'] ?? null]) : '')
        . '<h3>Look and time</h3>'
        . timezone_select('timezone', 'Time zone', (string) ($s['timezone'] ?? 'America/New_York'), 'For post dates and times shown on the site.')
        . radio_field('palette', 'Colors', array_map(fn($p) => $p['name'], PALETTES), (string) ($s['palette'] ?? 'purple'), ['hint' => 'Every set keeps the same high contrast in light, dark and high-contrast modes.'])
        . logo_select((string) ($s['logo'] ?? ''))
        . '<button type="submit" class="button">Save site details</button></form></section>'

        . '<section aria-labelledby="features-heading" id="features"><h2 id="features-heading">Features</h2>'
        . '<p>Turn parts of the site on or off. Turning something off hides its pages and control panel screens; nothing is deleted.</p>'
        . '<form method="post" action="' . h(admin_url('settings')) . '#features">' . csrf_field() . '<input type="hidden" name="part" value="features">'
        . implode('', array_map(fn($key, $f) => checkbox_field("f_$key", $f[0], feature_on($key), ['hint' => $f[1]]), array_keys(feature_labels()), feature_labels()))
        . '<button type="submit" class="button">Save features</button></form></section>'

        . '<section aria-labelledby="email-heading" id="email-settings"><h2 id="email-heading">Email sending</h2>'
        . '<p>The contact form, email updates and the monthly link report are sent one of two ways: through the web server’s own mail program (works on most hosts with nothing to set up, '
        . 'though some hosts need the From address to be a mailbox on the same account), or through Microsoft 365 using an app registration with the <strong>Mail.Send</strong> permission. README.md has the steps for Microsoft 365.</p>'
        . '<p>Status: <strong>' . (mail_configured() ? 'Set up' : 'Not set up yet. Messages are saved but not emailed.') . '</strong></p>'
        . '<form method="post" action="' . h(admin_url('settings')) . '#email-settings">' . csrf_field() . '<input type="hidden" name="part" value="email">'
        . radio_field('mail_method', 'Send email through', ['php' => 'The web server’s own mail program (PHP mail)', 'graph' => 'Microsoft 365 (Microsoft Graph)'], (string) ($c['mail_method'] ?? 'php'))
        . text_field('mail_from', 'From address (PHP mail)', (string) ($c['mail_from'] ?? ''), ['type' => 'email', 'autocomplete' => 'off', 'error' => $errors['mail_from'] ?? null,
            'hint' => 'For example hello@' . h(site_host()) . '. On cPanel, create this mailbox under Email Accounts first so other mail servers trust it.'])
        . text_field('mail_from_name', 'From name', (string) ($c['mail_from_name'] ?? ''), ['autocomplete' => 'off', 'hint' => 'Leave empty to use the site name.'])
        . text_field('contact_recipients', 'Send messages to', (string) $c['contact_recipients'], ['autocomplete' => 'off', 'error' => $errors['contact_recipients'] ?? null,
            'hint' => 'Where contact form messages and reports go. One or more email addresses, separated by commas.'])
        . text_field('contact_subject', 'Contact form email subject', (string) $c['contact_subject'])
        . '<h3>Microsoft 365 app</h3><p>Needed for Microsoft 365 sending, Microsoft Bookings and Microsoft 365 sign-in. Leave empty if you don’t use Microsoft 365.</p>'
        . text_field('graph_tenant_id', 'Directory (tenant) ID', (string) $c['graph_tenant_id'], ['spellcheck' => 'false', 'autocomplete' => 'off', 'error' => $errors['graph_tenant_id'] ?? null,
            'hint' => 'On the Overview page of your app registration.'])
        . text_field('graph_client_id', 'Application (client) ID', (string) $c['graph_client_id'], ['spellcheck' => 'false', 'autocomplete' => 'off', 'error' => $errors['graph_client_id'] ?? null])
        . text_field('graph_client_secret', 'Client secret value', '', ['type' => 'password', 'autocomplete' => 'off', 'hint' => $secretHint])
        . text_field('graph_sender', 'Send from this mailbox (Microsoft 365)', (string) $c['graph_sender'], ['type' => 'email', 'autocomplete' => 'off', 'error' => $errors['graph_sender'] ?? null,
            'hint' => 'A mailbox in your Microsoft 365 organization, for example hello@yourcompany.com.'])
        . '<div class="form-actions"><button type="submit" class="button" name="then" value="save">Save email settings</button>'
        . '<button type="submit" class="button button-secondary" name="then" value="test">Save and send a test email</button></div></form></section>'

        . assistant_settings_html()
        . bookings_settings_html($errors)
        . people_settings_html($errors)
        . '<section aria-labelledby="password-heading"><h2 id="password-heading">Change password</h2>'
        . '<form method="post" action="' . h(admin_url('settings')) . '#password-heading">' . csrf_field() . '<input type="hidden" name="part" value="password">'
        . '<input type="text" name="username" value="admin" autocomplete="username" aria-label="Username" hidden>'
        . text_field('current_password', 'Current password', '', ['type' => 'password', 'autocomplete' => 'current-password', 'required' => true, 'error' => $errors['current_password'] ?? null])
        . text_field('new_password', 'New password', '', ['type' => 'password', 'autocomplete' => 'new-password', 'required' => true, 'error' => $errors['new_password'] ?? null, 'hint' => 'At least 12 characters.'])
        . text_field('confirm_password', 'Type the new password again', '', ['type' => 'password', 'autocomplete' => 'new-password', 'required' => true, 'error' => $errors['confirm_password'] ?? null])
        . '<button type="submit" class="button">Change password</button></form></section>';
    admin_screen('Settings', $body, 'settings', ['errors' => $errors]);
}

function settings_save(): never
{
    $part = (string) ($_POST['part'] ?? '');
    $errors = [];

    if ($part === 'site') {
        $s = settings();
        $v = [
            'site_name' => trim((string) ($_POST['site_name'] ?? '')),
            'tagline' => trim((string) ($_POST['tagline'] ?? '')),
            'description' => trim((string) ($_POST['description'] ?? '')),
            'posts_per_page' => (int) ($_POST['posts_per_page'] ?? 10),
            'site_url' => rtrim(trim((string) ($_POST['site_url'] ?? '')), '/'),
            'mailing_address' => trim((string) ($_POST['mailing_address'] ?? '')),
            'legal_name' => trim((string) ($_POST['legal_name'] ?? '')),
            'contact_email' => trim((string) ($_POST['contact_email'] ?? '')),
            'contact_phone' => trim((string) ($_POST['contact_phone'] ?? '')),
            'contact_page' => trim((string) ($_POST['contact_page'] ?? '/contact/')),
            'contact_page_es' => trim((string) ($_POST['contact_page_es'] ?? ($s['contact_page_es'] ?? '/es/contacto/'))),
            'timezone' => trim((string) ($_POST['timezone'] ?? 'America/New_York')),
            'palette' => (string) ($_POST['palette'] ?? 'purple'),
            'logo' => trim((string) ($_POST['logo'] ?? '')),
        ];
        if ($v['contact_email'] !== '' && !filter_var($v['contact_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['contact_email'] = 'Enter the contact email in the right format, like name@example.com.';
        }
        foreach (['contact_page', 'contact_page_es'] as $k) {
            if (!preg_match('#^/[a-z0-9/-]*$#', $v[$k])) {
                $errors[$k] = 'Enter a page address on this site, starting with a slash, for example /contact/';
            }
        }
        if (!in_array($v['timezone'], DateTimeZone::listIdentifiers(), true)) {
            $errors['timezone'] = 'Choose a time zone from the list.';
        }
        if (!isset(PALETTES[$v['palette']])) {
            $v['palette'] = 'purple';
        }
        if ($v['logo'] !== '' && !in_array($v['logo'], array_column(array_filter(uploaded_files(), fn($f) => $f['image']), 'url'), true)) {
            $errors['logo'] = 'Choose a logo from the images you have uploaded, or none.';
        }
        if (!preg_match('#^https?://[a-z0-9.-]+(:\d+)?$#i', $v['site_url'])) {
            $errors['site_url'] = 'Enter the site address starting with https://, with nothing after the domain, for example https://example.com';
        }
        if ($v['site_name'] === '') {
            $errors['site_name'] = 'Enter the site name.';
        }
        if ($v['posts_per_page'] < 1 || $v['posts_per_page'] > 100) {
            $errors['posts_per_page'] = 'Enter a number of posts per page between 1 and 100.';
        }
        if ($errors) {
            settings_form($errors, $v);
        }
        save_settings(array_merge(settings(), $v));
        flash('Saved the site details.');
        go(admin_url('settings'));
    }

    if ($part === 'features') {
        $features = [];
        foreach (array_keys(feature_labels()) as $key) {
            $features[$key] = !empty($_POST["f_$key"]);
        }
        save_settings(array_merge(settings(), ['features' => $features]));
        flash('Saved the features. The menu and pages have been updated to match.');
        go(admin_url('settings') . '#features');
    }

    if ($part === 'email') {
        $v = [];
        foreach (['graph_tenant_id', 'graph_client_id', 'graph_sender', 'contact_recipients', 'contact_subject', 'mail_from', 'mail_from_name'] as $k) {
            $v[$k] = trim((string) ($_POST[$k] ?? ''));
        }
        $v['mail_method'] = ($_POST['mail_method'] ?? 'php') === 'graph' ? 'graph' : 'php';
        if ($v['mail_method'] === 'php' && $v['mail_from'] !== '' && !filter_var($v['mail_from'], FILTER_VALIDATE_EMAIL)) {
            $errors['mail_from'] = 'Enter the From address as an email address.';
        }
        if ($v['mail_method'] === 'php' && $v['mail_from'] === '') {
            $errors['mail_from'] = 'Enter the address emails should come from.';
        }
        if ($v['mail_method'] === 'graph' && $v['graph_sender'] === '') {
            $errors['graph_sender'] = 'Enter the Microsoft 365 mailbox to send from.';
        }
        if ($v['graph_tenant_id'] !== '' && !preg_match('/^[0-9a-f-]{36}$|^[a-z0-9.-]+\.[a-z]{2,}$/i', $v['graph_tenant_id'])) {
            $errors['graph_tenant_id'] = 'The tenant ID should look like 1a2b3c4d-1234-5678-9abc-1234567890ab.';
        }
        if ($v['graph_client_id'] !== '' && !preg_match('/^[0-9a-f-]{36}$/i', $v['graph_client_id'])) {
            $errors['graph_client_id'] = 'The client ID should look like 1a2b3c4d-1234-5678-9abc-1234567890ab.';
        }
        if ($v['graph_sender'] !== '' && !filter_var($v['graph_sender'], FILTER_VALIDATE_EMAIL)) {
            $errors['graph_sender'] = 'Enter the sending mailbox as an email address.';
        }
        foreach (preg_split('/[\s,;]+/', $v['contact_recipients'], -1, PREG_SPLIT_NO_EMPTY) as $addr) {
            if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                $errors['contact_recipients'] = "“{$addr}” isn’t a valid email address.";
            }
        }
        if ($v['contact_subject'] === '') {
            $v['contact_subject'] = 'New message from the website';
        }
        if ($errors) {
            settings_form($errors, $v);
        }
        $secret = trim((string) ($_POST['graph_client_secret'] ?? ''));
        if ($secret !== '') {
            $v['graph_client_secret'] = $secret;
        }
        save_config($v);
        @unlink(DATA_DIR . '/graph-token.php');

        if (($_POST['then'] ?? '') === 'test') {
            try {
                send_mail_owner('Test email from your website', '<p>This test email shows that your website can send email' . (mail_method() === 'graph' ? ' through Microsoft 365.' : ' through the server’s mail program.') . '</p>');
                flash('Saved. A test email was sent to ' . config()['contact_recipients'] . '. Check that inbox to make sure it arrived.');
            } catch (Throwable $e) {
                flash('Your settings were saved, but the test email could not be sent. ' . $e->getMessage(), 'error');
            }
        } else {
            flash('Saved the email settings.');
        }
        go(admin_url('settings') . '#email-settings');
    }

    if ($part === 'assistant') {
        $key = trim((string) ($_POST['anthropic_api_key'] ?? ''));
        $changes = ['assistant_enabled' => !empty($_POST['assistant_enabled'])];
        if ($key !== '') {
            $changes['anthropic_api_key'] = $key;
        }
        save_config($changes);
        flash(assistant_enabled() ? 'The assistant is on wherever [ask-assistant] is used.' : 'Saved. The assistant is off' . ((config()['anthropic_api_key'] ?? '') === '' ? ' until you add an API key.' : '.'));
        go(admin_url('settings') . '#assistant-heading');
    }
    if ($part === 'bookings') {
        bookings_settings_save();
    }
    if ($part === 'people') {
        people_settings_save();
    }
    if ($part === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        if (!password_verify($current, config()['password_hash'])) {
            $errors['current_password'] = 'Your current password is not right.';
        }
        if (strlen($new) < 12) {
            $errors['new_password'] = 'Choose a new password that is at least 12 characters long.';
        } elseif ($new !== ($_POST['confirm_password'] ?? '')) {
            $errors['confirm_password'] = 'The two new passwords do not match.';
        }
        if ($errors) {
            settings_form($errors);
        }
        // Anyone else signed in with the old password is signed out; this session carries on.
        save_config(['password_hash' => password_hash($new, PASSWORD_DEFAULT), 'sessions_valid_from' => time()]);
        session_regenerate_id(true);
        $_SESSION['login_at'] = time();
        flash('Your password has been changed. Anyone else who was signed in with the old password has been signed out.');
        go(admin_url('settings'));
    }
    not_found_screen();
}
