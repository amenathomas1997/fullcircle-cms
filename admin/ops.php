<?php
/**
 * Control panel screens for the operations layer: System, Sites and Backups.
 * Loaded by lib/features/ops.php only when one of those screens is opened.
 */

declare(strict_types=1);

/* =====================================================================
   Shared
   ===================================================================== */

/** Bytes used by a folder, following no links. */
function dir_size(string $dir): int
{
    if (!is_dir($dir)) {
        return 0;
    }
    $total = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        $total += $f->isFile() ? $f->getSize() : 0;
    }
    return $total;
}

/** Files inside data/ that never leave the server, and never come back from an archive: secrets, people and caches. */
function ops_private_file(string $relative): bool
{
    return (bool) preg_match('#^data/(config\.php|graph-token\.php|deploy-key(\.pub)?|deploy-known-hosts|users\.json|users/|stats/salt-[^/]+|login-attempts\.json|contact-rate\.json|cache/|setup-code\.txt|outbox/\.lock)#', $relative);
}

/** Is this path inside an archive one we would ever write back? Only the four site folders, nothing that climbs out. */
function ops_safe_entry(string $relative): bool
{
    if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '..') || str_contains($relative, "\0")) {
        return false;
    }
    return (bool) preg_match('#^(content|data|uploads)/|^assets/css/site\.css$#', $relative) && !ops_private_file($relative);
}

/** Copy every file under $from into the site, keeping only safe entries. Returns how many files were written. */
function ops_copy_tree(string $from): int
{
    $n = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($f->getPathname(), strlen($from) + 1));
        if (!ops_safe_entry($relative)) {
            continue;
        }
        write_file(ROOT . '/' . $relative, (string) file_get_contents($f->getPathname()));
        $n++;
    }
    return $n;
}

/** Remove a temporary folder and everything in it. */
function ops_remove_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

/** Forget everything read from disk this request, after files were replaced underneath us. */
function ops_refresh(): void
{
    settings(true);
    foreach (ITEM_TYPES as $type) {
        all_items($type, true);
    }
    foreach (glob(DATA_DIR . '/cache/*') ?: [] as $f) {
        @unlink($f);
    }
}

/**
 * Write an export of the site to $file: content, uploads, data without secrets, the stylesheet and a
 * manifest.json with the version and settings. The same shape is what "Import a site" reads.
 */
function ops_write_export(string $file): void
{
    $zip = new ZipArchive();
    if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('The export file could not be made.');
    }
    $count = 0;
    foreach (['content' => CONTENT_DIR, 'data' => DATA_DIR, 'uploads' => UPLOAD_DIR] as $prefix => $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
            if (!$f->isFile()) {
                continue;
            }
            $name = $prefix . '/' . str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
            if (ops_safe_entry($name)) {
                $zip->addFile($f->getPathname(), $name);
                $count++;
            }
        }
    }
    if (is_file(STYLESHEET)) {
        $zip->addFile(STYLESHEET, 'assets/css/site.css');
    }
    $manifest = ['kind' => 'full-circle-site-export', 'version' => cms_version(), 'site_name' => site_name(), 'site_url' => site_url('/'),
        'exported' => date('c'), 'php' => PHP_VERSION, 'files' => $count, 'settings' => settings()];
    $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    $zip->close();
}

/** A copy of the site as it is now, kept in the backup folder so a restore or import can be undone. Returns its path. */
function ops_safety_copy(string $why): string
{
    $dir = backup_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        throw new RuntimeException('The backup folder ' . $dir . ' could not be created, so nothing was changed. Ask your host to check it.');
    }
    $file = $dir . '/before-' . $why . '-' . date('Y-m-d_His') . '.zip';
    ops_write_export($file);
    @chmod($file, 0600);
    return $file;
}

/** Open a nightly archive for reading. */
function ops_open_archive(array $archive): PharData
{
    try {
        return new PharData($archive['path']);
    } catch (Throwable $e) {
        error_log('Backup archive could not be opened: ' . $e->getMessage());
        throw new RuntimeException('The backup ' . $archive['name'] . ' could not be read. It may be damaged.');
    }
}

