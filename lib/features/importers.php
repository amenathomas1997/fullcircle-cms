<?php
/**
 * Importers: bring an existing website into the kit as drafts.
 *
 * Three ways in, all on the owner's Import screen (admin/importers.php):
 *  1. A WordPress export file (WXR XML): pages, posts and attachments. Read with XMLReader so a
 *     64 MB file is fine. Media the content refers to is downloaded and the links are rewritten.
 *  2. A zip of HTML files (Google Sites Takeout, a hand-made site): one page per .html file,
 *     folders become parent pages, images referenced relatively are copied out of the zip.
 *  3. A live website address: the home page is fetched and same-host links followed, breadth
 *     first, up to 60 pages, with a polite pause between fetches.
 *
 * Every import is a dry run first (what the file or site holds), then the real thing on a POST.
 * Nothing imported goes live: every page and post is saved as a draft, an existing page or post
 * is never overwritten (it is skipped with a note), and a report lists everything created and
 * skipped with a link to each item's edit screen. The old address of every page is remembered,
 * so the report can add redirects in one click. Each import is logged to data/imports/<time>.json.
 *
 * The HTML is cleaned on the way in by import_clean_html(), a standalone function with its own
 * tests: `php lib/features/importers.php --test`.
 *
 * Downloaded media lands under uploads/import/<YYYY>/<MM>/. Work in progress (the uploaded
 * file, the crawled pages) waits in data/imports/work/<token>/ between the preview and the run.
 */

declare(strict_types=1);

if (!defined('DATA_DIR')) {
    // Run directly for the tests: the cleaning functions need nothing from the kit.
    define('DATA_DIR', __DIR__ . '/../../data');
    define('UPLOAD_DIR', __DIR__ . '/../../uploads');
}

const IMPORT_DIR = DATA_DIR . '/imports';
const IMPORT_WORK_DIR = IMPORT_DIR . '/work';
/** The biggest WordPress export or zip that can be uploaded. */
const IMPORT_UPLOAD_MAX = 64 * 1024 * 1024;
/** The biggest media file that is downloaded or copied. */
const IMPORT_MEDIA_MAX = 15 * 1024 * 1024;
const IMPORT_FETCH_TIMEOUT = 20;
const IMPORT_CRAWL_MAX = 60;
/** Microseconds to wait between fetches from the same site. */
const IMPORT_CRAWL_PAUSE = 500000;
/** How long a preview waits for its run before the work folder is cleaned up, in seconds. */
const IMPORT_WORK_KEEP = 86400;
const IMPORT_MEDIA_TYPES = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'docx', 'mp3', 'm4a'];
/** Attributes that survive cleaning, by element; '*' applies to every element. */
const IMPORT_KEEP_ATTRS = [
    '*' => ['lang', 'dir'],
    'a' => ['href', 'title', 'class'],
    'img' => ['src', 'alt', 'width', 'height'],
    'iframe' => ['src', 'width', 'height', 'title', 'allowfullscreen'],
    'audio' => ['src', 'controls'], 'video' => ['src', 'controls', 'width', 'height', 'poster'], 'source' => ['src', 'type'],
    'td' => ['colspan', 'rowspan'], 'th' => ['colspan', 'rowspan', 'scope'],
    'ol' => ['start', 'reversed'], 'time' => ['datetime'], 'blockquote' => ['cite'], 'q' => ['cite'],
    'abbr' => ['title'], 'details' => ['open'],
];
/** Elements that never make it into a page. */
const IMPORT_DROP_TAGS = ['script', 'style', 'noscript', 'template', 'form', 'input', 'button', 'select', 'textarea', 'link', 'meta', 'object', 'embed', 'svg', 'canvas'];
const IMPORT_BLOCK_TAGS = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'pre', 'table', 'thead', 'tbody', 'tr', 'figure', 'figcaption', 'div', 'section', 'article', 'aside', 'header', 'footer', 'nav', 'main', 'hr', 'details', 'summary', 'dl', 'dt', 'dd', 'address'];

/* =====================================================================
   HTML cleaning
   ===================================================================== */

/**
 * Clean a fragment of imported HTML so it reads well in the Blocks editor: WordPress block comments,
 * inline styles, classes (except "button"), srcset, scripts and empty paragraphs go; headings, lists,
 * tables, figures with captions, images with their width and height, and links stay. $rewriteUrl is
 * given every href and src and returns the address to use instead (the same one to leave it alone).
 * Shortcodes such as [gallery] are left as text; the caller notes them in the report.
 */
function import_clean_html(string $html, callable $rewriteUrl): string
{
    $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
    $html = str_replace(["\xC2\xA0", '&nbsp;', '&#160;'], ' ', $html);
    if (trim($html) === '') {
        return '';
    }
    $doc = import_dom($html);
    $body = $doc->getElementsByTagName('body')->item(0);
    if (!$body) {
        return '';
    }
    $xpath = new DOMXPath($doc);

    // Things that are never content.
    foreach (import_nodes($xpath, '//' . implode(' | //', IMPORT_DROP_TAGS)) as $node) {
        $node->parentNode?->removeChild($node);
    }

    // Attributes: keep only the few that carry meaning; rewrite addresses; class="button" survives.
    foreach (import_nodes($xpath, '//*') as $el) {
        /** @var DOMElement $el */
        $tag = strtolower($el->tagName);
        $keep = array_merge(IMPORT_KEEP_ATTRS['*'], IMPORT_KEEP_ATTRS[$tag] ?? []);
        $isButton = false;
        if ($el->hasAttribute('class')) {
            $classes = preg_split('/\s+/', trim($el->getAttribute('class'))) ?: [];
            $isButton = $tag === 'a' && (in_array('button', $classes, true) || in_array('wp-block-button__link', $classes, true));
        }
        foreach (iterator_to_array($el->attributes) as $attr) {
            /** @var DOMAttr $attr */
            $name = strtolower($attr->name);
            if (!in_array($name, $keep, true) || ($name === 'class' && !$isButton)) {
                $el->removeAttribute($attr->name);
            }
        }
        if ($isButton) {
            $el->setAttribute('class', 'button');
        }
        foreach (['href', 'src', 'poster'] as $urlAttr) {
            if ($el->hasAttribute($urlAttr)) {
                $old = trim($el->getAttribute($urlAttr));
                $new = (string) $rewriteUrl($old);
                if ($new !== $old) {
                    $el->setAttribute($urlAttr, $new);
                }
            }
        }
    }

    // Wrappers: a <div> (or section) around one block, a <span> with nothing left to say, a <font> tag.
    $changed = true;
    while ($changed) {
        $changed = false;
        foreach (import_nodes($xpath, '//div | //section | //article | //span | //font | //center') as $el) {
            /** @var DOMElement $el */
            $tag = strtolower($el->tagName);
            if ($tag === 'span' || $tag === 'font' || $tag === 'center') {
                import_unwrap($el);
                $changed = true;
                continue;
            }
            $children = import_element_children($el);
            $hasText = trim(import_own_text($el)) !== '';
            if (!$hasText && count($children) === 1) {
                import_unwrap($el);
                $changed = true;
            } elseif (!$hasText && !$children) {
                $el->parentNode?->removeChild($el);
                $changed = true;
            }
        }
    }

    // Empty paragraphs and headings: nothing but whitespace and line breaks.
    foreach (import_nodes($xpath, '//p | //h1 | //h2 | //h3 | //h4 | //h5 | //h6 | //li') as $el) {
        if (trim($el->textContent) === '' && !import_nodes(new DOMXPath($doc), './/img | .//iframe | .//audio | .//video', $el)) {
            if (strtolower($el->tagName) === 'li' && $el->parentNode && count(import_element_children($el->parentNode)) > 1) {
                $el->parentNode->removeChild($el);
            } elseif (strtolower($el->tagName) !== 'li') {
                $el->parentNode?->removeChild($el);
            }
        }
    }
    // Lists left with no items.
    foreach (import_nodes($xpath, '//ul | //ol') as $el) {
        if (!import_element_children($el)) {
            $el->parentNode?->removeChild($el);
        }
    }
    // A <br> at the very start or end of a block is noise.
    foreach (import_nodes($xpath, '//p/br[not(preceding-sibling::node()[normalize-space()])] | //p/br[not(following-sibling::node()[normalize-space()])]') as $br) {
        $br->parentNode?->removeChild($br);
    }

    return import_inner_html($body);
}

