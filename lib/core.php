<?php
/**
 * Full Circle CMS — shared code for the public site and the control panel.
 *
 * Content lives in flat files (no database):
 *   content/pages/<slug>.json   one file per page
 *   content/posts/<slug>.json   one file per blog post
 *   content/resources/<slug>.json   one file per cheat sheet
 *   content/settings.json       site name, menu, etc.
 *   content/layout.html         the HTML template wrapped around every page
 *   content/revisions/          earlier versions, saved automatically
 */

declare(strict_types=1);

const ROOT = __DIR__ . '/..';
const CONTENT_DIR = ROOT . '/content';
const DATA_DIR = ROOT . '/data';
const UPLOAD_DIR = ROOT . '/uploads';
const STYLESHEET = ROOT . '/assets/css/site.css';
const MAX_REVISIONS = 25;
const ITEM_TYPES = ['pages', 'posts', 'resources', 'videos', 'stories', 'products'];

require_once __DIR__ . '/i18n.php';

// Addresses the site uses itself, so pages can't take them.
const RESERVED_SLUGS = ['admin', 'assets', 'blog', 'content', 'data', 'feed', 'index', 'index-php', 'lib', 'resources', 'search', 'sitemap', 'stories', 'success-stories', 'tools', 'uploads', 'videos'];

// Dates and times use the time zone chosen in Settings.
date_default_timezone_set(in_array(settings()['timezone'], DateTimeZone::listIdentifiers(), true) ? settings()['timezone'] : 'UTC');

// Show PHP errors only in local preview; on the live site they go to the server log.
ini_set('display_errors', PHP_SAPI === 'cli-server' ? '1' : '0');

function h(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ---------- Files ---------- */

function read_json(string $file): ?array
{
    if (!is_file($file)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

/** Write a file atomically so a failed save never leaves half a file behind. */
function write_file(string $file, string $contents): void
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $tmp = $dir . '/.' . basename($file) . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $contents, LOCK_EX) === false || !rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('Could not save ' . basename($file) . '. Check that the folder is writable.');
    }
}

function write_json(string $file, array $data): void
{
    write_file($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}

/* ---------- Slugs ---------- */

function slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[\'’]/u', '', $text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim((string) $text, '-');
}

function valid_slug(string $slug): bool
{
    return (bool) preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) && strlen($slug) <= 100;
}

/* ---------- Pages and posts ---------- */

/** $type is 'pages', 'posts' or 'resources'. */
function item_file(string $type, string $slug): string
{
    if (!in_array($type, ITEM_TYPES, true) || !valid_slug($slug)) {
        throw new InvalidArgumentException('Invalid address.');
    }
    return CONTENT_DIR . "/$type/$slug.json";
}

function get_item(string $type, string $slug): ?array
{
    if (!valid_slug($slug)) {
        return null;
    }
    $item = read_json(item_file($type, $slug));
    if ($item) {
        $item['slug'] = $slug;
    }
    return $item;
}

/** Every item of a type. Read from disk once per request; saving or deleting clears the memory. */
function all_items(string $type, bool $refresh = false): array
{
    static $cache = [];
    if ($refresh) {
        $cache = [];
        return [];
    }
    if (isset($cache[$type])) {
        return $cache[$type];
    }
    $items = [];
    foreach (glob(CONTENT_DIR . "/$type/*.json") ?: [] as $file) {
        $slug = basename($file, '.json');
        if ($item = get_item($type, $slug)) {
            $items[] = $item;
        }
    }
    if ($type === 'posts') {
        usort($items, fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));
    } else {
        usort($items, fn($a, $b) => strcasecmp($a['title'] ?? '', $b['title'] ?? ''));
    }
    return $cache[$type] = $items;
}

/**
 * True when $key has already been seen $max times in the last $window seconds; otherwise this time is
 * counted and false is returned. The file is locked while it's updated, so a burst of requests at the
 * same moment is counted in full. Other keys in the file are kept for a day at most.
 */