/** The pages, posts and other items inside an archive, as [type/slug => title]. */
function ops_archive_items(PharData $phar): array
{
    $items = [];
    $types = implode('|', ITEM_TYPES);
    foreach (new RecursiveIteratorIterator($phar) as $f) {
        $path = $f->getPathname();
        if (!preg_match("#/content/($types)/([a-z0-9-]+)\.json$#", $path, $m)) {
            continue;
        }
        $data = json_decode((string) file_get_contents($path), true);
        $items["{$m[1]}/{$m[2]}"] = is_array($data) ? (string) ($data['title'] ?? $m[2]) : $m[2];
    }
    ksort($items);
    return $items;
}

function ops_item_noun(string $type): string
{
    return ['pages' => 'page', 'posts' => 'blog post', 'resources' => 'cheat sheet', 'videos' => 'video', 'stories' => 'success story'][$type] ?? $type;
}

/* =====================================================================
   System
   ===================================================================== */

/** How many PHP errors the host's log recorded today, or null when no log can be read. */
function ops_error_count_today(): ?array
{
    $candidates = [dirname(ROOT) . '/logs/' . str_replace('.', '_', site_host()) . '.php.error.log', ROOT . '/error_log', ROOT . '/admin/error_log'];
    foreach ($candidates as $log) {
        if (!is_readable($log) || !is_file($log)) {
            continue;
        }
        $today = date('d-M-Y');
        $count = 0;
        $fh = fopen($log, 'r');
        fseek($fh, max(0, filesize($log) - 2_000_000));
        while (($line = fgets($fh)) !== false) {
            if (str_starts_with($line, "[$today")) {
                $count++;
            }
        }
        fclose($fh);
        return ['count' => $count, 'log' => $log];
    }
    return null;
}

function ops_system_screen(bool $post): never
{
    if ($post) {
        $result = check_for_updates();
        flash(match ($result['status']) {
            'up-to-date' => 'Checked just now: this site is up to date (version ' . cms_version() . ').',
            'available' => 'Checked just now: ' . update_words($result) . '.',
            default => 'Checked just now: ' . $result['message'],
        }, $result['status'] === 'error' ? 'error' : 'success');
        go(admin_url('system'));
    }
    $status = update_status();
    $errors = ops_error_count_today();
    $branch = git_branch();
    $rows = [
        ['Full Circle CMS version', cms_version()],
        ['PHP version', PHP_VERSION],
        ['Space used by content', format_bytes(dir_size(CONTENT_DIR))],
        ['Space used by uploads', format_bytes(dir_size(UPLOAD_DIR))],
        ['Space used by data', format_bytes(dir_size(DATA_DIR))],
        ['Git', !git_available() ? 'Not available on this server' : ($branch !== '' ? 'Available; this site is on the branch ' . $branch : 'Available, but this site is not a git checkout')],
        ['Updates', update_words($status) . ($status ? ' (checked ' . format_time((string) $status['checked']) . ')' : '')],
        ['PHP errors today', $errors === null ? 'No error log can be read from here' : $errors['count'] . ' in ' . $errors['log']],
        ['Backups', ($latest = backup_archives()[0] ?? null) ? 'Latest ' . format_time($latest['time']) . ', ' . count(backup_archives()) . ' kept in ' . backup_dir() : 'None yet in ' . backup_dir()],
    ];
    $table = '<div class="table-wrap"><table><caption class="visually-hidden">About this site</caption><tbody>';
    foreach ($rows as [$label, $value]) {
        $table .= '<tr><th scope="row">' . h($label) . '</th><td>' . h($value) . '</td></tr>';
    }
    $table .= '</tbody></table></div>';
    $body = $table
        . '<h2>Updates</h2>'
        . '<p>Once a day the site looks at the kit’s repository for a newer version. Updates themselves are applied by your host from the command line with <code>php tools/update.php --apply</code>.</p>'
        . '<form method="post" action="' . h(admin_url('system')) . '" class="form-actions">' . csrf_field()
        . '<button type="submit" class="button">Check now</button></form>';
    admin_screen('System', $body, 'system');
}

