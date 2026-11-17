<?php
/**
 * Public website. Every address comes through here:
 *   /                          the page with the address "home"
 *   /<slug>/                   any other page
 *   /es/ and /es/<name>/       second-language pages (files "es" and "es-<name>"; "es" is whatever code Settings chose)
 *   /blog/                     blog posts, newest first (?q= searches, ?page= pages)
 *   /blog/category/<name>/     posts in one category
 *   /blog/<slug>/              one blog post
 *   /blog/feed.xml             RSS feed
 *   /blog/podcast.xml          podcast feed of posts with audio
 *   /resources/                cheat sheets (?q= searches)
 *   /resources/<slug>/         one cheat sheet, also as .docx, .large.docx and .brf (English only for .brf)
 *   /resources/<slug>/quiz/    a practice quiz made from the sheet;  /resources/<slug>/large-print/  printable large print
 *   /es/blog/...               second-language blog (posts "es-<name>"), /es/guias-rapidas/ its cheat sheets (the words come from its strings)
 *   /search/ and /es/buscar/   search the whole site
 *   /videos/, /success-stories/ and /updates/archive/   videos, success stories and past email updates
 *   /updates/confirm/ and /updates/unsubscribe/   email update sign-up links
 *   /sitemap.xml               list of addresses for search engines
 * Old WordPress addresses (/index.php/...) redirect to the new ones.
 */

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

// If something goes wrong, log it and show a helpful page instead of a blank screen.
set_exception_handler(function (Throwable $e): void {
    error_log('Site error: ' . $e);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    try {
        $name = site_name();
        $contact = contact_html('en');
    } catch (Throwable) {
        $name = 'this website';
        $contact = '';
    }
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Something went wrong – ' . h($name) . '</title></head><body style="font:1.125rem/1.6 system-ui,sans-serif;max-width:40rem;margin:3rem auto;padding:0 1rem">'
        . '<main><h1>Something went wrong</h1><p>Sorry, this page couldn’t be shown. Please try again in a few minutes.</p>'
        . ($contact !== '' ? '<p>If you need us now, ' . $contact . '.</p>' : '') . '</main></body></html>';
});

$path = current_path();

// Old WordPress addresses: /index.php/slug/ and /index.php/2025/06/09/post-slug/
if (preg_match('#^/index\.php(/.*)?$#', $path, $m)) {
    $old = $m[1] ?? '/';
    if (preg_match('#^/\d{4}/\d{2}/\d{2}/([a-z0-9-]+)/?$#', $old, $p)) {
        redirect('/blog/' . $p[1] . '/');
    }
    redirect($old === '' ? '/' : $old);
}
if (preg_match('#^/\d{4}/\d{2}/\d{2}/([a-z0-9-]+)/?$#', $path, $p)) {
    redirect('/blog/' . $p[1] . '/');
}
if ($path === '/feed/' || $path === '/feed') {
    redirect('/blog/feed.xml');
}
if (str_starts_with($path, '/wp-sitemap')) {
    redirect('/sitemap.xml');
}

// Addresses always end in a slash, like WordPress did.
if ($path !== '/' && !str_ends_with($path, '/') && !str_contains(basename($path), '.')) {
    redirect($path . '/' . (($q = $_SERVER['QUERY_STRING'] ?? '') !== '' ? "?$q" : ''));
}

$parts = array_values(array_filter(explode('/', $path), 'strlen'));
$first = $parts[0] ?? '';

// Parts of the site that are turned off in Settings don't exist.
if ($first !== '' && $first === second_lang() && !feature_on('spanish')) {
    not_found();
}
if ($first === 'resources' && !feature_on('resources')) {
    not_found();
}