function rate_limited(string $file, string $key, int $max, int $window): bool
{
    $fh = @fopen($file, 'c+');
    if (!$fh) {
        return false;
    }
    try {
        flock($fh, LOCK_EX);
        $log = json_decode((string) stream_get_contents($fh), true);
        $log = is_array($log) ? $log : [];
        $now = time();
        foreach ($log as $k => $times) {
            $cutoff = $now - ($k === $key ? $window : 86400);
            $log[$k] = array_values(array_filter((array) $times, fn($t) => $t > $cutoff));
            if (!$log[$k]) {
                unset($log[$k]);
            }
        }
        $limited = count($log[$key] ?? []) >= $max;
        if (!$limited) {
            $log[$key][] = $now;
        }
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($log));
        fflush($fh);
        flock($fh, LOCK_UN);
    } finally {
        fclose($fh);
    }
    @chmod($file, 0600);
    return $limited;
}

/** When a post was (or will be) published, as a timestamp; 0 if the date is missing or can't be read. */
function post_time(array $post): int
{
    return strtotime((string) ($post['date'] ?? '')) ?: 0;
}

function published_posts(): array
{
    $now = time();
    return array_values(array_filter(
        all_items('posts'),
        fn($p) => ($p['status'] ?? '') === 'published' && post_time($p) > 0 && post_time($p) <= $now
    ));
}

/** Save an item, keeping the previous version as a revision. Handles renames. */
function save_item(string $type, string $slug, array $data, ?string $oldSlug = null): void
{
    $data['updated'] = date('c');
    if (isset($GLOBALS['current_editor'])) {
        $data['updated_by'] = $GLOBALS['current_editor'];
    }
    unset($data['slug']);
    run_item_hooks('before_save', $type, $slug, $data);
    $file = item_file($type, $slug);
    if (is_file($file)) {
        save_revision($type, $slug, (string) file_get_contents($file));
    }
    // The new file is written first, so a failure here never loses the old one.
    write_json($file, $data);
    if ($oldSlug !== null && $oldSlug !== $slug) {
        $oldFile = item_file($type, $oldSlug);
        if (is_file($oldFile)) {
            save_revision($type, $oldSlug, (string) file_get_contents($oldFile));
            unlink($oldFile);
        }
        // Earlier versions follow the item to its new address.
        $oldRevs = revision_dir("$type/$oldSlug");
        $newRevs = revision_dir("$type/$slug");
        if (is_dir($oldRevs) && !is_dir($newRevs)) {
            rename($oldRevs, $newRevs);
        }
    }
    all_items($type, true);
    run_item_hooks('after_save', $type, $slug, $data);
}

function delete_item(string $type, string $slug): void
{
    $file = item_file($type, $slug);
    if (is_file($file)) {
        // Deleting keeps a copy in revisions, so it can be brought back.
        save_revision($type, $slug, (string) file_get_contents($file));
        unlink($file);
    }
    all_items($type, true);
    $none = [];
    run_item_hooks('after_delete', $type, $slug, $none);
}

/* ---------- Revisions ---------- */

/** $key identifies what changed: "pages/home", "posts/my-post", "resources/jaws", "template/layout", "template/css". */
function revision_dir(string $key): string
{
    if (!preg_match('#^(pages|posts|resources|videos|stories|template)/[a-z0-9-]+$#', $key)) {
        throw new InvalidArgumentException('Invalid revision key.');
    }
    return CONTENT_DIR . '/revisions/' . $key;
}

function save_revision(string $type, string $slug, string $contents): void
{
    $dir = revision_dir("$type/$slug");
    $name = date('Y-m-d_His') . '-' . bin2hex(random_bytes(2)) . '.rev';
    write_file("$dir/$name", $contents);
    $all = list_revisions("$type/$slug");
    foreach (array_slice($all, MAX_REVISIONS) as $old) {
        @unlink("$dir/{$old['id']}");
    }
}