/* =====================================================================
   Sites
   ===================================================================== */

function ops_sites_screen(bool $post): never
{
    $do = (string) ($_GET['do'] ?? '');
    if ($post) {
        match ($do) {
            'check' => ops_sites_check(),
            'add' => ops_sites_add(),
            'remove' => ops_sites_remove(),
            'secret' => ops_sites_new_secret(),
            default => not_found_screen(),
        };
    }
    match ($do) {
        'remove' => ops_sites_remove_confirm(),
        'secret' => ops_sites_secret_confirm(),
        default => ops_sites_list(),
    };
}

function ops_sites_list(array $errors = [], array $v = []): never
{
    $secret = (string) (config()['fleet_secret'] ?? '');
    $sites = fleet_sites();
    $fleet = read_json(FLEET_STATUS);
    $results = [];
    foreach ($fleet['sites'] ?? [] as $r) {
        $results[$r['url']] = $r;
    }

    $body = '<p>Every site built from the kit can report how it is doing to the others. This screen asks each site listed below for its report, and shows it in plain words.</p>'
        . '<h2 id="this-site">This site’s key</h2>';
    if ($secret === '') {
        $body .= '<p>This site has no key yet, so other sites cannot check it. Make one, then paste it into the Sites screen of the site that will do the checking.</p>'
            . '<form method="post" action="' . h(admin_url('sites', ['do' => 'secret'])) . '" class="form-actions">' . csrf_field()
            . '<button type="submit" class="button">Make a key for this site</button></form>';
    } else {
        $body .= '<p>Paste this key into the Sites screen of the site that checks on this one. Keep it private: it is what lets another site read this one’s status.</p>'
            . '<div class="field"><label for="fleet-secret">This site’s key</label><input type="text" id="fleet-secret" value="' . h($secret) . '" readonly></div>'
            . '<p><a class="button button-secondary" href="' . h(admin_url('sites', ['do' => 'secret'])) . '">Replace the key</a></p>';
    }

    $body .= '<h2 id="other-sites">Other sites</h2>';
    if (!$sites) {
        $body .= '<p>No other sites are listed yet. Add one below.</p>';
    } else {
        $body .= '<form method="post" action="' . h(admin_url('sites', ['do' => 'check'])) . '" class="form-actions">' . csrf_field()
            . '<button type="submit" class="button">' . (count($sites) === 1 ? 'Check this site' : 'Check all ' . count($sites) . ' sites') . '</button>'
            . ($fleet ? '<span>Last checked ' . h(format_time((string) $fleet['checked'])) . '.</span>' : '') . '</form>'
            . '<div class="table-wrap"><table><caption class="visually-hidden">Other sites and their last check</caption><thead><tr><th scope="col">Site</th><th scope="col">Address</th><th scope="col">Version</th><th scope="col">Last check</th><th scope="col">Actions</th></tr></thead><tbody>';
        foreach ($sites as $i => $site) {
            $r = $results[$site['url']] ?? null;
            $version = $r['status']['version'] ?? '';
            $body .= '<tr><th scope="row">' . h($site['name'] ?: $site['url']) . '</th><td><a href="' . h($site['url'] . '/') . '">' . h(preg_replace('#^https?://#', '', $site['url'])) . '</a></td>'
                . '<td>' . h($version !== '' ? $version : '–') . '</td><td>' . ($r ? h($r['summary']) : 'Not checked yet') . '</td>'
                . '<td class="actions"><a href="' . h(admin_url('sites', ['do' => 'remove', 'i' => $i])) . '">Remove<span class="visually-hidden"> ' . h($site['name'] ?: $site['url']) . '</span></a></td></tr>';
        }
        $body .= '</tbody></table></div>';
    }

    $body .= '<h2 id="add-site">Add a site</h2>'
        . '<form method="post" action="' . h(admin_url('sites', ['do' => 'add'])) . '">' . csrf_field()
        . text_field('name', 'Site name', (string) ($v['name'] ?? ''), ['required' => true, 'error' => $errors['name'] ?? ''])
        . text_field('url', 'Site address', (string) ($v['url'] ?? ''), ['type' => 'url', 'required' => true, 'hint' => 'Starting with https://, for example https://example.com', 'error' => $errors['url'] ?? ''])
        . text_field('secret', 'That site’s key', (string) ($v['secret'] ?? ''), ['required' => true, 'autocomplete' => 'off', 'spellcheck' => 'false', 'hint' => 'Copy it from “This site’s key” on that site’s own Sites screen.', 'error' => $errors['secret'] ?? ''])
        . '<div class="form-actions"><button type="submit" class="button">Add this site</button></div></form>';
    admin_screen('Sites', $body, 'sites', ['errors' => $errors]);
}

