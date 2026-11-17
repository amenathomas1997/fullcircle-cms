<?php
/**
 * The Import screen (owner only): three ways to bring an old website in as drafts, a preview
 * before each, a report after, and the last five imports. Everything it does lives in
 * lib/features/importers.php; this file is only the screens. Loaded when the screen is opened.
 *
 *   GET  ?a=import                     the three forms and the recent imports
 *   POST ?a=import&do=preview          take the file or crawl the site; then the preview
 *   GET  ?a=import&preview=<token>     "This file contains …. Import as drafts?"
 *   POST ?a=import&do=run              the real import, then the report
 *   POST ?a=import&do=cancel           drop a preview
 *   GET  ?a=import&report=<id>         what was created and skipped, with edit links
 *   POST ?a=import&do=redirects        add the redirects an import offered
 */

declare(strict_types=1);

function importers_screen(bool $post): never
{
    $do = (string) ($_GET['do'] ?? '');
    if ($post) {
        match ($do) {
            'preview' => importers_preview_start(),
            'run' => importers_run(),
            'cancel' => importers_cancel(),
            'redirects' => importers_add_redirects(),
            default => not_found_screen(),
        };
    }
    if (isset($_GET['preview'])) {
        importers_preview_screen((string) $_GET['preview']);
    }
    if (isset($_GET['report'])) {
        importers_report_screen((string) $_GET['report']);
    }
    import_tidy_work();
    importers_home();
}

/* ---------- The screen ---------- */

function importers_home(array $errors = [], array $v = []): never
{
    $limit = format_bytes(min(IMPORT_UPLOAD_MAX, upload_limit()));
    $action = h(admin_url('import', ['do' => 'preview']));
    $body = '<p>Bring an existing website in. Nothing goes live: every page and post is saved as a <strong>draft</strong> for you to review, a page or post that already exists here is never overwritten, and you get a report of what was created and what was skipped. First you see what the file or site holds; the import only happens when you confirm.</p>'
        . '<ul><li><a href="#wordpress">A WordPress export file</a></li><li><a href="#zip">A zip of HTML files</a></li><li><a href="#site">A live website address</a></li><li><a href="#recent">Recent imports</a></li></ul>'

        . '<h2 id="wordpress">From a WordPress export file</h2>'
        . '<p>In WordPress, go to Tools &gt; Export, choose “All content” and download the file. It is an XML file. Pages, posts (with their dates and categories) and media come in; the media is downloaded from the old site, so it should still be online.</p>'
        . '<form method="post" action="' . $action . '" enctype="multipart/form-data"><input type="hidden" name="kind" value="wordpress">' . csrf_field()
        . text_field('wxr', 'WordPress export file (.xml)', '', ['type' => 'file', 'accept' => '.xml,text/xml,application/xml', 'hint' => 'Up to ' . $limit . '.', 'error' => $errors['wxr'] ?? ''])
        . '<div class="form-actions"><button type="submit" class="button">Check the export file</button></div></form>'

        . '<h2 id="zip">From a zip of HTML files</h2>'
        . '<p>For a Google Sites Takeout or a hand-made site: a zip of .html files. Each file becomes a page, titled from its <code>&lt;title&gt;</code> or first heading. Files in a folder become pages under the folder’s own page (its index.html), and images the pages use are copied out of the zip.</p>'
        . '<form method="post" action="' . $action . '" enctype="multipart/form-data"><input type="hidden" name="kind" value="zip">' . csrf_field()
        . text_field('zip', 'Zip file of HTML pages', '', ['type' => 'file', 'accept' => '.zip,application/zip', 'hint' => 'Up to ' . $limit . '.', 'error' => $errors['zip'] ?? ''])
        . '<div class="form-actions"><button type="submit" class="button">Check the zip file</button></div></form>'

        . '<h2 id="site">From a live website address</h2>'
        . '<p>For Wix, Squarespace, Google Sites and other builders that have no export: enter the home page address. The importer reads the home page, follows the links to other pages on the same site (up to ' . IMPORT_CRAWL_MAX . ' pages) and keeps the main content of each. <strong>This can take a few minutes</strong>; the screen will not change until it has finished, so leave it be. Pages it cannot read are listed in the report.</p>'
        . '<form method="post" action="' . $action . '"><input type="hidden" name="kind" value="site">' . csrf_field()
        . text_field('site_url', 'Home page address', (string) ($v['site_url'] ?? ''), ['type' => 'url', 'autocomplete' => 'url', 'hint' => 'For example https://www.example.com/', 'error' => $errors['site_url'] ?? ''])
        . '<div class="form-actions"><button type="submit" class="button">Read the website</button></div></form>'

        . '<h2 id="recent">Recent imports</h2>';
    $logs = import_logs(5);
    if (!$logs) {
        $body .= '<p>Nothing has been imported yet.</p>';
    } else {
        $body .= '<div class="table-wrap"><table><caption class="visually-hidden">The last five imports, newest first</caption><thead><tr><th scope="col">When</th><th scope="col">From</th><th scope="col">Created</th><th scope="col">Skipped</th><th scope="col">Report</th></tr></thead><tbody>';
        foreach ($logs as $log) {
            $created = importers_count_words($log['created'] ?? []);
            $body .= '<tr><th scope="row">' . h(format_time((string) $log['time'])) . '</th><td>' . h(importers_kind_label((string) $log['kind'])) . ': ' . h((string) $log['source']) . '</td>'
                . '<td>' . h($created) . '</td><td>' . count($log['skipped'] ?? []) . '</td>'
                . '<td><a href="' . h(admin_url('import', ['report' => $log['id']])) . '">Report<span class="visually-hidden"> of the import on ' . h(format_time((string) $log['time'])) . '</span></a></td></tr>';
        }
        $body .= '</tbody></table></div>';
    }
    admin_screen('Import', $body, 'import', ['errors' => $errors]);
}

