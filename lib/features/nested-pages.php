<?php
/**
 * Nested pages: a page can live inside another page, giving addresses like
 * /services/reiki/ and /services/reiki/pricing/ (up to three levels).
 *
 * A page file carries an optional "parent" (the slug of the parent page). The
 * slug itself stays unique across the site, so the file name is unchanged and
 * links by slug keep working; only the public address is built from the
 * ancestry (page_path() in lib/i18n.php asks page_parent_path() for the part
 * in front of the page's own segment). A Spanish page ("es-...") may only nest
 * under another Spanish page, and every Spanish address still starts with /es/.
 *
 * A parent that is missing, in the other language, the page itself or part of
 * a loop is ignored: the page is treated as top level.
 *
 * Public side (index.php): page_by_path() turns /services/reiki/pricing/ into
 * the slug "pricing"; page_at_wrong_path() finds the page a wrong address was
 * aiming at so it can be redirected; breadcrumbs_html() adds the "You are here"
 * trail and BreadcrumbList structured data. Control panel (admin/index.php):
 * the Parent page select, the Pages tree, and what happens to children when a
 * page is renamed, moved or deleted.
 */

declare(strict_types=1);

const PAGE_MAX_LEVEL = 3;

/** The parent slug of a page, when it points at a real page in the same language; null otherwise. */
function page_parent(array|string $page): ?string
{
    $slug = is_array($page) ? (string) ($page['slug'] ?? '') : $page;
    if (is_string($page)) {
        $page = get_item('pages', $slug);
        if (!$page) {
            return null;
        }
    }
    $parent = (string) ($page['parent'] ?? '');
    if ($parent === '' || $parent === $slug || !valid_slug($parent) || in_array($parent, ['home', second_lang()], true)
        || slug_lang($parent) !== slug_lang($slug) || !get_item('pages', $parent)) {
        return null;
    }
    return $parent;
}

/**
 * Ancestors of a page, top level first; an empty array for a top-level page. A loop, or a chain deeper
 * than three levels (only possible by editing files by hand), makes the page top level.
 */
function page_ancestors(string $slug): array
{
    $seen = [$slug => true];
    $chain = [];
    $current = $slug;
    while (($parent = page_parent($current)) !== null) {
        if (isset($seen[$parent]) || count($chain) >= PAGE_MAX_LEVEL - 1) {
            return [];
        }
        $seen[$parent] = true;
        array_unshift($chain, $parent);
        $current = $parent;
    }
    return $chain;
}

/** 1 for a top-level page, 2 for a page inside one, 3 for a page inside that. */
function page_level(string $slug): int
{
    return count(page_ancestors($slug)) + 1;
}

/** The part of a page's address that comes from its parents ("/services/reiki/"), or "" for a top-level page. */
function page_parent_path(string $slug): string
{
    if (!valid_slug($slug) || !($ancestors = page_ancestors($slug))) {
        return '';
    }
    $path = home_path(slug_lang($slug));
    foreach ($ancestors as $ancestor) {
        $path .= page_segment($ancestor) . '/';
    }
    return $path;
}

/** The page's own part of its address: "reiki" for the slug "es-reiki". */
function page_segment(string $slug): string
{
    return slug_without_lang($slug);
}

/** Pages whose parent is this page, in title order. */
function page_children(string $slug): array
{
    return array_values(array_filter(all_items('pages'), fn($p) => page_parent($p) === $slug));
}

/** This page and everything inside it, parents before children. */
function page_descendants(string $slug, int $depth = 0): array
{
    $out = [];
    if ($depth >= PAGE_MAX_LEVEL) {
        return $out;
    }
    foreach (page_children($slug) as $child) {
        $out[] = $child['slug'];
        $out = array_merge($out, page_descendants($child['slug'], $depth + 1));
    }
    return $out;
}

/** How many levels a page and its children take up: 1 when it has no children. */
function page_subtree_height(string $slug, int $depth = 0): int
{
    $height = 1;
    if ($depth < PAGE_MAX_LEVEL) {
        foreach (page_children($slug) as $child) {
            $height = max($height, 1 + page_subtree_height($child['slug'], $depth + 1));
        }
    }
    return $height;
}

/* ---------- Public site ---------- */

/** The page named by the last segment of an address: "pricing" for ['services', 'reiki', 'pricing'], "es-reiki" under /es/. */
function page_slug_for_parts(array $parts): ?string
{
    $prefix = '';
    if (is_second_lang($parts[0] ?? '')) {
        $prefix = second_lang() . '-';
        $parts = array_slice($parts, 1);
    }
    if (!$parts || count($parts) > PAGE_MAX_LEVEL) {
        return null;
    }
    $slug = $prefix . end($parts);
    return valid_slug($slug) && get_item('pages', $slug) ? $slug : null;
}