/** Parse a fragment (or a whole document) as UTF-8 HTML, quietly. */
function import_dom(string $html): DOMDocument
{
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->preserveWhiteSpace = true;
    $prev = libxml_use_internal_errors(true);
    $isDocument = (bool) preg_match('/<(html|body)[\s>]/i', $html);
    $wrapped = $isDocument ? $html : '<!DOCTYPE html><html><head></head><body>' . $html . '</body></html>';
    // The declaration tells libxml the bytes are UTF-8 whatever the page claims.
    if (!preg_match('/^\s*<\?xml/i', $wrapped)) {
        $wrapped = '<?xml encoding="UTF-8">' . $wrapped;
    }
    $doc->loadHTML($wrapped, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    // The declaration would otherwise be printed back out.
    foreach (iterator_to_array($doc->childNodes) as $node) {
        if ($node instanceof DOMProcessingInstruction) {
            $doc->removeChild($node);
        }
    }
    return $doc;
}

/** The nodes an XPath query finds, as an array that is safe to change the tree while walking. */
function import_nodes(DOMXPath $xpath, string $query, ?DOMNode $context = null): array
{
    $list = $context ? $xpath->query($query, $context) : $xpath->query($query);
    return $list ? iterator_to_array($list) : [];
}

function import_element_children(DOMNode $node): array
{
    $out = [];
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMElement) {
            $out[] = $child;
        }
    }
    return $out;
}

/** The text directly inside a node, not inside its child elements. */
function import_own_text(DOMNode $node): string
{
    $text = '';
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMText) {
            $text .= $child->nodeValue;
        }
    }
    return $text;
}

/** Replace an element with its children. */
function import_unwrap(DOMElement $el): void
{
    $parent = $el->parentNode;
    if (!$parent) {
        return;
    }
    while ($el->firstChild) {
        $parent->insertBefore($el->firstChild, $el);
    }
    $parent->removeChild($el);
}

/** The HTML inside a node, blocks on their own lines, with no stray whitespace at either end. */
function import_inner_html(DOMNode $node): string
{
    $doc = $node->ownerDocument;
    $out = '';
    $run = '';   // Loose text and inline elements between blocks are gathered into a paragraph of their own.
    $flush = function () use (&$out, &$run): void {
        $text = trim(preg_replace('/\s+/', ' ', $run) ?? $run);
        if ($text !== '' && $text !== '<br>') {
            $out .= '<p>' . $text . "</p>\n";
        }
        $run = '';
    };
    foreach ($node->childNodes as $child) {
        $piece = (string) $doc->saveHTML($child);
        $isBlock = $child instanceof DOMElement && in_array(strtolower($child->tagName), array_merge(IMPORT_BLOCK_TAGS, ['iframe', 'video', 'audio']), true);
        if ($isBlock) {
            $flush();
            $out .= trim($piece) . "\n";
        } elseif (!($child instanceof DOMComment)) {
            $run .= $piece;
        }
    }
    $flush();
    $out = preg_replace('/\s*\n\s*/', "\n", trim($out)) ?? $out;
    return trim($out);
}

/** Does this HTML still hold a WordPress-style shortcode such as [gallery ids="1,2"]? */
function import_shortcodes(string $html): array
{
    preg_match_all('/\[([a-z][a-z0-9_-]*)(?:\s[^\]]*)?\]/i', strip_tags($html), $m);
    return array_values(array_unique(array_map('strtolower', $m[1])));
}

/* =====================================================================
   Reading a whole HTML page: its title, its main content, its links
   ===================================================================== */

/**
 * Pull the title and main content out of a full HTML page. The content is <main>, <article> or
 * [role=main] when the page has one, else the body minus navigation, header, footer and scripts,
 * narrowed to whichever wrapper holds the bulk of the text. Returns title, body (raw HTML, not yet
 * cleaned), the page's own description and every link address as written.
 */
function import_extract_page(string $html): array
{
    $doc = import_dom($html);
    $xpath = new DOMXPath($doc);
    $title = '';
    if ($t = $xpath->query('//title')->item(0)) {
        $title = trim(preg_replace('/\s+/', ' ', $t->textContent) ?? '');
    }
    $h1 = $xpath->query('//h1')->item(0);
    if ($title === '' && $h1) {
        $title = trim(preg_replace('/\s+/', ' ', $h1->textContent) ?? '');
    }
    // Site names tacked on to titles: "About us – Example Co" / "About us | Example Co".
    if (preg_match('/^(.{3,}?)\s+[|–—-]\s+.{2,}$/u', $title, $m) && $h1 && str_starts_with(mb_strtolower(trim($h1->textContent)), mb_strtolower($m[1]))) {
        $title = $m[1];
    }
    $description = '';
    if ($d = $xpath->query('//meta[translate(@name,"DESCRIPTION","description")="description"]/@content')->item(0)) {
        $description = trim($d->nodeValue ?? '');
    }
    $links = [];
    foreach (import_nodes($xpath, '//a[@href]') as $a) {
        $links[] = trim($a->getAttribute('href'));
    }

    $root = $xpath->query('//main | //*[@role="main"] | //article')->item(0);
    if (!$root) {
        $root = $doc->getElementsByTagName('body')->item(0);
    }
    if (!$root) {
        return ['title' => $title, 'body' => '', 'description' => $description, 'links' => $links];
    }
    foreach (import_nodes($xpath, './/nav | .//header | .//footer | .//aside | .//script | .//style | .//noscript | .//*[@role="navigation"] | .//*[@role="banner"] | .//*[@role="contentinfo"] | .//*[@aria-hidden="true"]', $root) as $node) {
        if ($node !== $root) {
            $node->parentNode?->removeChild($node);
        }
    }
    // Narrow to the wrapper holding most of the words, so a site's chrome falls away.
    $total = mb_strlen(trim(preg_replace('/\s+/', ' ', $root->textContent) ?? ''));
    while ($total > 0) {
        $best = null;
        foreach (import_element_children($root) as $child) {
            $len = mb_strlen(trim(preg_replace('/\s+/', ' ', $child->textContent) ?? ''));
            if ($len >= $total * 0.85 && in_array(strtolower($child->tagName), ['div', 'section', 'article', 'main', 'table', 'td', 'tbody', 'tr', 'center', 'span', 'font'], true)) {
                $best = $child;
            }
        }
        if (!$best) {
            break;
        }
        $root = $best;
    }
    // The page's own heading is the title; it would otherwise appear twice.
    foreach (import_nodes($xpath, './/h1', $root) as $heading) {
        if (trim(preg_replace('/\s+/', ' ', $heading->textContent) ?? '') === $title) {
            $heading->parentNode?->removeChild($heading);
            break;
        }
    }
    return ['title' => $title, 'body' => import_inner_html($root), 'description' => $description, 'links' => $links];
}

/* =====================================================================
   Addresses
   ===================================================================== */

/** Resolve a link the way a browser would, against the page it was found on. */
function import_resolve_url(string $href, string $base): string
{
    $href = trim($href);
    if ($href === '' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $href)) {
        return $href;
    }
    $b = parse_url($base);
    if (!$b || empty($b['scheme']) || empty($b['host'])) {
        return $href;
    }
    $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
    if (str_starts_with($href, '//')) {
        return $b['scheme'] . ':' . $href;
    }
    if (str_starts_with($href, '#')) {
        return $base;
    }
    if (str_starts_with($href, '?')) {
        return $origin . ($b['path'] ?? '/') . $href;
    }
    $basePath = $b['path'] ?? '/';
    if (str_starts_with($href, '/')) {
        $path = $href;
    } else {
        $dir = substr($basePath, 0, (int) strrpos($basePath, '/') + 1) ?: '/';
        $path = $dir . $href;
    }
    [$pathOnly, $rest] = preg_split('/(?=[?#])/', $path, 2) + ['', ''];
    $segments = [];
    foreach (explode('/', $pathOnly) as $i => $seg) {
        if ($seg === '..') {
            array_pop($segments);
        } elseif ($seg !== '.' && !($seg === '' && $i > 0 && $i < substr_count($pathOnly, '/'))) {
            $segments[] = $seg;
        }
    }
    $resolved = implode('/', $segments);
    if (str_ends_with($pathOnly, '/..') || str_ends_with($pathOnly, '/.')) {
        $resolved .= '/';
    }
    return $origin . '/' . ltrim($resolved, '/') . $rest;
}