function ops_sites_check(): never
{
    if (!fleet_sites()) {
        flash('There are no sites to check yet.', 'error');
        go(admin_url('sites'));
    }
    $all = check_fleet();
    $fine = count(array_filter($all['sites'], fn($s) => !empty($s['fine'])));
    $total = count($all['sites']);
    $sites = $total === 1 ? '1 site' : "$total sites";
    flash($fine === $total ? ($total === 1 ? 'Checked 1 site: all good.' : "Checked $total sites: all are fine.")
        : "Checked $sites: $fine " . ($fine === 1 ? 'is' : 'are') . ' fine, ' . ($total - $fine) . ' ' . ($total - $fine === 1 ? 'has' : 'have') . ' something to look at. The table has the details.');
    go(admin_url('sites'));
}

function ops_sites_add(): never
{
    $v = ['name' => trim((string) ($_POST['name'] ?? '')), 'url' => rtrim(trim((string) ($_POST['url'] ?? '')), '/'), 'secret' => trim((string) ($_POST['secret'] ?? ''))];
    $errors = [];
    if ($v['name'] === '') {
        $errors['name'] = 'Enter a name for the site.';
    }
    if (!preg_match('#^https?://[a-z0-9.-]+(:\d+)?$#i', $v['url'])) {
        $errors['url'] = 'Enter the site’s address starting with https:// and nothing after the domain name.';
    } elseif (rtrim(site_url('/'), '/') === $v['url']) {
        $errors['url'] = 'That is this site’s own address. Add the other sites here.';
    } elseif (in_array($v['url'], array_column(fleet_sites(), 'url'), true)) {
        $errors['url'] = 'That site is already on the list.';
    }
    if (strlen($v['secret']) < 16) {
        $errors['secret'] = 'The key is at least 16 characters; copy it from that site’s Sites screen.';
    }
    if ($errors) {
        ops_sites_list($errors, $v);
    }
    $sites = fleet_sites();
    $sites[] = $v;
    save_config(['fleet_sites' => $sites]);
    flash('Added ' . $v['name'] . '. Choose “Check all” to ask it how it is doing.');
    go(admin_url('sites'));
}

function ops_sites_remove_confirm(): never
{
    $i = (int) ($_GET['i'] ?? -1);
    $site = fleet_sites()[$i] ?? null;
    if (!$site) {
        not_found_screen();
    }
    $body = '<p>Remove ' . h($site['name'] ?: $site['url']) . ' (' . h($site['url']) . ') from the list? This site will stop checking on it. Nothing on that site changes.</p>'
        . '<form method="post" action="' . h(admin_url('sites', ['do' => 'remove', 'i' => $i])) . '" class="form-actions">' . csrf_field()
        . '<input type="hidden" name="url" value="' . h($site['url']) . '">'
        . '<button type="submit" class="button button-danger">Yes, remove this site from the list</button>'
        . '<a class="button button-secondary" href="' . h(admin_url('sites')) . '">No, go back</a></form>';
    admin_screen('Remove a site from the list', $body, 'sites');
}

function ops_sites_remove(): never
{
    $i = (int) ($_GET['i'] ?? -1);
    $sites = fleet_sites();
    $site = $sites[$i] ?? null;
    if (!$site || $site['url'] !== (string) ($_POST['url'] ?? '')) {
        flash('That site was not on the list any more.', 'error');
        go(admin_url('sites'));
    }
    unset($sites[$i]);
    save_config(['fleet_sites' => array_values($sites)]);
    $fleet = read_json(FLEET_STATUS);
    if ($fleet) {
        $fleet['sites'] = array_values(array_filter($fleet['sites'] ?? [], fn($s) => $s['url'] !== $site['url']));
        write_json(FLEET_STATUS, $fleet);
    }
    flash('Removed ' . ($site['name'] ?: $site['url']) . ' from the list.');
    go(admin_url('sites'));
}

