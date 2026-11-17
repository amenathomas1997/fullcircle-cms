# Changelog

Every release of the kit is listed here, newest first. `php tools/update.php` prints the entries that are new since the version a site has installed.

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
