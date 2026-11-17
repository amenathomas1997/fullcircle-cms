<?php
/**
 * The Redirects screen: add, edit and delete redirects, and see which addresses got 404s.
 * Loaded by lib/features/redirects.php only when the screen is shown.
 */

declare(strict_types=1);

function redirects_screen(bool $post): never
{
    if ($post) {
        if (isset($_GET['delete'])) {
            redirect_delete((string) $_GET['delete']);
        }
        redirect_save();
    }
    if (isset($_GET['delete'])) {
        redirect_delete_confirm((string) $_GET['delete']);
    }
    redirects_list();
}

function find_redirect_by_id(string $id): ?array
{
    foreach (redirects() as $r) {
        if (($r['id'] ?? '') === $id) {
            return $r;
        }
    }
    return null;
}

/** The whole screen: the add/edit form, the list of redirects and the missing addresses. */
function redirects_list(array $values = [], array $errors = []): never
{
    $editing = null;
    if (isset($_GET['edit']) && !$values) {
        $editing = find_redirect_by_id((string) $_GET['edit']);
        if (!$editing) {
            flash('That redirect could not be found.', 'error');
            go(admin_url('redirects'));
        }
        $values = $editing;
    } elseif (!empty($values['id'])) {
        $editing = find_redirect_by_id((string) $values['id']);
    }
    $from = (string) ($values['from'] ?? (isset($_GET['from']) ? clean_from_path((string) $_GET['from']) : ''));
    $isEdit = $editing !== null;

    $form = '<section aria-labelledby="redirect-form-heading"><h2 id="redirect-form-heading">' . ($isEdit ? 'Edit this redirect' : 'Add a redirect') . '</h2>'
        . '<form method="post" action="' . h(admin_url('redirects')) . '">' . csrf_field()
        . ($isEdit ? '<input type="hidden" name="id" value="' . h((string) $editing['id']) . '">' : '')
        . text_field('from', 'From this address', $from, ['required' => true, 'spellcheck' => 'false', 'autocomplete' => 'off', 'error' => $errors['from'] ?? null,
            'hint' => 'The old address on this site, for example /old-page/. Capital letters and a trailing slash don’t matter.'])
        . text_field('to', 'Send people to', (string) ($values['to'] ?? ''), ['required' => true, 'spellcheck' => 'false', 'autocomplete' => 'off', 'error' => $errors['to'] ?? null,
            'hint' => 'A page on this site, for example /new-page/, or a full https:// address on another site.'])
        . radio_field('code', 'Kind of redirect', ['301' => 'Moved for good (301): search engines update their links', '302' => 'Moved for now (302): search engines keep the old address'], (string) ($values['code'] ?? '301'))
        . '<div class="form-actions"><button type="submit" class="button">' . ($isEdit ? 'Save changes' : 'Add redirect') . '</button>'
        . ($isEdit ? '<a class="button button-secondary" href="' . h(admin_url('redirects')) . '">Cancel</a>' : '') . '</div></form></section>';

    $rows = '';
    foreach (redirects() as $r) {
        $label = h((string) $r['from']);
        $rows .= '<tr><th scope="row">' . $label . '</th><td>' . h((string) $r['to']) . '</td><td>' . ((int) $r['code'] === 302 ? 'For now (302)' : 'For good (301)') . '</td>'
            . '<td class="actions"><a href="' . h(admin_url('redirects', ['edit' => $r['id']])) . '#redirect-form-heading">Edit<span class="visually-hidden"> redirect from ' . $label . '</span></a>'
            . ' <a href="' . h(admin_url('redirects', ['delete' => $r['id']])) . '">Delete<span class="visually-hidden"> redirect from ' . $label . '</span></a></td></tr>';
    }
    $list = '<section aria-labelledby="redirects-heading"><h2 id="redirects-heading">Current redirects</h2>'
        . ($rows === '' ? '<p>No redirects yet.</p>'
            : '<div class="table-wrap"><table><caption>All redirects</caption><thead><tr><th scope="col">From</th><th scope="col">To</th><th scope="col">Kind</th><th scope="col">Actions</th></tr></thead><tbody>' . $rows . '</tbody></table></div>')
        . '</section>';

    $missing = array_slice(missing_addresses(30), 0, 50);
    $mrows = '';
    foreach ($missing as $m) {
        $path = (string) $m['path'];
        $has = find_redirect($path);
        $mrows .= '<tr><th scope="row">' . h($path) . '</th><td>' . $m['hits'] . '</td><td>' . h(format_date($m['last'])) . '</td><td class="actions">'
            . ($has ? 'Redirected to ' . h((string) $has['to']) : '<a href="' . h(admin_url('redirects', ['from' => $path])) . '#from">Add a redirect<span class="visually-hidden"> for ' . h($path) . '</span></a>') . '</td></tr>';
    }
    $missingHtml = '<section aria-labelledby="missing-heading"><h2 id="missing-heading">Missing addresses in the last 30 days</h2>'
        . '<p>Addresses people asked for that don’t exist, counted per day. Old links from emails, search engines or other sites often show up here; a redirect sends those visitors to the right page. Automated scans and files are left out.</p>'
        . ($mrows === '' ? '<p>Nothing missing in the last 30 days.</p>'
            : '<div class="table-wrap"><table><caption>Addresses that got a “page not found”, most hits first</caption><thead><tr><th scope="col">Address</th><th scope="col">Hits</th><th scope="col">Last seen</th><th scope="col">Actions</th></tr></thead><tbody>' . $mrows . '</tbody></table></div>')
        . '</section>';

    $body = '<p>A redirect sends anyone who opens an old address to a new one. Use it after renaming a page, or to catch addresses from your old website.</p>'
        . '<nav aria-label="On this screen"><ul><li><a href="#redirect-form-heading">' . ($isEdit ? 'Edit this redirect' : 'Add a redirect') . '</a></li><li><a href="#redirects-heading">Current redirects</a></li><li><a href="#missing-heading">Missing addresses</a></li></ul></nav>'
        . $form . $list . $missingHtml;
    admin_screen('Redirects', $body, 'redirects', ['errors' => $errors]);
}