function ops_sites_secret_confirm(): never
{
    if ((string) (config()['fleet_secret'] ?? '') === '') {
        go(admin_url('sites'));
    }
    $body = '<p>Replacing the key means any site that checks on this one will stop getting an answer until you paste the new key into its Sites screen.</p>'
        . '<form method="post" action="' . h(admin_url('sites', ['do' => 'secret'])) . '" class="form-actions">' . csrf_field()
        . '<button type="submit" class="button button-danger">Yes, replace the key</button>'
        . '<a class="button button-secondary" href="' . h(admin_url('sites')) . '">No, keep the current key</a></form>';
    admin_screen('Replace this site’s key', $body, 'sites');
}

function ops_sites_new_secret(): never
{
    $had = (string) (config()['fleet_secret'] ?? '') !== '';
    save_config(['fleet_secret' => bin2hex(random_bytes(24))]);
    flash($had ? 'Made a new key. Paste it into the Sites screen of any site that checks on this one.' : 'Made a key for this site. Paste it into the Sites screen of the site that will check on this one.');
    go(admin_url('sites') . '#this-site');
}

/* =====================================================================
   Backups
   ===================================================================== */

function ops_backups_screen(bool $post): never
{
    $do = (string) ($_GET['do'] ?? '');
    if ($post) {
        match ($do) {
            'item' => ops_restore_item(),
            'all' => ops_restore_all(),
            'import' => ops_import(),
            default => not_found_screen(),
        };
    }
    match ($do) {
        'download' => ops_backup_download(),
        'item' => ops_restore_item_form(),
        'all' => ops_restore_all_form(),
        'export' => ops_export(),
        'import' => ops_import_form(),
        default => ops_backups_list(),
    };
}

/** The archive named in the request, or the "not found" screen. */
function ops_requested_archive(): array
{
    $archive = backup_archive((string) ($_GET['file'] ?? ''));
    if (!$archive) {
        not_found_screen();
    }
    return $archive;
}

function ops_backups_list(): never
{
    $archives = backup_archives();
    $body = '<p>Every night <code>tools/backup.sh</code> saves the website’s pages, posts, uploads, settings and messages to <code>' . h(backup_dir()) . '</code> on the server and keeps the newest ' . BACKUP_KEEP . '. Passwords and email settings are never in a backup.</p>'
        . '<h2 id="nightly">Nightly backups</h2>';
    if (!$archives) {
        $body .= '<p>No nightly backup has run yet. Your host sets it up as a cron job (see the README).</p>';
    } else {
        $body .= '<div class="table-wrap"><table><caption class="visually-hidden">Nightly backups, newest first</caption><thead><tr><th scope="col">Date</th><th scope="col">Size</th><th scope="col">Made by version</th><th scope="col">Actions</th></tr></thead><tbody>';
        foreach ($archives as $a) {
            $m = $a['manifest'] ?? [];
            $plural = fn(int $n, string $one, string $many) => $n . ' ' . ($n === 1 ? $one : $many);
            $what = isset($m['items']) ? ' (' . $plural((int) $m['items']['pages'], 'page', 'pages') . ', ' . $plural((int) $m['items']['posts'], 'post', 'posts') . ', ' . $plural((int) $m['items']['uploads'], 'upload', 'uploads') . ')' : '';
            $body .= '<tr><th scope="row">' . h(format_date($a['date'])) . '</th><td>' . h(format_bytes($a['size'])) . '</td><td>' . h((string) ($m['version'] ?? 'unknown')) . h($what) . '</td>'
                . '<td class="actions"><a href="' . h(admin_url('backups', ['do' => 'download', 'file' => $a['name']])) . '">Download<span class="visually-hidden"> the backup from ' . h(format_date($a['date'])) . '</span></a>'
                . '<a href="' . h(admin_url('backups', ['do' => 'item', 'file' => $a['name']])) . '">Restore one item<span class="visually-hidden"> from ' . h(format_date($a['date'])) . '</span></a>'
                . '<a href="' . h(admin_url('backups', ['do' => 'all', 'file' => $a['name']])) . '">Restore everything<span class="visually-hidden"> from ' . h(format_date($a['date'])) . '</span></a></td></tr>';
        }
        $body .= '</tbody></table></div>';
    }
    $body .= '<h2 id="export">Export this site</h2>'
        . '<p>A zip file of everything that makes this site yours: pages, posts, uploads, settings, messages and the stylesheet, with a note of the kit version. Passwords and keys are left out. Use it to move the site to another server, or to keep a copy somewhere safe.</p>'
        . '<p><a class="button button-secondary" href="' . h(admin_url('backups', ['do' => 'export'])) . '">Export this site</a></p>'
        . '<h2 id="import">Import a site</h2>'
        . '<p>Bring in a site exported from another copy of the kit. On a fresh site it just loads; on a site that already has content you type the site name to confirm, and a copy of the current site is saved first.</p>'
        . '<p><a class="button button-secondary" href="' . h(admin_url('backups', ['do' => 'import'])) . '">Import a site</a></p>';
    admin_screen('Backups', $body, 'backups');
}

