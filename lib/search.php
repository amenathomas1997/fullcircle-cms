<?php
/**
 * Whole-site search (/search/, and /es/buscar/ on the second-language side) over
 * published pages, blog posts and cheat sheets in the visitor's language,
 * plus the structured data that helps Google show the business, FAQs and posts.
 */

declare(strict_types=1);

function search_url(): string
{
    return lang_route_path('search');
}

function plain_text(string $html): string
{
    return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

/** A short piece of text around the first word found, for the results list. */
function search_snippet(string $text, array $words, int $length = 200): string
{
    $pos = false;
    foreach ($words as $w) {
        $p = mb_stripos($text, $w);
        if ($p !== false && ($pos === false || $p < $pos)) {
            $pos = $p;
        }
    }
    $start = max(0, (int) $pos - 60);
    $snippet = mb_substr($text, $start, $length);
    return ($start > 0 ? '…' : '') . $snippet . (mb_strlen($text) > $start + $length ? '…' : '');
}

/** Every published page, post and cheat sheet in this language that contains all the words. Best matches first. */
function site_search(string $query): array
{
    $words = preg_split('/\s+/u', mb_strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY);
    if (!$words) {
        return [];
    }
    $lang = current_lang();
    $candidates = [];
    foreach (all_items('pages') as $p) {
        if (($p['status'] ?? '') === 'published' && slug_lang($p['slug']) === $lang) {
            $candidates[] = ['type' => t('type_page'), 'title' => $p['title'], 'url' => page_path($p['slug']),
                'text' => plain_text(preg_replace('/\[[a-z-]+\]/', '', (string) $p['body'])) . ' ' . ($p['description'] ?? '')];
        }
    }
    foreach (published_posts() as $p) {
        if (slug_lang($p['slug']) === $lang) {
            $candidates[] = ['type' => t('type_post'), 'title' => $p['title'], 'url' => post_url($p),
                'text' => post_text($p) . ' ' . implode(' ', post_categories($p))];
        }
    }
    foreach (published_resources() as $r) {
        if (slug_lang($r['slug']) === $lang) {
            $candidates[] = ['type' => t('type_resource'), 'title' => $r['title'], 'url' => '/resources/' . $r['slug'] . '/',
                'text' => ($r['summary'] ?? '') . ' ' . plain_text((string) $r['body'])];
        }
    }
    $results = [];
    foreach ($candidates as $c) {
        $title = mb_strtolower($c['title']);
        $text = mb_strtolower($c['text']);
        $score = 0;
        foreach ($words as $w) {
            $inTitle = str_contains($title, $w);
            $count = substr_count($text, $w);
            if (!$inTitle && $count === 0) {
                continue 2;
            }
            $score += ($inTitle ? 10 : 0) + min($count, 10);
        }
        $c['score'] = $score;
        $c['snippet'] = search_snippet($c['text'], $words);
        $results[] = $c;
    }
    usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
    return $results;
}

function search_page(): never
{
    $q = trim(mb_substr((string) ($_GET['q'] ?? ''), 0, 200));
    $results = $q !== '' ? site_search($q) : [];
    $out = '<h1>' . h(t('search_title')) . '</h1>'
        . '<form class="search-form" role="search" method="get" action="' . h(search_url()) . '">'
        . '<div class="field"><label for="site-search">' . h(t('search_for')) . '</label>'
        . '<input type="search" id="site-search" name="q" value="' . h($q) . '"></div>'
        . '<button type="submit" class="button">' . h(t('search')) . '</button></form>';
    if ($q !== '') {
        $n = count($results);
        $out .= '<h2 id="results-heading" tabindex="-1" data-focus-on-load>'
            . ($n === 1 ? sprintf(t('search_result_one'), h($q)) : sprintf(t('search_result_many'), $n, h($q))) . '</h2>';
        if (!$results) {
            $out .= '<p>' . sprintf(t('search_none'), '<a href="' . h(contact_path(current_lang())) . '">' . h(t('search_contact_link')) . '</a>') . '</p>';
        } else {
            $out .= '<ol class="search-results">';
            foreach ($results as $r) {
                $out .= '<li><h3><a href="' . h($r['url']) . '">' . h($r['title']) . '</a></h3>'
                    . '<p class="post-meta">' . h($r['type']) . '</p><p>' . h($r['snippet']) . '</p></li>';
            }
            $out .= '</ol>';
        }
    }
    send_html(render_layout($q !== '' ? t('search_results_for') . " $q" : t('search'), $out, t('search_description')));
}

/* ---------- Structured data for search engines (schema.org JSON-LD) ---------- */

function add_structured_data(array $data): void
{
    $GLOBALS['structured_data'][] = $data;
}

/** The business, on every page, plus anything a page added (FAQs, blog posts). */
function structured_data_html(): string
{
    $s = settings();
    $org = [
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        '@id' => site_url('/') . '#organization',
        'name' => legal_name(),
        'url' => site_url('/'),
    ];
    if (legal_name() !== $s['site_name']) {
        $org['alternateName'] = $s['site_name'];
    }
    if (trim((string) $s['description']) !== '') {
        $org['description'] = $s['description'];
    }
    if (($logo = trim((string) ($s['logo'] ?? ''))) !== '') {
        $org['logo'] = site_url($logo);
    }
    if (contact_phone() !== '') {
        $org['telephone'] = contact_phone();
    }
    if (contact_email() !== '') {
        $org['email'] = contact_email();
    }
    $items = array_merge([$org], $GLOBALS['structured_data'] ?? []);
    $out = '';
    foreach ($items as $item) {
        $out .= '<script type="application/ld+json">' . json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . "</script>\n";
    }
    return $out;
}

/** FAQ structured data from <details><summary>Question</summary>Answer</details> blocks. */
function faq_structured_data(string $html): void
{
    if (!preg_match_all('#<details>\s*<summary>(.*?)</summary>(.*?)</details>#s', $html, $m, PREG_SET_ORDER)) {
        return;
    }
    $questions = [];
    foreach ($m as $qa) {
        $questions[] = ['@type' => 'Question', 'name' => plain_text($qa[1]),
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => plain_text($qa[2])]];
    }
    add_structured_data(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $questions]);
}

function post_structured_data(array $post): void
{
    $data = [
        '@context' => 'https://schema.org',
        '@type' => 'BlogPosting',
        'headline' => $post['title'],
        'datePublished' => date('c', post_time($post)),
        'dateModified' => date('c', strtotime((string) ($post['updated'] ?? '')) ?: post_time($post)),
        'url' => site_url(post_url($post)),
        'inLanguage' => slug_lang($post['slug']),
        'description' => post_excerpt($post, 30),
        'author' => ['@id' => site_url('/') . '#business'],
        'publisher' => ['@id' => site_url('/') . '#business'],
        'image' => site_url('/assets/img/full-circle-logo.png'),
    ];
    if ($audio = post_audio($post['slug'])) {
        $data['audio'] = ['@type' => 'AudioObject', 'contentUrl' => site_url($audio['url']), 'encodingFormat' => $audio['type']];
    }
    add_structured_data($data);
}
