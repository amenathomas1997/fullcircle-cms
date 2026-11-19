<?php
/**
 * Share-for-review links: a secret address, /review/<token>/, that shows one
 * page or post exactly as visitors will see it, without signing in. Useful
 * for showing a draft to a colleague or client before it goes live.
 *
 * Links live in data/review-links.json:
 *   token (32 hex characters) => ['type' => 'pages'|'posts', 'slug' => …, 'created_by' => name,
 *                                 'created' => ISO time, 'expires' => ISO time]
 * They last 7 days unless the person creating one picks 1 to 30. Expired or
 * revoked links show a friendly "This review link has expired" page. Review
 * pages are never cached and carry a noindex tag.
 *
 * The control panel part is a "Review links" section at the end of the page
 * and post forms (review_links_section()), handled by the hidden review_links
 * screen (POST only: create or revoke, publish or discard a pending edit).
 *
 * Pending edits (1.6.0): a page can be saved as a "pending edit" instead of going
 * live: the form values are kept in data/pending/pages/<slug>.json with who saved
 * them and when, the live page is untouched, and a review link can be made that
 * shows the pending edit ('pending' => true on the link). Whoever is allowed to
 * edit the page can then publish the pending edit (it goes through the ordinary
 * save, so it is checked and kept in the page's history) or discard it. A link to
 * a pending edit that has since been published or discarded shows the live page.
 */

declare(strict_types=1);

const REVIEW_LINKS_FILE = DATA_DIR . '/review-links.json';
const REVIEW_DEFAULT_DAYS = 7;
const REVIEW_MAX_DAYS = 30;
const PENDING_DIR = DATA_DIR . '/pending';
const PENDING_FIELDS = ['title', 'body', 'hide_title', 'description'];

register_route('review_route');
register_admin_screen('review_links', fn (bool $post) => $post ? review_links_action() : go(back_url()), null, null);
register_item_hook('after_delete', function (string $type, string $slug, array &$data): void {
    review_links_revoke_item($type, $slug);
});
register_cron('daily', 'review_links_tidy');

/* ---------- Storage ---------- */

function review_links_all(): array
{
    $links = read_json(REVIEW_LINKS_FILE) ?? [];
    return array_filter($links, fn($l, $t) => is_array($l) && preg_match('/^[0-9a-f]{32}$/', (string) $t), ARRAY_FILTER_USE_BOTH);
}

function review_links_save(array $links): void
{
    write_json(REVIEW_LINKS_FILE, $links);
    @chmod(REVIEW_LINKS_FILE, 0600);
}

function review_link_expired(array $link): bool
{
    return (strtotime((string) ($link['expires'] ?? '')) ?: 0) <= time();
}

/** Live links for one item, newest first. */
function review_links_for(string $type, string $slug): array
{
    $out = [];
    foreach (review_links_all() as $token => $link) {
        if (($link['type'] ?? '') === $type && ($link['slug'] ?? '') === $slug && !review_link_expired($link)) {
            $out[$token] = $link;
        }
    }
    uasort($out, fn($a, $b) => strcmp((string) $b['created'], (string) $a['created']));
    return $out;
}

function review_link_create(string $type, string $slug, int $days, string $creator, bool $pending = false): string
{
    $days = max(1, min(REVIEW_MAX_DAYS, $days));
    $links = review_links_all();
    $token = bin2hex(random_bytes(16));
    $links[$token] = [
        'type' => $type,
        'slug' => $slug,
        'created_by' => $creator,
        'created' => date('c'),
        'expires' => date('c', time() + $days * 86400),
        'pending' => $pending,
    ];
    review_links_save($links);
    return $token;
}

function review_link_revoke(string $token): bool
{
    $links = review_links_all();
    if (!isset($links[$token])) {
        return false;
    }
    unset($links[$token]);
    review_links_save($links);
    return true;
}

/** When an item is deleted, its links go too. */
function review_links_revoke_item(string $type, string $slug): void
{
    $links = review_links_all();
    $kept = array_filter($links, fn($l) => !(($l['type'] ?? '') === $type && ($l['slug'] ?? '') === $slug));
    if (count($kept) !== count($links)) {
        review_links_save($kept);
    }
}