/** Newest first. */
function list_revisions(string $key): array
{
    $dir = revision_dir($key);
    $out = [];
    foreach (glob("$dir/*.rev") ?: [] as $file) {
        $id = basename($file);
        $ts = DateTime::createFromFormat('Y-m-d_His', substr($id, 0, 17));
        $out[] = ['id' => $id, 'time' => $ts ? $ts->getTimestamp() : filemtime($file), 'size' => filesize($file)];
    }
    usort($out, fn($a, $b) => strcmp($b['id'], $a['id']));
    return $out;
}

function read_revision(string $key, string $id): ?string
{
    if (!preg_match('/^[0-9_-]+-[0-9a-f]{4}\.rev$/', $id)) {
        return null;
    }
    $file = revision_dir($key) . '/' . $id;
    return is_file($file) ? (string) file_get_contents($file) : null;
}

/* ---------- Settings and template ---------- */

function settings(bool $refresh = false): array
{
    static $settings = null;
    if ($settings === null || $refresh) {
        $settings = (read_json(CONTENT_DIR . '/settings.json') ?? []) + [
            'site_name' => 'My website',
            'legal_name' => '',
            'tagline' => '',
            'description' => '',
            'site_url' => 'https://example.com',
            'contact_email' => '',
            'contact_phone' => '',
            'contact_page' => '/contact/',
            'contact_page_es' => '/es/contacto/',
            'timezone' => 'America/New_York',
            'palette' => 'purple',
            'logo' => '',
            'features' => [],
            'posts_per_page' => 10,
            'menu' => [],
            'menu_es' => [],
            'footer_html' => '',
            'footer_html_es' => '',
        ];
    }
    return $settings;
}

function save_settings(array $settings): void
{
    write_json(CONTENT_DIR . '/settings.json', $settings);
    settings(true);
}

/* ---------- Site details, features and colors (all from Settings) ---------- */

/** What's on or off by default on a fresh site. Settings > Features changes these. */
const FEATURE_DEFAULTS = ['resources' => true, 'testimonials' => true, 'videos' => false, 'stories' => false,
    'updates' => true, 'assistant' => false, 'spanish' => false, 'bookings' => false, 'search' => true];

/** Color sets a site can choose from. Each one has been checked for WCAG AA contrast on light and dark backgrounds. */
const PALETTES = [
    'purple' => ['name' => 'Purple', 'light' => ['#503aa8', '#3b2a80', '#f6cff4'], 'dark' => ['#c9b8ff', '#e1d6ff', '#3a2a48']],
    'blue' => ['name' => 'Blue', 'light' => ['#1d4ed8', '#1e3a8a', '#dbeafe'], 'dark' => ['#93c5fd', '#bfdbfe', '#1e3a5f']],
    'green' => ['name' => 'Green', 'light' => ['#15803d', '#14532d', '#dcfce7'], 'dark' => ['#86efac', '#bbf7d0', '#14402a']],
    'teal' => ['name' => 'Teal', 'light' => ['#0f766e', '#134e4a', '#ccfbf1'], 'dark' => ['#5eead4', '#99f6e4', '#134e4a']],
    'rust' => ['name' => 'Rust red', 'light' => ['#b91c1c', '#7f1d1d', '#fee2e2'], 'dark' => ['#fca5a5', '#fecaca', '#4a1d1d']],
    'charcoal' => ['name' => 'Charcoal', 'light' => ['#374151', '#111827', '#e5e7eb'], 'dark' => ['#d1d5db', '#f3f4f6', '#374151']],
];

function site_name(): string
{
    return (string) settings()['site_name'];
}

/** The legal or full name for copyright lines and emails; the site name when none is set. */
function legal_name(): string
{
    return (string) (settings()['legal_name'] ?: settings()['site_name']);
}

function contact_email(): string
{
    return trim((string) (settings()['contact_email'] ?? ''));
}