if ($path === '/sitemap.xml') {
    sitemap();
}
if ($path === '/health.txt') {
    health_endpoint();
}
if ($path === '/robots.txt') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "User-agent: *\nDisallow: /admin/\n\nSitemap: " . site_url('/sitemap.xml') . "\n";
    exit;
}
if ($path === '/search/') {
    feature_on('search') ? search_page() : not_found();
}
if ($first === 'videos' && feature_on('videos')) {
    count($parts) === 1 ? videos_index() : (count($parts) === 2 && valid_slug($parts[1]) ? video_page($parts[1]) : not_found());
}
if ($first === 'success-stories' && feature_on('stories')) {
    count($parts) === 1 ? stories_index() : (count($parts) === 2 && valid_slug($parts[1]) ? story_page($parts[1]) : not_found());
}
if ($first === 'updates' && !feature_on('updates')) {
    not_found();
}
if ($path === '/updates/archive/') {
    update_archive_index();
}
if (count($parts) === 3 && $first === 'updates' && $parts[1] === 'archive') {
    update_archive_page($parts[2]);
}
if ($path === '/updates/confirm/' || $path === '/updates/unsubscribe/') {
    subscription_link($path === '/updates/confirm/' ? 'confirm' : 'unsubscribe');
}
if ($first === 'blog' && count($parts) === 2 && is_second_lang(slug_lang($parts[1])) && $parts[1] !== second_lang()) {
    redirect(blog_base(second_lang()) . slug_without_lang($parts[1]) . '/');
}
if (is_second_lang($first) && in_array($parts[1] ?? '', ['blog', lang_route('search', $first), lang_route('resources', $first)], true)) {
    current_lang($first);
    $rest = array_slice($parts, 1);
    match (true) {
        $rest === [lang_route('search')] => feature_on('search') ? search_page() : not_found(),
        $rest === [lang_route('resources')] => resources_index(),
        $rest === ['blog'] => blog_index(),
        count($rest) === 2 && $rest[1] === 'feed.xml' => rss_feed(),
        count($rest) === 2 && valid_slug($rest[1]) => blog_post("$first-" . $rest[1]),
        count($rest) === 3 && $rest[1] === 'category' && valid_slug($rest[2]) => blog_index($rest[2]),
        default => not_found(),
    };
}
if ($first === 'blog') {
    match (true) {
        count($parts) === 1 => blog_index(),
        count($parts) === 2 && $parts[1] === 'feed.xml' => rss_feed(),
        count($parts) === 2 && $parts[1] === 'podcast.xml' => podcast_feed(),
        count($parts) === 2 && valid_slug($parts[1]) => blog_post($parts[1]),
        count($parts) === 3 && $parts[1] === 'category' && valid_slug($parts[2]) => blog_index($parts[2]),
        default => not_found(),
    };
}
if ($first === 'resources') {
    if (count($parts) === 1) {
        resources_index();
    }
    if (count($parts) === 2 && preg_match('/^([a-z0-9-]+)\.(large\.docx|docx|brf)$/', $parts[1], $m)) {
        match ($m[2]) {
            'docx' => resource_docx($m[1]),
            'large.docx' => resource_docx($m[1], true),
            default => resource_brf($m[1]),
        };
    }
    if (count($parts) === 3 && valid_slug($parts[1]) && $parts[2] === 'quiz') {
        quiz_page($parts[1]);
    }
    if (count($parts) === 3 && valid_slug($parts[1]) && $parts[2] === 'large-print') {
        resource_large_print($parts[1]);
    }
    if (count($parts) === 2 && valid_slug($parts[1])) {
        resource_page($parts[1]);
    }
    not_found();
}
if (is_second_lang($first)) {
    current_lang($first);
    // Features with second-language addresses (for example /es/eventos/) answer before the plain pages.
    run_registered_routes($path, $parts);
    if (count($parts) === 1) {
        show_page($first, "/$first/");
    }
    if (count($parts) === 2 && valid_slug($parts[1])) {
        show_page("$first-{$parts[1]}", "/$first/{$parts[1]}/");
    }
    not_found();
}
// Features and this site's own code (lib/features/, site/) answer their addresses here.
run_registered_routes($path, $parts);

if (count($parts) === 0) {
    show_page('home', '/');
}
if (count($parts) === 1) {
    if ($first === 'home') {
        redirect('/');
    }
    if (valid_slug($first) && is_second_lang(slug_lang($first))) {
        redirect(page_path($first));
    }
    show_page($first, "/$first/");
}
not_found();

/* ---------- Routes ---------- */

