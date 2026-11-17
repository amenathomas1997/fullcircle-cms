<?php
/**
 * Pre-publish accessibility check. Every time a page, post, cheat sheet or story
 * is saved, its HTML is read and plain-language warnings are stored in the item
 * as $data['a11y'] (an array of strings). The control panel shows them in the
 * "Saved" message, at the top of the edit form (page_form / post_form print
 * a11y_warnings_html()), on a Dashboard card and on the Accessibility screen.
 * Nothing is ever blocked: the owner decides what to fix.
 */

declare(strict_types=1);

const A11Y_TYPES = ['pages', 'posts', 'resources', 'stories'];
const A11Y_WEAK_LINK_TEXT = ['click here', 'here', 'read more', 'link', 'more', 'learn more', 'this'];

register_item_hook('before_save', function (string $type, string $slug, array &$data): void {
    if (!in_array($type, A11Y_TYPES, true)) {
        return;
    }
    $warnings = a11y_check($type, $data);
    if ($warnings) {
        $data['a11y'] = $warnings;
    } else {
        unset($data['a11y']);
    }
});

// page_save() and friends call flash('Saved…') after save_item() returns, so a message set here would be replaced.
// Instead the warnings are added to whatever success message is in the session at the very end of the request,
// which is after that flash() and before PHP writes the session.
register_item_hook('after_save', function (string $type, string $slug, array &$data): void {
    if (!in_array($type, A11Y_TYPES, true) || empty($data['a11y']) || !function_exists('flash')) {
        return;
    }
    $warnings = $data['a11y'];
    register_shutdown_function(function () use ($warnings): void {
        if (empty($_SESSION['flash']) || ($_SESSION['flash']['type'] ?? '') !== 'success' || str_contains((string) $_SESSION['flash']['message'], 'to check for accessibility')) {
            return;
        }
        $_SESSION['flash']['message'] = rtrim((string) $_SESSION['flash']['message']) . ' ' . a11y_flash_text($warnings);
    });
});

register_admin_screen('a11y', fn (bool $post) => a11y_screen(), 'Accessibility');

register_dashboard_card(function (): string {
    $count = count(a11y_items_with_warnings());
    return '<li><h2><a href="' . h(admin_url('a11y')) . '">Accessibility</a></h2><p>'
        . ($count ? ($count === 1 ? '<strong>1 page or post has</strong>' : "<strong>$count pages or posts have</strong>") . ' things to check.' : 'Nothing to check in your pages and posts.')
        . '</p></li>';
});

/* ---------- The checker ---------- */

/** Parse an item's body as HTML. Returns null when there is nothing to check. */
function a11y_dom(string $html): ?DOMDocument
{
    if (trim($html) === '' || !class_exists('DOMDocument')) {
        return null;
    }
    $doc = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $ok = $doc->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"><title>x</title></head><body>' . $html . '</body></html>', LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return $ok ? $doc : null;
}

/** The text inside an element with spacing tidied. */
function a11y_text(DOMNode $node): string
{
    return trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
}

/** A short quote of some text for a message. */
function a11y_quote(string $text, int $max = 40): string
{
    $text = trim((string) preg_replace('/\s+/u', ' ', $text));
    return '“' . (mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text) . '”';
}

/**
 * Read the item's HTML and return plain-language warnings, or an empty array.
 * $type matters because a page can hide its title: then the content must supply the heading level 1 itself.
 */