/** The host of an address, lowercase, without "www.", so www.example.com and example.com are the same site. */
function import_host(string $url): string
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    return preg_replace('/^www\./', '', $host) ?? $host;
}

/** A page path with one leading and one trailing slash, no index file, no query or fragment. */
function import_norm_path(string $path): string
{
    $path = (string) parse_url($path, PHP_URL_PATH) ?: '/';
    $path = preg_replace('#/index\.html?$#i', '/', $path) ?? $path;
    $path = '/' . trim($path, '/');
    return $path === '/' ? '/' : $path . '/';
}

/** The file extension of an address, lowercase, or '' when it has none. */
function import_url_ext(string $url): string
{
    $path = (string) parse_url($url, PHP_URL_PATH);
    return strtolower(pathinfo($path, PATHINFO_EXTENSION));
}

/** Is this a page address worth crawling: same site, no query, no fragment, an HTML (or extension-less) path? */
function import_crawlable(string $url, string $host): bool
{
    $p = parse_url($url);
    if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || import_host($url) !== $host) {
        return false;
    }
    if (isset($p['query']) || isset($p['fragment']) || isset($p['user'])) {
        return false;
    }
    return in_array(import_url_ext($url), ['', 'html', 'htm'], true);
}

/* =====================================================================
   Fetching and storing media
   ===================================================================== */

/**
 * Fetch an address with curl: 20 seconds, a size limit, redirects followed, http(s) only. Returns
 * ['body', 'type', 'url'] (the final address) or an error message in $error.
 */