function importers_kind_label(string $kind): string
{
    return ['wordpress' => 'WordPress export', 'zip' => 'Zip of HTML files', 'site' => 'Website'][$kind] ?? $kind;
}

/** "3 pages, 2 posts and 5 media files". */
function importers_count_words(array $created): string
{
    $n = ['pages' => 0, 'posts' => 0, 'media' => 0];
    foreach ($created as $c) {
        $n[$c['type']] = ($n[$c['type']] ?? 0) + 1;
    }
    return importers_plural_list($n);
}

function importers_plural_list(array $n): string
{
    $words = [];
    foreach (['pages' => ['page', 'pages'], 'posts' => ['post', 'posts'], 'media' => ['media file', 'media files']] as $key => [$one, $many]) {
        if (($n[$key] ?? 0) > 0) {
            $words[] = $n[$key] . ' ' . ($n[$key] === 1 ? $one : $many);
        }
    }
    if (!$words) {
        return 'nothing';
    }
    $last = array_pop($words);
    return $words ? implode(', ', $words) . ' and ' . $last : $last;
}

/* ---------- Preview (the dry run) ---------- */

/** Take the upload or crawl the site, keep what was found in a work folder, and show the preview. */
function importers_preview_start(): never
{
    $kind = (string) ($_POST['kind'] ?? '');
    if (!in_array($kind, ['wordpress', 'zip', 'site'], true)) {
        not_found_screen();
    }
    set_time_limit(600);
    ignore_user_abort(true);
    $meta = ['kind' => $kind, 'started' => date('c')];
    if ($kind === 'site') {
        $url = trim((string) ($_POST['site_url'] ?? ''));
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL) || (string) parse_url($url, PHP_URL_HOST) === '') {
            importers_home(['site_url' => 'Enter the website’s home page address, for example https://www.example.com/'], ['site_url' => $url]);
        }
        [$token, $dir] = import_new_work();
        try {
            $crawl = import_site_crawl($url, $dir);
        } catch (Throwable $e) {
            import_remove_tree($dir);
            throw new RuntimeException('The website could not be read: ' . $e->getMessage());
        }
        if (!$crawl['pages']) {
            import_remove_tree($dir);
            $why = $crawl['failed'][0]['reason'] ?? 'nothing came back';
            importers_home(['site_url' => 'The home page could not be read (' . rtrim($why, '.') . '). Check the address and that the site is online.'], ['site_url' => $url]);
        }
        $meta += ['source' => $url, 'crawl' => $crawl,
            'preview' => ['counts' => ['pages' => count($crawl['pages']), 'posts' => 0, 'media' => 0, 'other' => 0], 'titles' => array_slice(array_filter(array_column($crawl['pages'], 'title')), 0, 8), 'site_url' => $url, 'site_name' => '']];
    } else {
        $field = $kind === 'wordpress' ? 'wxr' : 'zip';
        $file = $_FILES[$field] ?? null;
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            importers_home([$field => 'Choose the ' . ($kind === 'wordpress' ? 'export' : 'zip') . ' file first.']);
        }
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE || (int) $file['size'] > IMPORT_UPLOAD_MAX) {
            importers_home([$field => 'That file is bigger than this server accepts (' . format_bytes(min(IMPORT_UPLOAD_MAX, upload_limit())) . '). Ask your host to raise upload_max_filesize and post_max_size, or split the export.']);
        }
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            importers_home([$field => 'The upload didn’t finish. Please try again.']);
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if ($kind === 'wordpress' && $ext !== 'xml') {
            importers_home([$field => 'A WordPress export is an .xml file (from Tools > Export).']);
        }
        if ($kind === 'zip' && $ext !== 'zip') {
            importers_home([$field => 'Choose a .zip file.']);
        }
        [$token, $dir] = import_new_work();
        $source = $dir . '/source.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $source)) {
            import_remove_tree($dir);
            throw new RuntimeException('The file could not be kept. Check that data/ is writable.');
        }
        try {
            $meta['preview'] = $kind === 'wordpress' ? import_wxr_preview($source) : import_zip_preview($source);
        } catch (Throwable $e) {
            import_remove_tree($dir);
            importers_home([$field => $e instanceof RuntimeException ? $e->getMessage() : 'That file could not be read.']);
        }
        $meta['source'] = (string) $file['name'];
        if ($kind === 'wordpress' && array_sum($meta['preview']['counts']) === 0) {
            import_remove_tree($dir);
            importers_home([$field => 'That file holds no pages, posts or media. Is it a WordPress export (Tools > Export, “All content”)?']);
        }
    }
    write_json($dir . '/meta.json', $meta);
    go(admin_url('import', ['preview' => $token]));
}