/**
 * The page at a nested address, as a slug, or null when no page lives at exactly that address.
 * $parts are the address segments: ['services', 'reiki'] or ['es', 'servicios', 'reiki'].
 */
function page_by_path(array $parts): ?string
{
    $slug = page_slug_for_parts($parts);
    return $slug !== null && page_path($slug) === '/' . implode('/', $parts) . '/' ? $slug : null;
}

/** The page a wrong address was aiming at (by its last segment), when it lives somewhere else; null otherwise. */
function page_at_wrong_path(array $parts): ?string
{
    $slug = page_slug_for_parts($parts);
    return $slug !== null && page_path($slug) !== '/' . implode('/', $parts) . '/' ? $slug : null;
}

/** The "You are here" trail for a nested page, with BreadcrumbList structured data; "" for a top-level page. */
function breadcrumbs_html(string $slug): string
{
    $ancestors = page_ancestors($slug);
    if (!$ancestors) {
        return '';
    }
    $lang = slug_lang($slug);
    $trail = [[home_path($lang), t('home')]];
    foreach ($ancestors as $ancestor) {
        $trail[] = [page_path($ancestor), (string) (get_item('pages', $ancestor)['title'] ?? $ancestor)];
    }
    $page = get_item('pages', $slug);
    $trail[] = [page_path($slug), (string) ($page['title'] ?? $slug)];

    $items = '';
    $list = [];
    foreach ($trail as $i => [$path, $title]) {
        $last = $i === count($trail) - 1;
        $items .= $last ? '<li><a href="' . h($path) . '" aria-current="page">' . h($title) . '</a></li>'
            : '<li><a href="' . h($path) . '">' . h($title) . '</a></li>';
        $list[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $title, 'item' => site_url($path)];
    }
    add_structured_data(['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list]);
    return '<nav aria-label="' . h(t('you_are_here')) . '"><ol class="breadcrumbs">' . $items . "</ol></nav>\n";
}

/* ---------- Control panel ---------- */

/** The "Parent page" select for the page form. $slug is the page's address so far ("" for a new page without one). */
function parent_field(array $v, ?string $origSlug): string
{
    $slug = (string) ($v['slug'] ?: ($origSlug ?? ''));
    if ($slug === 'home' || $slug === second_lang()) {
        return '';
    }
    $lang = slug_lang($slug);
    $current = (string) ($v['parent'] ?? '');
    // Pages inside this one can't become its parent, and the whole family must fit in three levels.
    $excluded = $origSlug !== null ? array_merge([$origSlug], page_descendants($origSlug)) : [];
    $height = $origSlug !== null ? page_subtree_height($origSlug) : 1;
    $options = '<option value="">None (a top-level page)</option>';
    foreach (all_items('pages') as $p) {
        if (slug_lang($p['slug']) !== $lang || in_array($p['slug'], ['home', second_lang()], true) || in_array($p['slug'], $excluded, true)
            || page_level($p['slug']) + $height > PAGE_MAX_LEVEL) {
            continue;
        }
        $options .= '<option value="' . h($p['slug']) . '"' . ($current === $p['slug'] ? ' selected' : '') . '>'
            . h($p['title']) . ' (' . h(page_path($p['slug'])) . ')</option>';
    }
    $hint = 'Put this page inside another one: its address then starts with the parent’s, for example <code>/services/reiki/</code>. '
        . 'Pages can be nested up to three levels deep. '
        . (is_second_lang($lang) ? 'A ' . h(second_lang_name()) . ' page can only go inside another ' . h(second_lang_name()) . ' page.'
            : (second_lang() !== '' ? 'The list shows ' . h(language_name(primary_lang())) . ' pages; save a ' . h(second_lang_name()) . ' address (starting with <code>' . h(second_lang()) . '-</code>) first to choose a ' . h(second_lang_name()) . ' parent.' : ''));
    return '<div class="field"><label for="parent">Parent page</label>'
        . '<p class="hint" id="parent-hint">' . $hint . '</p>'
        . '<select id="parent" name="parent" aria-describedby="parent-hint">' . $options . '</select></div>';
}

/** The posted parent for a page about to be saved as $slug: the slug when it is allowed, "" otherwise. */
function clean_parent(string $slug, string $parent, ?string $origSlug): string
{
    if ($parent === '' || !valid_slug($parent) || $parent === $slug || in_array($parent, ['home', second_lang()], true)
        || in_array($slug, ['home', 'es'], true) || slug_lang($parent) !== slug_lang($slug) || !get_item('pages', $parent)) {
        return '';
    }
    $family = $origSlug !== null ? array_merge([$origSlug], page_descendants($origSlug)) : [];
    if (in_array($parent, $family, true)) {
        return '';
    }
    $height = $origSlug !== null ? page_subtree_height($origSlug) : 1;
    return page_level($parent) + $height > PAGE_MAX_LEVEL ? '' : $parent;
}

/** Addresses of a page and everything inside it, by slug, so they can be compared after a change. */
function page_tree_paths(string $slug): array
{
    $paths = [];
    foreach (array_merge([$slug], page_descendants($slug)) as $s) {
        $paths[$s] = page_path($s);
    }
    return $paths;
}

/** After a page was saved: children follow a renamed slug, and menu links follow every address that changed. */
function nested_page_saved(?string $origSlug, string $slug, array $oldPaths): void
{
    if ($origSlug !== null && $origSlug !== $slug) {
        foreach (all_items('pages') as $p) {
            if (($p['parent'] ?? '') === $origSlug) {
                $p['parent'] = $slug;
                save_item('pages', $p['slug'], $p);
            }
        }
    }
    $moves = [];
    foreach ($oldPaths as $s => $old) {
        $new = page_path($s === $origSlug ? $slug : $s);
        if ($new !== $old) {
            $moves[$old] = $new;
        }
    }
    menu_follow_moves($moves);
}

/** Point menu links at new addresses: $moves is old address => new address. */
function menu_follow_moves(array $moves): void
{
    if (!$moves) {
        return;
    }
    $settings = settings();
    $changed = false;
    foreach (['menu', 'menu_es'] as $key) {
        foreach ($settings[$key] as &$link) {
            if (isset($moves[$link['url'] ?? ''])) {
                $link['url'] = $moves[$link['url']];
                $changed = true;
            }
        }
        unset($link);
    }
    if ($changed) {
        save_settings($settings);
    }
}

/** The question on the delete screen when the page has children; "" when it has none. */
function delete_children_field(string $slug): string
{
    $children = page_children($slug);
    if (!$children) {
        return '';
    }
    $names = implode(', ', array_map(fn($c) => '“' . h($c['title']) . '”', $children));
    $parent = page_parent($slug);
    $up = $parent !== null ? 'Move them up a level, inside “' . h((string) (get_item('pages', $parent)['title'] ?? $parent)) . '”'
        : 'Move them up a level, so they become top-level pages';
    return '<p>This page has ' . count($children) . ' page' . (count($children) === 1 ? '' : 's') . ' inside it: ' . $names . '.</p>'
        . radio_field('children', 'What should happen to the pages inside it?', ['up' => $up . ' (their addresses change)', 'delete' => 'Delete them too (a copy of each is kept)'], 'up');
}

/** Before a page is deleted: move its children up a level, or delete them too. Returns the addresses of pages that were deleted. */
function nested_before_delete(string $slug, string $mode): array
{
    $deleted = [];
    if ($mode === 'delete') {
        foreach (array_reverse(page_descendants($slug)) as $child) {
            $deleted[] = page_path($child);
            delete_item('pages', $child);
        }
        return $deleted;
    }
    $parent = page_parent($slug);
    $oldPaths = [];
    foreach (page_descendants($slug) as $d) {
        $oldPaths[$d] = page_path($d);
    }
    foreach (page_children($slug) as $child) {
        if ($parent !== null) {
            $child['parent'] = $parent;
        } else {
            unset($child['parent']);
        }
        save_item('pages', $child['slug'], $child);
    }
    $moves = [];
    foreach ($oldPaths as $d => $old) {
        if (page_path($d) !== $old) {
            $moves[$old] = page_path($d);
        }
    }
    menu_follow_moves($moves);
    return $deleted;
}

/** Every page as a tree for the Pages list: parents before children, each with a "level" of 1, 2 or 3. */
function pages_in_tree_order(): array
{
    $byParent = [];
    foreach (all_items('pages') as $p) {
        $byParent[page_parent($p) ?? ''][] = $p;
    }
    $out = [];
    $walk = function (string $parent, int $level) use (&$walk, &$out, $byParent): void {
        foreach ($byParent[$parent] ?? [] as $p) {
            $p['level'] = $level;
            $out[] = $p;
            if ($level < PAGE_MAX_LEVEL) {
                $walk($p['slug'], $level + 1);
            }
        }
    };
    $walk('', 1);
    // Anything left out (a loop among pages whose parents all exist) is listed at the top level rather than lost.
    $listed = array_column($out, 'slug');
    foreach (all_items('pages') as $p) {
        if (!in_array($p['slug'], $listed, true)) {
            $p['level'] = 1;
            $out[] = $p;
        }
    }
    return $out;
}
