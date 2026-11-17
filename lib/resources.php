<?php
/**
 * Cheat sheets: /resources/ lists them, /resources/<name>/ shows one, and
 * /resources/<name>.docx and /resources/<name>.brf download it as a Word
 * document or a braille-ready file. Downloads are made from the page's HTML
 * whenever they're asked for, so they always match the web page.
 *
 * Cheat sheets in the site's second language have addresses starting with its
 * code ("es-") and are listed at /es/guias-rapidas/ (the word comes from the
 * language's route_resources string). Only English sheets download as braille:
 * the braille translator writes English braille (UEB).
 */

declare(strict_types=1);

/** The last line of every downloaded cheat sheet: who it's from and how to reach them. */
function resource_footer_text(string $lang): string
{
    $from = sprintf(t_in($lang, 'res_from'), site_name(), site_host());
    $p = contact_phone();
    $e = contact_email();
    if ($p === '' && $e === '') {
        return $from;
    }
    $how = $p !== '' ? sprintf(t_in($lang, 'res_call'), $p) . ($e !== '' ? sprintf(t_in($lang, 'res_or_email'), $e) : '') : sprintf(t_in($lang, 'res_email'), $e);
    return "$from $how.";
}

require_once __DIR__ . '/braille.php';
require_once __DIR__ . '/docx.php';

/** Published cheat sheets in a language (the current one unless given). */
function published_resources(?string $lang = null): array
{
    $lang ??= current_lang();
    return array_values(array_filter(all_items('resources'), fn($r) => ($r['status'] ?? '') === 'published' && slug_lang($r['slug']) === $lang));
}

function resources_index_path(?string $lang = null): string
{
    return lang_route_path('resources', $lang);
}

function resource_downloads(array $r): string
{
    $base = '/resources/' . $r['slug'];
    $title = h($r['title']);
    $quiz = count(quiz_rows($r)) >= 4;
    return '<ul class="downloads">'
        . ($quiz ? '<li><a class="button" href="' . h($base) . '/quiz/">' . h(t('res_quiz')) . '<span class="visually-hidden">: ' . $title . '</span></a></li>' : '')
        . '<li><a class="button button-outline" href="' . h($base) . '.docx" download>' . h(t('res_docx')) . '<span class="visually-hidden">: ' . $title . '</span></a></li>'
        . '<li><a class="button button-outline" href="' . h($base) . '.large.docx" download>' . h(t('res_large_docx')) . '<span class="visually-hidden">: ' . $title . '</span></a></li>'
        . '<li><a class="button button-outline" href="' . h($base) . '/large-print/">' . h(t('res_large_page')) . '<span class="visually-hidden">: ' . $title . '</span></a></li>'
        // Braille is English only: the translator writes UEB.
        . (slug_lang($r['slug']) === 'en' ? '<li><a class="button button-outline" href="' . h($base) . '.brf" download>' . h(t('res_brf')) . '<span class="visually-hidden">: ' . $title . '</span></a></li>' : '')
        . '</ul>';
}

function resource_list_html(array $items): string
{
    if (!$items) {
        return '<p>' . h(t('res_none')) . '</p>';
    }
    $out = '<ul class="resource-list">';
    foreach ($items as $r) {
        $out .= '<li><article><h2><a href="/resources/' . h($r['slug']) . '/">' . h($r['title']) . '</a></h2>'
            . '<p>' . h($r['summary'] ?? '') . '</p></article></li>';
    }
    return $out . '</ul>';
}

