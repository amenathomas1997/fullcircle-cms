# Extending Full Circle CMS

Features live in their own files and plug into the core through a small registry (`lib/registry.php`). The core files (`index.php`, `lib/core.php`, `admin/index.php`, `admin/admin-lib.php`) should rarely need editing.

## Where code goes

- `lib/features/<name>.php` — a feature that ships with the kit. Loaded on every request, public and control panel.
- `site/<name>.php` — one website's own additions (its old business-specific shortcodes, for example). Same rules, not part of the kit.
- `admin/<name>.js`, `assets/js/<name>.js` — scripts, loaded by the screen that needs them.

A feature file registers what it adds at load time:

```php
<?php
declare(strict_types=1);

register_feature('events', 'Events', 'Classes and events with an Upcoming list and a calendar feed.');

register_route(function (string $path, array $parts): bool {
    if ($parts[0] ?? '' !== 'events' || !feature_on('events')) {
        return false;
    }
    send_html(render_layout('Events', events_html()));   // never returns
});

register_shortcode('[upcoming-events]', fn (string $path) => feature_on('events') ? upcoming_html() : '');

register_admin_screen('events', fn (bool $post) => $post ? events_save() : events_screen(), 'Events', 'events');

register_settings_section(fn (array $errors) => events_settings_html($errors), 'events', fn () => events_settings_save());

register_dashboard_card(fn () => '<li><h2><a href="' . h(admin_url('events')) . '">Events</a></h2><p>…</p></li>');

register_cron('daily', fn () => events_tidy());
```

## Rules every feature follows

1. **Escape everything.** `h()` for text in HTML, `xml()` in feeds. Content authors' HTML (`$page['body']`) is trusted; visitor input never is.
2. **Accessible markup, the same way as the rest of the panel.** Build forms with `text_field()`, `textarea_field()`, `checkbox_field()`, `radio_field()`, `csrf_field()`; errors go in the `$errors` array keyed by field id and are shown by `admin_screen()` as a summary that links to the field. Every screen has one `<h1>` (added by `admin_screen()`), headings in order, tables with `<th scope>`, buttons that say what they do, status messages with `flash()`.
3. **POST changes state, GET never does.** `admin/index.php` checks the CSRF token for every POST; a screen handler receives `$post` and branches on it. Destructive actions get a confirm screen.
4. **Feature switches.** Call `register_feature()` and check `feature_on('name')` in routes, shortcodes and screens. Off means the pages 404 and the screens vanish; nothing is deleted.
5. **Settings, never code.** Site-specific values come from `settings()` (public, in content/settings.json) or `config()` (secrets, in data/config.php). Use `site_name()`, `contact_email()`, `contact_phone()`, `contact_path()`, `site_url()`, `site_host()`.
6. **Files.** Write with `write_json()` / `write_file()` (atomic). Keep data under `DATA_DIR` (private) or `CONTENT_DIR` (content that belongs to the site). Validate slugs with `valid_slug()` before touching a path.
7. **Email** goes through `send_mail_to()` or `send_mail_owner()`; never call a transport directly.
8. **Language.** Public strings in both English and Spanish: add keys to `lib/i18n.php` and use `t('key')`; check `current_lang()`.
9. **strict_types** at the top of every PHP file; PHP 8.1 syntax; no Composer packages unless the feature already needs one (only the assistant does).
10. **Test on a fresh install:** `php tools/setup.php --yes` with `SETUP_*` variables, `php -S localhost:8000 router.php`, then exercise the pages and screens, check `php -l` on every file and that the dev server log shows no warnings.

## Blocks editor block types

`admin/blocks-editor.js` turns HTML into a list of blocks and back. A new block type needs: an entry in `TYPES`, a parser branch in `parse()` that recognises exactly the HTML it writes, a `serialize()` case that writes it, a `renderBlock()` branch of ordinary labelled fields, and a `changeType()` conversion. Everything the parser doesn't recognise becomes an HTML block, so round trips are lossless.

## Item hooks

`save_item()` and `delete_item()` call `run_item_hooks('before_save' | 'after_save' | 'after_delete', $type, $slug, $data)`. Use `before_save` to validate or annotate (for example the accessibility check adds `$data['a11y']`), `after_save` to rebuild indexes.
