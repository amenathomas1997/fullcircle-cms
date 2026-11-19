<?php
/**
 * Page history with undo: a "Previous versions" section on the page, post and cheat sheet
 * edit screens. It lists the last versions that were saved (who saved each and when) and
 * each has a "Put this version back" button. Putting a version back is itself a new save,
 * so the version it replaces joins the list and the change can be undone the same way.
 *
 * The copies themselves are the revisions the core already keeps: save_item() writes the
 * version being replaced to content/revisions/<type>/<slug>/ (a private folder; the web
 * server refuses /content/). Each copy is the whole item as JSON, so it carries the title,
 * the content and the "updated_by" and "updated" fields of the save that made it.
 * Only the title and the content body come back when a version is put back; the address,
 * status, parent page and the other settings stay as they are now.
 *
 * The hidden control panel screen "history" takes the POST (type, slug, id) and goes back
 * to the edit screen. tools/selftest.php checks the save, list and restore cycle.
 */

declare(strict_types=1);

const HISTORY_SHOWN = 10;
const HISTORY_FIELDS = ['title', 'body'];
const HISTORY_TYPES = ['pages' => 'page', 'posts' => 'post', 'resources' => 'cheat sheet'];

register_admin_screen('history', fn (bool $post) => $post ? history_restore_action() : go(back_url()), null, null);

/**
 * The last versions of one item, newest first, each as
 * ['id' => revision file, 'saved' => ISO time or '', 'saved_by' => name or '', 'title' => ..., 'replaced' => unix time, 'same' => true when it matches the current item].
 */
function history_versions(string $type, string $slug, int $limit = HISTORY_SHOWN): array
{
    if (!in_array($type, ITEM_TYPES, true) || !valid_slug($slug)) {
        return [];
    }
    $current = get_item($type, $slug);
    $out = [];
    foreach (array_slice(list_revisions("$type/$slug"), 0, $limit) as $r) {
        $data = json_decode((string) read_revision("$type/$slug", $r['id']), true);
        if (!is_array($data)) {
            continue;
        }
        $out[] = [
            'id' => $r['id'],
            'saved' => (string) ($data['updated'] ?? ''),
            'saved_by' => (string) ($data['updated_by'] ?? ''),
            'title' => (string) ($data['title'] ?? ''),
            'replaced' => (int) $r['time'],
            'same' => $current !== null && history_same($current, $data),
        ];
    }
    return $out;
}

/** Do two versions have the same title and content? */
function history_same(array $a, array $b): bool
{
    foreach (HISTORY_FIELDS as $f) {
        if ((string) ($a[$f] ?? '') !== (string) ($b[$f] ?? '')) {
            return false;
        }
    }
    return true;
}

/** The item as it will be saved when $version is put back: the current item with the old title and content. */
function history_restore_data(array $current, array $version): array
{
    foreach (HISTORY_FIELDS as $f) {
        if (array_key_exists($f, $version)) {
            $current[$f] = $version[$f];
        }
    }
    return $current;
}

/**
 * Put one version back as a new save. Returns the saved item, or throws a RuntimeException in plain words.
 * The current version is kept as a revision by save_item(), so this can be undone from the same list.
 */
function history_restore(string $type, string $slug, string $id): array
{
    if (!isset(HISTORY_TYPES[$type]) || !valid_slug($slug)) {
        throw new RuntimeException('That page or post could not be found.');
    }
    $current = get_item($type, $slug);
    if (!$current) {
        throw new RuntimeException('That ' . HISTORY_TYPES[$type] . ' could not be found. It may have been deleted.');
    }
    $contents = read_revision("$type/$slug", $id);
    $version = $contents !== null ? json_decode($contents, true) : null;
    if (!is_array($version)) {
        throw new RuntimeException('That version could not be found.');
    }
    $data = history_restore_data($current, $version);
    unset($data['slug']);
    save_item($type, $slug, $data);
    return get_item($type, $slug) ?? $data;
}