function resources_index(): never
{
    if (current_lang() === 'es') {
        resources_index_es();
    }
    $q = trim((string) ($_GET['q'] ?? ''));
    $items = published_resources();
    $status = '';
    if ($q !== '') {
        $words = preg_split('/\s+/u', mb_strtolower($q), -1, PREG_SPLIT_NO_EMPTY);
        $items = array_values(array_filter($items, function ($r) use ($words) {
            $text = mb_strtolower($r['title'] . ' ' . ($r['summary'] ?? '') . ' ' . strip_tags(str_replace('<', ' <', (string) $r['body'])));
            foreach ($words as $w) {
                if (!str_contains($text, $w)) {
                    return false;
                }
            }
            return true;
        }));
        $status = '<p role="status">' . h(count($items) ? sprintf(t(count($items) === 1 ? 'res_found_one' : 'res_found_many'), ...(count($items) === 1 ? [$q] : [count($items), $q]))
            : sprintf(t('res_found_none'), $q)) . '</p>';
    }
    $out = '<h1>' . h(t('res_title')) . '</h1>'
        . '<p class="lede">' . h(t('res_lede')) . '</p>'
        . '<form class="search-form" role="search" method="get" action="' . h(resources_index_path()) . '">'
        . '<div class="field"><label for="resource-search">' . h(t('res_search')) . '</label>'
        . '<p class="hint" id="resource-search-hint">' . h(t('res_search_hint')) . '</p>'
        . '<input type="search" id="resource-search" name="q" value="' . h($q) . '" aria-describedby="resource-search-hint"></div>'
        . '<button type="submit" class="button">' . h(t('search')) . '</button></form>'
        . $status . resource_list_html($items)
        . ($q !== '' ? '<p><a href="' . h(resources_index_path()) . '">' . h(t('res_see_all')) . '</a></p>' : '');
    send_html(render_layout(t('res_title'), $out, t('res_description'),
        null, $q === '' && published_resources(other_lang()) ? resources_index_path(other_lang()) : null));
}

/** The Spanish list has its own page: no search form, and a note that braille files are in English. */
function resources_index_es(): never
{
    $out = '<h1>Guías rápidas</h1>'
        . '<p class="lede">Atajos de teclado para lectores de pantalla y las aplicaciones que usa en el trabajo. Léalas aquí o descárguelas como documento de Word.</p>'
        . resource_list_html(published_resources('es'))
        . '<p>Las versiones en braille están disponibles en inglés en <a href="/resources/" hreflang="en">las guías rápidas en inglés</a>.</p>'
        . '<p>¿Quiere ayuda para aprenderlas? <a href="/es/reservar-servicios/">Reserve una sesión de capacitación individual</a>.</p>';
    send_html(render_layout('Guías rápidas', $out, 'Guías rápidas gratuitas de atajos de teclado para VoiceOver, JAWS, NVDA, Google Docs y Slack.', null, resources_index_path('en')));
}

function resource_for(string $slug): array
{
    current_lang(slug_lang($slug));
    $r = get_item('resources', $slug);
    if (!$r || (($r['status'] ?? '') !== 'published' && !can_preview())) {
        not_found();
    }
    return $r;
}

function resource_page(string $slug): never
{
    $r = resource_for($slug);
    $out = '<article><h1>' . h($r['title']) . '</h1>'
        . '<p class="lede">' . h($r['summary'] ?? '') . '</p>'
        . '<h2 class="visually-hidden">' . h(t('res_download')) . '</h2>' . resource_downloads($r)
        . '<div class="resource-body">' . $r['body'] . '</div></article>'
        . '<p><a href="' . h(resources_index_path()) . '">' . h(t('res_all')) . '</a></p>';
    $otherPath = null;
    if (($other = (string) ($r['translation'] ?? '')) !== '' && ($o = get_item('resources', $other)) && ($o['status'] ?? '') === 'published') {
        $otherPath = '/resources/' . $other . '/';
    }
    send_html(render_layout((string) ($r['title'] ?? ''), $out, (string) ($r['summary'] ?? ''), null, $otherPath));
}

function resource_docx(string $slug, bool $large = false): never
{
    $r = resource_for($slug);
    $data = html_to_docx($r['title'], (string) $r['body'], (string) ($r['summary'] ?? ''), slug_lang($slug), $large);
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $slug . ($large ? '-large-print' : '') . '.docx"');
    header('Content-Length: ' . strlen($data));
    echo $data;
    exit;
}