function import_fetch(string $url, int $maxBytes, ?string &$error = null, string $accept = '*/*'): ?array
{
    $error = null;
    if (!preg_match('#^https?://#i', $url)) {
        $error = 'Only http and https addresses can be fetched.';
        return null;
    }
    if (!function_exists('curl_init')) {
        $error = 'This server has no curl, so nothing can be downloaded.';
        return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => IMPORT_FETCH_TIMEOUT,
        CURLOPT_USERAGENT => 'Full Circle CMS importer',
        CURLOPT_HTTPHEADER => ['Accept: ' . $accept],
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => fn($ch, $dlTotal, $dlNow) => ($dlNow > $maxBytes || $dlTotal > $maxBytes) ? 1 : 0,
        CURLOPT_MAXFILESIZE => $maxBytes,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $type = strtolower((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
    $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $err = curl_errno($ch);
    $errMsg = curl_error($ch);
    curl_close($ch);
    if ($err === CURLE_ABORTED_BY_CALLBACK || $err === CURLE_FILESIZE_EXCEEDED) {
        $error = 'Bigger than ' . round($maxBytes / 1048576) . ' MB.';
        return null;
    }
    if ($err !== 0 || !is_string($body)) {
        $error = $err === CURLE_OPERATION_TIMEDOUT ? 'Took longer than ' . IMPORT_FETCH_TIMEOUT . ' seconds.' : ($errMsg ?: 'Could not be fetched.');
        return null;
    }
    if ($code >= 400) {
        $error = "The site answered with error $code.";
        return null;
    }
    if (strlen($body) > $maxBytes) {
        $error = 'Bigger than ' . round($maxBytes / 1048576) . ' MB.';
        return null;
    }
    return ['body' => $body, 'type' => $type, 'url' => $final];
}

/** May this media address be downloaded: a file type we keep, on the old site itself or anywhere over https? */
function import_media_url_ok(string $url, string $sourceHost): bool
{
    $ext = import_url_ext($url);
    if (!in_array($ext, IMPORT_MEDIA_TYPES, true)) {
        return false;
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if ($scheme === 'https') {
        return true;
    }
    return $scheme === 'http' && $sourceHost !== '' && import_host($url) === $sourceHost;
}

/** Where downloaded media goes this month. */
function import_media_dir(): string
{
    return UPLOAD_DIR . '/import/' . date('Y/m');
}

/**
 * Save media bytes under uploads/import/YYYY/MM/ with a name made from the old file name,
 * after checking the bytes really are that kind of file. Returns the new address, or null with a reason.
 */
function import_store_media(string $bytes, string $name, ?string &$error = null): ?string
{
    $error = null;
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === 'jpeg') {
        $ext = 'jpg';
    }
    if (!in_array($ext, IMPORT_MEDIA_TYPES, true)) {
        $error = 'Not a file type the site keeps.';
        return null;
    }
    if (strlen($bytes) > IMPORT_MEDIA_MAX) {
        $error = 'Bigger than ' . round(IMPORT_MEDIA_MAX / 1048576) . ' MB.';
        return null;
    }
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    $expected = ['jpg' => ['image/jpeg'], 'png' => ['image/png'], 'gif' => ['image/gif'], 'webp' => ['image/webp'], 'pdf' => ['application/pdf'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'mp3' => ['audio/mpeg', 'audio/mp3', 'application/octet-stream'], 'm4a' => ['audio/mp4', 'audio/x-m4a', 'video/mp4', 'application/octet-stream']][$ext];
    if (!in_array($mime, $expected, true)) {
        $error = "The file is not really a .$ext file ($mime).";
        return null;
    }
    $base = slugify(pathinfo($name, PATHINFO_FILENAME)) ?: 'file';
    // WordPress keeps resized copies as name-300x200.jpg; the original name reads better.
    $base = preg_replace('/-\d{2,4}x\d{2,4}$/', '', $base) ?: $base;
    $dir = import_media_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        $error = 'The uploads folder is not writable.';
        return null;
    }
    $file = "$base.$ext";
    for ($i = 2; is_file("$dir/$file"); $i++) {
        // The same bytes already there: reuse the file rather than keep two copies.
        if (hash_file('sha256', "$dir/$file") === hash('sha256', $bytes)) {
            return '/uploads/import/' . date('Y/m') . '/' . $file;
        }
        $file = "$base-$i.$ext";
    }
    write_file("$dir/$file", $bytes);
    @chmod("$dir/$file", 0644);
    return '/uploads/import/' . date('Y/m') . '/' . $file;
}

/* =====================================================================
   One import in progress: the shared context and the saving of drafts
   ===================================================================== */

/** A fresh context for one import run; the report is built from it. */
function import_context(string $kind, string $source, string $sourceHost = ''): array
{
    return [
        'id' => date('Y-m-d_His') . '-' . bin2hex(random_bytes(2)),
        'time' => date('c'),
        'kind' => $kind,
        'source' => $source,
        'source_host' => $sourceHost,
        'by' => (string) ($GLOBALS['current_editor'] ?? ''),
        'created' => [],   // ['type' => 'pages'|'posts'|'media', 'slug' => …, 'title' => …, 'from' => old address, 'url' => new address]
        'skipped' => [],   // ['what' => …, 'reason' => …]
        'notes' => [],     // plain sentences for the owner
        'redirects' => [], // ['from' => old path, 'to' => new path]
        'redirects_added' => false,
        'paths' => [],     // old path => new path, used to rewrite links between imported pages
        'media' => [],     // old address => new address (or null when it failed)
        'taken' => [],     // type => [slug => true] for slugs claimed during this run
    ];
}

/** The slug this item gets: the wanted one, or -2, -3… if the import already used it or the site reserves it. */
function import_claim_slug(array &$ctx, string $type, string $wanted): string
{
    $base = substr(trim(slugify($wanted), '-'), 0, 80) ?: 'untitled';
    $slug = $base;
    for ($i = 2; isset($ctx['taken'][$type][$slug]) || ($type === 'pages' && (in_array($slug, RESERVED_SLUGS, true) || $slug === 'home')); $i++) {
        $slug = "$base-$i";
    }
    $ctx['taken'][$type][$slug] = true;
    return $slug;
}

/**
 * Save one page or post as a draft, unless that address already belongs to something on this site
 * (then it is skipped and the report says so). Records the old → new address for the redirects.
 * Returns the saved slug, or null when skipped.
 */
function import_save_draft(array &$ctx, string $type, string $slug, array $data, string $oldPath = ''): ?string
{
    $title = (string) ($data['title'] ?? '');
    if (get_item($type, $slug)) {
        $ctx['skipped'][] = ['what' => $title !== '' ? $title : $slug, 'reason' => 'A ' . ($type === 'pages' ? 'page' : 'post') . " already uses the address “{$slug}”, so it was left alone."];
        return null;
    }
    $data['status'] = 'draft';
    if ($oldPath !== '') {
        $data['imported_from'] = $oldPath;
    }
    save_item($type, $slug, $data);
    $newPath = $type === 'posts' ? "/blog/$slug/" : page_path($slug);
    $ctx['created'][] = ['type' => $type, 'slug' => $slug, 'title' => $title, 'from' => $oldPath, 'url' => $newPath];
    if ($oldPath !== '' && str_starts_with($oldPath, '/')) {
        $from = import_norm_path($oldPath);
        if ($from !== '/') {
            $ctx['paths'][$from] = $newPath;
        }
        if ($from !== $newPath && $from !== '/' && !in_array($from, array_column($ctx['redirects'], 'from'), true)) {
            $ctx['redirects'][] = ['from' => $from, 'to' => $newPath];
        }
    }
    return $slug;
}

/**
 * The link rewriter for one import: media addresses are downloaded (once) and swapped for their new
 * home; links to other imported pages point at their new addresses; everything else is left as it is.
 * $fetchMedia takes an absolute address and returns the bytes, or null.
 */
function import_rewriter(array &$ctx, string $pageUrl, callable $fetchMedia): callable
{
    return function (string $url) use (&$ctx, $pageUrl, $fetchMedia): string {
        if ($url === '' || str_starts_with($url, '#') || preg_match('/^(mailto|tel|sms|data|javascript):/i', $url)) {
            return $url;
        }
        $abs = import_resolve_url($url, $pageUrl);
        $host = import_host($abs);
        $isMedia = in_array(import_url_ext($abs), IMPORT_MEDIA_TYPES, true);
        if ($isMedia) {
            if (array_key_exists($abs, $ctx['media'])) {
                return $ctx['media'][$abs] ?? $url;
            }
            $new = null;
            $why = null;
            $bytes = $fetchMedia($abs, $why);
            if ($bytes !== null) {
                $new = import_store_media($bytes, basename((string) parse_url($abs, PHP_URL_PATH)), $why);
            }
            $ctx['media'][$abs] = $new;
            if ($new !== null) {
                // The same file under two old names (WordPress keeps resized copies) is listed once.
                if (!in_array($new, array_column($ctx['created'], 'url'), true)) {
                    $ctx['created'][] = ['type' => 'media', 'slug' => '', 'title' => basename($new), 'from' => $abs, 'url' => $new];
                }
                return $new;
            }
            if ($why !== null) {
                $ctx['skipped'][] = ['what' => $abs, 'reason' => 'Not downloaded: ' . $why . ' The link still points at the old site.'];
            }
            return $url;
        }
        // A link to another page of the old site.
        if ($host !== '' && $host === $ctx['source_host'] && preg_match('#^https?://#i', $abs)) {
            $path = import_norm_path($abs);
            if (isset($ctx['paths'][$path])) {
                return $ctx['paths'][$path] . ((string) parse_url($abs, PHP_URL_FRAGMENT) !== '' ? '#' . parse_url($abs, PHP_URL_FRAGMENT) : '');
            }
            // Not an imported page: keep the path, so the link at least stays on this site.
            return (string) parse_url($abs, PHP_URL_PATH) ?: '/';
        }
        return $url;
    };
}

/** The fetcher used for live addresses: honours the file types, the size limit and the same-host-or-https rule. */
function import_media_fetcher(string $sourceHost): callable
{
    return function (string $url, ?string &$why) use ($sourceHost): ?string {
        if (!import_media_url_ok($url, $sourceHost)) {
            $why = 'Only JPEG, PNG, GIF, WebP, PDF, Word, MP3 and M4A files from the old site or from https addresses are downloaded.';
            return null;
        }
        $res = import_fetch($url, IMPORT_MEDIA_MAX, $why);
        return $res['body'] ?? null;
    };
}

/** Clean one page's body, noting shortcodes left behind. */
function import_prepare_body(array &$ctx, string $html, string $title, callable $rewrite): string
{
    $clean = import_clean_html($html, $rewrite);
    foreach (import_shortcodes($clean) as $code) {
        $ctx['notes'][] = "“{$title}” still holds the shortcode [{$code}], which this site does not know. It was left as text for you to replace.";
    }
    return $clean;
}

/* =====================================================================
   1. WordPress export (WXR)
   ===================================================================== */

/**
 * Walk the items of a WordPress export with XMLReader, so the whole file never sits in memory.
 * $onItem receives each item as a plain array and may return false to stop early. Returns what the
 * channel says about the site: its address and name.
 */
function import_wxr_walk(string $file, callable $onItem): array
{
    $info = ['site_url' => '', 'site_name' => ''];
    $reader = new XMLReader();
    $prev = libxml_use_internal_errors(true);
    if (!$reader->open($file, null, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR)) {
        libxml_use_internal_errors($prev);
        throw new RuntimeException('That file could not be read as XML.');
    }
    $doc = new DOMDocument();
    $inItem = false;
    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT) {
            continue;
        }
        $name = $reader->name;
        if ($name === 'item') {
            $inItem = true;
            $node = $reader->expand($doc);
            if ($node && $onItem(import_wxr_item($node)) === false) {
                break;
            }
            // expand() leaves the reader on the item; next() moves past it without re-reading its children.
            $reader->next();
            continue;
        }
        if (!$inItem) {
            if ($name === 'wp:base_site_url' || $name === 'wp:base_blog_url') {
                $v = trim($reader->readString());
                if ($info['site_url'] === '' && $v !== '') {
                    $info['site_url'] = $v;
                }
            } elseif ($name === 'link' && $info['site_url'] === '') {
                $info['site_url'] = trim($reader->readString());
            } elseif ($name === 'title' && $info['site_name'] === '') {
                $info['site_name'] = trim($reader->readString());
            }
        }
    }
    $reader->close();
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $info;
}

/** One <item> as a plain array. Unknown post types come through with their type, to be skipped. */
function import_wxr_item(DOMNode $node): array
{
    $get = function (string $tag) use ($node): string {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && ($child->nodeName === $tag || $child->localName === $tag)) {
                return (string) $child->textContent;
            }
        }
        return '';
    };
    $categories = [];
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMElement && $child->localName === 'category' && $child->getAttribute('domain') === 'category') {
            $c = trim($child->textContent);
            if ($c !== '' && strtolower($c) !== 'uncategorized') {
                $categories[] = $c;
            }
        }
    }
    return [
        'type' => $get('wp:post_type') ?: $get('post_type'),
        'status' => $get('wp:status') ?: $get('status'),
        'id' => (int) ($get('wp:post_id') ?: $get('post_id')),
        'parent' => (int) ($get('wp:post_parent') ?: $get('post_parent')),
        'name' => $get('wp:post_name') ?: $get('post_name'),
        'title' => trim($get('title')),
        'link' => trim($get('link')),
        'date' => $get('wp:post_date') ?: $get('post_date'),
        'content' => $get('content:encoded') ?: $get('encoded'),
        'excerpt' => trim($get('excerpt:encoded')),
        'categories' => array_values(array_unique($categories)),
        'attachment_url' => trim($get('wp:attachment_url') ?: $get('attachment_url')),
    ];
}

/** Is this item one that gets imported? Trashed and auto-saved things are not. */
function import_wxr_wanted(array $item): bool
{
    return in_array($item['type'], ['page', 'post', 'attachment'], true) && !in_array($item['status'], ['trash', 'auto-draft', 'inherit-trash'], true);
}