function redirect_save(): never
{
    $id = (string) ($_POST['id'] ?? '');
    $v = [
        'id' => $id,
        'from' => clean_from_path((string) ($_POST['from'] ?? '')),
        'to' => trim((string) ($_POST['to'] ?? '')),
        'code' => (string) ($_POST['code'] ?? '301') === '302' ? 302 : 301,
    ];
    $errors = [];
    $fromKey = redirect_key($v['from']);
    if (trim((string) ($_POST['from'] ?? '')) === '' || $fromKey === '/') {
        $errors['from'] = 'Enter the old address, for example /old-page/. The home page itself cannot be redirected.';
    } elseif (!preg_match('#^/[^\s?\#]*$#', $v['from']) || str_starts_with($fromKey, '/admin')) {
        $errors['from'] = 'The address must start with a slash and have no spaces, for example /old-page/.';
    } else {
        foreach (redirects() as $r) {
            if (redirect_key((string) $r['from']) === $fromKey && ($r['id'] ?? '') !== $id) {
                $errors['from'] = 'There is already a redirect from that address. Edit that one instead.';
            }
        }
    }
    if ($v['to'] === '') {
        $errors['to'] = 'Enter where to send people.';
    } elseif (!valid_redirect_target($v['to'])) {
        $errors['to'] = 'Enter a page on this site starting with a slash, like /new-page/, or a full address starting with https://.';
    } elseif (str_starts_with($v['to'], '/') && redirect_key((string) parse_url($v['to'], PHP_URL_PATH)) === $fromKey) {
        $errors['to'] = 'That would send people round in a circle: the two addresses are the same.';
    }
    if ($errors) {
        redirects_list($v, $errors);
    }
    $list = redirects();
    $found = false;
    foreach ($list as $i => $r) {
        if ($id !== '' && ($r['id'] ?? '') === $id) {
            $list[$i] = ['id' => $id] + ['from' => $v['from'], 'to' => $v['to'], 'code' => $v['code']];
            $found = true;
        }
    }
    if (!$found) {
        if ($id !== '') {
            flash('That redirect could not be found.', 'error');
            go(admin_url('redirects'));
        }
        $list[] = ['id' => bin2hex(random_bytes(4)), 'from' => $v['from'], 'to' => $v['to'], 'code' => $v['code']];
    }
    save_redirects($list);
    flash(($found ? 'Saved the redirect from ' : 'Added a redirect from ') . $v['from'] . ' to ' . $v['to'] . '.');
    go(admin_url('redirects'));
}

function redirect_delete_confirm(string $id): never
{
    $r = find_redirect_by_id($id);
    if (!$r) {
        not_found_screen();
    }
    $body = '<p>Are you sure you want to delete the redirect from ' . h((string) $r['from']) . ' to ' . h((string) $r['to']) . '? People who open the old address will see “Page not found” again.</p>'
        . '<form method="post" action="' . h(admin_url('redirects', ['delete' => $id])) . '" class="form-actions">' . csrf_field()
        . '<button type="submit" class="button button-danger">Yes, delete this redirect</button>'
        . '<a class="button button-secondary" href="' . h(admin_url('redirects')) . '">No, go back</a></form>';
    admin_screen('Delete redirect: ' . h((string) $r['from']), $body, 'redirects');
}

function redirect_delete(string $id): never
{
    $r = find_redirect_by_id($id);
    if (!$r) {
        not_found_screen();
    }
    save_redirects(array_filter(redirects(), fn($x) => ($x['id'] ?? '') !== $id));
    flash('Deleted the redirect from ' . $r['from'] . '.');
    go(admin_url('redirects'));
}
