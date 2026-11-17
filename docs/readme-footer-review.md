## Footer screen, scheduled pages and review links

Three additions in `lib/features/`, registered through `lib/registry.php`.

### Footer (`lib/features/footer.php`)

- A **Footer** control panel screen (`/admin/?a=footer`, `&lang=es` for the Spanish footer when Spanish pages are on). It saves to `content/settings.json` as `footer_groups` and `footer_groups_es`, each shaped like `{"contact": true, "groups": [{"heading": "More", "links": [{"label": "About", "url": "/about/"}]}], "small_print": ""}`.
- `render_footer($lang)` fills the `{{footer}}` placeholder. It builds `<div class="footer-columns">` with one `<section aria-labelledby>` per column (the same markup the stylesheet already styles), the Contact column from `contact_phone()` / `contact_email()` (or a link to `contact_path()` when both are empty), and `<p class="small-print">` under the columns. Links to the other language get `lang` and `hreflang`.
- Fallback: when a language has no groups and no Contact column, the raw `footer_html` / `footer_html_es` from Site template is used, so sites set up before this change look the same as before. The Site template screen says so next to those boxes. Spanish pages fall back in this order: Spanish groups, Spanish raw HTML, English groups, English raw HTML.
- `lib/starter.php` now writes `footer_groups` (and `footer_groups_es`) for a new site instead of raw footer HTML.
- `lib/core.php` changed on one line: the `{{footer}}` entry calls `render_footer()` when it exists.
- New `t()` keys: `footer_contact`, `footer_links`, `footer_call`, `footer_email`, `footer_contact_us`.

### Scheduled pages (`lib/features/scheduled-pages.php`)

- Pages get an optional `publish_at` (`YYYY-MM-DDTHH:MM`, site time zone, like a post's `date`). The field is on the page form in `admin/index.php`; `page_save()` validates it with `clean_publish_at()` and drops the key when empty.
- `page_is_live()`, `page_is_scheduled()` and `item_is_live()` hold the rule. `show_page()` in `index.php` hides a published page whose `publish_at` is in the future unless `can_preview()` (signed in) or a review link for that page is in play. `status_label()` shows "Scheduled" for such pages.
- Menus are manual, so a scheduled page is not added or removed from them. `sitemap.xml` still lists every published page, including scheduled ones; a one-line `page_is_live()` check in `sitemap()` would tighten that if wanted.

### Review links (`lib/features/review-links.php`)

- `data/review-links.json` (mode 0600): `token => {type, slug, created_by, created, expires}`. Tokens are 32 hex characters from `random_bytes(16)`. Default life 7 days; 1 to 30 selectable; anything else is clamped to 7.
- Public route `/review/<token>/` (`register_route`). A valid, unexpired token sets `$GLOBALS['review_item']` and `$GLOBALS['layout_banner']`, then calls `show_page()` or `blog_post()`, so the review copy is the real page in the real layout. The visibility checks in `show_page()` (index.php) and `blog_post()` (lib/blog.php) accept `reviewing($type, $slug)` beside `can_preview()`. The response carries `Cache-Control: no-store` and `X-Robots-Tag: noindex`, and `render_layout()` prepends the banner (`role="status"`) and inserts `<meta name="robots" content="noindex">` when `$GLOBALS['layout_banner']` is set (a four-line addition in `lib/core.php`).
- Expired, revoked, unknown or deleted-item tokens get a 410 page, "This review link has expired", with a contact link, in the item's language.
- Control panel: `review_links_section()` is appended to the page and post forms (one line each in `admin/index.php`), listing live links in read-only text boxes with Open and Revoke, and a Create form. Both POST to the hidden `review_links` screen (no menu entry; GET redirects back). Revoke is one click with a flash, as the brief allows.
- An `after_delete` item hook removes the links of a deleted page or post; a daily cron job (`register_cron('daily')`) prunes entries that expired more than a week ago.
- New `t()` keys: `review_banner`, `review_banner_live`, `review_expired_title`, `review_expired_body`, `review_expired_contact`, `review_contact_link`, `review_home_link`.

### Core edits in this change

- `lib/core.php`: `{{footer}}` line; banner/noindex block in `render_layout()`.
- `index.php`: the visibility check in `show_page()`.
- `lib/blog.php`: the visibility check in `blog_post()`.
- `admin/index.php`: `status_label()` reads `publish_at` for pages; `page_form()` gains the Publish on field and the Review links section; `page_save()` validates and stores `publish_at` and says when a page is scheduled; `post_form()` gains the Review links section; `template_form()` footer hints mention the Footer screen.
- `lib/starter.php`: footer groups instead of footer HTML.
- `lib/i18n.php`: the keys above.
