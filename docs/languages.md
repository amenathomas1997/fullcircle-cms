# Languages

A site is written in one **primary language** (English unless Settings says otherwise) and can have one **second language**. The second language gets its own side of the site: a home page, pages, a blog, search, cheat sheets, a menu, a footer and a language switch in the header.

Everything here is in `lib/i18n.php`. Nothing else in the kit needs to know which languages a site uses: it asks.

## Choosing languages

**Settings > Site details > Languages** has two drop-downs:

- **Language the site is written in** (`primary_language` in `content/settings.json`, default `en`).
- **Second language** (`second_language`, a code or empty). Choosing a language turns its pages on; choosing **None** turns them off but keeps the pages and the code. This is the same switch as **Second language pages** under Settings > Features (`features.spanish`, a historical name), which reads the chosen language's name.

The codes a site can choose, with the names shown to visitors: `en` English, `es` Español, `fr` Français, `de` Deutsch, `it` Italiano, `pt` Português, `nl` Nederlands, `pl` Polski, `ar` العربية, `zh` 中文, `ja` 日本語, `ko` 한국어, `hi` हिन्दी, `vi` Tiếng Việt, `tl` Tagalog (`SUPPORTED_LANGUAGES`). Arabic pages get `dir="rtl"` on `<html>`; every page gets `lang` from its language.

`php tools/setup.php` asks the same questions (`SETUP_PRIMARY_LANGUAGE`, `SETUP_SECOND_LANGUAGE=fr`, or the older `SETUP_SPANISH=yes`), and the first-run screen in the control panel offers the second language.

**Sites from before this choice existed** have `features.spanish` on and no `second_language`. They behave exactly as before: the second language is Spanish, every `/es/` address, slug and feed is unchanged. Nothing needs migrating.

## Addresses and file names

The second language's code is the prefix everywhere Spanish used `es` before:

| What | Primary language | Second language (`fr` as the example) |
| --- | --- | --- |
| Home page | file `home`, address `/` | file `fr`, address `/fr/` |
| A page | `contact` → `/contact/` | `fr-contact` → `/fr/contact/` |
| A blog post | `hello` → `/blog/hello/` | `fr-bonjour` → `/fr/blog/bonjour/` |
| Blog list and feed | `/blog/`, `/blog/feed.xml` | `/fr/blog/`, `/fr/blog/feed.xml` |
| Search | `/search/` | `/fr/<route_search>/` |
| Cheat sheets list | `/resources/` | `/fr/<route_resources>/` (the sheets themselves stay at `/resources/<slug>/`) |
| Events | `/events/` | `/fr/<route_events>/` |

The words in the second language's built-in addresses come from its strings: `route_search`, `route_resources`, `route_events` and `route_contact` (`buscar`, `guias-rapidas`, `eventos`, `contacto` in Spanish). A language without them uses the English words, so French search is at `/fr/search/` until someone translates `route_search`. The primary language's addresses are always the English words, whatever the primary language is.

The settings keys `menu_es`, `footer_html_es`, `footer_groups_es` and `contact_page_es` hold the second language's menu, footers and contact page whatever its code. The names are historical and were kept so existing sites keep working.

## The site's own words

Labels, buttons, headings and messages that the site writes itself (not page content) come from string files:

- `lib/languages/<code>.php` returns the array for a language. English (`en.php`) is complete and is the fallback for every other language; Spanish (`es.php`) is complete too. Other languages have no file yet, so visitors see English until a translation exists. To add a language to the kit, copy `en.php` to `<code>.php` and translate the values.
- `content/languages/<code>.json` holds one site's own words for a language and sits on top of the shipped file. It can override any key, or add keys a site's own code in `site/` uses.

The **Translations** screen in the control panel (owners only; it appears under Checks and tools) edits that JSON file: one row per key that exists in English, with the English text and this language's text, a filter box, and one Save for all rows. An empty box means "use the kit's wording" (or the English text for a language without a kit translation). It covers every language in use other than English, so a site written in Spanish with English pages edits Spanish there.

In code:

- `t('key')` gives the text in the current language, falling back to English, then to the key itself. `{site}` and `{contact}` are filled in from Settings.
- `t_in('es', 'key')` does the same for a given language (for emails, documents and other places outside a page).
- `strings('es')` is the whole array (read once per request); `own_strings()` and `save_own_strings()` read and write the JSON file.
- Dates: `date_long` says how a language orders the day, month and year (`{month} {day}, {year}` in English, `{day} de {month} de {year}` in Spanish), used with `month_1` to `month_12`.

## Functions for feature and site code

| Function | Gives |
| --- | --- |
| `primary_lang()`, `second_lang()` | the two codes (`second_lang()` is empty only when none can apply) |
| `second_lang_on()` | true when the second language's pages are turned on |
| `languages()` | code => native name for the languages in use (one or two) |
| `supported_languages()`, `language_name()`, `language_english_name()`, `language_tag()`, `language_dir()` | the list a site can choose from, and one language's native name, English name, `es-US`-style tag and `ltr`/`rtl` |
| `current_lang()` | the language of the page being shown; `current_lang('fr')` sets it |
| `is_second_lang($lang)`, `other_lang($lang)` | tests and the other language of the site |
| `slug_lang($slug)`, `slug_without_lang($slug)`, `page_path($slug)` | the language of a file name, the name without its prefix, and a page's address |
| `home_path($lang)` | `/` or `/fr/` |
| `lang_route('search', $lang)`, `lang_route_path('search', $lang)` | the word a language uses in a built-in address, and the full address (`/search/` or `/fr/recherche/`) |
| `blog_base($lang)`, `resources_index_path($lang)`, `events_base($lang)`, `search_url()` | the list addresses for a language |
| `contact_path($lang)` | the contact page for a language, from Settings |
| `html_dir($html, $lang)` | adds `dir="rtl"` to `<html>` for a right-to-left language |

Rules of thumb for a feature file:

1. Never compare with `'es'` or write `/es/`. Ask `is_second_lang()`, `second_lang()`, `home_path()` or `lang_route_path()`.
2. Put visitor-facing words in `lib/languages/en.php` (and `es.php`) and use `t()`. Words in the control panel stay English; use `language_english_name()` or `second_lang_name()` to name a language there.
3. A route on the second-language side looks like `is_second_lang($parts[0]) && ($parts[1] ?? '') === lang_route('things', $parts[0])`, then `current_lang($parts[0])`.
4. Starter content: `create_starter_content(string $second = '')` makes Spanish pages for `es`, and for any other language the same pages in English with a note asking for a translation. Nothing is machine translated.

## Testing a language change

```
php tools/setup.php --yes   # with SETUP_SECOND_LANGUAGE=fr
curl -i http://localhost:8000/fr/           # lang="fr", switch says English
curl -i http://localhost:8000/fr-contact/   # 301 to /fr/contact/
curl http://localhost:8000/fr/blog/feed.xml
echo '{"skip": "Aller au contenu principal"}' > content/languages/fr.json
```

Then in the control panel: Settings shows the two drop-downs, the Features switch reads "Second language pages (French)", the page editor's address hint names `fr-`, the Menu and Footer screens have an English and a French tab, and Translations lists every English key with a French column.