/** The dry run: how many pages, posts and media files the file holds, and a few of their titles. */
function import_wxr_preview(string $file): array
{
    $counts = ['pages' => 0, 'posts' => 0, 'media' => 0, 'other' => 0];
    $titles = [];
    $info = import_wxr_walk($file, function (array $item) use (&$counts, &$titles): void {
        if (!import_wxr_wanted($item)) {
            if ($item['type'] !== '' && !in_array($item['type'], ['nav_menu_item', 'revision', 'wp_global_styles', 'wp_navigation', 'wp_template', 'wp_template_part', 'wp_block', 'customize_changeset', 'oembed_cache', 'wp_font_family', 'wp_font_face'], true)) {
                $counts['other']++;
            }
            return;
        }
        $key = ['page' => 'pages', 'post' => 'posts', 'attachment' => 'media'][$item['type']];
        $counts[$key]++;
        if (count($titles) < 8 && $key !== 'media' && $item['title'] !== '') {
            $titles[] = $item['title'];
        }
    });
    return ['counts' => $counts, 'titles' => $titles, 'site_url' => $info['site_url'], 'site_name' => $info['site_name']];
}

/** The real WordPress import. Two passes: first the plan (ids, slugs, old → new addresses), then the saving. */
function import_wxr_run(string $file, string $sourceName, array &$ctx): void
{
    // Pass 1: every page and post gets its slug now, so parents and links between pages can be resolved.
    $plan = [];
    $attachments = [];
    $info = import_wxr_walk($file, function (array $item) use (&$plan, &$attachments, &$ctx): void {
        if (!import_wxr_wanted($item)) {
            return;
        }
        if ($item['type'] === 'attachment') {
            if ($item['attachment_url'] !== '') {
                $attachments[] = $item['attachment_url'];
            }
            return;
        }
        $type = $item['type'] === 'page' ? 'pages' : 'posts';
        $wanted = $item['name'] !== '' ? rawurldecode($item['name']) : $item['title'];
        $slug = import_claim_slug($ctx, $type, $wanted);
        $oldPath = $item['link'] !== '' ? import_norm_path($item['link']) : '';
        $plan[$item['id']] = ['slug' => $slug, 'type' => $type, 'old' => $oldPath];
        $newPath = $type === 'posts' ? "/blog/$slug/" : page_path($slug);
        // Links to this item point at its new address, or at the page that already holds that address.
        if ($oldPath !== '' && $oldPath !== '/') {
            $ctx['paths'][$oldPath] = $newPath;
        }
    });
    if ($ctx['source_host'] === '' && $info['site_url'] !== '') {
        $ctx['source_host'] = import_host($info['site_url']);
    }
    $ctx['source'] = $sourceName . ($info['site_name'] !== '' ? ' (' . $info['site_name'] . ')' : '');
    $fetch = import_media_fetcher($ctx['source_host']);
    $base = $info['site_url'] !== '' ? rtrim($info['site_url'], '/') . '/' : 'https://' . ($ctx['source_host'] ?: 'example.invalid') . '/';

    // Pass 2: save.
    $byTitle = [];
    import_wxr_walk($file, function (array $item) use (&$ctx, $plan, $fetch, $base, &$byTitle): void {
        if (!import_wxr_wanted($item) || $item['type'] === 'attachment') {
            return;
        }
        $p = $plan[$item['id']] ?? null;
        if (!$p) {
            return;
        }
        $title = $item['title'] !== '' ? $item['title'] : ucfirst(str_replace('-', ' ', $p['slug']));
        $rewrite = import_rewriter($ctx, $item['link'] !== '' ? $item['link'] : $base, $fetch);
        $body = import_prepare_body($ctx, $item['content'], $title, $rewrite);
        if ($p['type'] === 'pages') {
            $data = ['title' => $title, 'description' => import_plain_excerpt($item['excerpt']), 'body' => $body];
            if ($item['parent'] > 0 && isset($plan[$item['parent']]) && $plan[$item['parent']]['type'] === 'pages') {
                $data['parent'] = $plan[$item['parent']]['slug'];
            }
            if ($data['description'] === '') {
                unset($data['description']);
            }
        } else {
            $date = preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})/', $item['date'], $m) ? $m[1] . 'T' . $m[2] : date('Y-m-d\TH:i');
            $data = ['title' => $title, 'date' => $date, 'excerpt' => import_plain_excerpt($item['excerpt']), 'categories' => $item['categories'], 'body' => $body];
        }
        import_save_draft($ctx, $p['type'], $p['slug'], $data, $p['old']);
    });

    // Attachments the content never mentioned are still part of the site: bring them too.
    $rewrite = import_rewriter($ctx, $base, $fetch);
    foreach (array_unique($attachments) as $url) {
        if (!array_key_exists($url, $ctx['media'])) {
            $rewrite($url);
        }
    }
    $ctx['notes'][] = 'Posts keep their original dates and categories. Featured images and the site logo were not brought over; set a logo under Settings.';
}

/** An excerpt as plain text, one paragraph, no WordPress block comments. */
function import_plain_excerpt(string $html): string
{
    $text = html_entity_decode(strip_tags(preg_replace('/<!--.*?-->/s', '', $html) ?? $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
}

/* =====================================================================
   2. A zip of HTML files
   ===================================================================== */

/** The HTML files in a zip, with the shared top folder trimmed off, and the other entries (images and such). */
function import_zip_scan(string $file): array
{
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) {
        throw new RuntimeException('That file is not a zip file that can be opened.');
    }
    $pages = [];
    $others = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $stat = $zip->statIndex($i) ?: [];
        if (str_ends_with($name, '/') || str_contains($name, '..') || str_starts_with($name, '/') || str_starts_with($name, '__MACOSX/') || str_contains($name, '/.') || str_starts_with($name, '.')) {
            continue;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === 'html' || $ext === 'htm') {
            $pages[] = ['name' => $name, 'size' => (int) ($stat['size'] ?? 0)];
        } else {
            $others[$name] = (int) ($stat['size'] ?? 0);
        }
    }
    $zip->close();
    if (!$pages) {
        throw new RuntimeException('That zip file holds no .html files.');
    }
    // A zip made of one folder ("Takeout/", "my-site/"): the folder is the site, not a page above it.
    $prefix = import_common_prefix(array_column($pages, 'name'));
    foreach ($pages as &$p) {
        $p['path'] = substr($p['name'], strlen($prefix));
    }
    unset($p);
    usort($pages, fn($a, $b) => [substr_count($a['path'], '/'), $a['path']] <=> [substr_count($b['path'], '/'), $b['path']]);
    return ['pages' => $pages, 'others' => $others, 'prefix' => $prefix];
}

/** The folder path every name starts with ("Takeout/site/"), or ''. */
function import_common_prefix(array $names): string
{
    if (!$names) {
        return '';
    }
    $parts = explode('/', (string) $names[0]);
    array_pop($parts);
    while ($parts) {
        $prefix = implode('/', $parts) . '/';
        if (count(array_filter($names, fn($n) => str_starts_with((string) $n, $prefix))) === count($names)) {
            return $prefix;
        }
        array_pop($parts);
    }
    return '';
}

function import_zip_preview(string $file): array
{
    $scan = import_zip_scan($file);
    $images = count(array_filter(array_keys($scan['others']), fn($n) => in_array(strtolower(pathinfo((string) $n, PATHINFO_EXTENSION)), IMPORT_MEDIA_TYPES, true)));
    $zip = new ZipArchive();
    $zip->open($file);
    $titles = [];
    foreach (array_slice($scan['pages'], 0, 8) as $p) {
        $html = (string) $zip->getFromName($p['name'], 200000);
        $titles[] = import_extract_page($html)['title'] ?: $p['path'];
    }
    $zip->close();
    return ['counts' => ['pages' => count($scan['pages']), 'posts' => 0, 'media' => $images, 'other' => 0], 'titles' => $titles, 'site_url' => '', 'site_name' => ''];
}

/**
 * The slug and parent a file path gives a page: "services/pricing.html" is "pricing" under "services",
 * "services/index.html" is the services page itself, the root index.html is the home page.
 */