function ops_backup_download(): never
{
    $a = ops_requested_archive();
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9]+/', '-', strtolower(site_host())) . '-' . $a['name'] . '"');
    header('Content-Length: ' . $a['size']);
    header('Cache-Control: no-store');
    readfile($a['path']);
    exit;
}

function ops_restore_item_form(array $errors = []): never
{
    $a = ops_requested_archive();
    $items = ops_archive_items(ops_open_archive($a));
    $body = '<p>Choose one page, post or other item from the backup made on ' . h(format_date($a['date'])) . '. The version on the website now is kept as an earlier version, so this can be undone under Earlier versions.</p>';
    if (!$items) {
        $body .= '<p>This backup holds no pages or posts.</p>';
    } else {
        $options = '';
        foreach ($items as $key => $title) {
            [$type] = explode('/', $key);
            $options .= '<option value="' . h($key) . '">' . h(ucfirst(ops_item_noun($type)) . ': ' . $title . ' (' . substr($key, strlen($type) + 1) . ')') . '</option>';
        }
        $error = $errors['item'] ?? '';
        $body .= '<form method="post" action="' . h(admin_url('backups', ['do' => 'item', 'file' => $a['name']])) . '">' . csrf_field()
            . '<div class="field' . ($error ? ' field-error' : '') . '"><label for="item">What to restore</label>'
            . ($error ? '<p class="error-message" id="item-error"><span class="visually-hidden">Error: </span>' . h($error) . '</p>' : '')
            . '<select id="item" name="item"' . ($error ? ' aria-describedby="item-error" aria-invalid="true"' : '') . '>' . $options . '</select></div>'
            . '<div class="form-actions"><button type="submit" class="button">Restore this item</button>'
            . '<a class="button button-secondary" href="' . h(admin_url('backups')) . '">Go back</a></div></form>';
    }
    admin_screen('Restore one item from ' . format_date($a['date']), $body, 'backups', ['errors' => $errors]);
}

function ops_restore_item(): never
{
    $a = ops_requested_archive();
    $key = (string) ($_POST['item'] ?? '');
    [$type, $slug] = explode('/', $key, 2) + ['', ''];
    if (!in_array($type, ITEM_TYPES, true) || !valid_slug($slug)) {
        ops_restore_item_form(['item' => 'Choose an item from the list.']);
    }
    $phar = ops_open_archive($a);
    $entry = "phar://{$a['path']}/content/$type/$slug.json";
    $data = is_file($entry) ? json_decode((string) file_get_contents($entry), true) : null;
    if (!is_array($data)) {
        ops_restore_item_form(['item' => 'That item is not in this backup.']);
    }
    unset($phar);
    save_item($type, $slug, $data);
    $title = (string) ($data['title'] ?? $slug);
    ops_log('Restored the ' . ops_item_noun($type) . " “{$title}” from the backup of {$a['date']}");
    flash('Restored the ' . ops_item_noun($type) . " “{$title}” from the backup made on " . format_date($a['date']) . '. The version it replaced was kept under Earlier versions.');
    go(admin_url('backups'));
}