function importers_preview_screen(string $token): never
{
    $dir = import_work_dir($token);
    $meta = $dir ? read_json($dir . '/meta.json') : null;
    if (!$meta) {
        flash('That preview has expired. Start the import again.', 'error');
        go(admin_url('import'));
    }
    $p = $meta['preview'];
    $c = $p['counts'];
    $what = ['wordpress' => 'This export file', 'zip' => 'This zip file', 'site' => 'This website'][$meta['kind']];
    $total = $c['pages'] + $c['posts'] + $c['media'];
    $body = '<p>' . h($what) . ' (<code>' . h((string) $meta['source']) . '</code>' . ($p['site_name'] !== '' ? ', “' . h($p['site_name']) . '”' : '') . ') contains <strong>' . h(importers_plural_list($c)) . '</strong>.'
        . ($c['other'] > 0 ? ' ' . $c['other'] . ' other ' . ($c['other'] === 1 ? 'item' : 'items') . ' (custom post types, menus and the like) will be left out.' : '') . '</p>';
    if ($meta['kind'] === 'wordpress' && $p['site_url'] !== '') {
        $body .= '<p>Media will be downloaded from <code>' . h($p['site_url']) . '</code>, so that site should still be online.</p>';
    }
    if ($meta['kind'] === 'site') {
        $crawl = $meta['crawl'];
        $body .= '<h2>Pages found</h2><ul>';
        foreach ($crawl['pages'] as $pg) {
            $body .= '<li>' . h($pg['title'] !== '' ? $pg['title'] : '(no title)') . ' <span class="hint">' . h($pg['url']) . '</span></li>';
        }
        $body .= '</ul>';
        if ($crawl['failed']) {
            $body .= '<h2>Addresses that could not be read</h2><ul>';
            foreach ($crawl['failed'] as $f) {
                $body .= '<li>' . h($f['url']) . ': ' . h($f['reason']) . '</li>';
            }
            $body .= '</ul>';
        }
        if (!empty($crawl['more'])) {
            $body .= '<p>The crawl stopped at ' . IMPORT_CRAWL_MAX . ' pages; ' . (int) $crawl['more'] . ' more linked ' . ($crawl['more'] === 1 ? 'address was' : 'addresses were') . ' not followed.</p>';
        }
    } elseif ($p['titles']) {
        $body .= '<h2>Some of the titles</h2><ul>';
        foreach ($p['titles'] as $t) {
            $body .= '<li>' . h($t) . '</li>';
        }
        $body .= '</ul>';
    }
    $body .= '<h2>Import as drafts?</h2>'
        . '<p>Pages and posts are saved as drafts, so nothing shows on the website until you publish it. A page or post whose address is already in use here is skipped, never replaced. '
        . ($meta['kind'] === 'site' || ($meta['kind'] === 'wordpress' && $c['media'] > 0) ? 'Media is downloaded now, which can take a few minutes for a big site; the screen will not change until it has finished. ' : '')
        . 'Afterwards you get a report with a link to each new draft.</p>'
        . '<form method="post" action="' . h(admin_url('import', ['do' => 'run'])) . '" class="form-actions">' . csrf_field() . '<input type="hidden" name="token" value="' . h($token) . '">'
        . '<button type="submit" class="button">Import ' . ($total === 1 ? 'it' : h(importers_plural_list(['pages' => $c['pages'], 'posts' => $c['posts']]) !== 'nothing' ? importers_plural_list(['pages' => $c['pages'], 'posts' => $c['posts']]) : 'the media')) . ' as drafts</button></form>'
        . '<form method="post" action="' . h(admin_url('import', ['do' => 'cancel'])) . '" class="form-actions">' . csrf_field() . '<input type="hidden" name="token" value="' . h($token) . '">'
        . '<button type="submit" class="button button-secondary">Cancel and go back</button></form>';
    admin_screen('Preview the import', $body, 'import');
}