function contact_phone(): string
{
    return trim((string) (settings()['contact_phone'] ?? ''));
}

/** The contact page for a language, from Settings. */
function contact_path(string $lang = 'en'): string
{
    $s = settings();
    return (string) ($lang === 'es' ? ($s['contact_page_es'] ?: ($s['contact_page'] ?: '/contact/')) : ($s['contact_page'] ?: '/contact/'));
}

/** "call or text 555-0100 or email hello@example.com", in plain words, for emails and error messages. */
function contact_words(?string $lang = null): string
{
    $lang ??= current_lang();
    $p = contact_phone();
    $e = contact_email();
    $parts = [];
    if ($lang === 'es') {
        if ($p !== '') {
            $parts[] = "llame o envíe un mensaje de texto al $p";
        }
        if ($e !== '') {
            $parts[] = "escriba a $e";
        }
        return $parts ? implode(' o ', $parts) : 'use la página de contacto';
    }
    if ($p !== '') {
        $parts[] = "call or text $p";
    }
    if ($e !== '') {
        $parts[] = "email $e";
    }
    return $parts ? implode(' or ', $parts) : 'use the contact page';
}

/** The same, with tel: and mailto: links, for web pages. */
function contact_html(?string $lang = null): string
{
    $lang ??= current_lang();
    $p = contact_phone();
    $e = contact_email();
    $parts = [];
    if ($p !== '') {
        $link = '<a href="tel:' . h(preg_replace('/[^0-9+]/', '', $p)) . '">' . h($p) . '</a>';
        $parts[] = $lang === 'es' ? "llame o envíe un mensaje de texto al $link" : "call or text $link";
    }
    if ($e !== '') {
        $link = '<a href="mailto:' . h($e) . '">' . h($e) . '</a>';
        $parts[] = $lang === 'es' ? "escriba a $link" : "email $link";
    }
    if (!$parts) {
        $link = '<a href="' . h(contact_path($lang)) . '">' . ($lang === 'es' ? 'la página de contacto' : 'the contact page') . '</a>';
        return $lang === 'es' ? "use $link" : "use $link";
    }
    return implode($lang === 'es' ? ' o ' : ' or ', $parts);
}

/** Is an optional part of the site turned on? */
function feature_on(string $name): bool
{
    $features = (array) (settings()['features'] ?? []);
    return (bool) ($features[$name] ?? FEATURE_DEFAULTS[$name] ?? (registered('features')[$name][2] ?? false));
}

/** The site's host name, such as example.com, from the Site address setting. */
function site_host(): string
{
    return (string) (parse_url((string) settings()['site_url'], PHP_URL_HOST) ?: 'localhost');
}

/** The chosen color set as a style block, or nothing for the default purple. */
function palette_css(): string
{
    $key = (string) (settings()['palette'] ?? 'purple');
    if ($key === 'purple' || !isset(PALETTES[$key])) {
        return '';
    }
    [$l, $ld, $lp] = PALETTES[$key]['light'];
    [$d, $dd, $dp] = PALETTES[$key]['dark'];
    $light = "--purple:$l;--purple-dark:$ld;--pink:$lp";
    $dark = "--purple:$d;--purple-dark:$dd;--pink:$dp";
    return '<style>:root{' . $light . '}@media (prefers-color-scheme: dark){:root:not([data-theme="light"]):not([data-theme="contrast"]){' . $dark . '}}'
        . ':root[data-theme="dark"]{' . $dark . '}</style>';
}

/** The color browsers use for their own chrome around the page. */
function theme_color(): string
{
    $key = (string) (settings()['palette'] ?? 'purple');
    return PALETTES[$key]['light'][0] ?? PALETTES['purple']['light'][0];
}

/** The site logo as an image tag for the header, or nothing when no logo is set. */
function logo_html(): string
{
    $logo = trim((string) (settings()['logo'] ?? ''));
    return $logo !== '' ? '<img src="' . h($logo) . '" alt="" height="56">' : '';
}

