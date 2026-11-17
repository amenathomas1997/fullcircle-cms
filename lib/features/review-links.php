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
 * screen (POST only: create or revoke).
 */

declare(strict_types=1);

const REVIEW_LINKS_FILE = DATA_DIR . '/review-links.json';
const REVIEW_DEFAULT_DAYS = 7;
const REVIEW_MAX_DAYS = 30;

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

function review_link_create(string $type, string $slug, int $days, string $creator): string
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
    $banner = '<p class="review-banner" role="status" style="background:#ffee58;color:#111;padding:.75rem 1rem;border:3px solid #111;border-radius:8px;font-weight:700">'
        . h(sprintf(t($live ? 'review_banner_live' : 'review_banner'), (string) $link['created_by'], format_date((string) $link['expires']))) . '</p>';
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
    $out = '<section id="review-links" aria-labelledby="review-links-heading"><h2 id="review-links-heading">Review links</h2>'
        . '<p>A review link lets someone see this ' . $noun . ' exactly as visitors will, without signing in, even while it is a draft. '
        . 'Send it by email or chat. Anyone with the link can open it until it expires, and you can revoke it at any time.</p>';
    if ($links) {
        $n = 0;
        foreach ($links as $token => $link) {
            $n++;
            $id = "review-link-$n";
            $out .= '<div class="menu-item"><div class="field"><label for="' . $id . '">Link ' . $n . ', shared by ' . h((string) $link['created_by'])
                . ', works until ' . h(format_time((string) $link['expires'])) . '</label>'
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
        $token = review_link_create($type, $slug, $days, (string) ($GLOBALS['current_editor'] ?? 'Site password'));
        flash('Created a review link for “' . (string) $item['title'] . '” that works until ' . format_time((string) review_links_all()[$token]['expires'])
            . '. It is the first link under Review links; copy it from the box there.');
        go($editUrl);
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
