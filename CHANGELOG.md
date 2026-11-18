# Changelog

Every release of the kit is listed here, newest first. `php tools/update.php` prints the entries that are new since the version a site has installed.

## 1.5.0 — 2026-11-17

### Alerts, safer updates and CI

- **Alerts by email** from the site that watches the others: a site down for two checks in a row (and back again), a certificate under 14 days, no backup for two nights, or contact messages a site could not email. At most once a day per concern, several in one email, with a switch and a test button on the Sites screen.
- `tools/update.php --apply --verify` runs the self-test after updating and puts the code back if it fails (exit code 2).
- `tools/selftest.php`: syntax of every file, the bootstrap and registry, the appointment engine, the iCalendar parser, the alert logic, and with `--site` a crawl of a running site and every control panel screen. A GitHub Actions workflow runs it on every push.
- Fixes: a just-updated site no longer reports its own version as an available update; the Backups screen and the status report look for backups in the account's home folder, like backup.sh, so subdomain sites are not backed up inside the web root; the local router serves the calendar sign-in callback.

## 1.4.0 — 2026-11-17

### Appointments

- An **Appointments** feature (off by default). Visitors book a time at `/book/`: a service, a day with openings, a time, their details. Openings are worked out from the owner's hours and the busy times in one connected calendar, which can be a cPanel-hosted or other CalDAV calendar (iCloud, Fastmail, Nextcloud), a Microsoft 365 or Outlook.com calendar connected by signing in once, or a Google Calendar shared with a service account. Each booking is written into that calendar, checked again at the moment of confirming so a time cannot be taken twice, and kept in `data/appointments/`. The visitor gets a confirmation email with an "Add to my calendar" file and a cancel link, the owner is emailed, and a reminder goes out the day before. The Appointments screen has Bookings, Services, Hours and wording, and Calendar tabs, with a connection test. Shortcode `[book]`.
- Deploy screen fixes from the first real deployment: the command runner read the exit code wrongly on PHP 8.1 (a successful step looked like a failure), the final check used a cURL constant PHP does not define, and the cPanel sign-in error now explains two-factor authentication and the API token option.

## 1.3.0 — 2026-11-16

### Deploy screen

- A **Deploy new sites** feature (off by default; owner only). Given a cPanel account's sign-in, the domain, the site's details and the owner's name and email, the Deploy screen builds a new site from here: it signs in with the cPanel API, finds the web folder and fixes the domain's PHP version, puts this site's SSH key on the account (password over SSH or API token through the API), checks PHP and git, clones the kit, runs the set-up, adds the cron jobs, makes a fleet key and lists the new site on the Sites screen, and sends the owner a set-password link. Every step is reported in plain words and a stopped deployment can be carried on from that step. Passwords and tokens are never stored.
- Payments: a fix for PHP 8.1, which refuses an arrow function declared as never returning.

## 1.2.0 — 2026-11-16

### Pages

- Nested pages: a "Parent page" list on the page form puts a page inside another, up to three levels (`/services/reiki/pricing/`). The Pages screen shows the tree with "Level 2:" and "Level 3:" read before nested titles; visitors get a "You are here" breadcrumb trail (with BreadcrumbList structured data) and the parent's menu item marked as the current section. Addresses are built with `page_path()`; a page opened by its bare name or an old address is redirected (301) to its real one. Renaming or moving carries the children along and updates menu links; deleting asks whether the children move up a level or go too.

### Languages

- Any second language, not only Spanish: Settings, Site details, Languages has "Language the site is written in" and "Second language" (`primary_language`, `second_language`; fifteen codes in `SUPPORTED_LANGUAGES`). Choosing a language turns its pages on; "None" turns them off and keeps the pages. The Features switch reads "Second language pages (French)" and does the same thing. `tools/setup.php` takes `SETUP_PRIMARY_LANGUAGE` and `SETUP_SECOND_LANGUAGE` (`SETUP_SPANISH=yes` still means `es`), and the first-run screen offers the list.
- The language's code is the prefix everywhere Spanish used `es` (`fr-contact` at `/fr/contact/`, `/fr/blog/`); built-in address words come from `route_search`, `route_resources`, `route_events` and `route_contact`, with the English words as the fallback. Arabic pages get `dir="rtl"`. Sites from before the choice keep working as Spanish sites with nothing to migrate.
- The site's own words come from `lib/languages/<code>.php` (English and Spanish complete, English the fallback) and a site's `content/languages/<code>.json`, edited on the new Translations screen (owner): one row per English key, a filter, one Save. Starter content for a language other than Spanish is English with a "waiting to be translated" note.

### Importing

- An Import screen (owner) brings an old website in as drafts three ways: a WordPress export file (WXR up to 64 MB, read with `XMLReader`; pages with their parent, posts with date, categories and excerpt, media downloaded from the old site), a zip of HTML files (Google Sites Takeout or any folder; folders become parent pages), or a live address crawled breadth first up to 60 pages. A preview comes first, nothing goes live, existing addresses are never overwritten, and the report lists created, skipped and notes with Edit links and an "Add redirects for N old addresses" button. Media (15 MB each) lands under `uploads/import/`; every import is logged to `data/imports/` and the Activity log; `set_time_limit(600)` for the crawl and the import. `import_clean_html()` has its own tests (`php lib/features/importers.php --test`).

### Control panel