/** Drop links that expired more than a week ago. The file stays small; recently expired ones still get the "expired" page. */
function review_links_tidy(): void
{
    $links = review_links_all();
    $kept = array_filter($links, fn($l) => (strtotime((string) ($l['expires'] ?? '')) ?: 0) > time() - 7 * 86400);
    if (count($kept) !== count($links)) {
        review_links_save($kept);
    }
}

function review_url(string $token): string
{
    return site_url("/review/$token/");
}

/* ---------- Public: /review/<token>/ ---------- */

/** The item a valid review link in this request unlocks, as [type, slug], set by review_route(). */
function reviewing(string $type, string $slug): bool
{
    return ($GLOBALS['review_item'] ?? null) === [$type, $slug];
}

function review_route(string $path, array $parts): bool
{
    if (($parts[0] ?? '') !== 'review' || count($parts) !== 2) {
        return false;
    }
    $token = $parts[1];
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    $link = preg_match('/^[0-9a-f]{32}$/', $token) ? (review_links_all()[$token] ?? null) : null;
    $item = $link ? get_item((string) $link['type'], (string) $link['slug']) : null;
    if (!$link || !$item || review_link_expired($link)) {
        review_expired_page($link);
    }
    $type = (string) $link['type'];
    $slug = (string) $link['slug'];
    current_lang(slug_lang($slug));
    $live = item_is_live($type, $item);
    // A link to a pending edit shows the page with that edit's title and content in place of the live ones.
    $pending = !empty($link['pending']) ? pending_edit_get($type, $slug) : null;
    if ($pending) {
        $values = array_intersect_key((array) $pending['values'], array_flip(PENDING_FIELDS));
        register_filter('page_item', fn (array $page) => array_merge($page, $values));
    }
    $banner = '<p class="review-banner" role="status" style="background:#ffee58;color:#111;padding:.75rem 1rem;border:3px solid #111;border-radius:8px;font-weight:700">'
        . h(sprintf(t($pending ? 'review_banner_pending' : ($live ? 'review_banner_live' : 'review_banner')), (string) $link['created_by'], format_date((string) $link['expires']))) . '</p>';
    $GLOBALS['layout_banner'] = $banner;
    $GLOBALS['review_item'] = [$type, $slug];
    if ($type === 'posts') {
        blog_post($slug);
    }
    show_page($slug, page_path($slug));
}

function review_expired_page(?array $link): never
{
    if ($link) {
        current_lang(slug_lang((string) ($link['slug'] ?? '')));
    }
    $content = '<h1 tabindex="-1" data-focus-on-load>' . h(t('review_expired_title')) . '</h1>'
        . '<p>' . h(t('review_expired_body')) . '</p>'
        . '<p>' . h(t('review_expired_contact')) . ' <a href="' . h(contact_path(current_lang())) . '">' . h(t('review_contact_link')) . '</a>.</p>'
        . '<p><a href="' . h(home_path()) . '">' . h(t('review_home_link')) . '</a></p>';
    send_html(render_layout(t('review_expired_title'), $content), 410);
}

/* ---------- Control panel ---------- */

