# Nested pages

A page can live inside another page. Services at `/services/`, Reiki inside it at `/services/reiki/`, and Pricing inside that at `/services/reiki/pricing/`. Pages nest up to three levels deep. The code is in `lib/features/nested-pages.php`, with small hooks in `index.php`, `lib/i18n.php` (`page_path()`) and `admin/index.php`.

## For site owners

- **Choosing a parent.** On the page form, under the address, "Parent page" lists the pages this page can go inside, each with the address it would give ("Services (/services/)"). The list only shows pages in the same language; a Spanish page (address starting `es-`) can only go inside another Spanish page, and its address still starts with `/es/`. The page itself and the pages inside it are left out, and so are pages that would take the family past three levels. For a new page the list shows English pages until a Spanish address is saved.
- **The Pages list** shows pages as a tree: a page inside another is listed under its parent, indented, and screen readers hear "Level 2:" or "Level 3:" before its title.
- **Addresses.** A page's address is its parent's address plus its own name. A page inside another keeps its own name unique across the site, so two pages can't both be called `pricing` even under different parents. Visiting a page by the wrong address (`/pricing/` instead of `/services/reiki/pricing/`, or an old address after the page moved) sends the visitor on to the right one with a permanent redirect, so old links keep working.
- **Breadcrumbs.** A nested page shows a "You are here" trail above its heading (Home › Services › Reiki › Pricing); on Spanish pages, "Está aquí" and "Inicio". Search engines get the same trail as BreadcrumbList structured data.
- **Renaming or moving.** Changing a page's address or parent carries the pages inside it along, and menu links to any address that changed are updated.
- **Deleting.** Deleting a page that has pages inside it asks what should happen to them: move them up a level (the default; they take the deleted page's place, and their addresses and menu links change) or delete them too. A copy of every deleted page is kept under Earlier versions.
- **Menus** are unchanged: link to the full nested address, for example `/services/reiki/`. A menu item for `/services/` is marked as the current section on every page inside it.

## For developers

Storage: `content/pages/<slug>.json` gains an optional `"parent": "<slug>"`. The slug stays unique site-wide, so file names and `get_item('pages', $slug)` are unchanged. A parent that is missing, is the page itself, is in the other language, is `home` or `es`, or forms a loop is ignored and the page is treated as top level.

Functions in `lib/features/nested-pages.php`:

| Function | What it does |
| --- | --- |
| `page_parent($pageOrSlug)` | The parent slug when it points at a real page in the same language, else null. |
| `page_ancestors($slug)` | Ancestor slugs, top level first; `[]` for a top-level page, a loop or a chain deeper than three levels. |
| `page_level($slug)`, `page_children($slug)`, `page_descendants($slug)`, `page_subtree_height($slug)` | Tree helpers. |
| `page_parent_path($slug)` | The part of the address that comes from the parents (`/services/reiki/`), or `""`. `page_path()` in `lib/i18n.php` puts it in front of the page's own segment. |
| `page_by_path(array $parts)` | The slug at exactly that address (`['es', 'servicios', 'reiki']`), or null. |
| `page_at_wrong_path(array $parts)` | The page the last segment names when it lives at another address, so `index.php` can redirect. |
| `breadcrumbs_html($slug)` | The `<nav aria-label="You are here"><ol class="breadcrumbs">` trail plus `add_structured_data()`; `""` for a top-level page. Called by `show_page()`. |
| `parent_field()`, `clean_parent()`, `page_tree_paths()`, `nested_page_saved()`, `menu_follow_moves()`, `delete_children_field()`, `nested_before_delete()`, `pages_in_tree_order()` | Control panel pieces used by `page_form()`, `page_save()`, `delete_confirm()`, `item_delete()` and `pages_list()`. |

Routing in `index.php`: registered feature routes still answer first. After them, `page_by_path()` is tried for any address (and inside the `/es/` block for Spanish ones); if it finds nothing but `page_at_wrong_path()` names a page, the visitor is redirected (301) to `page_path()` of that page. One-segment addresses then fall through to `show_page()` exactly as before, so unknown addresses still 404.

Always build page addresses with `page_path($slug)`; never `"/$slug/"`. The sitemap, site search, the assistant, review links and the control panel already do.

Styles: `.breadcrumbs` at the end of `assets/css/site.css` (tokens, AA contrast, forced-colors rule); `.tree-level-2` and `.tree-level-3` in `admin/admin.css` indent the Pages list.

Text: `you_are_here` and `home` in `lib/i18n.php` (English and Spanish).
