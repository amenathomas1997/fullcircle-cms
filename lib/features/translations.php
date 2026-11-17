<?php
/**
 * Translations: a control panel screen where the site owner changes the words the
 * site uses itself (buttons, labels, messages) in the site's languages. Each row is
 * one key from lib/languages/en.php: the English text, and this language's text.
 * What the owner types is saved to content/languages/<code>.json, which sits on top
 * of the shipped lib/languages/<code>.php (see lib/i18n.php). Only keys that exist in
 * English are listed; a site's own extra keys in the JSON file are kept as they are.
 *
 * The screen is for owners only (admin/index.php lists it under ACTION_ROLES) and
 * appears when the second language pages are on, or the site isn't written in English.
 */

declare(strict_types=1);

register_admin_screen('translations', fn (bool $post) => $post ? translations_save() : translations_form(), 'Translations', null);

/** The languages whose words can be edited: every language in use other than English. */
function translatable_languages(): array
{
    return array_values(array_filter(array_keys(languages()), fn($code) => $code !== 'en'));
}

/** The language picked with ?lang=, or the first editable one; empty when there is nothing to translate. */
function translations_lang(): string
{
    $list = translatable_languages();
    $pick = (string) ($_GET['lang'] ?? '');
    return in_array($pick, $list, true) ? $pick : ($list[0] ?? '');
}

/** The key, the English text and this language's text, for every key in English. Filtered by a few words when $q is given. */
function translation_rows(string $code, string $q = ''): array
{
    $english = strings('en');
    $shipped = [];
    if (preg_match('/^[a-z]{2,3}$/', $code) && is_file(ROOT . "/lib/languages/$code.php")) {
        $shipped = (array) require ROOT . "/lib/languages/$code.php";
    }
    $own = own_strings($code);
    $words = preg_split('/\s+/u', mb_strtolower(trim($q)), -1, PREG_SPLIT_NO_EMPTY);
    $rows = [];
    foreach ($english as $key => $text) {
        if (!is_string($key) || !is_string($text)) {
            continue;
        }
        $mine = (string) ($own[$key] ?? '');
        $default = (string) ($shipped[$key] ?? '');
        if ($words) {
            $hay = mb_strtolower("$key $text $mine $default");
            foreach ($words as $w) {
                if (!str_contains($hay, $w)) {
                    continue 2;
                }
            }
        }
        $rows[] = ['key' => $key, 'en' => $text, 'mine' => $mine, 'shipped' => $default];
    }
    return $rows;
}