function layout_template(): string
{
    return (string) @file_get_contents(CONTENT_DIR . '/layout.html');
}

/* ---------- Rendering ---------- */

function format_date(string $iso): string
{
    $t = strtotime($iso);
    return $t ? date('F j, Y', $t) : '';
}

function current_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    return rawurldecode($path);
}

/** Full address of a path. Uses the Site address setting so links never depend on the request's Host header. */
function site_url(string $path = '/'): string
{
    $base = rtrim((string) (settings()['site_url'] ?? ''), '/');
    if ($base !== '' && PHP_SAPI !== 'cli-server') {
        return $base . $path;
    }
    $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return ($https ? 'https' : 'http') . '://' . $host . $path;
}

function render_menu(string $path): string
{
    $items = '';
    $menu = current_lang() === 'es' && settings()['menu_es'] ? settings()['menu_es'] : settings()['menu'];
    foreach ($menu as $link) {
        $url = (string) ($link['url'] ?? '');
        // "page" for this exact page; "true" when we're somewhere inside that section (a blog post under Blog).
        $current = $url === $path ? 'page'
            : ($url !== '/' && $url !== '/es/' && str_starts_with($url, '/') && str_starts_with($path, $url) ? 'true' : '');
        $items .= '<li><a href="' . h($url) . '"' . ($current !== '' ? ' aria-current="' . $current . '"' : '') . '>'
            . h($link['label'] ?? '') . '</a></li>';
    }
    return "<ul>$items</ul>";
}

/** The Display options button and panel (text size and colors), shown in the header. */
function render_display_options(): string
{
    $sizes = ['default' => t('size_default'), 'large' => t('size_large'), 'larger' => t('size_larger')];
    $themes = ['auto' => t('theme_auto'), 'light' => t('theme_light'), 'dark' => t('theme_dark'), 'contrast' => t('theme_contrast')];
    $group = function (string $name, string $legend, array $options): string {
        $out = '<fieldset><legend>' . h($legend) . '</legend>';
        foreach ($options as $value => $label) {
            $id = "$name-$value";
            $out .= '<div class="option"><input type="radio" id="' . $id . '" name="' . $name . '" value="' . $value . '">'
                . '<label for="' . $id . '">' . h($label) . '</label></div>';
        }
        return $out . '</fieldset>';
    };
    return '<div class="display-options" hidden>'
        . '<button type="button" class="display-toggle" aria-expanded="false" aria-controls="display-panel">' . h(t('display')) . '</button>'
        . '<div id="display-panel" class="display-panel" hidden>'
        . $group('fc-text', t('text_size'), $sizes) . $group('fc-theme', t('colors'), $themes)
        . '<div class="display-actions"><button type="button" class="button button-outline" data-display-reset>' . h(t('display_reset')) . '</button>'
        . '<button type="button" class="button" data-display-close>' . h(t('display_close')) . '</button></div>'
        . '</div></div>';
}

/** Link to the same page in the other language, when there is one. */
function render_language_switch(?string $otherPath): string
{
    $other = current_lang() === 'es' ? 'en' : 'es';
    $hasSpanish = (bool) get_item('pages', 'es');
    if ($otherPath === null && !$hasSpanish) {
        return '';
    }
    $otherPath ??= $other === 'es' ? '/es/' : '/';
    return '<a class="language-switch" href="' . h($otherPath) . '" lang="' . $other . '" hreflang="' . $other . '">' . h(LANGUAGES[$other]) . '</a>';
}

/**
 * Fill the site template. The template uses these placeholders:
 *   {{title}} {{page_title}} {{site_name}} {{tagline}} {{description}} {{canonical}}
 *   {{site_url}} {{menu}} {{content}} {{footer}} {{year}}
 *   {{lang}} {{home_url}} {{skip_link}} {{menu_label}} {{display_options}} {{language_switch}} {{alternates}}
 *   {{search_link}} {{logo}} {{icon}} {{og_image}} {{theme_color}} {{palette_css}} {{copyright}}
 * Structured data for search engines is added just before </head>.
 * $otherPath is the address of this page in the other language, if it has one.
 */