function import_path_plan(string $path): array
{
    $path = str_replace('\\', '/', $path);
    $dir = trim(dirname($path) === '.' ? '' : dirname($path), '/');
    $file = pathinfo($path, PATHINFO_FILENAME);
    $isIndex = in_array(strtolower($file), ['index', 'default', 'home'], true);
    if ($isIndex && $dir === '') {
        return ['slug' => 'home', 'parentDir' => null, 'dir' => ''];
    }
    if ($isIndex) {
        $parts = explode('/', $dir);
        $own = array_pop($parts);
        return ['slug' => rawurldecode($own), 'parentDir' => implode('/', $parts), 'dir' => $dir];
    }
    return ['slug' => rawurldecode($file), 'parentDir' => $dir, 'dir' => $dir . ($dir !== '' ? '/' : '') . $file];
}

function import_zip_run(string $file, array &$ctx): void
{
    $scan = import_zip_scan($file);
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) {
        throw new RuntimeException('That zip file could not be opened again.');
    }
    $prefix = $scan['prefix'];
    // Plan first: slugs for every file, and which folder each page stands for, so parents and links resolve.
    $plan = [];
    $folderSlug = [];
    foreach ($scan['pages'] as $p) {
        $pp = import_path_plan($p['path']);
        $slug = $pp['slug'] === 'home' ? import_claim_slug($ctx, 'pages', 'imported-home') : import_claim_slug($ctx, 'pages', $pp['slug']);
        $plan[$p['name']] = ['slug' => $slug, 'path' => $p['path'], 'parentDir' => $pp['parentDir'], 'home' => $pp['slug'] === 'home'];
        if (!isset($folderSlug[$pp['dir']])) {
            $folderSlug[$pp['dir']] = $slug;
        }
        // Links to the old home page go to this site's home, not to the imported draft of it.
        $old = '/' . $p['path'];
        $ctx['paths'][import_norm_path($old)] = $pp['slug'] === 'home' ? '/' : page_path($slug);
        $ctx['paths'][strtolower($old)] = $ctx['paths'][import_norm_path($old)];
    }
    $zipBase = 'zip://' . 'import.invalid/';
    $ctx['source_host'] = 'import.invalid';
    foreach ($scan['pages'] as $p) {
        $entry = $plan[$p['name']];
        $html = (string) $zip->getFromName($p['name']);
        $page = import_extract_page($html);
        $title = $page['title'] !== '' ? $page['title'] : ucfirst(str_replace(['-', '_'], ' ', $entry['slug']));
        $pageUrl = $zipBase . str_replace(' ', '%20', $p['path']);
        $fetch = function (string $url, ?string &$why) use ($zip, $prefix): ?string {
            $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
            $name = $prefix . ltrim($path, '/');
            $stat = $zip->statName($name);
            if (!$stat) {
                $why = 'The file is not in the zip.';
                return null;
            }
            if ((int) $stat['size'] > IMPORT_MEDIA_MAX) {
                $why = 'Bigger than ' . round(IMPORT_MEDIA_MAX / 1048576) . ' MB.';
                return null;
            }
            $bytes = $zip->getFromName($name);
            return is_string($bytes) ? $bytes : null;
        };
        $rewrite = import_rewriter($ctx, $pageUrl, $fetch);
        // Links to other files in the zip, with or without .html, become links to the new pages.
        $linkRewrite = function (string $url) use ($rewrite, $pageUrl, &$ctx): string {
            if (preg_match('#^(https?:)?//#i', $url) || preg_match('/^(mailto|tel|sms|data|javascript):/i', $url) || str_starts_with($url, '#')) {
                return $url;
            }
            $abs = import_resolve_url($url, $pageUrl);
            if (in_array(import_url_ext($abs), IMPORT_MEDIA_TYPES, true)) {
                return $rewrite($url);
            }
            $path = import_norm_path($abs);
            $withHtml = rtrim($path, '/') . '.html';
            foreach ([$path, strtolower($path), $withHtml, strtolower($withHtml)] as $try) {
                if (isset($ctx['paths'][$try])) {
                    return $ctx['paths'][$try];
                }
            }
            return $url;
        };
        $body = import_prepare_body($ctx, $page['body'], $title, $linkRewrite);
        $data = ['title' => $title, 'body' => $body];
        if ($page['description'] !== '') {
            $data['description'] = $page['description'];
        }
        if ($entry['parentDir'] !== null && $entry['parentDir'] !== '' && isset($folderSlug[$entry['parentDir']]) && $folderSlug[$entry['parentDir']] !== $entry['slug']) {
            $data['parent'] = $folderSlug[$entry['parentDir']];
        }
        $saved = import_save_draft($ctx, 'pages', $entry['slug'], $data, '/' . $p['path']);
        if ($saved !== null && $entry['home']) {
            $ctx['notes'][] = "The old home page was saved as the draft “{$title}” at /{$saved}/, because this site already has a home page. Copy what you want from it into Home.";
        }
    }
    $zip->close();
}

/* =====================================================================
   3. A live website
   ===================================================================== */

/**
 * Crawl a site breadth first from its home page: same-host links only, no queries or fragments,
 * at most IMPORT_CRAWL_MAX pages, a pause between fetches. Each page's HTML is saved in $workDir
 * so the import that follows the preview need not fetch it again. Returns the list of pages
 * (url, file, title) and the addresses that could not be read.
 */
function import_site_crawl(string $start, string $workDir): array
{
    $home = import_resolve_url('', $start) ?: $start;
    $host = import_host($home);
    $queue = [import_norm_url($home)];
    $seen = [$queue[0] => true];
    $pages = [];
    $failed = [];
    $seenContent = [];
    $n = 0;
    while ($queue && count($pages) < IMPORT_CRAWL_MAX) {
        $url = array_shift($queue);
        if ($n++ > 0) {
            usleep(IMPORT_CRAWL_PAUSE);
        }
        $why = null;
        $res = import_fetch($url, 4 * 1024 * 1024, $why, 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5');
        if (!$res) {
            $failed[] = ['url' => $url, 'reason' => $why ?? 'Could not be fetched.'];
            continue;
        }
        if (!str_contains($res['type'], 'html') && !preg_match('/<html[\s>]/i', substr($res['body'], 0, 4096))) {
            $failed[] = ['url' => $url, 'reason' => 'Not an HTML page (' . ($res['type'] ?: 'unknown type') . ').'];
            continue;
        }
        $page = import_extract_page($res['body']);
        // A site that answers a missing address with its home page (a "soft 404") would otherwise be imported twice.
        $hash = sha1($page['title'] . "\n" . $page['body']);
        if (isset($seenContent[$hash]) && $page['body'] !== '') {
            $failed[] = ['url' => $url, 'reason' => 'The same page as ' . $seenContent[$hash] . ' (the old site probably answers missing addresses with that page).'];
            continue;
        }
        $seenContent[$hash] = $url;
        $file = $workDir . '/page-' . count($pages) . '.html';
        write_file($file, $res['body']);
        $pages[] = ['url' => $url, 'final' => $res['url'], 'file' => basename($file), 'title' => $page['title']];
        foreach ($page['links'] as $href) {
            $abs = import_resolve_url($href, $res['url']);
            if (!import_crawlable($abs, $host)) {
                continue;
            }
            $abs = import_norm_url($abs);
            if (!isset($seen[$abs])) {
                $seen[$abs] = true;
                $queue[] = $abs;
            }
        }
    }
    $more = count($queue);
    return ['pages' => $pages, 'failed' => $failed, 'host' => $host, 'home' => $queue[0] ?? $home, 'more' => $more];
}

/** One form of a page address: lowercase host, no fragment, no trailing index.html, a trailing slash on folders. */
function import_norm_url(string $url): string
{
    $p = parse_url($url);
    if (!$p || empty($p['host'])) {
        return $url;
    }
    $path = $p['path'] ?? '/';
    $path = preg_replace('#/index\.html?$#i', '/', $path) ?? $path;
    if ($path === '') {
        $path = '/';
    }
    return strtolower($p['scheme'] ?? 'http') . '://' . strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '') . $path;
}