function importers_cancel(): never
{
    $dir = import_work_dir((string) ($_POST['token'] ?? ''));
    if ($dir) {
        import_remove_tree($dir);
    }
    flash('The import was cancelled. Nothing was changed.');
    go(admin_url('import'));
}

/* ---------- The real import ---------- */

function importers_run(): never
{
    $token = (string) ($_POST['token'] ?? '');
    $dir = import_work_dir($token);
    $meta = $dir ? read_json($dir . '/meta.json') : null;
    if (!$meta) {
        flash('That preview has expired. Start the import again.', 'error');
        go(admin_url('import'));
    }
    set_time_limit(600);
    ignore_user_abort(true);
    $ctx = import_context((string) $meta['kind'], (string) $meta['source'], $meta['kind'] === 'site' ? import_host((string) $meta['source']) : import_host((string) ($meta['preview']['site_url'] ?? '')));
    try {
        match ($meta['kind']) {
            'wordpress' => import_wxr_run($dir . '/source.xml', (string) $meta['source'], $ctx),
            'zip' => import_zip_run($dir . '/source.zip', $ctx),
            'site' => import_site_run($meta['crawl'], $dir, $ctx),
        };
    } catch (Throwable $e) {
        error_log('Import failed: ' . $e);
        $ctx['notes'][] = 'The import stopped early: ' . $e->getMessage() . ' What was created before that is listed here.';
    }
    import_log_write($ctx);
    import_remove_tree($dir);
    $n = count(array_filter($ctx['created'], fn($c) => $c['type'] !== 'media'));
    import_activity('imported ' . importers_count_words($ctx['created']) . ' as drafts', importers_kind_label($ctx['kind']) . ': ' . $ctx['source']);
    flash('Imported ' . importers_count_words($ctx['created']) . ($n > 0 ? ' as drafts.' : '.') . ($ctx['skipped'] ? ' ' . count($ctx['skipped']) . ' ' . (count($ctx['skipped']) === 1 ? 'item was' : 'items were') . ' skipped; the report says why.' : '') . ($n === 0 ? ' Nothing new to review.' : ' Review each draft and publish it when it reads well.'));
    go(admin_url('import', ['report' => $ctx['id']]));
}

/* ---------- The report ---------- */

