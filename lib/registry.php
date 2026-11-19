<?php
/**
 * How optional features and a site's own code plug into the CMS without
 * editing its core files. A feature is a PHP file in lib/features/ (part of
 * the kit) or site/ (one website's own additions). Each file registers what
 * it adds when it is loaded by lib/bootstrap.php:
 *
 *   register_route(fn (string $path, array $parts): bool => ...)
 *       Public addresses. Return true after sending the page (send_html() exits), false to pass.
 *   register_shortcode('[my-thing]', fn (string $path): string => ...)
 *       Text an editor can put in a page, swapped for HTML. Handle its own POSTs inside the callable.
 *   register_admin_screen('things', fn (bool $post) => ..., 'Things', 'things')
 *       A control panel screen at /admin/?a=things, its menu label, and the feature switch that shows it (or null).
 *   register_settings_section(fn (array $errors): string => ..., 'things', fn (): never => ...)
 *       A section on the Settings screen and the handler for its form (part=things).
 *   register_dashboard_card(fn (): string => ...)
 *       One <li> for the Dashboard.
 *   register_feature('things', 'Things', 'What it does', false)
 *       A switch under Settings > Features, off by default unless the last argument is true.
 *   register_cron('often' | 'hourly' | 'daily', fn (): void => ...)
 *       Work for tools/send-updates.php (every 10 minutes) and tools/check-links.php (hourly, and daily after 6 am).
 *   register_item_hook('before_save' | 'after_save' | 'after_delete', fn (string $type, string $slug, array &$data): void => ...)
 *       Runs around save_item() and delete_item().
 *   register_placeholder('{{my_link}}', fn (): string => ...)
 *       A placeholder a site's layout template can use; filled on every page.
 *   register_filter('organization', fn (array $org): array => ...)
 *       Change a value the core builds before it is used. Names so far: 'organization' (the schema.org
 *       data on every page), 'layout_replacements' (every template placeholder), 'robots' (robots.txt text),
 *       'page_item' (the page about to be shown, so a review link can show a pending edit).
 *
 * Everything registered is kept in $GLOBALS['registry'] and read by the core at the right moment.
 */

declare(strict_types=1);

$GLOBALS['registry'] = ['routes' => [], 'shortcodes' => [], 'admin' => [], 'settings' => [], 'cards' => [], 'features' => [], 'cron' => [], 'items' => [], 'filters' => [], 'placeholders' => []];

function register_route(callable $handler): void
{
    $GLOBALS['registry']['routes'][] = $handler;
}

function register_shortcode(string $code, callable $render): void
{
    $GLOBALS['registry']['shortcodes'][$code] = $render;
}

function register_admin_screen(string $action, callable $handler, ?string $navLabel = null, ?string $feature = null): void
{
    $GLOBALS['registry']['admin'][$action] = ['handler' => $handler, 'label' => $navLabel, 'feature' => $feature];
}

function register_settings_section(callable $html, ?string $part = null, ?callable $save = null): void
{
    $GLOBALS['registry']['settings'][] = ['html' => $html, 'part' => $part, 'save' => $save];
}

function register_dashboard_card(callable $html): void
{
    $GLOBALS['registry']['cards'][] = $html;
}

function register_feature(string $key, string $label, string $description, bool $default = false): void
{
    $GLOBALS['registry']['features'][$key] = [$label, $description, $default];
}

function register_cron(string $when, callable $job): void
{
    $GLOBALS['registry']['cron'][$when][] = $job;
}

function register_item_hook(string $event, callable $fn): void
{
    $GLOBALS['registry']['items'][$event][] = $fn;
}

function register_placeholder(string $placeholder, callable $fn): void
{
    $GLOBALS['registry']['placeholders'][$placeholder] = $fn;
}

function register_filter(string $name, callable $fn): void
{
    $GLOBALS['registry']['filters'][$name][] = $fn;
}

/** Pass a value through every filter registered under $name. */
function apply_filters(string $name, mixed $value): mixed
{
    foreach (registered('filters')[$name] ?? [] as $fn) {
        $value = $fn($value);
    }
    return $value;
}

/* ---------- Read by the core ---------- */

function registered(string $kind): array
{
    return $GLOBALS['registry'][$kind] ?? [];
}

/** Give registered routes a chance to answer; each one exits if it does. */
function run_registered_routes(string $path, array $parts): void
{
    foreach (registered('routes') as $handler) {
        if ($handler($path, $parts)) {
            exit;
        }
    }
}

/** Run every job registered for a cron slot, keeping one failure from stopping the rest. */
function run_cron(string $when): void
{
    foreach (registered('cron')[$when] ?? [] as $job) {
        try {
            $job();
        } catch (Throwable $e) {
            error_log("Cron job ($when) failed: " . $e->getMessage());
        }
    }
}

function run_item_hooks(string $event, string $type, string $slug, array &$data): void
{
    foreach (registered('items')[$event] ?? [] as $fn) {
        $fn($type, $slug, $data);
    }
}

/** Load every feature file in the kit and every file of this site's own code. */
function load_features(): void
{
    foreach (array_merge(glob(ROOT . '/lib/features/*.php') ?: [], glob(ROOT . '/site/*.php') ?: []) as $file) {
        require_once $file;
    }
}