/** "Saved November 18, 2026 at 3:04 pm by Pat" for one version. Works outside the control panel too (the self-test). */
function history_version_words(array $v): string
{
    $ts = $v['saved'] !== '' ? (strtotime($v['saved']) ?: (int) $v['replaced']) : (int) $v['replaced'];
    return 'Saved ' . date('F j, Y \a\t g:i a', $ts) . ($v['saved_by'] !== '' ? ' by ' . $v['saved_by'] : '');
}

/** The "Previous versions" section for an edit screen. Nothing for a new, unsaved item. */
function history_section(string $type, ?string $slug): string
{
    if ($slug === null || !isset(HISTORY_TYPES[$type]) || !valid_slug($slug)) {
        return '';
    }
    $noun = HISTORY_TYPES[$type];
    $versions = history_versions($type, $slug);
    $all = count(list_revisions("$type/$slug"));
    $out = '<section id="previous-versions" aria-labelledby="previous-versions-heading"><h2 id="previous-versions-heading">Previous versions</h2>'
        . '<p>Every time this ' . $noun . ' is saved, the version it replaces is kept here. '
        . 'Putting a version back saves it as a new version, so the one it replaces stays in this list and you can undo it the same way. '
        . 'The title and the content come back; the address, status and other settings stay as they are now.</p>';
    if (!$versions) {
        return $out . '<p>There are no previous versions yet. They appear here after the next save.</p></section>';
    }
    $out .= '<ol class="version-list">';
    $n = 0;
    foreach ($versions as $v) {
        $n++;
        $label = history_version_words($v);
        $titleNote = $v['title'] !== '' && $v['title'] !== (get_item($type, $slug)['title'] ?? '') ? ' Title then: “' . h($v['title']) . '”.' : '';
        $out .= '<li>' . h($label) . '.' . $titleNote
            . ($v['same'] ? ' <span class="tag">Same as now</span>' : '')
            . ' <a href="' . h(admin_url('revisions', ['key' => "$type/$slug", 'view' => $v['id']])) . '">See it<span class="visually-hidden">, version ' . $n . ', ' . h($label) . '</span></a>';
        if (!$v['same']) {
            $out .= '<form method="post" action="' . h(admin_url('history')) . '" class="inline">' . csrf_field()
                . '<input type="hidden" name="type" value="' . h($type) . '"><input type="hidden" name="slug" value="' . h($slug) . '"><input type="hidden" name="id" value="' . h($v['id']) . '">'
                . '<button type="submit" class="button button-secondary">Put this version back<span class="visually-hidden">, version ' . $n . ', ' . h($label) . '</span></button></form>';
        }
        $out .= '</li>';
    }
    $out .= '</ol>';
    if ($all > count($versions)) {
        $out .= '<p><a href="' . h(admin_url('revisions', ['key' => "$type/$slug"])) . '">All ' . $all . ' earlier versions</a></p>';
    }
    return $out . '</section>';
}

function history_restore_action(): never
{
    $type = (string) ($_POST['type'] ?? '');
    $slug = (string) ($_POST['slug'] ?? '');
    $id = (string) ($_POST['id'] ?? '');
    if (!isset(HISTORY_TYPES[$type]) || !valid_slug($slug)) {
        flash('That page or post could not be found.', 'error');
        go(back_url());
    }
    // Authors reach blog posts only (lib/features/accounts.php).
    if ($type !== 'posts' && function_exists('role_at_least') && !role_at_least('editor')) {
        require_role('editor');
    }
    $edit = admin_url(['pages' => 'page', 'posts' => 'post', 'resources' => 'resource'][$type], ['slug' => $slug]) . '#previous-versions';
    try {
        $versions = array_column(history_versions($type, $slug, MAX_REVISIONS), null, 'id');
        $before = $versions[$id] ?? null;
        $saved = history_restore($type, $slug, $id);
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
        go($edit);
    }
    if (function_exists('activity_log')) {
        activity_log('put back an earlier version of a ' . HISTORY_TYPES[$type], "“{$saved['title']}” ($type/$slug)" . ($before ? ', the version ' . lcfirst(history_version_words($before)) : ''));
    }
    flash('Put back the version ' . ($before ? lcfirst(history_version_words($before)) : 'you chose') . ' of “' . $saved['title'] . '”. '
        . 'The version it replaced is now the first item under Previous versions, so you can undo this.');
    go($edit);
}
