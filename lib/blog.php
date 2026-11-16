<?php
/**
 * The blog: post lists, search, categories, single posts with audio,
 * related posts, the RSS feeds and the podcast feed.
 *
 * English posts are at /blog/<address>/. Spanish posts have addresses starting
 * with "es-" and appear at /es/blog/<rest of the address>/, with their own list,
 * topics and feed.
 *
 * Audio: a post has an audio version when uploads/audio/<post address>.m4a
 * (or .mp3) exists. Upload it on the post's edit screen, or make one with
 * tools/make-audio.sh.
 */

declare(strict_types=1);

const AUDIO_DIR = UPLOAD_DIR . '/audio';
const WORDS_PER_MINUTE = 200;

/** /blog/ or /es/blog/ for the current language. */
function blog_base(?string $lang = null): string
{
    return ($lang ?? current_lang()) === 'es' ? '/es/blog/' : '/blog/';
}

function post_url(array $post): string
{
    $slug = $post['slug'];
    return slug_lang($slug) === 'es' ? '/es/blog/' . substr($slug, 3) . '/' : '/blog/' . $slug . '/';
}

/** Published posts in one language, newest first. */
function posts_in_lang(string $lang): array
{
    return array_values(array_filter(published_posts(), fn($p) => slug_lang($p['slug']) === $lang));
}