function import_site_run(array $crawl, string $workDir, array &$ctx): void
{
    $host = $crawl['host'];
    $ctx['source_host'] = $host;
    // Plan slugs and parents from the paths first, so links between pages can be rewritten.
    $plan = [];
    $folderSlug = [];
    foreach ($crawl['pages'] as $i => $p) {
        $path = (string) parse_url($p['url'], PHP_URL_PATH) ?: '/';
        $rel = ltrim($path, '/');
        if ($rel === '' || str_ends_with($rel, '/')) {
            $rel .= 'index.html';
        }
        $pp = import_path_plan($rel);
        $wanted = $pp['slug'] === 'home' ? 'imported-home' : ($pp['slug'] !== '' ? $pp['slug'] : ($p['title'] ?: 'page'));
        $slug = import_claim_slug($ctx, 'pages', $wanted);
        $plan[$i] = ['slug' => $slug, 'parentDir' => $pp['parentDir'], 'home' => $pp['slug'] === 'home', 'old' => import_norm_path($path)];
        if (!isset($folderSlug[$pp['dir']])) {
            $folderSlug[$pp['dir']] = $slug;
        }
        if ($plan[$i]['old'] !== '/') {
            $ctx['paths'][$plan[$i]['old']] = page_path($slug);
        }
    }
    $fetch = import_media_fetcher($host);
    foreach ($crawl['pages'] as $i => $p) {
        $entry = $plan[$i];
        $html = (string) @file_get_contents($workDir . '/' . basename($p['file']));
        if ($html === '') {
            $ctx['skipped'][] = ['what' => $p['url'], 'reason' => 'The fetched copy of the page had gone before it could be imported.'];
            continue;
        }
        $page = import_extract_page($html);
        $title = $page['title'] !== '' ? $page['title'] : ucfirst(str_replace('-', ' ', $entry['slug']));
        $rewrite = import_rewriter($ctx, $p['final'] ?: $p['url'], $fetch);
        $body = import_prepare_body($ctx, $page['body'], $title, $rewrite);
        $data = ['title' => $title, 'body' => $body];
        if ($page['description'] !== '') {
            $data['description'] = $page['description'];
        }
        if ($entry['parentDir'] !== null && $entry['parentDir'] !== '' && isset($folderSlug[$entry['parentDir']]) && $folderSlug[$entry['parentDir']] !== $entry['slug']) {
            $data['parent'] = $folderSlug[$entry['parentDir']];
        }
        $saved = import_save_draft($ctx, 'pages', $entry['slug'], $data, $entry['old']);
        if ($saved !== null && $entry['home']) {
            $ctx['notes'][] = "The old home page was saved as the draft “{$title}” at /{$saved}/, because this site already has a home page. Copy what you want from it into Home.";
        }
    }
    foreach ($crawl['failed'] as $f) {
        $ctx['skipped'][] = ['what' => $f['url'], 'reason' => 'Could not be read: ' . $f['reason']];
    }
    if (!empty($crawl['more'])) {
        $ctx['notes'][] = 'The crawl stopped at ' . IMPORT_CRAWL_MAX . ' pages; ' . $crawl['more'] . ' more linked ' . ($crawl['more'] === 1 ? 'address was' : 'addresses were') . ' not fetched. Import them by address if you need them.';
    }
}

/* =====================================================================
   Work folders, logs and redirects
   ===================================================================== */

function import_valid_token(string $token): bool
{
    return (bool) preg_match('/^[a-f0-9]{16}$/', $token);
}

/** A fresh private folder for one import in progress, and its token. */
function import_new_work(): array
{
    $token = bin2hex(random_bytes(8));
    $dir = IMPORT_WORK_DIR . '/' . $token;
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('The data/imports folder could not be created. Check that data/ is writable.');
    }
    return [$token, $dir];
}

function import_work_dir(string $token): ?string
{
    $dir = IMPORT_WORK_DIR . '/' . $token;
    return import_valid_token($token) && is_dir($dir) ? $dir : null;
}

function import_remove_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

/** Previews nobody went on with are cleared out after a day. */
function import_tidy_work(): void
{
    foreach (glob(IMPORT_WORK_DIR . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (filemtime($dir) < time() - IMPORT_WORK_KEEP) {
            import_remove_tree($dir);
        }
    }
}

/** Write the report of a finished import to data/imports/<id>.json. */
function import_log_write(array $ctx): void
{
    unset($ctx['taken']);
    if (!is_dir(IMPORT_DIR)) {
        mkdir(IMPORT_DIR, 0700, true);
    }
    write_json(IMPORT_DIR . '/' . $ctx['id'] . '.json', $ctx);
    @chmod(IMPORT_DIR . '/' . $ctx['id'] . '.json', 0600);
}

function import_log_read(string $id): ?array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}-[a-f0-9]{4}$/', $id)) {
        return null;
    }
    return read_json(IMPORT_DIR . '/' . $id . '.json');
}

/** The last $n imports, newest first. */
function import_logs(int $n = 5): array
{
    $files = glob(IMPORT_DIR . '/*.json') ?: [];
    rsort($files);
    $out = [];
    foreach (array_slice($files, 0, $n) as $file) {
        if ($log = read_json($file)) {
            $out[] = $log;
        }
    }
    return $out;
}

/** Tell the activity log, when the kit has one, without ever failing the import. */
function import_activity(string $what, string $detail = ''): void
{
    try {
        if (function_exists('activity_log')) {
            activity_log($what, $detail);
        } elseif (function_exists('log_activity')) {
            log_activity($what . ($detail !== '' ? ": $detail" : ''));
        }
    } catch (Throwable $e) {
        error_log('Activity log failed: ' . $e->getMessage());
    }
}

/** The redirects an import would add that are not already there. */
function import_pending_redirects(array $log): array
{
    $existing = [];
    if (function_exists('redirects')) {
        foreach (redirects() as $r) {
            $existing[redirect_key((string) $r['from'])] = true;
        }
    }
    $out = [];
    foreach ($log['redirects'] ?? [] as $r) {
        $from = (string) $r['from'];
        $key = function_exists('redirect_key') ? redirect_key($from) : strtolower(rtrim($from, '/') ?: '/');
        if ($key === '/' || isset($existing[$key]) || $from === $r['to']) {
            continue;
        }
        $existing[$key] = true;
        $out[] = ['from' => $from, 'to' => (string) $r['to']];
    }
    return $out;
}

/** Write the import's redirects through the redirects feature (or straight into its file when the feature is absent). */
function import_add_redirects(array $log): int
{
    $pending = import_pending_redirects($log);
    if (!$pending) {
        return 0;
    }
    $list = function_exists('redirects') ? redirects() : (read_json(DATA_DIR . '/redirects.json')['redirects'] ?? []);
    foreach ($pending as $r) {
        $list[] = ['id' => bin2hex(random_bytes(4)), 'from' => $r['from'], 'to' => $r['to'], 'code' => 301];
    }
    if (function_exists('save_redirects')) {
        save_redirects($list);
    } else {
        write_json(DATA_DIR . '/redirects.json', ['redirects' => array_values($list)]);
    }
    return count($pending);
}

/* =====================================================================
   Registration
   ===================================================================== */

if (function_exists('register_admin_screen')) {
    register_admin_screen('import', function (bool $post): void {
        require_once ROOT . '/admin/importers.php';
        if (function_exists('is_owner') && !is_owner()) {
            not_found_screen();
        }
        importers_screen($post);
    }, 'Import', null);
}

/* =====================================================================
   Tests: php lib/features/importers.php --test
   ===================================================================== */

