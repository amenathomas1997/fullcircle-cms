<?php
/**
 * Languages. A site is written in a primary language (English unless Settings says
 * otherwise) and may have a second one. Pages in the second language have addresses
 * starting with its code, /es/ for Spanish, and their files are named "es" (its home
 * page) or "es-<name>"; the same prefix marks second-language posts and cheat sheets.
 *
 * The text the site uses itself (labels, buttons, messages; not page content) comes
 * from lib/languages/<code>.php, with a site's own changes in content/languages/<code>.json
 * (the Translations screen). Only English and Spanish ship complete; any other language
 * shows English until it is translated. t('key') gives the text in the current language.
 *
 * Settings: primary_language (default en), second_language (a code, or empty). A site
 * set up before languages could be chosen has features.spanish on and no second_language:
 * it keeps working as Spanish. The settings keys menu_es, footer_html_es, footer_groups_es
 * and contact_page_es belong to the second language whatever its code; the name is historical.
 */

declare(strict_types=1);

/** Every language a site can choose: code => [name in that language, name in English, tag for documents]. */
const SUPPORTED_LANGUAGES = [
    'en' => ['English', 'English', 'en-US'],
    'es' => ['Español', 'Spanish', 'es-US'],
    'fr' => ['Français', 'French', 'fr-FR'],
    'de' => ['Deutsch', 'German', 'de-DE'],
    'it' => ['Italiano', 'Italian', 'it-IT'],
    'pt' => ['Português', 'Portuguese', 'pt-BR'],
    'nl' => ['Nederlands', 'Dutch', 'nl-NL'],
    'pl' => ['Polski', 'Polish', 'pl-PL'],
    'ar' => ['العربية', 'Arabic', 'ar-SA'],
    'zh' => ['中文', 'Chinese', 'zh-CN'],
    'ja' => ['日本語', 'Japanese', 'ja-JP'],
    'ko' => ['한국어', 'Korean', 'ko-KR'],
    'hi' => ['हिन्दी', 'Hindi', 'hi-IN'],
    'vi' => ['Tiếng Việt', 'Vietnamese', 'vi-VN'],
    'tl' => ['Tagalog', 'Tagalog', 'tl-PH'],
];

/** Languages written right to left: <html> gets dir="rtl". */
const RTL_LANGUAGES = ['ar'];

/** The second language of a site that has never chosen one (features.spanish from before the choice existed). */
const LEGACY_SECOND_LANGUAGE = 'es';

/* ---------- Which languages this site uses ---------- */

/** code => name in its own language, for every language a site can choose. */
function supported_languages(): array
{
    return array_map(fn($l) => $l[0], SUPPORTED_LANGUAGES);
}

function language_name(string $code): string
{
    return SUPPORTED_LANGUAGES[$code][0] ?? $code;
}

/** "Spanish", for the control panel, which stays in English. */
function language_english_name(string $code): string
{
    return SUPPORTED_LANGUAGES[$code][1] ?? $code;
}

/** "es-US", for Word documents and feeds. */
function language_tag(string $code): string
{
    return SUPPORTED_LANGUAGES[$code][2] ?? $code;
}

/** "ltr" or "rtl". */
function language_dir(string $code): string
{
    return in_array($code, RTL_LANGUAGES, true) ? 'rtl' : 'ltr';
}

/** The language the site is written in: pages without a prefix, /blog/, /search/. */
function primary_lang(): string
{
    $code = (string) (settings()['primary_language'] ?? '');
    return isset(SUPPORTED_LANGUAGES[$code]) ? $code : 'en';
}

/**
 * The code of the second language: the one chosen in Settings, or Spanish when none was ever chosen.
 * Empty only when it would be the same as the primary language. Whether its pages are shown is a
 * separate question: feature_on('spanish') (the "Second language pages" switch).
 */
function second_lang(): string
{
    $code = (string) (settings()['second_language'] ?? '');
    if ($code === '') {
        $code = LEGACY_SECOND_LANGUAGE;
    }
    return isset(SUPPORTED_LANGUAGES[$code]) && $code !== primary_lang() ? $code : '';
}

/** True when the second language's pages are turned on. */
function second_lang_on(): bool
{
    return second_lang() !== '' && feature_on('spanish');
}

/** The English name of the second language, for control panel labels ("Spanish menu"). */
function second_lang_name(): string
{
    return second_lang() !== '' ? language_english_name(second_lang()) : 'Second language';
}

/** code => name in its own language, for the languages in use: the primary one, and the second when it is on. */
function languages(): array
{
    $list = [primary_lang() => language_name(primary_lang())];
    if (second_lang_on()) {
        $list[second_lang()] = language_name(second_lang());
    }
    return $list;
}

/** True when $lang (the current language unless given) is the second language. */
function is_second_lang(?string $lang = null): bool
{
    $lang ??= current_lang();
    return $lang !== '' && $lang === second_lang();
}

/** The other language of the site; the primary one when there is no second. */
function other_lang(?string $lang = null): string
{
    $lang ??= current_lang();
    if (is_second_lang($lang)) {
        return primary_lang();
    }
    return second_lang() !== '' ? second_lang() : primary_lang();
}