/** The "Review links" section at the end of a page or post form. Nothing for a new, unsaved item. */
function review_links_section(string $type, ?string $slug): string
{
    if ($slug === null || !in_array($type, ['pages', 'posts'], true)) {
        return '';
    }
    $noun = $type === 'posts' ? 'post' : 'page';
    $links = review_links_for($type, $slug);
    $pending = $type === 'pages' ? pending_edit_get($type, $slug) : null;
    $out = '<section id="review-links" aria-labelledby="review-links-heading"><h2 id="review-links-heading">Review links</h2>'
        . '<p>A review link lets someone see this ' . $noun . ' exactly as visitors will, without signing in, even while it is a draft' . ($type === 'pages' ? ' or a pending edit' : '') . '. '
        . 'Send it by email or chat. Anyone with the link can open it until it expires, and you can revoke it at any time.</p>';
    if ($links) {
        $n = 0;
        foreach ($links as $token => $link) {
            $n++;
            $id = "review-link-$n";
            $out .= '<div class="menu-item"><div class="field"><label for="' . $id . '">Link ' . $n . ', shared by ' . h((string) $link['created_by'])
                . ', works until ' . h(format_time((string) $link['expires'])) . (!empty($link['pending']) ? ($pending ? ', shows the pending edit' : ', made for a pending edit that is gone, so it shows the page as it is') : '') . '</label>'
                . '<input type="text" id="' . $id . '" readonly value="' . h(review_url($token)) . '" spellcheck="false"></div>'
                . '<div class="menu-buttons"><a class="button button-secondary" href="' . h(review_url($token)) . '">Open<span class="visually-hidden"> review link ' . $n . '</span></a>'
                . '<form method="post" action="' . h(admin_url('review_links')) . '">' . csrf_field()
                . '<input type="hidden" name="do" value="revoke"><input type="hidden" name="token" value="' . h($token) . '">'
                . '<input type="hidden" name="type" value="' . h($type) . '"><input type="hidden" name="slug" value="' . h($slug) . '">'
                . '<button type="submit" class="button button-danger">Revoke<span class="visually-hidden"> review link ' . $n . '</span></button></form></div></div>';
        }
    } else {
        $out .= '<p>This ' . $noun . ' has no review links.</p>';
    }
    $options = '';
    foreach ([1, 2, 3, 5, 7, 10, 14, 21, 30] as $d) {
        $options .= '<option value="' . $d . '"' . ($d === REVIEW_DEFAULT_DAYS ? ' selected' : '') . '>' . $d . ' ' . ($d === 1 ? 'day' : 'days') . '</option>';
    }
    $out .= '<form method="post" action="' . h(admin_url('review_links')) . '">' . csrf_field()
        . '<input type="hidden" name="do" value="create"><input type="hidden" name="type" value="' . h($type) . '"><input type="hidden" name="slug" value="' . h($slug) . '">'
        . ($pending ? radio_field('shows', 'What the new link shows', ['pending' => 'The pending edit saved ' . format_time((string) $pending['saved']) . ' by ' . (string) $pending['saved_by'] . ' (not live yet)', 'live' => 'The page as it is now'], 'pending') : '')
        . '<div class="form-actions"><div class="field"><label for="review-days">Works for</label><select id="review-days" name="days">' . $options . '</select></div>'
        . '<button type="submit" class="button button-secondary">Create a review link</button></div></form></section>';
    return $out;
}

function review_links_action(): never
{
    $type = (string) ($_POST['type'] ?? '');
    $slug = (string) ($_POST['slug'] ?? '');
    $do = (string) ($_POST['do'] ?? '');
    if (!in_array($type, ['pages', 'posts'], true) || !valid_slug($slug)) {
        flash('That page or post could not be found.', 'error');
        go(back_url());
    }
    $editUrl = admin_url($type === 'posts' ? 'post' : 'page', ['slug' => $slug]) . '#review-links';
    $item = get_item($type, $slug);
    if (!$item) {
        flash('That page or post could not be found. It may have been deleted.', 'error');
        go(admin_url($type));
    }
    if ($do === 'create') {
        $days = (int) ($_POST['days'] ?? REVIEW_DEFAULT_DAYS);
        if ($days < 1 || $days > REVIEW_MAX_DAYS) {
            $days = REVIEW_DEFAULT_DAYS;
        }
        $pending = $type === 'pages' && ($_POST['shows'] ?? '') === 'pending' && pending_edit_get($type, $slug) !== null;
        $token = review_link_create($type, $slug, $days, (string) ($GLOBALS['current_editor'] ?? 'Site password'), $pending);
        flash('Created a review link for ' . ($pending ? 'the pending edit of ' : '') . '“' . (string) $item['title'] . '” that works until ' . format_time((string) review_links_all()[$token]['expires'])
            . '. It is the first link under Review links; copy it from the box there.');
        go($editUrl);
    }
    if ($do === 'publish_pending' || $do === 'discard_pending') {
        pending_edit_action($type, $slug, $do === 'publish_pending');
    }
    if ($do === 'revoke') {
        $token = (string) ($_POST['token'] ?? '');
        flash(preg_match('/^[0-9a-f]{32}$/', $token) && review_link_revoke($token)
            ? 'Revoked the review link. Anyone who had it now sees “This review link has expired”.'
            : 'That review link was already gone.');
        go($editUrl);
    }
    not_found_screen();
}