function ops_restore_all_form(array $errors = []): never
{
    $a = ops_requested_archive();
    $body = '<p>This puts back every page, post, upload, setting and message from the backup made on <strong>' . h(format_date($a['date'])) . '</strong>, replacing what is on the website now. '
        . 'Your password, email settings and the people who can sign in are not changed. A copy of the site as it is now is saved in the backup folder first, so this can be undone.</p>'
        . '<form method="post" action="' . h(admin_url('backups', ['do' => 'all', 'file' => $a['name']])) . '">' . csrf_field()
        . text_field('confirm_date', 'To confirm, type the backup’s date as ' . $a['date'], '', ['autocomplete' => 'off', 'spellcheck' => 'false', 'inputmode' => 'numeric', 'error' => $errors['confirm_date'] ?? '', 'hint' => 'Year, month and day with dashes, exactly as shown.'])
        . '<div class="form-actions"><button type="submit" class="button button-danger">Restore everything from this backup</button>'
        . '<a class="button button-secondary" href="' . h(admin_url('backups')) . '">No, go back</a></div></form>';
    admin_screen('Restore everything from ' . format_date($a['date']), $body, 'backups', ['errors' => $errors]);
}

function ops_restore_all(): never
{
    $a = ops_requested_archive();
    if (trim((string) ($_POST['confirm_date'] ?? '')) !== $a['date']) {
        ops_restore_all_form(['confirm_date' => 'The date you typed does not match ' . $a['date'] . '. Nothing was changed.']);
    }
    $phar = ops_open_archive($a);
    foreach (new RecursiveIteratorIterator($phar) as $f) {
        $relative = substr($f->getPathname(), strlen('phar://' . $a['path']) + 1);
        if (str_contains($relative, '..') || !preg_match('#^(content|data|uploads|assets)/#', $relative)) {
            throw new RuntimeException('The backup holds files outside the site folders, so it was not restored.');
        }
    }
    $safety = ops_safety_copy('restore');
    $work = sys_get_temp_dir() . '/fc-restore-' . bin2hex(random_bytes(6));
    mkdir($work, 0700, true);
    try {
        $phar->extractTo($work, null, true);
        $n = ops_copy_tree($work);
    } catch (Throwable $e) {
        error_log('Restore failed: ' . $e->getMessage());
        throw new RuntimeException('The backup could not be unpacked. Nothing more was changed; a copy of the site before the attempt is at ' . $safety . '.');
    } finally {
        ops_remove_tree($work);
    }
    ops_refresh();
    ops_log("Restored everything ($n files) from the backup of {$a['date']}");
    flash("Restored everything from the backup made on " . format_date($a['date']) . " ($n files). The site as it was before is saved at $safety.");
    go(admin_url('backups'));
}

function ops_export(): never
{
    $tmp = tempnam(sys_get_temp_dir(), 'fcx');
    ops_write_export($tmp);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9]+/', '-', strtolower(site_host())) . '-site-export-' . date('Y-m-d') . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
    unlink($tmp);
    exit;
}

/** A site is empty when it only holds what setup made: no uploads, no messages, and at most the starter pages and post. */
function ops_site_is_empty(): bool
{
    $items = 0;
    foreach (ITEM_TYPES as $type) {
        $items += count(all_items($type));
    }
    $uploads = array_filter(glob(UPLOAD_DIR . '/*/*/*') ?: [], 'is_file');
    return $items <= 10 && !$uploads && !(glob(MESSAGE_DIR . '/*.json') ?: []);
}

