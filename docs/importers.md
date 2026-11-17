# Importers: bringing an existing website in

The **Import** screen (owner only, under *Checks and tools*) brings an old website into the kit as drafts. There are three ways in; all three end in a report of what was created and what was skipped, with a link to each new draft's edit screen.

Nothing goes live on its own:

- every imported page and post is saved as a **draft**;
- a page or post whose address is already in use on this site is **never overwritten**: it is skipped and the report says so;
- you see a preview first ("This file contains 12 pages, 40 posts and 85 media files. Import as drafts?") and the import only happens when you confirm.

Code: `lib/features/importers.php` (everything the importers do) and `admin/importers.php` (the screens). Work in progress waits in `data/imports/work/<token>/`; finished imports are logged to `data/imports/<time>.json`; downloaded media lands under `uploads/import/<YYYY>/<MM>/`.

## 1. A WordPress export file

In WordPress: *Tools > Export*, choose *All content*, download the `.xml` file (a WXR file) and upload it on the Import screen. Files up to 64 MB are accepted, within what the server allows (`upload_max_filesize` and `post_max_size`; the screen shows the working limit). The file is read with `XMLReader`, one item at a time, so a big export does not need to fit in memory.

What comes in:

- **Pages**, with their address (slug). A page under another page keeps that link in a `parent` field holding the parent's slug, ready for nested pages.
- **Posts**, with their date, categories and excerpt. Tags are not kept.
- **Attachments** and any media the content refers to: JPEG, PNG, GIF, WebP, PDF, Word, MP3 and M4A files up to 15 MB each, downloaded with curl (20 seconds each) from the old site itself or from any https address. Links and images in the content are rewritten to the new copies under `/uploads/import/`. The old site must therefore still be online. A WordPress "-300x200" resized name is trimmed back to the original name.
- Links between imported pages and posts are rewritten to their new addresses.

What does not: trashed items, revisions, menus, custom post types and the like (the preview says how many were left out), featured images, and the site logo. Shortcodes such as `[gallery]` are left as text and listed in the report's notes.

Each post keeps its original date; posts that were drafts in WordPress stay drafts, and so does everything else.

## 2. A zip of HTML files

For a Google Sites Takeout, an old hand-made site, or any folder of `.html` files: zip it and upload it. Each `.html` file becomes a page:

- the title is the `<title>` (with a trailing " – Site name" dropped when the first heading matches) or the first `<h1>`;
- the body is `<main>`, `<article>` or `[role=main]` when the page has one; otherwise the `<body>` minus navigation, header, footer, scripts and styles, narrowed to the wrapper that holds most of the words;
- `folder/index.html` becomes the folder's own page, and the other files in that folder get it as their `parent`;
- the root `index.html` is saved as the draft `imported-home` (this site already has a home page), with a note in the report; links to it point at `/`;
- images and files the pages reference relatively are copied out of the zip (same types and size limit as above), and links between the files become links between the new pages;
- a top-level folder that every file shares (`Takeout/`, `my-site/`) is not treated as a page.

## 3. A live website address

For Wix, Squarespace, Google Sites and other builders with no export. Enter the home page address; the importer fetches it and follows links on the same host, breadth first, up to **60 pages**, 20 seconds per fetch, with a half-second pause between fetches. It skips links with query strings or fragments, `mailto:` and `tel:` links, and any file other than `.html`, `.htm` or no extension. Content is extracted as for a zip (section 2), images are downloaded, and the report lists addresses that could not be read, pages that answered with the same content as another (sites that show the home page for a missing address), and how many linked pages were left unfetched when the limit was reached.

**It runs in the foreground.** The preview crawls the site, and the import downloads the media; each step holds the screen until it has finished, which can take a few minutes for a big site. The screens say so. Leave the tab alone until it comes back; closing it does not stop the work, but you will have to find the report under *Recent imports*. There is no background job.

## The report, redirects and the log

The report lists pages, blog posts (each with an **Edit** link), media files, skipped items with reasons, and notes. When imported items have addresses that differ from the old site's (a WordPress post at `/2020/05/hello/` is now `/blog/hello/`; `services/pricing.html` is now `/pricing/`), the report offers **Add redirects for N old addresses**. One click writes 301 redirects through the Redirects feature (`data/redirects.json`); ones already present are not added twice, and the report then links to the Redirects screen.

Every import writes `data/imports/<date>_<time>-<id>.json` with everything the report shows (`created`, `skipped`, `notes`, `redirects`, the old→new address map and media map). The Import screen shows the last five. An import is also noted in the Activity log, as is each draft it creates and each batch of redirects it adds.

Each imported item carries an `imported_from` field with its old address, for reference. The page and post edit forms do not keep `imported_from` or `parent` when the item is next saved from the form; the nested-pages work adds the field for `parent`.

## HTML cleaning

`import_clean_html(string $html, callable $rewriteUrl): string` cleans every body so it reads well in the Blocks editor. It is standalone (DOM only, nothing from the kit) and has its own tests:

```
php lib/features/importers.php --test
```

What it does: strips comments including WordPress block comments; drops inline styles, ids, event handlers, `data-*` attributes and every class except `button` (a WordPress `wp-block-button__link` becomes `class="button"`); drops `srcset`, `sizes` and `loading` but keeps `width` and `height` on images; keeps `<figure>` and `<figcaption>`; keeps `href`, `alt`, table `scope`/`colspan`/`rowspan`, `ol start`, iframe `src`/`title`/size; removes scripts, styles, forms and embeds; unwraps `<span>`, `<font>` and any `<div>` or `<section>` that only wraps one block (nested wrappers too); turns `&nbsp;` into a space; removes empty paragraphs, headings and list items; gathers loose text and inline elements between blocks into a paragraph; and passes every `href` and `src` through `$rewriteUrl`. Shortcodes are left as text; `import_shortcodes()` finds them for the report.

## Limits and notes for hosts

- Uploads: 64 MB in the code, further limited by PHP's `upload_max_filesize` and `post_max_size`.
- Media: 15 MB per file; JPEG, PNG, GIF, WebP, PDF, DOCX, MP3, M4A; each file's bytes are checked against its extension before it is kept.
- Time: `set_time_limit(600)` for the crawl and the import; hosts that cap execution time at the web server (FastCGI timeouts) may cut a very large import short, in which case the report says it stopped early and lists what was created up to then.
- Previews that are never confirmed are removed from `data/imports/work/` after a day.
- `curl` and the `xmlreader`, `zip` and `dom` extensions are needed; they are standard on cPanel hosts.
