# Changelog

Every release of the kit is listed here, newest first. `php tools/update.php` prints the entries that are new since the version a site has installed.

## 1.7.1 — 2026-11-19
The update tool keeps a site's own `.htaccess` the way it keeps its layout and stylesheet, because cPanel appends a PHP handler block to it; until now that block stopped the update with "local changes to the kit's files".

## 1.7.0 — 2026-11-19

### Forms: attached files, fields that appear on an answer, signatures, and saving to finish later

- **File upload field**: a form can ask for a photo, PDF, Word or Excel document or plain text file, up to 10 MB (or less if the server allows less). Files are checked by their contents, not just their name, and kept beside the submission under `data/`, where nothing is served; Submissions has a Download link, the email names the file, and deleting a submission removes its files.
- **Show a field only when…**: any field can depend on an earlier choice list, checkboxes or yes/no field having a particular answer. The field appears and disappears as the person answers (with the site script) and is skipped by the server when it is not shown, so it is never required by mistake. Conditions follow their field when fields are moved and clear when it is removed.
- **Signature field**: the person types their full name and ticks a box agreeing that this is their signature, which is what the submission records, with the time. A drawing box is also offered to mouse, finger and stylus users; it is optional, and the drawing is kept as a PNG beside the submission.
- **Save and finish later**: a per-form switch adds a second button. What was typed so far is kept for 30 days under a private link, shown on the page and emailed when the form asks for an email address; coming back by the link puts the answers back, and sending the form removes the saved copy. Expired copies are tidied daily.
- The self-test drives all four: conditions, file checks (a file whose contents do not match its name is refused), signatures, drafts and the condition remapping.

## 1.6.1 — 2026-11-18
`tools/update.php --migrate` runs only the migrations not yet run, with no git involved, for a site whose code arrives by rsync from a deploy pipeline. Until now such a site never ran its migrations.

## 1.6.0 — 2026-11-18

### Undo, safer sign-in, a monthly report, and more for appointments

- **Previous versions with undo**: the page, post and cheat sheet edit screens end with a list of the last 10 saved versions, who saved each and when, each with a "Put this version back" button. Putting a version back is a new save, so the version it replaced stays in the list and the step can be undone the same way. The title and content come back; the address, status and other settings stay as they are.
- **Pending edits and review links for pages**: "Save as a pending edit" keeps a change off the website until it is published. A notice at the top of the page form offers to edit, publish or discard it, and a review link can show the pending edit, so a client can send the owner a preview before anything goes live.
- **Two-step sign-in by email**: on a browser an account has not used before, a six-digit code is emailed after the password; it works for 10 minutes and the browser is then remembered for 30 days. On by default, with a switch under Settings (which cannot be turned on until the site can send email) and an owner-only "Forget all remembered browsers" button. Codes are limited to 3 per account in 15 minutes and 5 tries each. Accounts with an authenticator app are not asked twice.
- **Monthly report**: on the 1st of each month the owner gets one email about last month: visitors (or "not counted"), appointments made and cancelled, form answers, contact messages and any unsent, uptime of the sites this one watches, the latest backup, days left on the certificate, the kit version and what changed in the kit that month. A switch and a "Send me this month's report now" button under Settings.
- **Appointments**: minutes kept free before and after each booking, per service with a site default (the old single gap setting carries over to both). Visitors can change the time of a booking from their confirmation email or page: the old booking is released only once the new time is confirmed, the calendar event is moved (old deleted, new made), and the visitor and owner each get one email. A text message reminder the day before, through Twilio (Settings, Text messages), for visitors who tick the box when booking; off until the three Twilio values are filled in.
- `tools/calendar-roundtrip.php` makes, reads back, busy-checks and deletes one event in the connected calendar, for whichever kind is connected; its header lists the Google service account steps.
- A LICENSE file (MIT, Full Circle AT). The self-test covers page history, the sign-in code lifecycle, the report builder for a fixed month, the uptime tally and the reschedule flow against the stand-in calendars; the mail transport and texting service can be stood in for.
- Migration 002 makes the pending-edits folder and splits the old appointment gap into before and after.

## 1.5.2 — 2026-11-18
Microsoft calendar test works for Outlook.com accounts

- "Test the connection" on the Appointments screen no longer asks Microsoft Graph for the owner's profile. A personal (Outlook.com) account's calendar token does not cover the profile and Graph answered 403 with no message, so the test failed as "Microsoft Graph:" even though the calendar itself was reachable. The test now names the account saved when the calendar was connected.

## 1.5.1 — 2026-11-18

### Calendar connections checked end to end

- The self-test now stands in for Microsoft Graph, Google Calendar and a CalDAV server and runs the real request code against them: token renewal, paged busy times, making and removing events, discovery, and the plain-words errors for an expired connection, an unshared calendar and a wrong password.
- Fixes found that way: the CalDAV calendar list could offer a tasks-only calendar (it now reads which components a calendar holds); a Microsoft or Google access token revoked before its time is renewed once and the call repeated instead of failing.

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