/** The cheat sheet on a plain page set in large print, ready to print (24 point on paper). */
function resource_large_print(string $slug): never
{
    $r = resource_for($slug);
    $out = '<div class="large-print"><h1>' . h($r['title']) . '</h1><p>' . h($r['summary'] ?? '') . '</p>'
        . '<p class="no-print"><button type="button" class="button" data-print>' . h(t('res_print')) . '</button> '
        . h(t('res_print_hint')) . '</p>'
        . $r['body'] . '</div>'
        . '<p class="no-print"><a href="/resources/' . h($slug) . '/">' . h(t('res_back')) . '</a></p>';
    send_html(render_layout(t('res_large_prefix') . $r['title'], $out, (string) ($r['summary'] ?? '')));
}

/** The cheat sheet in uncontracted braille, 40 cells by 25 lines. */
function resource_brf(string $slug): never
{
    $r = resource_for($slug);
    if (slug_lang($slug) !== 'en') {
        not_found();
    }
    header('Content-Type: text/plain; charset=us-ascii');
    header('Content-Disposition: attachment; filename="' . $slug . '.brf"');
    echo html_to_brf($r['title'], (string) $r['body'], (string) ($r['summary'] ?? ''));
    exit;
}

/**
 * Headings get a blank line before them, list items and table rows start in
 * cell 1 with run-over lines in cell 3, and each table row reads "action: keys".
 */
function html_to_brf(string $title, string $html, string $intro = ''): string
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8"?><div id="root">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    $text = fn(DOMNode $n) => trim((string) preg_replace('/\s+/u', ' ', $n->textContent));

    $lines = braille_wrap(braille_text($title));
    $lines[] = '';
    if ($intro !== '') {
        array_push($lines, ...braille_wrap(braille_text($intro), 2));
    }
    $walk = function (DOMNode $parent) use (&$walk, &$lines, $text) {
        foreach ($parent->childNodes as $node) {
            if (!$node instanceof DOMElement) {
                if ($node instanceof DOMText && trim($node->wholeText) !== '') {
                    array_push($lines, ...braille_wrap(braille_text($node->wholeText), 2));
                }
                continue;
            }
            switch (strtolower($node->tagName)) {
                case 'h1':
                case 'h2':
                case 'h3':
                case 'h4':
                    if (end($lines) !== '') {
                        $lines[] = '';
                    }
                    array_push($lines, ...braille_wrap(braille_text($text($node)), 0, 2));
                    break;
                case 'ul':
                case 'ol':
                    foreach ($node->childNodes as $li) {
                        if ($li instanceof DOMElement && strtolower($li->tagName) === 'li') {
                            array_push($lines, ...braille_wrap(braille_text($text($li)), 0, 2));
                        }
                    }
                    break;
                case 'table':
                    foreach ($node->getElementsByTagName('tr') as $tr) {
                        if ($tr->parentNode instanceof DOMElement && strtolower($tr->parentNode->tagName) === 'thead') {
                            continue;
                        }
                        $cells = [];
                        foreach ($tr->childNodes as $c) {
                            if ($c instanceof DOMElement && in_array(strtolower($c->tagName), ['td', 'th'], true)) {
                                $cells[] = $text($c);
                            }
                        }
                        if ($cells) {
                            $row = count($cells) > 1 ? $cells[0] . ': ' . implode(', ', array_slice($cells, 1)) : $cells[0];
                            array_push($lines, ...braille_wrap(braille_text($row), 0, 2));
                        }
                    }
                    break;
                case 'div':
                case 'section':
                case 'blockquote':
                    $walk($node);
                    break;
                default:
                    array_push($lines, ...braille_wrap(braille_text($text($node)), 2));
            }
        }
    };
    $walk($dom->getElementById('root'));
    $lines[] = '';
    array_push($lines, ...braille_wrap(braille_text(resource_footer_text('en')), 2));
    return braille_pages($lines);
}