function importers_report_screen(string $id): never
{
    $log = import_log_read($id);
    if (!$log) {
        not_found_screen();
    }
    $pages = array_values(array_filter($log['created'], fn($c) => $c['type'] === 'pages'));
    $posts = array_values(array_filter($log['created'], fn($c) => $c['type'] === 'posts'));
    $media = array_values(array_filter($log['created'], fn($c) => $c['type'] === 'media'));
    $body = '<p>' . h(importers_kind_label((string) $log['kind'])) . ' <code>' . h((string) $log['source']) . '</code>, imported ' . h(format_time((string) $log['time'])) . ($log['by'] !== '' ? ' by ' . h((string) $log['by']) : '') . '. '
        . 'Created ' . h(importers_count_words($log['created'])) . '; skipped ' . count($log['skipped']) . '. Everything created is a draft until you publish it.</p>';

    // Redirects: offered once, while there are any to add.
    $pending = import_pending_redirects($log);
    if ($pending) {
        $n = count($pending);
        $body .= '<div class="notice" role="region" aria-labelledby="redirects-title"><h2 id="redirects-title">Keep old links working</h2>'
            . '<p>' . $n . ' imported ' . ($n === 1 ? 'page or post has an address that differs' : 'pages and posts have addresses that differ') . ' from the old site’s. A redirect sends visitors (and search engines) from the old address to the new one. You can see and change them on the Redirects screen.</p>'
            . '<form method="post" action="' . h(admin_url('import', ['do' => 'redirects'])) . '" class="form-actions">' . csrf_field() . '<input type="hidden" name="id" value="' . h($id) . '">'
            . '<button type="submit" class="button">Add redirects for ' . $n . ' old ' . ($n === 1 ? 'address' : 'addresses') . '</button></form></div>';
    } elseif (!empty($log['redirects_added'])) {
        $body .= '<p>Redirects from the old addresses were added. See them on the <a href="' . h(admin_url('redirects')) . '">Redirects screen</a>.</p>';
    }

    $table = function (string $heading, string $idAttr, array $rows, string $editAction) use (&$body): void {
        if (!$rows) {
            return;
        }
        $body .= '<h2 id="' . $idAttr . '">' . h($heading) . ' (' . count($rows) . ')</h2><div class="table-wrap"><table><caption class="visually-hidden">' . h($heading) . ' created by this import</caption>'
            . '<thead><tr><th scope="col">Title</th><th scope="col">New address</th><th scope="col">Old address</th><th scope="col">Actions</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $exists = get_item($r['type'], $r['slug']) !== null;
            $body .= '<tr><th scope="row">' . h($r['title']) . '</th><td><code>' . h($r['url']) . '</code></td><td>' . ($r['from'] !== '' ? '<code>' . h($r['from']) . '</code>' : '') . '</td>'
                . '<td class="actions">' . ($exists ? '<a href="' . h(admin_url($editAction, ['slug' => $r['slug']])) . '">Edit<span class="visually-hidden"> ' . h($r['title']) . '</span></a>' : '<span class="hint">Since deleted</span>') . '</td></tr>';
        }
        $body .= '</tbody></table></div>';
    };
    $table('Pages', 'pages', $pages, 'page');
    $table('Blog posts', 'posts', $posts, 'post');

    if ($media) {
        $body .= '<h2 id="media">Media files (' . count($media) . ')</h2><p>Saved under <code>/uploads/import/</code>; links and images in the imported pages now point at these copies. They are listed on <a href="' . h(admin_url('media')) . '">Images and files</a> too.</p><ul>';
        foreach ($media as $m) {
            $body .= '<li><code>' . h($m['url']) . '</code> <span class="hint">from ' . h($m['from']) . '</span></li>';
        }
        $body .= '</ul>';
    }
    if ($log['skipped']) {
        $body .= '<h2 id="skipped">Skipped (' . count($log['skipped']) . ')</h2><ul>';
        foreach ($log['skipped'] as $s) {
            $body .= '<li><strong>' . h($s['what']) . '</strong>: ' . h($s['reason']) . '</li>';
        }
        $body .= '</ul>';
    }
    if ($log['notes']) {
        $body .= '<h2 id="notes">Notes</h2><ul>';
        foreach ($log['notes'] as $n) {
            $body .= '<li>' . h($n) . '</li>';
        }
        $body .= '</ul>';
    }
    $body .= '<p><a href="' . h(admin_url('import')) . '">Back to Import</a></p>';
    admin_screen('Import report', $body, 'import');
}

function importers_add_redirects(): never
{
    $id = (string) ($_POST['id'] ?? '');
    $log = import_log_read($id);
    if (!$log) {
        not_found_screen();
    }
    $n = import_add_redirects($log);
    $log['redirects_added'] = true;
    write_json(IMPORT_DIR . '/' . $id . '.json', $log);
    import_activity('added ' . $n . ' ' . ($n === 1 ? 'redirect' : 'redirects') . ' from an import', $log['source']);
    flash($n > 0 ? 'Added ' . $n . ' ' . ($n === 1 ? 'redirect' : 'redirects') . '. Visitors who follow the old addresses now land on the new pages.' : 'Those redirects were already in place; nothing was added.');
    go(admin_url('import', ['report' => $id]));
}