/* ---------- Pending edits of pages ---------- */

function pending_edit_file(string $type, string $slug): string
{
    if (!in_array($type, ITEM_TYPES, true) || !valid_slug($slug)) {
        throw new InvalidArgumentException('Invalid pending edit.');
    }
    return PENDING_DIR . "/$type/$slug.json";
}

/** The pending edit of an item, or null: ['values' => the form values, 'saved' => ISO time, 'saved_by' => name]. */
function pending_edit_get(string $type, string $slug): ?array
{
    if (!in_array($type, ITEM_TYPES, true) || !valid_slug($slug)) {
        return null;
    }
    $p = read_json(pending_edit_file($type, $slug));
    return $p && is_array($p['values'] ?? null) ? $p : null;
}

function pending_edit_save(string $type, string $slug, array $values, string $by): void
{
    write_json(pending_edit_file($type, $slug), ['values' => $values, 'saved' => date('c'), 'saved_by' => $by]);
    @chmod(pending_edit_file($type, $slug), 0600);
}

function pending_edit_delete(string $type, string $slug): void
{
    @unlink(pending_edit_file($type, $slug));
}

register_item_hook('after_delete', function (string $type, string $slug, array &$data): void {
    pending_edit_delete($type, $slug);
});

/** The notice at the top of the page form when a pending edit exists: who saved it, and the buttons to edit, publish or discard it. */
function pending_edit_notice(string $type, ?string $slug, bool $editingPending): string
{
    $p = $slug !== null ? pending_edit_get($type, $slug) : null;
    if (!$p) {
        return '';
    }
    $action = h(admin_url('review_links'));
    $hidden = csrf_field() . '<input type="hidden" name="type" value="' . h($type) . '"><input type="hidden" name="slug" value="' . h($slug) . '">';
    return '<section class="notice" id="pending-edit" aria-labelledby="pending-edit-heading"><h2 id="pending-edit-heading">' . ($editingPending ? 'You are editing the pending version' : 'There is a pending edit of this page') . '</h2>'
        . '<p>' . h((string) $p['saved_by']) . ' saved a pending edit on ' . h(format_time((string) $p['saved'])) . '. It is not on the website yet'
        . ($editingPending ? '; the form below shows it. “Save page” publishes it; “Save as a pending edit” keeps it waiting.' : '. The form below shows the page as it is live.') . '</p>'
        . '<div class="form-actions">'
        . ($editingPending ? '<a class="button button-secondary" href="' . h(admin_url('page', ['slug' => $slug])) . '">Show the live version instead</a>'
            : '<a class="button button-secondary" href="' . h(admin_url('page', ['slug' => $slug, 'pending' => 1])) . '">Edit the pending version</a>')
        . '<form method="post" action="' . $action . '" class="inline">' . $hidden . '<input type="hidden" name="do" value="publish_pending"><button type="submit" class="button">Publish the pending edit</button></form>'
        . '<form method="post" action="' . $action . '" class="inline">' . $hidden . '<input type="hidden" name="do" value="discard_pending"><button type="submit" class="button button-danger">Discard the pending edit</button></form>'
        . '</div></section>';
}

/** Publish a pending edit through the ordinary page save (so it is checked and kept in the page's history), or discard it. */
function pending_edit_action(string $type, string $slug, bool $publish): never
{
    $edit = admin_url('page', ['slug' => $slug]);
    $p = pending_edit_get($type, $slug);
    if (!$p || $type !== 'pages') {
        flash('There is no pending edit of that page any more.', 'error');
        go($edit);
    }
    if (!$publish) {
        pending_edit_delete($type, $slug);
        if (function_exists('activity_log')) {
            activity_log('discarded a pending edit', "pages/$slug, saved by {$p['saved_by']}");
        }
        flash('Discarded the pending edit. The page is as it was.');
        go($edit);
    }
    // page_save() reads the form from $_POST: give it the pending values and let it publish them.
    $_POST = ['csrf' => $_POST['csrf'] ?? ''] + (array) $p['values'] + ['then' => 'edit', 'from_pending' => '1'];
    unset($_POST['updated']); // the live page may have been saved since; the pending edit knowingly replaces it
    $_GET['slug'] = $slug;
    page_save();
}