function ops_import_form(array $errors = []): never
{
    $empty = ops_site_is_empty();
    $body = '<p>Choose a zip file made by “Export this site” on another copy of the kit. It brings in that site’s pages, posts, uploads, settings and messages. This site’s password, email settings and address stay as they are.</p>'
        . ($empty ? '<p>This site is still empty, so the import just loads.</p>'
            : '<p><strong>This site already has content.</strong> Importing replaces it. A copy of the site as it is now is saved in the backup folder first, and you type this site’s name to confirm.</p>')
        . '<form method="post" action="' . h(admin_url('backups', ['do' => 'import'])) . '" enctype="multipart/form-data">' . csrf_field()
        . text_field('export_file', 'Export file (zip)', '', ['type' => 'file', 'accept' => '.zip,application/zip', 'error' => $errors['export_file'] ?? ''])
        . (!$empty ? text_field('confirm_name', 'To confirm, type this site’s name: ' . site_name(), '', ['autocomplete' => 'off', 'error' => $errors['confirm_name'] ?? '']) : '')
        . '<div class="form-actions"><button type="submit" class="button' . ($empty ? '' : ' button-danger') . '">Import this site</button>'
        . '<a class="button button-secondary" href="' . h(admin_url('backups')) . '">Go back</a></div></form>';
    admin_screen('Import a site', $body, 'backups', ['errors' => $errors]);
}

function ops_import(): never
{
    $file = $_FILES['export_file'] ?? null;
    $errors = [];
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $errors['export_file'] = 'Choose the export zip file first.';
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $errors['export_file'] = in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'That file is bigger than the server allows (' . format_bytes(upload_limit()) . '). Ask your host to raise the limit, or import over SSH with tools/restore.sh.'
            : 'The file did not upload properly. Please try again.';
    }
    $empty = ops_site_is_empty();
    if (!$empty && trim((string) ($_POST['confirm_name'] ?? '')) !== site_name()) {
        $errors['confirm_name'] = 'Type this site’s name exactly: ' . site_name() . '. Nothing was changed.';
    }
    if ($errors) {
        ops_import_form($errors);
    }
    $zip = new ZipArchive();
    if ($zip->open($file['tmp_name']) !== true) {
        ops_import_form(['export_file' => 'That file is not a zip file that can be opened.']);
    }
    $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
    if (!is_array($manifest) || ($manifest['kind'] ?? '') !== 'full-circle-site-export') {
        $zip->close();
        ops_import_form(['export_file' => 'That zip file was not made by “Export this site”, so it was not imported.']);
    }
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if (str_ends_with($name, '/') || $name === 'manifest.json') {
            continue;
        }
        if (str_contains($name, '..') || str_starts_with($name, '/') || !preg_match('#^(content|data|uploads|assets)/#', $name)) {
            $zip->close();
            ops_import_form(['export_file' => 'The zip file holds files outside the site folders, so it was not imported.']);
        }
    }
    $safety = $empty ? '' : ops_safety_copy('import');
    $work = sys_get_temp_dir() . '/fc-import-' . bin2hex(random_bytes(6));
    mkdir($work, 0700, true);
    try {
        if (!$zip->extractTo($work)) {
            throw new RuntimeException('extractTo failed');
        }
        $zip->close();
        $n = ops_copy_tree($work);
    } catch (Throwable $e) {
        error_log('Import failed: ' . $e->getMessage());
        throw new RuntimeException('The export could not be unpacked. ' . ($safety !== '' ? 'A copy of the site before the attempt is at ' . $safety . '.' : 'Nothing was changed.'));
    } finally {
        ops_remove_tree($work);
    }
    // The imported settings describe the other site; this site keeps its own address.
    $previous = settings();
    ops_refresh();
    $s = settings();
    $s['site_url'] = $previous['site_url'];
    save_settings($s);
    ops_refresh();
    $from = (string) ($manifest['site_name'] ?? 'another site');
    ops_log("Imported the site “{$from}” ($n files, exported " . ($manifest['exported'] ?? 'unknown date') . ' from kit version ' . ($manifest['version'] ?? 'unknown') . ')');
    flash("Imported “{$from}” ($n files). " . ($safety !== '' ? "The site as it was before is saved at $safety. " : '') . 'Check Settings and the home page.');
    go(admin_url('backups'));
}
