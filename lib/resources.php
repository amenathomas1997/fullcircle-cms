<?php
/**
 * Cheat sheets: /resources/ lists them, /resources/<name>/ shows one, and
 * /resources/<name>.docx and /resources/<name>.brf download it as a Word
 * document or a braille-ready file. Downloads are made from the page's HTML
 * whenever they're asked for, so they always match the web page.
 *
 * Spanish cheat sheets have addresses starting with "es-" and are listed at
 * /es/guias-rapidas/. They download as Word documents only: the braille
 * translator writes English braille (UEB), not Spanish braille.
 */

declare(strict_types=1);

/** The last line of every downloaded cheat sheet: who it's from and how to reach them. */
function resource_footer_text(string $lang): string
{
    $from = ($lang === 'es' ? 'De ' : 'From ') . site_name() . ', ' . site_host() . '.';
    $p = contact_phone();
    $e = contact_email();
    if ($p === '' && $e === '') {
        return $from;
    }
    $how = $lang === 'es'
        ? ($p !== '' ? "Llame o envíe un mensaje de texto al $p" : '') . ($p !== '' && $e !== '' ? ', o escriba a ' : ($e !== '' ? 'Escriba a ' : '')) . ($e !== '' ? $e : '')
        : ($p !== '' ? "Call or text $p" : '') . ($p !== '' && $e !== '' ? ', or email ' : ($e !== '' ? 'Email ' : '')) . ($e !== '' ? $e : '');
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
    return ($lang ?? current_lang()) === 'es' ? '/es/guias-rapidas/' : '/resources/';
}

function resource_downloads(array $r): string
{
    $base = '/resources/' . $r['slug'];
    $title = h($r['title']);
    $quiz = count(quiz_rows($r)) >= 4;
    if (slug_lang($r['slug']) === 'es') {
        return '<ul class="downloads">'
            . ($quiz ? '<li><a class="button" href="' . h($base) . '/quiz/">Practique con preguntas<span class="visually-hidden">: ' . $title . '</span></a></li>' : '')
            . '<li><a class="button button-outline" href="' . h($base) . '.docx" download>Documento de Word<span class="visually-hidden">: ' . $title . '</span></a></li>'
            . '<li><a class="button button-outline" href="' . h($base) . '.large.docx" download>Word en letra grande<span class="visually-hidden">: ' . $title . '</span></a></li>'
            . '<li><a class="button button-outline" href="' . h($base) . '/large-print/">Página para imprimir en letra grande<span class="visually-hidden">: ' . $title . '</span></a></li>'
            . '</ul>';
    }
    return '<ul class="downloads">'
        . ($quiz ? '<li><a class="button" href="' . h($base) . '/quiz/">Practice quiz<span class="visually-hidden">: ' . $title . '</span></a></li>' : '')
        . '<li><a class="button button-outline" href="' . h($base) . '.docx" download>Word document<span class="visually-hidden">: ' . $title . '</span></a></li>'
        . '<li><a class="button button-outline" href="' . h($base) . '.large.docx" download>Large print Word<span class="visually-hidden">: ' . $title . '</span></a></li>'
        . '<li><a class="button button-outline" href="' . h($base) . '/large-print/">Large print page to print<span class="visually-hidden">: ' . $title . '</span></a></li>'
        . '<li><a class="button button-outline" href="' . h($base) . '.brf" download>Braille file (BRF)<span class="visually-hidden">: ' . $title . '</span></a></li>'
        . '</ul>';
}

function resource_list_html(array $items): string
{
    if (!$items) {
        return '<p>' . (current_lang() === 'es' ? 'Todavía no hay guías rápidas.' : 'No cheat sheets yet.') . '</p>';
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
        $status = '<p role="status">' . (count($items) ? count($items) . ' ' . (count($items) === 1 ? 'cheat sheet mentions' : 'cheat sheets mention') . ' “' . h($q) . '”.'
            : 'No cheat sheets mention “' . h($q) . '”. Try a different word, like the name of a key or app.') . '</p>';
    }
    $out = '<h1>Cheat sheets</h1>'
        . '<p class="lede">Keyboard shortcuts for screen readers and the apps you use at work. Read them here, or download them as a Word document or a braille-ready file for your notetaker or embosser.</p>'
        . '<form class="search-form" role="search" method="get" action="/resources/">'
        . '<div class="field"><label for="resource-search">Search the cheat sheets</label>'
        . '<p class="hint" id="resource-search-hint">For example “say all”, “headings” or “Slack”.</p>'
        . '<input type="search" id="resource-search" name="q" value="' . h($q) . '" aria-describedby="resource-search-hint"></div>'
        . '<button type="submit" class="button">Search</button></form>'
        . $status . resource_list_html($items)
        . ($q !== '' ? '<p><a href="/resources/">See all cheat sheets</a></p>' : '');
    send_html(render_layout('Cheat sheets', $out, 'Cheat sheets from ' . site_name() . ', as web pages, Word documents and braille files.',
        null, $q === '' && published_resources('es') ? resources_index_path('es') : null));
}

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
        . '<h2 class="visually-hidden">' . (current_lang() === 'es' ? 'Descargar' : 'Download') . '</h2>' . resource_downloads($r)
        . '<div class="resource-body">' . $r['body'] . '</div></article>'
        . '<p><a href="' . h(resources_index_path()) . '">' . (current_lang() === 'es' ? 'Todas las guías rápidas' : 'All cheat sheets') . '</a></p>';
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
    $es = current_lang() === 'es';
    $out = '<div class="large-print"><h1>' . h($r['title']) . '</h1><p>' . h($r['summary'] ?? '') . '</p>'
        . '<p class="no-print"><button type="button" class="button" data-print>' . ($es ? 'Imprimir esta página' : 'Print this page') . '</button> '
        . ($es ? 'O use el comando Imprimir de su navegador (Control + P, o Comando + P en Mac).' : 'Or use your browser’s Print command (Control + P, or Command + P on a Mac).') . '</p>'
        . $r['body'] . '</div>'
        . '<p class="no-print"><a href="/resources/' . h($slug) . '/">' . ($es ? 'Volver a la guía' : 'Back to the cheat sheet') . '</a></p>';
    send_html(render_layout(($es ? 'Letra grande: ' : 'Large print: ') . $r['title'], $out, (string) ($r['summary'] ?? '')));
}

/** The cheat sheet in uncontracted braille, 40 cells by 25 lines. */
function resource_brf(string $slug): never
{
    $r = resource_for($slug);
    if (slug_lang($slug) === 'es') {
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