function translations_form(array $errors = []): never
{
    require_role('owner');
    $code = translations_lang();
    if ($code === '') {
        admin_screen('Translations', '<p>There is nothing to translate yet: the site is written in English and has no second language turned on. '
            . 'Choose a second language under <a href="' . h(admin_url('settings')) . '">Settings</a>, then turn on “Second language pages” under Features.</p>', 'translations');
    }
    $q = trim((string) ($_GET['q'] ?? ''));
    $name = language_english_name($code);
    $native = language_name($code);
    $rows = translation_rows($code, $q);
    $complete = is_file(ROOT . "/lib/languages/$code.php");

    $switch = '';
    if (count(translatable_languages()) > 1) {
        $switch = '<nav aria-label="Which language"><ul class="period-list">';
        foreach (translatable_languages() as $c) {
            $switch .= '<li><a href="' . h(admin_url('translations', ['lang' => $c])) . '"' . ($c === $code ? ' aria-current="page"' : '') . '>' . h(language_english_name($c)) . '</a></li>';
        }
        $switch .= '</ul></nav>';
    }
    $intro = '<p>These are the words the website uses itself in ' . h($name) . ': buttons, labels, headings and messages. Page content is edited under Pages. '
        . ($complete ? 'A translation comes with the kit; anything you type here replaces it. '
            : 'No ' . h($name) . ' translation comes with the kit yet, so visitors see the English words until you fill them in here. ')
        . 'Leave a box empty to use ' . ($complete ? 'the kit’s wording' : 'the English text') . '. '
        . 'Keep any <code>%s</code>, <code>%d</code>, <code>{site}</code> or <code>{contact}</code> you see: the site fills them in. '
        . 'Your words are saved in <code>content/languages/' . h($code) . '.json</code>.</p>'
        . '<form method="get" action="' . h(admin_url()) . '" role="search" class="inline-form"><input type="hidden" name="a" value="translations"><input type="hidden" name="lang" value="' . h($code) . '">'
        . text_field('q', 'Show only rows containing', $q, ['hint' => 'A word from the key or the text, for example “blog” or “testimonial”.'])
        . '<button type="submit" class="button button-secondary">Filter</button>'
        . ($q !== '' ? ' <a href="' . h(admin_url('translations', ['lang' => $code])) . '">Show all</a>' : '') . '</form>';

    $table = '<p>' . ($rows ? count($rows) . ' of ' . count(strings('en')) . ' rows shown.' : 'No rows match that filter.') . '</p>';
    if ($rows) {
        $table .= '<form method="post" action="' . h(admin_url('translations', ['lang' => $code] + ($q !== '' ? ['q' => $q] : []))) . '">' . csrf_field()
            . '<div class="form-actions"><button type="submit" class="button">Save all ' . h($name) . ' translations</button></div>'
            . '<div class="table-wrap"><table class="translations"><caption>' . h($name) . ' translations' . ($q !== '' ? ' containing “' . h($q) . '”' : '') . '</caption>'
            . '<thead><tr><th scope="col">Key</th><th scope="col">English</th><th scope="col">' . h($name) . '</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $id = 't-' . $r['key'];
            $table .= '<tr><th scope="row" id="' . h($id) . '-key"><code>' . h($r['key']) . '</code></th>'
                . '<td id="' . h($id) . '-en">' . h($r['en']) . '</td>'
                . '<td><label for="' . h($id) . '" class="visually-hidden">' . h($name) . ' text for ' . h($r['key']) . '</label>'
                . '<textarea id="' . h($id) . '" name="t[' . h($r['key']) . ']" rows="' . (mb_strlen($r['en']) > 70 ? 3 : 1) . '" lang="' . h($code) . '"'
                . ($r['shipped'] !== '' ? ' placeholder="' . h($r['shipped']) . '"' : '') . ' aria-describedby="' . h($id) . '-en">' . h($r['mine']) . '</textarea></td></tr>';
        }
        $table .= '</tbody></table></div>'
            . '<div class="form-actions"><button type="submit" class="button">Save all ' . h($name) . ' translations</button></div></form>';
    }
    admin_screen('Translations: ' . $name . ' (' . $native . ')', $switch . $intro . $table, 'translations', ['errors' => $errors]);
}

function translations_save(): never
{
    require_role('owner');
    $code = translations_lang();
    if ($code === '') {
        not_found_screen();
    }
    $english = strings('en');
    $own = own_strings($code);
    $posted = (array) ($_POST['t'] ?? []);
    $changed = 0;
    foreach ($posted as $key => $text) {
        $key = (string) $key;
        if (!isset($english[$key])) {
            continue;
        }
        $text = str_replace("\r\n", "\n", trim((string) $text));
        if (mb_strlen($text) > 2000) {
            $text = mb_substr($text, 0, 2000);
        }
        if (($own[$key] ?? '') !== $text) {
            $changed++;
        }
        if ($text === '') {
            unset($own[$key]);
        } else {
            $own[$key] = $text;
        }
    }
    save_own_strings($code, $own);
    if (function_exists('activity_log')) {
        activity_log('changed translations', language_english_name($code) . ", $changed " . ($changed === 1 ? 'row' : 'rows'));
    }
    flash($changed === 0 ? 'Nothing had changed.' : "Saved $changed " . ($changed === 1 ? 'translation' : 'translations') . ' in ' . language_english_name($code) . '. The website uses them straight away.');
    go(admin_url('translations', ['lang' => $code] + (($_GET['q'] ?? '') !== '' ? ['q' => (string) $_GET['q']] : [])));
}