/** The language of the page being shown, set by show_page(). Only the languages of this site are accepted. */
function current_lang(?string $set = null): string
{
    static $lang = null;
    if ($set !== null) {
        $lang = $set === primary_lang() || is_second_lang($set) ? $set : primary_lang();
    }
    return $lang ?? primary_lang();
}

/* ---------- Text ---------- */

/**
 * The strings of a language: lib/languages/<code>.php with the site's own content/languages/<code>.json
 * on top. Read once per request; strings_changed() forgets them after the Translations screen saves.
 */
function strings(string $code, bool $forget = false): array
{
    static $cache = [];
    if ($forget) {
        $cache = [];
        return [];
    }
    if (isset($cache[$code])) {
        return $cache[$code];
    }
    $list = [];
    if (preg_match('/^[a-z]{2,3}$/', $code) && is_file(__DIR__ . "/languages/$code.php")) {
        $list = (array) require __DIR__ . "/languages/$code.php";
    }
    foreach (own_strings($code) as $key => $text) {
        $list[$key] = $text;
    }
    return $cache[$code] = $list;
}

function strings_changed(): void
{
    strings('', true);
}

/** The site's own translations for a language (content/languages/<code>.json), without the shipped strings. */
function own_strings(string $code): array
{
    if (!preg_match('/^[a-z]{2,3}$/', $code)) {
        return [];
    }
    return array_filter(read_json(CONTENT_DIR . "/languages/$code.json") ?? [], fn($text, $key) => is_string($key) && is_string($text), ARRAY_FILTER_USE_BOTH);
}

/** Save the site's own translations for a language; empty texts are dropped. */
function save_own_strings(string $code, array $list): void
{
    if (!preg_match('/^[a-z]{2,3}$/', $code)) {
        throw new InvalidArgumentException('Invalid language code.');
    }
    $list = array_filter($list, fn($text, $key) => is_string($key) && is_string($text) && $text !== '', ARRAY_FILTER_USE_BOTH);
    ksort($list);
    write_json(CONTENT_DIR . "/languages/$code.json", $list);
    strings_changed();
}

/** Text in the current language, falling back to English, then to the key itself. */
function t(string $key): string
{
    return t_in(current_lang(), $key);
}

/** Text in a given language. */
function t_in(string $lang, string $key): string
{
    $text = strings($lang)[$key] ?? strings('en')[$key] ?? $key;
    // {site} and {contact} come from Settings.
    return str_contains($text, '{') ? strtr($text, ['{site}' => site_name(), '{contact}' => contact_words($lang)]) : $text;
}

/* ---------- Addresses ---------- */

/** The language of a page, post or cheat sheet, from its file name: "es" or "es-<name>" is the second language. */
function slug_lang(string $slug): string
{
    $second = second_lang();
    return $second !== '' && ($slug === $second || str_starts_with($slug, "$second-")) ? $second : primary_lang();
}

/** "es-contacto" gives "contacto"; a name without the second language's prefix is returned as it is. */
function slug_without_lang(string $slug): string
{
    $second = second_lang();
    if ($second !== '' && $slug === $second) {
        return '';
    }
    return $second !== '' && str_starts_with($slug, "$second-") ? substr($slug, strlen($second) + 1) : $slug;
}

/** The web address of a page. */
function page_path(string $slug): string
{
    if ($slug === 'home') {
        return '/';
    }
    $second = second_lang();
    if ($second !== '' && $slug === $second) {
        return "/$second/";
    }
    if ($second !== '' && str_starts_with($slug, "$second-")) {
        return "/$second/" . substr($slug, strlen($second) + 1) . '/';
    }
    return "/$slug/";
}

/** "/" for the primary language, "/es/" for the second. */
function home_path(?string $lang = null): string
{
    return is_second_lang($lang) ? '/' . second_lang() . '/' : '/';
}

/**
 * The word a language uses in a built-in address: "buscar" for search in Spanish. From the
 * route_<name> strings; languages without one use the English word. Only used on the
 * second-language side: the primary language's addresses are always /search/, /resources/ and so on.
 */
function lang_route(string $route, ?string $lang = null): string
{
    $word = (string) (strings($lang ?? current_lang())['route_' . $route] ?? '');
    return $word !== '' && valid_slug($word) ? $word : $route;
}

/** "/search/" in the primary language, "/es/buscar/" in the second. $route is search, resources, events or contact. */
function lang_route_path(string $route, ?string $lang = null): string
{
    $lang ??= current_lang();
    return is_second_lang($lang) ? "/$lang/" . lang_route($route, $lang) . '/' : "/$route/";
}

/** Add dir="rtl" to the <html> tag of a page in a right-to-left language. */
function html_dir(string $html, string $lang): string
{
    return language_dir($lang) === 'rtl' ? (string) preg_replace('/<html\b(?![^>]*\sdir=)/i', '<html dir="rtl"', $html, 1) : $html;
}