function a11y_check(string $type, array $item): array
{
    $doc = a11y_dom((string) ($item['body'] ?? ''));
    if (!$doc) {
        return [];
    }
    $xp = new DOMXPath($doc);
    $w = [];
    // Pages can hide their title; every other kind always shows its title as the heading level 1.
    $titleShown = !($type === 'pages' && !empty($item['hide_title']));

    // Images without an alt attribute. alt="" is fine: it marks a decorative image.
    $n = 0;
    foreach ($xp->query('//img') as $img) {
        $n++;
        if (!$img->hasAttribute('alt')) {
            $w[] = "Image $n (" . basename((string) $img->getAttribute('src')) . ') has no alt text. Describe what it shows in a sentence, or mark it decorative if it adds nothing.';
        }
    }

    // Headings: exactly one level 1, and no skipped levels.
    $headings = [];
    foreach ($xp->query('//h1|//h2|//h3|//h4|//h5|//h6') as $hEl) {
        $headings[] = [(int) substr($hEl->nodeName, 1), a11y_text($hEl)];
    }
    $ones = array_values(array_filter($headings, fn($x) => $x[0] === 1));
    if ($titleShown && $ones) {
        $w[] = 'The title is already the main heading (level 1), and the content has ' . (count($ones) === 1 ? 'another level 1 heading, ' . a11y_quote($ones[0][1]) : count($ones) . ' more level 1 headings') . '. Make it a heading level 2.';
    } elseif (!$titleShown && count($ones) > 1) {
        $w[] = 'There are ' . count($ones) . ' headings at level 1 (' . implode(', ', array_map(fn($x) => a11y_quote($x[1]), array_slice($ones, 0, 3))) . '). A page needs exactly one.';
    } elseif (!$titleShown && !$ones) {
        $w[] = 'The page title is hidden, so the content needs a heading level 1 near the top, and it has none.';
    }
    $prev = $titleShown ? 1 : 0;
    foreach ($headings as [$level, $text]) {
        if ($prev > 0 && $level > $prev + 1) {
            $w[] = 'Heading levels skip: ' . a11y_quote($text) . " is level $level but the heading before it is level $prev. Use level " . ($prev + 1) . ' instead.';
        }
        if ($text === '') {
            $w[] = "A heading level $level is empty. Give it words or remove it.";
        }
        $prev = $level;
    }

    // Links that don't say where they go.
    foreach ($xp->query('//a') as $a) {
        $text = a11y_text($a);
        $plain = mb_strtolower(trim($text, " \t\n.,:;!?…»«\"'’“”()"));
        $href = (string) $a->getAttribute('href');
        if ($text === '') {
            $hasAltImage = $xp->query('.//img[normalize-space(@alt) != ""]', $a)->length > 0;
            if (!$hasAltImage && trim((string) $a->getAttribute('aria-label')) === '') {
                $w[] = 'A link' . ($href !== '' ? ' to ' . a11y_quote($href, 60) : '') . ' has no text, so a screen reader cannot say what it is. Give it words, or give its image alt text.';
            }
        } elseif (in_array($plain, A11Y_WEAK_LINK_TEXT, true)) {
            $w[] = 'A link says only ' . a11y_quote($text) . '. Screen reader users often read links on their own, so say where it goes, for example “Read our prices”.';
        } elseif (preg_match('#^(https?://|www\.)\S+$#i', $text)) {
            $w[] = 'A link’s text is just the address ' . a11y_quote($text, 60) . '. Use words that say where it goes, for example “Our Facebook page”.';
        }
    }

    // Buttons without a name.
    foreach ($xp->query('//button') as $button) {
        if (a11y_text($button) === '' && trim((string) $button->getAttribute('aria-label')) === '' && $xp->query('.//img[normalize-space(@alt) != ""]', $button)->length === 0) {
            $w[] = 'A button has no text, so a screen reader cannot say what it does.';
        }
    }

    // Tables without header cells.
    $n = 0;
    foreach ($xp->query('//table') as $table) {
        $n++;
        if ($xp->query('.//th', $table)->length === 0) {
            $w[] = "Table $n has no header cells. Make the first row’s cells headers (<th scope=\"col\">) so screen readers can say which column each value belongs to.";
        }
    }

    // Frames without a title.
    foreach ($xp->query('//iframe') as $frame) {
        if (trim((string) $frame->getAttribute('title')) === '') {
            $w[] = 'An embedded frame (iframe' . (($src = (string) $frame->getAttribute('src')) !== '' ? ' from ' . a11y_quote((string) parse_url($src, PHP_URL_HOST), 40) : '') . ') has no title. Add title="…" saying what it is, for example “Map of our office”.';
        }
    }

    // Text written in capitals: 20 or more letters with no lowercase at all.
    foreach ($xp->query('//text()[not(ancestor::code) and not(ancestor::pre) and not(ancestor::script) and not(ancestor::style)]') as $textNode) {
        $text = trim((string) preg_replace('/\s+/u', ' ', $textNode->nodeValue));
        $letters = (string) preg_replace('/[^\p{L}]/u', '', $text);
        if (mb_strlen($letters) >= 20 && !preg_match('/\p{Ll}/u', $letters)) {
            $w[] = a11y_quote($text) . ' is written all in capitals. Screen readers may spell it out letter by letter, and it is harder to read. Use ordinary capitals.';
        }
    }

    // Very long paragraphs.
    foreach ($xp->query('//p') as $p) {
        $text = a11y_text($p);
        if (mb_strlen($text) > 1200) {
            $w[] = 'The paragraph starting ' . a11y_quote($text) . ' is ' . number_format(mb_strlen($text)) . ' characters long. Split it into shorter paragraphs, or a list, so it is easier to follow.';
        }
    }

    return array_values(array_unique($w));
}