/** Only ever sends people to a path on this site: never "//other.com", "\other.com" or anything with a line break. */
function redirect(string $to): never
{
    if (!preg_match('#^/(?![/\\\\])[^\r\n]*$#', $to) || str_contains($to, '\\')) {
        $to = '/';
    }
    header('Location: ' . $to, true, 301);
    exit;
}

function send_html(string $html, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    if ($status === 200) {
        // Send the page before counting the visit, so the counter's file lock never delays anyone.
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        record_visit(current_path());
    }
    exit;
}

function not_found(): never
{
    if (function_exists('log_404')) {
        log_404(current_path());
    }
    $content = '<h1>' . h(t('not_found_heading')) . '</h1><p>' . h(t('not_found_body')) . '</p>'
        . '<p><a href="' . h(home_path()) . '">' . h(t('not_found_home')) . '</a>' . h(t('not_found_or')) . '<a href="' . h(contact_path(current_lang())) . '">' . h(t('not_found_contact')) . '</a>' . h(t('not_found_help')) . '.</p>';
    send_html(render_layout(t('not_found_title'), $content), 404);
}

/** Drafts are only visible to someone signed in to the control panel, for previewing. */
function can_preview(): bool
{
    if (empty($_COOKIE['fcat_admin'])) {
        return false;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('fcat_admin');
        session_start(['read_and_close' => true, 'use_strict_mode' => true]);
    }
    // The same 8-hour idle limit the control panel uses.
    return !empty($_SESSION['admin']) && time() - (int) ($_SESSION['last_seen'] ?? 0) <= 8 * 3600;
}

function show_page(string $slug, string $path): never
{
    if (!valid_slug($slug)) {
        not_found();
    }
    current_lang(slug_lang($slug));
    $page = get_item('pages', $slug);
    // Drafts and pages waiting for their publish date are only shown to someone signed in, or holding a review link for this page.
    if (!$page || (!page_is_live($page) && !can_preview() && !reviewing('pages', $slug))) {
        not_found();
    }
    $body = render_shortcodes((string) ($page['body'] ?? ''), $path);

    // The same page in the other language, if there is one.
    $otherPath = null;
    if (($other = (string) ($page['translation'] ?? '')) !== '' && ($otherPage = get_item('pages', $other)) && ($otherPage['status'] ?? '') === 'published') {
        $otherPath = page_path($other);
    }

    if (in_array($slug, ['faq', 'es-preguntas-frecuentes'], true)) {
        faq_structured_data($body);
    }
    $pageTitle = (string) ($page['title'] ?? $slug);
    $content = empty($page['hide_title']) ? '<h1>' . h($pageTitle) . "</h1>\n" . $body : $body;
    // The English home page uses the site name and tagline as its title.
    $title = $slug === 'home' ? '' : $pageTitle;
    send_html(render_layout($title, $content, (string) ($page['description'] ?? ''), $path, $otherPath));
}

function sitemap(): never
{
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach (all_items('pages') as $page) {
        if (($page['status'] ?? '') === 'published' && (!function_exists('page_is_live') || page_is_live($page))) {
            echo '<url><loc>' . h(site_url(page_path($page['slug']))) . '</loc></url>';
        }
    }
    foreach (published_posts() as $post) {
        echo '<url><loc>' . h(site_url(post_url($post))) . '</loc></url>';
    }
    foreach (array_keys(languages()) as $lang) {
        if (is_second_lang($lang) && !posts_in_lang($lang)) {
            continue;
        }
        echo '<url><loc>' . h(site_url(blog_base($lang))) . '</loc></url>';
        foreach (array_keys(all_categories($lang)) as $cat) {
            echo '<url><loc>' . h(site_url(blog_base($lang) . "category/$cat/")) . '</loc></url>';
        }
        if ($resources = published_resources($lang)) {
            echo '<url><loc>' . h(site_url(resources_index_path($lang))) . '</loc></url>';
            foreach ($resources as $r) {
                echo '<url><loc>' . h(site_url('/resources/' . $r['slug'] . '/')) . '</loc></url>';
            }
        }
    }
    echo '</urlset>';
    exit;
}