function import_self_test(): int
{
    $fails = 0;
    $check = function (string $name, bool $ok, string $got = '') use (&$fails): void {
        echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok ? '' : "\n     got: " . str_replace("\n", "\n          ", $got)) . "\n";
        if (!$ok) {
            $fails++;
        }
    };
    $same = fn(string $u) => $u;

    $out = import_clean_html('<!-- wp:paragraph --><p style="color:red" class="has-text-align-center">Hello&nbsp;there</p><!-- /wp:paragraph -->', $same);
    $check('WordPress block comments, inline styles, classes and nbsp go', $out === '<p>Hello there</p>', $out);

    $out = import_clean_html('<p>Keep</p><p></p><p>&nbsp;</p><p><br></p><h2> </h2><p>Also</p>', $same);
    $check('empty paragraphs and headings go', $out === "<p>Keep</p>\n<p>Also</p>", $out);

    $out = import_clean_html('<div class="wp-block-group"><div class="wp-block-group__inner-container"><p>One</p></div></div>', $same);
    $check('single-block div wrappers are unwrapped, nested too', $out === '<p>One</p>', $out);

    $out = import_clean_html('<div class="columns"><p>A</p><p>B</p></div>', $same);
    $check('a div around two blocks stays', $out === "<div>\n<p>A</p>\n<p>B</p>\n</div>" || $out === '<div><p>A</p><p>B</p></div>', $out);

    $out = import_clean_html('<figure class="wp-block-image size-large"><img src="https://old.example/wp-content/uploads/2020/01/cat.jpg" alt="A cat" class="wp-image-12" width="800" height="600" srcset="a.jpg 1x, b.jpg 2x" sizes="(max-width: 800px) 100vw" loading="lazy"><figcaption class="wp-element-caption">Our cat</figcaption></figure>',
        fn($u) => str_replace('https://old.example/wp-content/uploads/2020/01/', '/uploads/import/2026/11/', $u));
    $check('figure and figcaption kept, img keeps width/height, srcset dropped, src rewritten',
        $out === '<figure><img src="/uploads/import/2026/11/cat.jpg" alt="A cat" width="800" height="600"><figcaption>Our cat</figcaption></figure>', $out);

    $out = import_clean_html('<div class="wp-block-buttons"><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/contact/">Contact</a></div></div><p><a class="button fancy" href="/x/">X</a> <a class="other" href="/y/">Y</a></p>', $same);
    $check('class="button" survives (WordPress button links become it); other classes go',
        $out === "<p><a class=\"button\" href=\"/contact/\">Contact</a></p>\n<p><a class=\"button\" href=\"/x/\">X</a> <a href=\"/y/\">Y</a></p>", $out);

    $out = import_clean_html('<p>Before</p><script>alert(1)</script><style>p{}</style><p onclick="x()" id="p1" data-x="1">After <span style="font-weight:bold">bold?</span> <strong>yes</strong></p>', $same);
    $check('scripts, styles, event handlers, ids and data attributes go; spans unwrapped; strong kept',
        $out === "<p>Before</p>\n<p>After bold? <strong>yes</strong></p>", $out);

    $out = import_clean_html('<p>Gallery: [gallery ids="1,2,3"]</p>', $same);
    $check('shortcodes are left as text', $out === '<p>Gallery: [gallery ids="1,2,3"]</p>', $out);
    $check('shortcodes are found for the report', import_shortcodes($out) === ['gallery'], json_encode(import_shortcodes($out)));

    $out = import_clean_html('<p>Café — “quotes” &amp; 日本語</p>', $same);
    $check('UTF-8 text survives as characters', $out === '<p>Café — “quotes” &amp; 日本語</p>', $out);

    $out = import_clean_html('<table class="t"><tr><th scope="col" style="x">H</th></tr><tr><td colspan="2">C</td></tr></table><ul class="l"><li>a</li><li></li></ul><ol start="3"><li>c</li></ol>', $same);
    $check('tables keep scope and colspan; empty list items go; ol keeps start',
        str_contains($out, '<th scope="col">H</th>') && str_contains($out, '<td colspan="2">C</td>') && str_contains($out, '<ul><li>a</li></ul>') && str_contains($out, '<ol start="3">'), $out);

    $out = import_clean_html('<p>See <a href="https://old.example/about/">about</a> and <a href="#top">top</a>.</p>', fn($u) => $u === 'https://old.example/about/' ? '/about/' : $u);
    $check('hrefs are rewritten through the callback', $out === '<p>See <a href="/about/">about</a> and <a href="#top">top</a>.</p>', $out);

    $out = import_clean_html('<iframe src="https://www.youtube.com/embed/x" width="560" height="315" title="Video" frameborder="0" allow="autoplay" allowfullscreen></iframe>', $same);
    $check('iframes keep src, size, title and allowfullscreen', $out === '<iframe src="https://www.youtube.com/embed/x" width="560" height="315" title="Video" allowfullscreen></iframe>', $out);

    $out = import_clean_html('Loose text<p>Para</p>', $same);
    $check('loose text at the top becomes a paragraph', $out === "<p>Loose text</p>\n<p>Para</p>", $out);

    $check('empty input gives empty output', import_clean_html("  <!-- wp:spacer -->\n ", $same) === '');

    // Page extraction.
    $page = import_extract_page('<html><head><title>About us – Example Co</title><meta name="description" content="Who we are"></head><body><header><nav><a href="/">Home</a></nav></header><main><h1>About us</h1><p>We are a small team with a long story to tell about ourselves.</p><a href="team.html">Team</a></main><footer>© Example</footer></body></html>');
    $check('title loses the site name when the h1 matches', $page['title'] === 'About us', $page['title']);
    $check('main content is used and the h1 removed', $page['body'] === '<p>We are a small team with a long story to tell about ourselves.</p>' . "\n" . '<p><a href="team.html">Team</a></p>', $page['body']);
    $check('description and links are read', $page['description'] === 'Who we are' && $page['links'] === ['/', 'team.html'], json_encode([$page['description'], $page['links']]));

    $page = import_extract_page('<html><head><title>Plain</title></head><body><div id="nav"><a href="/a">A</a> <a href="/b">B</a></div><div id="wrap"><div id="content"><h2>Welcome</h2><p>Lots and lots of words live here in the content area of this very plain hand-made website page.</p><p>And a second paragraph with yet more words to make the content clearly the biggest block.</p></div></div><div id="foot">tiny</div></body></html>');
    $check('without <main>, the largest text block is chosen', str_starts_with($page['body'], '<h2>Welcome</h2>') && !str_contains($page['body'], 'tiny') && !str_contains($page['body'], 'href="/a"'), $page['body']);

    // Addresses.
    $check('relative links resolve', import_resolve_url('../img/a.png', 'https://x.test/site/pages/p.html') === 'https://x.test/site/img/a.png', import_resolve_url('../img/a.png', 'https://x.test/site/pages/p.html'));
    $check('root links resolve', import_resolve_url('/css/x.css', 'https://x.test/a/b/') === 'https://x.test/css/x.css');
    $check('sibling links resolve', import_resolve_url('team.html', 'https://x.test/about/index.html') === 'https://x.test/about/team.html');
    $check('absolute links stay', import_resolve_url('https://y.test/', 'https://x.test/') === 'https://y.test/');
    $check('crawlable: same host, no query, html or none', import_crawlable('https://x.test/about/', 'x.test') && import_crawlable('https://www.x.test/a.html', 'x.test')
        && !import_crawlable('https://x.test/a?b=1', 'x.test') && !import_crawlable('https://x.test/a#b', 'x.test') && !import_crawlable('https://x.test/a.pdf', 'x.test') && !import_crawlable('https://other.test/', 'x.test') && !import_crawlable('mailto:a@x.test', 'x.test'));
    $check('paths normalise', import_norm_path('/a/b/index.html') === '/a/b/' && import_norm_path('/') === '/' && import_norm_path('https://x.test/p?q=1') === '/p/');
    $pp = import_path_plan('services/pricing.html');
    $check('file paths plan slug and parent', $pp['slug'] === 'pricing' && $pp['parentDir'] === 'services', json_encode($pp));
    $pp = import_path_plan('services/index.html');
    $check('index.html is the folder\'s own page', $pp['slug'] === 'services' && $pp['parentDir'] === '', json_encode($pp));
    $check('common prefix is found', import_common_prefix(['Takeout/site/index.html', 'Takeout/site/a/b.html']) === 'Takeout/site/' && import_common_prefix(['index.html', 'a/b.html']) === '');

    echo $fails === 0 ? "All tests passed.\n" : "$fails test(s) failed.\n";
    return $fails;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(import_self_test() === 0 ? 0 : 1);
}