function render_layout(string $title, string $content, string $description = '', ?string $path = null, ?string $otherPath = null): string
{
    $s = settings();
    $path ??= current_path();
    $lang = current_lang();
    $alternates = '';
    if ($otherPath !== null) {
        $other = $lang === 'es' ? 'en' : 'es';
        $alternates = '<link rel="alternate" hreflang="' . $lang . '" href="' . h(site_url($path)) . '">'
            . '<link rel="alternate" hreflang="' . $other . '" href="' . h(site_url($otherPath)) . '">';
    }
    $fullTitle = $title === '' ? $s['site_name'] . ($s['tagline'] !== '' ? ' – ' . $s['tagline'] : '') : $title . ' – ' . $s['site_name'];
    $replace = [
        '{{title}}' => h($fullTitle),
        '{{page_title}}' => h($title === '' ? $s['site_name'] : $title),
        '{{site_url}}' => h(rtrim(site_url('/'), '/')),
        '{{site_name}}' => h($s['site_name']),
        '{{tagline}}' => h($s['tagline']),
        '{{description}}' => h($description !== '' ? $description : $s['description']),
        '{{canonical}}' => h(site_url($path)),
        '{{menu}}' => render_menu($path),
        '{{footer}}' => $lang === 'es' && $s['footer_html_es'] !== '' ? $s['footer_html_es'] : $s['footer_html'],
        '{{lang}}' => $lang,
        '{{home_url}}' => $lang === 'es' ? '/es/' : '/',
        '{{skip_link}}' => h(t('skip')),
        '{{menu_label}}' => h(t('menu_label')),
        '{{display_options}}' => render_display_options(),
        '{{language_switch}}' => feature_on('spanish') ? render_language_switch($otherPath) : '',
        '{{alternates}}' => $alternates,
        '{{search_link}}' => feature_on('search') ? '<a class="search-link" href="' . (current_lang() === 'es' ? '/es/buscar/' : '/search/') . '">' . h(t('site_search')) . '</a>' : '',
        '{{logo}}' => logo_html(),
        '{{icon}}' => ($logo = trim((string) ($s['logo'] ?? ''))) !== '' ? '<link rel="icon" href="' . h($logo) . '"><link rel="apple-touch-icon" href="' . h($logo) . '">' : '',
        '{{og_image}}' => $logo !== '' ? '<meta property="og:image" content="' . h(site_url($logo)) . '">' : '',
        '{{theme_color}}' => theme_color(),
        '{{palette_css}}' => palette_css(),
        '{{copyright}}' => h(legal_name()),
        '{{year}}' => date('Y'),
        '{{content}}' => $content,
    ];
    // {{content}} goes last so text inside a page that looks like a placeholder is left alone.
    // The stylesheet address changes whenever it is edited, so visitors never see an old cached copy.
    $html = str_replace(
        ['/assets/css/site.css"', '/assets/js/site.js"'],
        ['/assets/css/site.css?v=' . (@filemtime(STYLESHEET) ?: 1) . '"', '/assets/js/site.js?v=' . (@filemtime(ROOT . '/assets/js/site.js') ?: 1) . '"'],
        layout_template()
    );
    if (function_exists('structured_data_html')) {
        // A callback, so backslashes and dollar signs in the JSON are kept as they are.
        $html = preg_replace_callback('#</head>#i', fn() => structured_data_html() . '</head>', $html, 1);
    }
    $contentPos = strpos($html, '{{content}}');
    if ($contentPos === false) {
        return strtr($html, $replace) . $content;
    }
    unset($replace['{{content}}']);
    return strtr(substr($html, 0, $contentPos), $replace) . $content
        . strtr(substr($html, $contentPos + 11), $replace);
}
