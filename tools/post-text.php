<?php
// Turns a blog post (JSON on standard input) into plain text for reading aloud.
// Used by tools/make-audio.sh.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require __DIR__ . '/../lib/bootstrap.php';
$post = json_decode((string) stream_get_contents(STDIN), true);
if (!is_array($post) || empty($post['title'])) {
    fwrite(STDERR, "That doesn't look like a blog post.\n");
    exit(1);
}
$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML('<?xml encoding="utf-8"?><div id="root">' . ($post['body'] ?? '') . '</div>');
$es = slug_lang((string) ($argv[1] ?? '')) === 'es' || ($post['lang'] ?? '') === 'es';
$name = site_name();
$lines = [$post['title'] . '.', $es ? "Del blog de $name." : "From the $name blog.", ''];
$walk = function (DOMNode $parent) use (&$walk, &$lines) {
    foreach ($parent->childNodes as $n) {
        if (!$n instanceof DOMElement) {
            continue;
        }
        $tag = strtolower($n->tagName);
        $text = trim((string) preg_replace('/\s+/u', ' ', $n->textContent));
        if (in_array($tag, ['ul', 'ol', 'div', 'section', 'blockquote', 'figure'], true)) {
            $walk($n);
        } elseif ($text !== '') {
            // Headings and list items end with a full stop so the voice pauses.
            $lines[] = preg_match('/[.!?:]$/u', $text) ? $text : $text . '.';
            if (in_array($tag, ['h2', 'h3', 'h4'], true)) {
                $lines[] = '';
            }
        }
    }
};
$walk($dom->getElementById('root'));
$lines[] = '';
// The address is spelled so the voice reads it clearly: "example dot com".
$spoken = str_replace('.', ' dot ', site_host());
$lines[] = $es ? "Gracias por escuchar. Puede leer esta publicación y más en " . str_replace(' dot ', ' punto ', $spoken) . '.' : "Thanks for listening. You can read this post and more at $spoken.";
echo implode("\n", $lines), "\n";