- The control panel menu is read as four named groups, Content, People and messages, Checks and tools, and Site, instead of one list of thirty links. Screens a feature registers join Content unless named in another group.
- The Settings screen's "On this screen" navigation is generated from the level 2 headings actually present, so sections features add or remove appear and disappear with them.
- The guides: nested pages, languages and importing are documented in OWNER-GUIDE.md and README.md; the separate docs files are gone.

## 1.1.0 — 2026-11-16

### Sign-in and people

- Named accounts replace the shared site password: each person has a name, email address, password and role (owner, editor or author). `tools/setup.php` and the web first run create the owner (`SETUP_OWNER_NAME`, `SETUP_OWNER_EMAIL`); an existing site's shared password works once more to create the owner, and a Microsoft 365 allow-list becomes editor accounts.
- My account: change your name and password, turn on two-factor sign-in (authenticator app codes, ten one-time recovery codes), or turn it off.
- "Forgot your password?" emails a one-hour, single-use link. `tools/set-password.php` resets a person's password, creates the first owner, or adds an owner with `--owner`.
- People screen (owner): invite, change roles, send set-password links, turn off someone's two-factor, remove. Activity screen (owner): the last 200 of up to 5,000 recorded actions, filterable by person.
- Microsoft 365 sign-in matches an account by email address and gives that account's role; Settings keeps only the on/off switch.

### Writing

- Pages get the same Editor switch as posts (Visual, Blocks, HTML) and start in HTML.
- Blocks editor: a Question and answer block (an expandable FAQ entry) and five section blocks: Hero (page opening), Features or services list, Call to action, Contact details and Two columns. A new site's home page is built from them. Round trips are lossless; unknown HTML stays an HTML block.
- Alt text help from Claude: "Suggest a description" under an Image block's Alt text field, and a Description column with a "Describe" button on Images and files, remembered for the next time the picture is used. Needs the Anthropic API key and `vendor/`; 200 descriptions a day.
- Accessibility check on every save of a page, post, cheat sheet or story: warnings in the "Saved" message and above the edit form, an Accessibility screen and a Dashboard card. Nothing is blocked.
- Footer screen: a Contact column from Settings, up to 6 link groups of 20 links, and a small-print line, in English and Spanish, with the Site template's footer HTML as the fallback for older sites.
- Scheduled pages: a "Publish on" date and time on the page form; the page is hidden from visitors, the sitemap and the menu until then.
- Review links: share a draft or scheduled page or post for 1 to 30 days without signing in, with a banner, noindex headers and a Revoke button.

### Email

- A third sending method, SMTP, beside PHP mail and Microsoft 365: an ordinary email account (Gmail, Outlook.com or a host's mailbox) with STARTTLS or implicit TLS, app-password hints, and error messages that name the step that failed.

### Forms and events

- Forms (on by default): build forms in the control panel from eleven field types; each gets a `[form name]` shortcode; answers are kept under Submissions with read marks, emailed with a Reply-To, and downloadable as CSV.
- Events (off by default): events with times, places, online links and weekly or monthly repeats; `/events/`, one page per event with an "Add to my calendar" file, `/events/feed.ics`, `[upcoming-events]` and `[events-list]`, and `/es/eventos/` with Spanish dates.

### Payments

- Payments (off by default): card payments on a Stripe-hosted page. Products with a `[pay address]` shortcode and a `/pay/address/` page, an Orders screen with monthly totals and CSV, owner emails, a signed webhook, test and live keys, Spanish pages included.

### Addresses

- Redirects screen, always on: 301 or 302 redirects from old addresses, and a log of missing addresses (404s) from the last 30 days with an "Add a redirect" link for each and a Dashboard card.

### Operations

- `tools/backup.sh` finds the site from its own location, writes a manifest beside each archive and honours `backup_dir` in `data/config.php`; the Backups screen reads the same folder.
- Backups screen: restore one item, restore everything (with a safety copy first), export this site, import a site. `tools/restore.sh` is the command-line twin of restore everything.
- Restores, imports and backup downloads are written to the Activity log.
- The daily cron slot now runs the features' daily jobs (update check, review-link pruning). Migration 001 makes sure the data folders exist.
- README rewritten around creating sites with `git clone` and updating them with `tools/update.php`, with a checklist for a new client site; OWNER-GUIDE rewritten to cover everything above.

## 1.0.0 — 2026-11-16

The first numbered release of Full Circle CMS: a flat-file PHP 8.1 website system with an accessible control panel, built for owners who use screen readers.

- Pages and blog posts written as HTML, in a visual editor, or in the Blocks editor (every paragraph, heading, list and image is a labelled form field), with 25 earlier versions kept per item and a preview before saving.
- Blog with drafts, scheduled posts, topics, reading time, related posts, an RSS feed and an audio version per post with a podcast feed.
- Contact form that saves every message and emails it; email through PHP mail or Microsoft 365.
- Images and files, a menu editor, a site template editor, private visitor counts, a monthly link check with a daily health check.
- Optional features with a switch each: site search, cheat sheets (Word, large print and braille downloads with a quiz), testimonials, email updates, videos, success stories, the Ask a question assistant, Microsoft Bookings next opening, and Spanish pages.
- Operations for people who host several sites: a VERSION file and `tools/update.php`, a System screen with an update check, a signed `/status.json` and a Sites screen that checks every site in one go, and a Backups screen with restore, export and import.