/* ---------- Showing the warnings ---------- */

/** "2 things to check for accessibility: …" for the end of a "Saved" message. */
function a11y_flash_text(array $warnings): string
{
    $count = count($warnings);
    $shown = array_slice($warnings, 0, 2);
    $more = $count - count($shown);
    return ($count === 1 ? '1 thing' : "$count things") . ' to check for accessibility: ' . implode(' ', $shown)
        . ($more > 0 ? " And $more more, listed at the top of this form." : '');
}

/** The warnings for an item: the ones stored when it was saved, or a fresh check for items saved before this existed. */
function a11y_item_warnings(string $type, array $item): array
{
    if (array_key_exists('a11y', $item)) {
        return array_values(array_filter((array) $item['a11y'], 'is_string'));
    }
    return a11y_check($type, $item);
}

/**
 * The list shown at the top of the edit form. page_form() and post_form() in admin/index.php call this
 * when it exists; $item is the saved item (null for a new one).
 */
function a11y_warnings_html(?array $item, string $type = 'pages'): string
{
    if (!$item || !($warnings = a11y_item_warnings($type, $item))) {
        return '';
    }
    $items = implode('', array_map(fn($x) => '<li>' . h($x) . '</li>', $warnings));
    return '<section class="a11y-warnings" aria-labelledby="a11y-title"><h2 id="a11y-title">'
        . (count($warnings) === 1 ? '1 thing' : count($warnings) . ' things') . ' to check for accessibility</h2>'
        . '<p class="hint">Found the last time this was saved. Nothing is blocked: fix what you can in the content below and it is checked again when you save.</p>'
        . '<ul>' . $items . '</ul></section>';
}

/** Which kinds of item the control panel can edit right now, with the screen that edits each. */
function a11y_editable_types(): array
{
    $types = ['pages' => ['Pages', 'page'], 'posts' => ['Blog posts', 'post'], 'resources' => ['Cheat sheets', 'resource'], 'stories' => ['Success stories', 'stories']];
    foreach (['resources', 'stories'] as $feature) {
        if (!feature_on($feature)) {
            unset($types[$feature]);
        }
    }
    return $types;
}

/** Every item that has warnings, as [type => [item with 'a11y' filled in]]. Read once per request. */
function a11y_items_with_warnings(): array
{
    static $found = null;
    if ($found !== null) {
        return $found;
    }
    $found = [];
    foreach (array_keys(a11y_editable_types()) as $type) {
        foreach (all_items($type) as $item) {
            if ($warnings = a11y_item_warnings($type, $item)) {
                $item['a11y'] = $warnings;
                $found[] = [$type, $item];
            }
        }
    }
    return $found;
}

function a11y_screen(): never
{
    $types = a11y_editable_types();
    $found = a11y_items_with_warnings();
    $body = '<p>Every time you save a page or post, its content is checked for the most common things that trip up screen reader users: '
        . 'pictures without a description, headings out of order, links that say only “click here”, tables without header cells, text in all capitals and very long paragraphs. '
        . 'This screen lists everything found so far. Nothing is blocked; fix what you can and save again.</p>';
    if (!$found) {
        $body .= '<p><strong>Nothing to check.</strong> Your pages and posts have passed every check.</p>';
        admin_screen('Accessibility check', $body, 'a11y');
    }
    $body .= '<p>' . (count($found) === 1 ? '1 page or post has' : count($found) . ' pages or posts have') . ' things to check.</p>';
    foreach ($types as $type => [$label, $screen]) {
        $ofType = array_filter($found, fn($x) => $x[0] === $type);
        if (!$ofType) {
            continue;
        }
        $body .= '<h2>' . h($label) . '</h2><ul class="a11y-list">';
        foreach ($ofType as [, $item]) {
            $count = count($item['a11y']);
            $body .= '<li><h3><a href="' . h(admin_url($screen, ['slug' => $item['slug']])) . '">' . h((string) ($item['title'] ?? $item['slug'])) . '</a> ('
                . ($count === 1 ? '1 thing' : "$count things") . ' to check)</h3><ul>'
                . implode('', array_map(fn($x) => '<li>' . h($x) . '</li>', $item['a11y'])) . '</ul></li>';
        }
        $body .= '</ul>';
    }
    admin_screen('Accessibility check', $body, 'a11y');
}