function post_text(array $post): string
{
    return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', (string) ($post['body'] ?? ''))), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

function reading_minutes(array $post): int
{
    return max(1, (int) round(count(preg_split('/\s+/u', post_text($post), -1, PREG_SPLIT_NO_EMPTY)) / WORDS_PER_MINUTE));
}

function excerpt_from_html(string $html, int $words = 40): string
{
    $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    $list = explode(' ', $text);
    return count($list) > $words ? implode(' ', array_slice($list, 0, $words)) . '…' : $text;
}

function post_excerpt(array $post, int $words = 40): string
{
    return ($post['excerpt'] ?? '') !== '' ? $post['excerpt'] : excerpt_from_html((string) $post['body'], $words);
}

/** @return array<string, string> category address => category name */
function post_categories(array $post): array
{
    $out = [];
    foreach ((array) ($post['categories'] ?? []) as $name) {
        $name = trim((string) $name);
        if ($name !== '' && ($slug = slugify($name)) !== '') {
            $out[$slug] = $name;
        }
    }
    return $out;
}

/** Every category used by a published post in this language, with how many posts use it. */
function all_categories(?string $lang = null): array
{
    $cats = [];
    foreach (posts_in_lang($lang ?? current_lang()) as $post) {
        foreach (post_categories($post) as $slug => $name) {
            $cats[$slug] ??= ['name' => $name, 'count' => 0];
            $cats[$slug]['count']++;
        }
    }
    uasort($cats, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $cats;
}

/** The audio file for a post, if it has one: ['url' =>, 'path' =>, 'type' =>, 'size' =>]. */
function post_audio(string $slug): ?array
{
    foreach (['m4a' => 'audio/mp4', 'mp3' => 'audio/mpeg'] as $ext => $type) {
        $path = AUDIO_DIR . "/$slug.$ext";
        if (is_file($path)) {
            return ['url' => "/uploads/audio/$slug.$ext", 'path' => $path, 'type' => $type, 'size' => filesize($path)];
        }
    }
    return null;
}

function format_post_date(string $iso): string
{
    $t = strtotime($iso);
    if (!$t) {
        return '';
    }
    if (current_lang() === 'es') {
        $months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        return date('j', $t) . ' de ' . $months[(int) date('n', $t) - 1] . ' de ' . date('Y', $t);
    }
    return date('F j, Y', $t);
}

function post_meta(array $post): string
{
    $items = '<li><time datetime="' . h((string) ($post['date'] ?? '')) . '">' . h(format_post_date((string) ($post['date'] ?? ''))) . '</time></li>'
        . '<li>' . reading_minutes($post) . ' ' . h(t('minute_read')) . '</li>';
    if (post_audio($post['slug'])) {
        $items .= '<li>' . h(t('audio_available')) . '</li>';
    }
    return '<ul class="post-meta">' . $items . '</ul>';
}

function category_links(array $cats, ?string $current = null): string
{
    if (!$cats) {
        return '';
    }
    $out = '<ul class="tags">';
    foreach ($cats as $slug => $name) {
        $label = is_array($name) ? $name['name'] . ' (' . $name['count'] . ')' : $name;
        $out .= '<li><a href="' . h(blog_base() . "category/$slug/") . '"' . ($slug === $current ? ' aria-current="page"' : '') . '>' . h($label) . '</a></li>';
    }
    return $out . '</ul>';
}

function post_list_html(array $posts, string $headingLevel = 'h2'): string
{
    $out = '<ul class="post-list">';
    foreach ($posts as $post) {
        $out .= "<li><article><$headingLevel><a href=\"" . h(post_url($post)) . '">' . h($post['title']) . "</a></$headingLevel>"
            . post_meta($post) . '<p>' . h(post_excerpt($post)) . '</p></article></li>';
    }
    return $out . '</ul>';
}

/** Posts in this language whose title, summary, text or categories contain every word searched for. */
function search_posts(string $query): array
{
    $words = preg_split('/\s+/u', mb_strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_filter(posts_in_lang(current_lang()), function ($post) use ($words) {
        $haystack = mb_strtolower($post['title'] . ' ' . ($post['excerpt'] ?? '') . ' ' . post_text($post) . ' ' . implode(' ', post_categories($post)));
        foreach ($words as $w) {
            if (!str_contains($haystack, $w)) {
                return false;
            }
        }
        return true;
    }));
}

/** The other language's blog list, for the language switch, when it has posts. */
function other_blog_path(): ?string
{
    $other = current_lang() === 'es' ? 'en' : 'es';
    return posts_in_lang($other) ? blog_base($other) : null;
}

function blog_index(?string $category = null): never
{
    $es = current_lang() === 'es';
    $q = trim((string) ($_GET['q'] ?? ''));
    $posts = posts_in_lang(current_lang());
    $cats = all_categories();
    $title = t('blog');
    $intro = '';

    if ($category !== null) {
        if (!isset($cats[$category])) {
            not_found();
        }
        $title = t('posts_about') . ' ' . $cats[$category]['name'];
        $posts = array_values(array_filter($posts, fn($p) => isset(post_categories($p)[$category])));
    } elseif ($q !== '') {
        $posts = search_posts($q);
        $title = t('search_results_for') . ' “' . $q . '”';
        $intro = '<p role="status">' . h(count($posts) === 0 ? t('no_results') : (count($posts) === 1 ? t('found_one') : sprintf(t('found_many'), count($posts)))) . '</p>';
    }

    $perPage = max(1, (int) settings()['posts_per_page']);
    $pages = max(1, (int) ceil(count($posts) / $perPage));
    $n = max(1, min($pages, (int) ($_GET['page'] ?? 1)));
    $base = $category !== null ? blog_base() . "category/$category/" : blog_base();
    $qs = $q !== '' ? '&q=' . rawurlencode($q) : '';

    $out = '<h1>' . h($title) . '</h1>' . $intro
        . '<form class="search-form" role="search" method="get" action="' . h(blog_base()) . '">'
        . '<div class="field"><label for="blog-search">' . h(t('blog_search')) . '</label>'
        . '<input type="search" id="blog-search" name="q" value="' . h($q) . '"></div>'
        . '<button type="submit" class="button">' . h(t('search')) . '</button></form>';

    if (!$posts && $q === '') {
        $out .= '<p>' . h(t('no_posts')) . '</p>';
    }
    $out .= post_list_html(array_slice($posts, ($n - 1) * $perPage, $perPage));

    if ($pages > 1) {
        $out .= '<nav class="pagination" aria-label="' . h(t('blog')) . '"><ul>';
        if ($n > 1) {
            $out .= '<li><a href="' . h($base . '?page=' . ($n - 1) . $qs) . '" rel="prev">' . h(t('newer')) . '</a></li>';
        }
        $out .= '<li>' . h(sprintf(t('page_of'), $n, $pages)) . '</li>';
        if ($n < $pages) {
            $out .= '<li><a href="' . h($base . '?page=' . ($n + 1) . $qs) . '" rel="next">' . h(t('older')) . '</a></li>';
        }
        $out .= '</ul></nav>';
    }
    if ($cats) {
        $out .= '<h2>' . h(t('browse_topics')) . '</h2>' . category_links($cats, $category);
    }
    if ($category !== null || $q !== '') {
        $out .= '<p><a href="' . h(blog_base()) . '">' . h(t('all_posts')) . '</a></p>';
    }
    $out .= '<h2>' . h(t('follow_blog')) . '</h2><ul>'
        . '<li><a href="' . h(blog_base()) . 'feed.xml">' . h(t('rss_feed')) . '</a></li>'
        . (!$es && podcast_episodes() ? '<li><a href="/blog/podcast.xml">Podcast feed with audio versions of posts</a>. Add this address to your podcast app.</li>' : '')
        . (!$es && get_item('pages', 'updates') ? '<li><a href="/updates/">Get new posts by email</a></li>' : '')
        . '</ul>';
    $pageTitle = $n > 1 ? "$title, " . sprintf(t('page_of'), $n, $pages) : $title;
    send_html(render_layout($pageTitle, $out, $es ? 'Artículos del blog de ' . site_name() . '.' : 'Articles from the ' . site_name() . ' blog.',
        null, $category === null && $q === '' ? other_blog_path() : null));
}

function related_posts(array $post, int $limit = 3): array
{
    $mine = post_categories($post);
    $scored = [];
    foreach (posts_in_lang(slug_lang($post['slug'])) as $i => $other) {
        if ($other['slug'] === $post['slug']) {
            continue;
        }
        $shared = count(array_intersect_key($mine, post_categories($other)));
        $scored[] = [$shared, -$i, $other];
    }
    usort($scored, fn($a, $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
    return array_map(fn($s) => $s[2], array_slice($scored, 0, $limit));
}

function blog_post(string $slug): never
{
    current_lang(slug_lang($slug));
    $post = get_item('posts', $slug);
    $visible = $post && ($post['status'] ?? '') === 'published' && post_time($post) > 0 && post_time($post) <= time();
    if (!$post || (!$visible && !can_preview())) {
        not_found();
    }
    $listen = '';
    if ($audio = post_audio($slug)) {
        $listen = '<section class="listen" aria-labelledby="listen-heading"><h2 id="listen-heading">' . h(t('listen')) . '</h2>'
            . '<audio controls preload="none" src="' . h($audio['url']) . '"></audio>'
            . '<p><a href="' . h($audio['url']) . '" download>' . h(t('download_audio')) . '</a> (' . max(1, (int) round($audio['size'] / 1048576)) . ' MB). '
            . h(t('transcript_note')) . '</p></section>';
    }
    $cats = post_categories($post);
    $content = '<article class="post"><h1>' . h($post['title']) . '</h1>' . post_meta($post)
        . ($cats ? '<p class="visually-hidden">' . h(t('topics')) . '</p>' . category_links($cats) : '')
        . $listen
        . '<div class="post-body">' . $post['body'] . '</div></article>';
    if ($related = related_posts($post)) {
        $content .= '<section class="related" aria-labelledby="related-heading"><h2 id="related-heading">' . h(t('more_from_blog')) . '</h2>'
            . post_list_html($related, 'h3') . '</section>';
    }
    $content .= '<p><a href="' . h(blog_base()) . '">' . h(t('back_to_blog')) . '</a></p>';
    $otherPath = null;
    if (($other = (string) ($post['translation'] ?? '')) !== '' && ($otherPost = get_item('posts', $other)) && ($otherPost['status'] ?? '') === 'published') {
        $otherPath = post_url($otherPost);
    }
    post_structured_data($post);
    send_html(render_layout($post['title'], $content, post_excerpt($post, 30), post_url($post), $otherPath));
}

function xml(string $v): string
{
    return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function rss_feed(): never
{
    $s = settings();
    $es = current_lang() === 'es';
    header('Content-Type: application/rss+xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<rss version="2.0"><channel><title>' . xml($s['site_name'] . ' Blog' . ($es ? ' en español' : '')) . '</title>'
        . '<link>' . xml(site_url(blog_base())) . '</link><description>' . xml($es ? 'Artículos del blog de ' . site_name() . '.' : $s['description']) . '</description>'
        . '<language>' . ($es ? 'es' : 'en-us') . '</language>';
    foreach (array_slice(posts_in_lang(current_lang()), 0, 20) as $post) {
        $url = site_url(post_url($post));
        echo '<item><title>' . xml($post['title']) . '</title><link>' . xml($url) . '</link><guid>' . xml($url) . '</guid>'
            . '<pubDate>' . date(DATE_RSS, post_time($post)) . '</pubDate>'
            . '<description>' . xml($post['body']) . '</description></item>';
    }
    echo '</channel></rss>';
    exit;
}

/** English posts with audio. (Spanish audio plays on the post page but isn't in the English podcast.) */
function podcast_episodes(): array
{
    return array_values(array_filter(posts_in_lang('en'), fn($p) => post_audio($p['slug']) !== null));
}

/** A podcast feed that Apple Podcasts, Overcast and other apps can subscribe to. */
function podcast_feed(): never
{
    $s = settings();
    $cover = is_file(ROOT . '/assets/img/podcast-cover.png') ? '/assets/img/podcast-cover.png' : '/assets/img/full-circle-logo.png';
    header('Content-Type: application/rss+xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" xmlns:atom="http://www.w3.org/2005/Atom"><channel>'
        . '<title>' . xml($s['site_name'] . ' Blog, read aloud') . '</title>'
        . '<link>' . xml(site_url('/blog/')) . '</link>'
        . '<atom:link href="' . xml(site_url('/blog/podcast.xml')) . '" rel="self" type="application/rss+xml"/>'
        . '<language>en-us</language>'
        . '<description>' . xml('Audio versions of articles about assistive technology from ' . $s['site_name'] . '.') . '</description>'
        . '<itunes:author>' . xml($s['site_name']) . '</itunes:author>'
        . '<itunes:image href="' . xml(site_url($cover)) . '"/>'
        . '<itunes:category text="Technology"/><itunes:explicit>false</itunes:explicit><itunes:type>episodic</itunes:type>';
    foreach (podcast_episodes() as $post) {
        $audio = post_audio($post['slug']);
        $url = site_url(post_url($post));
        echo '<item><title>' . xml($post['title']) . '</title><link>' . xml($url) . '</link>'
            . '<guid isPermaLink="false">' . xml($url . '#audio') . '</guid>'
            . '<pubDate>' . date(DATE_RSS, post_time($post)) . '</pubDate>'
            . '<description>' . xml(post_excerpt($post) . ' Read the full post: ' . $url) . '</description>'
            . '<enclosure url="' . xml(site_url($audio['url'])) . '" length="' . $audio['size'] . '" type="' . $audio['type'] . '"/>'
            . '<itunes:explicit>false</itunes:explicit></item>';
    }
    echo '</channel></rss>';
    exit;
}
