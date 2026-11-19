# Full Circle CMS

Full Circle CMS is a small website system written in plain PHP. There is no database: pages, blog posts and settings are JSON files in the `content` folder, and private data (accounts, messages, email settings, keys) lives in `data`. It runs on ordinary shared hosting (cPanel, Apache or LiteSpeed, PHP 8.1 or later) and comes with an accessible control panel at `/admin/`. Everything was built to work well with screen readers, both for visitors and for the person running the site. This README is for the person who installs and hosts a site. OWNER-GUIDE.md is for the person who writes on it day to day. docs/EXTENDING.md is for anyone adding code. The kit is free to use, change and share under the MIT license (see LICENSE).

## What's in the box

Always on:

- Pages and blog posts, each with a choice of three editors: visual (TinyMCE 7), Blocks (every paragraph, heading, list, image and whole section is a labelled form field, built for JAWS, NVDA and VoiceOver) and plain HTML; earlier versions kept (25 per item), a preview before saving, and an accessibility check on every save. Pages can sit inside other pages, three levels deep, with breadcrumbs.
- An Import screen that brings an old website in as drafts: a WordPress export file, a zip of HTML files, or a live address that is crawled.
- Blog with drafts, scheduled posts, topics, reading time, related posts, an RSS feed, and an audio version per post with a podcast feed. Pages can be scheduled too, and any page or post can be shared before it is live with a review link.
- Named accounts with three roles (owner, editor, author), optional two-factor sign-in, "Forgot your password?" by email, a People screen and an Activity log.
- A contact form (`[contact-form]`) that saves every message in the control panel and emails it to you.
- Images and files (JPEG, PNG, GIF, WebP, PDF, Word) with alt-text suggestions from Claude when an API key is set, a menu editor, a Footer screen, a site template editor (layout, stylesheet), redirects with a log of missing addresses, private visitor counts with no cookies, a monthly link check with a daily health check, nightly backups with restore, export and import, and a one-click backup download.
- Operations for people who host several sites: a VERSION file, `tools/update.php` (with `--verify`, which rolls back an update that fails its checks), migrations, a System screen with an update check, a signed `/status.json`, a Sites screen with email alerts when a site is down or needs attention, a Deploy screen that builds a new site on any cPanel account, and `tools/selftest.php` for CI.
- Display options for visitors: text size and light, dark or high-contrast colors, remembered in their browser.

Optional, each with a switch in Settings, then Features:

- Site search (on by default): a search link in the header.
- Cheat sheets (on by default): reference sheets at `/resources/`, each downloadable as Word, large-print Word and a braille-ready file, with a practice quiz. Shortcode `[cheat-sheets]`.
- Testimonials (on by default): visitors send one with `[testimonial-form]`; you approve it; `[testimonials]` shows the approved ones.
- Email updates (on by default): sign-up with `[subscribe-form]`, double opt-in, updates sent in the background, an archive at `/updates/archive/`.
- Forms (on by default): the owner builds forms in the control panel; each gets a shortcode `[form name]`; answers are kept under Submissions, emailed, and downloadable as CSV.
- Events (off): an events page at `/events/`, one page per event with an "Add to my calendar" file, a calendar feed at `/events/feed.ics`, shortcodes `[upcoming-events]` and `[events-list]`.
- Payments (off): card payments on a Stripe-hosted page; products under Products, each with a shortcode `[pay address]`, orders under Orders. Needs a Stripe account.
- Videos (off): a videos page at `/videos/` with transcripts.
- Success stories (off): stories at `/success-stories/` with a consent check.
- Ask a question, Claude (off): `[ask-assistant]` answers visitors' questions from the site's own content. Needs an Anthropic API key and Composer.
- Microsoft Bookings next opening (off): `[next-opening]` shows the next free slot in a Bookings calendar.
- Appointments (off): visitors book a time at `/book/`. Openings come from your hours and your own calendar, a cPanel-hosted (CalDAV) calendar, a Microsoft 365 or Outlook.com calendar, or a Google Calendar, and every booking is written into it. Confirmation, cancel link and a reminder the day before. Shortcode `[book]`. See Appointments below.
- Second language pages (off): a second side of the site in any of fifteen languages, Spanish at `/es/` for example, with its own menu, footer, blog, search, events list and a language switch, plus a Translations screen for the site's own words. See Languages below.

Turning a feature off hides its pages, screens and shortcodes. Nothing is deleted.

## Requirements

- PHP 8.1 or later with the `curl`, `fileinfo`, `mbstring`, `json`, `dom`, `xmlreader` and `zip` extensions (all standard on cPanel).
- Apache or LiteSpeed with `mod_rewrite` and `.htaccess` files allowed. A cPanel-style account whose web folder is `public_html` fits the tools best.
- SSH access with git for creating sites, updating them and cron jobs. Web-only setup is possible too (see below), but updates arrive through git.
- Composer, only if you turn on the Ask a question assistant or want alt-text suggestions: run `composer install --no-dev` in the site folder (it installs the Anthropic PHP SDK into `vendor/`).

## Try it on your computer

```bash
php -S localhost:8000 router.php
```

Open http://localhost:8000/admin/. Because the request comes from the same computer, the first-run screen appears without a set-up code. Fill in the site name, address, optional contact email, an optional second language, your name, your sign-in email address and a password of at least 12 characters, then choose "Create my site". You are signed in as the owner and taken to Settings.

## Create a new site from the kit

Updates arrive through git, so every site must be a clone of the kit repository, not a copy of its files:

```bash
cd ~
git clone <kit repository address> public_html      # or clone into a new folder and point the domain at it
cd public_html
php tools/setup.php                                  # or SETUP_* variables and --yes, see below
composer install --no-dev                            # only if the assistant or alt-text help will be on
```

Everything that belongs to one site is ignored by git and so survives every update: `content/*` (pages, posts, settings, revisions), `data/*` and `uploads/*`. Two files the owner edits in the control panel, `content/layout.html` and `assets/css/site.css`, are tracked by the kit; `tools/update.php` sets the site's versions aside for the pull and puts them back afterwards. The `site/` folder is tracked by git but ships empty; a site's own PHP files there are untracked, so a pull leaves them alone too. Never put a site's own code or settings in `lib/`, `admin/` or `index.php`: a fast-forward pull refuses to run over local edits.

If a site was installed by copying files, turn it into a clone without losing anything: `git init`, `git remote add origin <kit address>`, `git fetch origin`, `git reset origin/main` (the files already match, so this only writes the index), then `git checkout -- .` to be sure. The ignored folders are untouched.

### On the server, over SSH

1. In the website folder, run `php tools/setup.php`.
2. Answer the questions: site name, legal name, tagline, site address, contact email, phone, time zone, color set, the language the site is written in, Spanish pages yes or no (or a different second language's code), then the owner account: your name, your sign-in email address and a password of at least 12 characters, typed twice.
3. Sign in at `https://your-domain/admin/` with that email address and password.

Every answer can be given as an environment variable instead, and `php tools/setup.php --yes` accepts the defaults for anything not given, so the whole thing can run without a keyboard:

- `SETUP_SITE_NAME` (default "My website")
- `SETUP_LEGAL_NAME` (default: the site name)
- `SETUP_TAGLINE`
- `SETUP_SITE_URL` (default `https://example.com`; nothing after the domain)
- `SETUP_EMAIL` and `SETUP_PHONE`
- `SETUP_TIMEZONE` (default `America/New_York`)
- `SETUP_PALETTE`: `purple`, `blue`, `green`, `teal`, `rust` or `charcoal`
- `SETUP_PRIMARY_LANGUAGE` (default `en`; any code from the Languages section)
- `SETUP_SECOND_LANGUAGE` (a code such as `es` or `fr`; empty for none). `SETUP_SPANISH=yes` is the older shorthand for `es`.
- `SETUP_OWNER_NAME` (default "Site owner")
- `SETUP_OWNER_EMAIL` (default: `SETUP_EMAIL`)
- `SETUP_PASSWORD` (12 characters or more)

Example:

```bash
SETUP_SITE_NAME="Example Co" SETUP_SITE_URL="https://example.com" SETUP_EMAIL="hello@example.com" SETUP_OWNER_NAME="Pat Example" SETUP_PASSWORD="a long sentence here" php tools/setup.php --yes
```

The script refuses to run on a site that already has an owner account and pages.

### Over the web

1. Put the files in place (a git clone is still best).
2. Create a file `data/setup-code.txt` containing any code of 12 or more characters. This stops a stranger who finds `/admin/` first from claiming the site.
3. Open `https://your-domain/admin/`. The first-run form asks for the one-time code, site name, site address, public contact email (optional), a second language (a drop-down, "None, English only" by default), your name, your sign-in email address and the password twice.
4. Choose "Create my site". The code file is deleted and you are signed in as the owner.

Without the code file the screen explains both options and waits.

### What setup creates

- Pages: Home (a hero, a features list and a call to action, all editable as blocks), About, Contact us (with `[contact-form]`), Accessibility statement and Privacy, all published. With a second language chosen, the same five again, linked as translations: written in Spanish for `es`, and for any other language in English with a note at the top saying the page is waiting to be translated (nothing is machine translated).
- A first blog post, published now.
- The main menu: Home, About, Blog, Contact (and a second-language menu when one is chosen), and footer link groups with your contact details.
- Settings: the features at their defaults and contact page addresses; `data/config.php` with "Send messages to" set to the contact email; `data/users.json` with the owner account.
- Empty folders `data/cache`, `data/stats` and `uploads/audio`.

Pages the shortcodes need but setup does not create, such as an "Updates" page with `[subscribe-form]`, are added later in Pages.

## Accounts, roles and passwords

Accounts live in `data/users.json` (mode 600): id, name, lowercase unique email, password hash, role, two-factor secret, hashed recovery codes, created, last sign-in, and a `sessions_valid_from` time that ends old sessions after a password change. Roles are `owner`, `editor` and `author`. `admin/index.php` maps each screen to its least role in the `ACTION_ROLES` array near the top and `require_role()` enforces it: Settings, Site template, the backup download, People and Activity are owner-only, as are System, Sites and Backups; screens registered by features default to editor; authors get blog posts (always saved as drafts), images and My account.

**Existing sites with a shared site password.** A site that only has `password_hash` in `data/config.php` keeps working. The sign-in screen still takes the site password; the first person to use it is asked for a name, email and new password, becomes the owner, and the site password is retired. If the site had a Microsoft 365 allow-list (`admin_people` in `config.php`), those people become editor accounts the first time the code runs; they sign in with Microsoft 365 as before, or use "Forgot your password?" to set one.

**tools/set-password.php**, for when email isn't working or nobody can sign in:

```
php tools/set-password.php name@example.com          # reset that person's password
php tools/set-password.php                           # no owner yet: creates the owner account
php tools/set-password.php new@example.com --owner   # add another owner from the command line
```

Resetting a password signs that person out everywhere and clears the sign-in lock.

**Password links.** Invitations, resets from the People screen and "Forgot your password?" email a link through `send_mail_to()`, so Email sending must be filled in. Tokens are random, stored hashed in `data/password-tokens.json`, valid for one hour and used once. "Forgot your password?" always answers the same way, is limited to 5 requests an hour per address and 3 per email, and tells people to ask the host to run `tools/set-password.php` when email isn't set up. When an owner invites or resets someone and mail can't be sent, the People screen shows the link to pass on by hand.

**Two-factor sign-in** is TOTP (RFC 6238: 30-second steps, six digits, SHA-1, one step of drift), implemented in `lib/features/accounts.php` without packages. The set-up screen shows the secret in groups of four and the `otpauth://` address as text, so any authenticator app can take it without a camera; no QR image is produced. Ten recovery codes are stored as SHA-256 hashes and each works once. Owners can turn two-factor off for a locked-out person on the People screen.

**Sessions** store the account id and end at the next click when the account is removed, its password is changed or an owner sends a set-password link, when Microsoft 365 sign-in is turned off for someone who used it, and after 8 hours idle. Microsoft 365 sign-in matches an account by email and gives that account's role; Settings, then Microsoft 365 sign-in holds only the on/off switch.

**Activity log.** `data/activity.jsonl`, one JSON object per line, trimmed to its last 5,000 lines; the Activity screen shows 200. Saves and deletes of every item type are recorded through item hooks; sign-ins, failed sign-ins, settings saved, people changes, two-factor changes, backup downloads, restores and imports are recorded directly.

## Settings walk-through

Settings has an "On this screen" navigation at the top, built from the level 2 headings that are actually on the screen, so a section a feature adds or removes shows up in the list on its own.

**Site details.** Site name, tagline, site description, site address (used for every absolute link and for the Microsoft sign-in redirect), mailing address for email updates (required by CAN-SPAM), and blog posts per page. Under "Contact details": full or legal name, public contact email, public phone number and the contact page address (plus the second language's when one is on). Under "Languages": the language the site is written in and the second language (see Languages below). Under "Look and time": the time zone, the color set (Purple, Blue, Green, Teal, Rust red, Charcoal, each checked for WCAG AA contrast) and the logo (an image from Images and files, or none).

**Features.** One tick box per optional feature, described above, including Forms, Events and Payments. "Second language pages (Spanish)" names whichever language is chosen and is the same switch as the Second language drop-down.

**Email sending.** "Send email through": PHP mail, an email account's SMTP server, or Microsoft 365 (see Email below), plus the From address and name (PHP mail), the SMTP server fields, the Microsoft 365 app details, "Send messages to" (one or more addresses for contact messages, form answers, orders and reports) and the contact email subject. "Save and send a test email" sends to the "Send messages to" addresses. The dashboard shows a warning until this section is complete.

**Ask a question assistant.** An Anthropic API key and an on/off tick box. The same key powers alt-text suggestions.

**Next opening on Book Services.** The Bookings page address, minimum notice in hours and session length. "Save and check now" tests it.

**Microsoft 365 sign-in.** An on/off tick box; who may sign in is decided by the People screen.

**Payments** (when the feature is on). Stripe secret key, currency, thank-you message, and the webhook address to add in Stripe with its signing secret.

**Your password and people.** Links to My account, People and Activity.

## Email

Everything that sends mail (contact form, form answers, password links, update confirmations and updates, order notices, the link report, test emails) goes through one of three methods chosen in Settings, then Email sending. `mail_method` in `data/config.php` is `php`, `smtp` or `graph`.

### PHP mail (default)

Uses the server's own mail program. Enter a From address on your own domain. On cPanel, first create that mailbox under Email Accounts; many hosts refuse to send, or other mail servers distrust the message, when the From address is not a real mailbox on the account.

### SMTP (an ordinary email account)

A small SMTP client in `lib/mail.php` (stream sockets, no package): EHLO, STARTTLS, AUTH PLAIN or LOGIN, with a 20-second timeout and an error that names the step that failed, so the owner sees "while signing in to the email account: 535 ..." in the Settings message. Fields: SMTP server name, Port (587 by default; 465 opens an implicit TLS connection, anything else starts plain and upgrades with STARTTLS), User name, Password (`smtp_password`, never echoed back; an empty field keeps the stored one) and From address (empty means the user name). Certificates are verified, and a password is never sent over a plain connection except to `localhost`.

Common settings:

- Gmail: `smtp.gmail.com`, port 587, the Gmail address, and an app password (Google Account, Security, turn on 2-Step Verification, then App passwords).
- Outlook.com (Hotmail, Live): `smtp-mail.outlook.com`, port 587, the address, and an app password from the Microsoft account's Security page.
- A cPanel mailbox: `mail.<domain>`, port 465 or 587, the mailbox password (cPanel, Email Accounts, Connect Devices).
- Microsoft 365 work and school accounts usually have SMTP AUTH turned off by the organization; use the Microsoft 365 method for those.

### Microsoft 365 (Microsoft Graph)

Mail is sent from a Microsoft 365 mailbox through an app registration. The same registration is reused for Bookings and for Microsoft 365 sign-in.

1. Go to https://entra.microsoft.com and sign in as an administrator.
2. Go to Identity, Applications, App registrations, and choose New registration. Name it after the website, leave the other options as they are, and choose Register.
3. On the app's Overview page, copy the Application (client) ID and the Directory (tenant) ID.
4. Go to API permissions, choose Add a permission, Microsoft Graph, Application permissions, find Mail.Send, tick it and choose Add permissions. Then choose "Grant admin consent for" your organization and confirm.
5. Go to Certificates and secrets, choose New client secret, give it a description and an expiry, and choose Add. Copy the secret's Value straight away; it is only shown once.
6. In the control panel, go to Settings, then Email sending. Choose "Microsoft 365 (Microsoft Graph)", paste the tenant ID, client ID and secret value, enter the mailbox to send from and the "Send messages to" addresses, and choose "Save and send a test email".

Mail.Send on its own lets the app send as any mailbox in the organization. To limit it to one mailbox, use an Exchange Online application access policy: https://learn.microsoft.com/graph/auth-limit-mailbox-access. When the secret expires, make a new one and paste it in. Saving the email settings clears the cached token in `data/graph-token.php`.

### Bookings next opening

On the same app registration, add the Microsoft Graph application permission Bookings.Read.All and grant admin consent. Turn the feature on under Features, fill in Settings, then Next opening on Book Services, and put `[next-opening]` on a page. It checks the calendar at most every 30 minutes.

### Microsoft 365 sign-in for the control panel

On the same app registration, under Authentication, add a Web redirect URI of `https://your-domain/admin/oauth.php` (the exact address is shown in Settings). The sign-in uses the `openid`, `profile` and `email` scopes with PKCE and needs no admin consent, but the tenant ID must be the GUID form. Tick "Allow Microsoft 365 sign-in" in Settings. A person can use it when their Microsoft 365 address matches the email of their account on the People screen; they get that account's role. Email and password sign-in keeps working.

## Appointments

Turn on **Appointments** under Settings, then Features, and open the Appointments screen. It has four parts:

- **Services**: what can be booked, each with a name, a length in minutes, an optional price, where it happens and a short description. Each service has its own page under `/book/`; `/book/` lists them (or goes straight to the only one). Put `[book]` on any page to list them there.
- **Hours and wording**: the hours you take appointments for each weekday (two ranges a day, so a lunch break works), how far apart start times are offered, the minutes kept free before and after each appointment (a service can set its own), the shortest notice, how far ahead people can book, whether to ask for a phone number, the label of the notes box, and the text shown after booking. Times are in the site's time zone from Settings.
- **Calendar** (owner only): the one calendar that says when you are busy. Any busy time there is never offered, and every booking is written into it as an event with the visitor's details. A visitor's booking is checked against the calendar again at the moment they confirm, so two people cannot take the same time.
- **Bookings**: who booked what and when, with a cancel link. Cancelling removes the event from the calendar and emails the visitor.

The visitor picks a day (only days with openings are listed), then a time from a list of radio buttons, fills in their name and email, and gets a confirmation page and email with an "Add to my calendar" file, a "Change the time" link and a cancel link. The owner is emailed too. A reminder goes to the visitor about a day before (the hourly cron job sends it), and a text message reminder as well if texts are set up under Settings, Text messages (Twilio) and the visitor ticked the box when booking. Bookings are kept as one JSON file each in `data/appointments/`.

Changing the time (1.6.0): the "Change the time" link (`/book/move/<id>/?t=`) shows the same days and times, with the visitor's own booking counted as free. The old booking is released only once the new time is confirmed: the new calendar event is made first, then the old one is deleted, the old record is marked moved, and the visitor and the owner each get one "moved" email. `tools/calendar-roundtrip.php` makes, reads back, busy-checks and deletes one event in the connected calendar, whichever kind it is; its header says what to set up for Google first.

### A cPanel-hosted calendar, or any CalDAV calendar

cPanel gives every email account a calendar (Calendars and Contacts in webmail). On the Calendar tab choose CalDAV, enter the server address as your domain with port 2080, for example `https://example.com:2080`, the email account as the user name and its password, save, then press **Find calendars** and pick the calendar. The same works for iCloud (`https://caldav.icloud.com` with an app-specific password), Fastmail (`https://caldav.fastmail.com`) and Nextcloud. Repeating events are expanded here for the usual rules (daily, weekly on given days, monthly, yearly, with end dates, counts and skipped dates); events marked as free, and cancelled events, do not block time. An all-day event blocks the whole day unless it is marked as free.

### A Microsoft 365 or Outlook.com calendar

This uses the same Microsoft app registration as email sending (Settings, Email sending), but as *you*, not as the app, so it also works for a personal Outlook.com calendar. On the app registration:

1. Under Authentication, add a Web redirect URI of `https://your-domain/admin/calendar-oauth.php`.
2. Under API permissions, add the **delegated** permission `Calendars.ReadWrite` (Microsoft Graph). Admin consent is not needed for your own calendar.
3. For a personal account, the app registration's supported account types must include personal Microsoft accounts.

Then on the Calendar tab choose Microsoft, pick the account kind, save, and press **Connect a Microsoft calendar**. You sign in once; the site keeps a refresh token in `data/config.php` and renews it itself. If the account has several calendars, pick one after connecting. By default the visitor is added to the event as a guest, so Outlook sends them a calendar invitation as well as the site's own email; turn that off under Hours and wording.

### A Google Calendar

Google calendars are reached through a *service account*, so there is no sign-in screen and nothing to renew:

1. In [Google Cloud Console](https://console.cloud.google.com/) make a project (or use any), turn on the **Google Calendar API**, and under IAM and admin, Service accounts, make a service account. Under its Keys, add a key of type JSON and download the file.
2. In Google Calendar, open the calendar's settings, **Share with specific people**, and add the service account's email address (it ends in `.iam.gserviceaccount.com`) with **Make changes to events**.
3. On the Calendar tab choose Google, paste the whole key file into the key box, enter the calendar ID (your Gmail address, or the ID shown under Integrate calendar) and save.

A service account cannot invite guests, so the visitor's calendar entry comes from the site's email and its "Add to my calendar" file.

**Test the calendar connection** on the Calendar tab tries the connection and reports what it found. **Forget the saved credentials and tokens** removes everything the site holds for the calendar.

## Payments (Stripe Checkout)

`lib/features/payments.php` and `admin/payments.php`, off by default. No card data touches the site: every payment happens on a Stripe-hosted Checkout page.

1. Create a Stripe account and copy the secret key (Developers, API keys). Start with the test key (`sk_test_`).
2. Turn on Payments under Features, then paste the key in Settings, Payments, with the currency (`usd` by default) and the thank-you message. Keys are stored in `data/config.php` as `stripe_secret_key` and `stripe_webhook_secret`, never echoed; an empty field keeps the saved value.
3. Add the webhook in Stripe (Developers, Webhooks): the address is shown in Settings and ends in `/pay/webhook/`; choose the single event `checkout.session.completed`; paste the signing secret (`whsec_`) into Settings. Test and live keys each need their own webhook. Without it, orders are still recorded when the customer returns to the thank-you page; the webhook catches the ones who close the tab first.
4. Test with card 4242 4242 4242 4242, then swap in the live key and the live webhook secret.

Products are items in `content/products/<slug>.json`; each registers the shortcode `[pay <slug>]` and a standalone page at `/pay/<slug>/`. Checkout sessions are created with curl against the Stripe API (rate limit 20 attempts an hour per address). Orders are `data/orders/<session id>.json`, recorded once whichever path gets there first, and listed on the Orders screen with monthly totals and a CSV download. Owner emails go through `send_mail_owner()`, so Email sending must work for them to arrive; orders are saved either way. Refunds are done in the Stripe dashboard.

## Redirects and the 404 log

Always on (`lib/features/redirects.php`, `admin/redirects.php`). `data/redirects.json` holds the list; a request path is matched against `from` case-insensitively, with or without a trailing slash, in a registered route and again from `not_found()`, so addresses the core handles itself (old blog posts, cheat sheets) are redirected too. `to` must be a path on this site or an `https://` URL; 301 or 302. The home page and anything under `/admin` cannot be a `from`.

`log_404()` counts hits per path per day in `data/404s.json`, keeps 90 days and at most 500 distinct paths, and skips paths with a file extension, bot user agents, paths over 150 characters and scanner noise (`wp-`, `xmlrpc`, `.env`, `.git` and so on). The Redirects screen shows the top 50 of the last 30 days with an "Add a redirect" link that prefills the address.

## Review links and scheduled pages

Review links (`lib/features/review-links.php`) live in `data/review-links.json` (mode 600) as `token => {type, slug, created_by, created, expires}`, 32 hex characters each, 7 days by default and 1 to 30 selectable. The public route `/review/<token>/` renders the real page in the real layout with a banner, `Cache-Control: no-store` and `X-Robots-Tag: noindex`. Expired, revoked or unknown tokens get a 410 page. Deleting the page or post removes its links; a daily cron job prunes old entries.

Pending edits (1.6.0): on a page's edit screen, **Save as a pending edit** keeps the change in `data/pending/pages/<slug>.json` instead of publishing it. A notice at the top of the page form then offers to edit, publish or discard it, and the Review links section can make a link that shows the pending edit (`pending => true` on the link), so a client can send the site owner a preview before anything goes live. Publishing goes through the ordinary page save, so it is checked and kept in the page's history.

Page history (1.6.0, `lib/features/page-history.php`): the page, post and cheat sheet edit screens end with a **Previous versions** list (the last 10 saves, who saved each and when) and a **Put this version back** button on each. Putting one back saves it as a new version, so the one it replaced joins the list and the step can be undone. The copies are the revisions the core already keeps under `content/revisions/`.

Scheduled pages (`lib/features/scheduled-pages.php`): a page may carry `publish_at` (site time zone). `page_is_live()` hides a published page whose time has not come from visitors, the sitemap and the Pages list status ("Scheduled"), while signed-in users and review links still see it.

## Nested pages

`lib/features/nested-pages.php`, with small hooks in `index.php`, `lib/i18n.php` (`page_path()`) and `admin/index.php`. A page's JSON gains an optional `"parent": "<slug>"`; the slug stays unique site-wide, so file names and `get_item('pages', $slug)` are unchanged, and two pages cannot both be called `pricing` even under different parents. A page's address is its parent's address plus its own segment: `/services/`, `/services/reiki/`, `/services/reiki/pricing/`. `PAGE_MAX_LEVEL` caps the tree at three levels; the Parent page list leaves out any page that would take a family past that, and a parent that is missing, is the page itself, is in the other language, is `home` or the second-language home, or forms a loop is ignored. Second-language pages nest only inside second-language pages and keep their `/es/` prefix.

Routing: registered feature routes answer first, then `page_by_path()` is tried for any address (and inside the second-language block); if nothing is found but `page_at_wrong_path()` names a page by its last segment (`/pricing/`, or an old address after a move), the visitor gets a 301 to `page_path()` of that page. One-segment addresses then fall through to `show_page()` as before, so unknown addresses still 404. `breadcrumbs_html()` prints `<nav aria-label="You are here"><ol class="breadcrumbs">` above the heading and adds BreadcrumbList structured data. Renaming or moving a page carries its children along and `menu_follow_moves()` updates menu links; deleting one asks whether the children move up a level or go too. Always build page addresses with `page_path($slug)`, never `"/$slug/"`. Styles: `.breadcrumbs` in `assets/css/site.css`, `.tree-level-2` and `.tree-level-3` in `admin/admin.css`.

## Languages

A site is written in one primary language (`primary_language` in `content/settings.json`, default `en`) and can have one second language (`second_language`, a code or empty). Everything is in `lib/i18n.php`; nothing else in the kit needs to know which languages a site uses. The codes a site can choose, with the names visitors see, are in `SUPPORTED_LANGUAGES`: `en` English, `es` Español, `fr` Français, `de` Deutsch, `it` Italiano, `pt` Português, `nl` Nederlands, `pl` Polski, `ar` العربية, `zh` 中文, `ja` 日本語, `ko` 한국어, `hi` हिन्दी, `vi` Tiếng Việt, `tl` Tagalog. Every page gets `lang` from its language; languages in `RTL_LANGUAGES` (Arabic) get `dir="rtl"` on `<html>` through `html_dir()`.

Choosing a second language in Settings (or on the first run, or with `SETUP_SECOND_LANGUAGE`) turns its pages on; choosing None turns them off but keeps the pages and the code. It is the same switch as "Second language pages" under Features, stored as `features.spanish` (a historical name). Sites from before the choice existed have `features.spanish` on and no `second_language`: they stay Spanish (`LEGACY_SECOND_LANGUAGE`) and nothing needs migrating. The settings keys `menu_es`, `footer_html_es`, `footer_groups_es` and `contact_page_es` hold the second language's menu, footers and contact page whatever its code.

**Addresses and file names.** The second language's code is the prefix everywhere Spanish used `es`: the home page is the file `<code>` at `/<code>/`; a page `<code>-contact` is at `/<code>/contact/`; a post `<code>-bonjour` is at `/<code>/blog/bonjour/`; the blog list and feed are `/<code>/blog/` and `/<code>/blog/feed.xml`. The words in the built-in addresses for search, the cheat sheets list, events and the contact page come from the language's strings `route_search`, `route_resources`, `route_events` and `route_contact` (`buscar`, `guias-rapidas`, `eventos`, `contacto` in Spanish); a language without them uses the English words, so French search is at `/fr/search/` until someone translates `route_search`. The primary language's addresses are always the English words. Cheat sheets themselves stay at `/resources/<slug>/`.

**The site's own words** (labels, buttons, headings, messages, not page content) come from two layers: `lib/languages/<code>.php` returns the array for a language (`en.php` is complete and is the fallback for every other language; `es.php` is complete too; other languages have no file yet, so visitors see English until a translation exists; to add one to the kit, copy `en.php` to `<code>.php` and translate the values), and `content/languages/<code>.json` holds one site's own words for a language on top of the shipped file. The Translations screen (owner, under Checks and tools) edits that JSON: one row per English key, a filter box, one Save for all rows; it covers every language in use other than English. In code: `t('key')` in the current language with English then the key itself as fallbacks, `t_in('es', 'key')` for a given language (emails, documents), `strings('es')` for the whole array; `date_long` with `month_1` to `month_12` orders dates. For feature code: never compare with `'es'` or write `/es/`; use `is_second_lang()`, `second_lang()`, `home_path()`, `lang_route_path()`, and `language_english_name()` or `second_lang_name()` to name the language in the control panel. `create_starter_content(string $second = '')` writes Spanish starter pages for `es` and English pages with a "waiting to be translated" note for any other code.

## Importers

The Import screen (owner, under Checks and tools; `admin/importers.php`, with everything it does in `lib/features/importers.php`) brings an old website in as drafts, three ways: a WordPress export file (WXR, read with `XMLReader` one item at a time: pages with their parent, posts with date, categories and excerpt, attachments and any media the content refers to, downloaded from the old site; trashed items, revisions, menus, custom post types, tags, featured images and the logo are left out and shortcodes are left as text), a zip of HTML files (a Google Sites Takeout or any folder: `<title>` or first `<h1>`, the `<main>`/`<article>`/`[role=main]` or the wrapper with most words, `folder/index.html` becoming the folder's page and its siblings' parent, the root `index.html` saved as the draft `imported-home`), or a live address (same-host links followed breadth first). Every import is a preview first and the real thing on confirm; nothing goes live, an address already in use is skipped rather than overwritten, and the report lists created, skipped and notes with an Edit link per draft and an "Add redirects for N old addresses" button that writes 301s through the Redirects feature. Each item carries `imported_from`.

Limits, from the constants at the top of `lib/features/importers.php`: uploads 64 MB (`IMPORT_UPLOAD_MAX`, further limited by PHP's `upload_max_filesize` and `post_max_size`, which `.user.ini` sets to 64 MB; the screen shows the working limit); media 15 MB per file (`IMPORT_MEDIA_MAX`; JPEG, PNG, GIF, WebP, PDF, DOCX, MP3, M4A, bytes checked against the extension) fetched with curl in 20 seconds each (`IMPORT_FETCH_TIMEOUT`); crawls stop at 60 pages (`IMPORT_CRAWL_MAX`) with a half-second pause between fetches. Both the preview and the import run in the foreground under `set_time_limit(600)`; a host that cuts execution at the web server (FastCGI timeouts) may stop a very large import short, in which case the report says so and lists what was created up to then. Media lands under `uploads/import/<YYYY>/<MM>/`; work in progress waits in `data/imports/work/<token>/` (cleaned after a day, `IMPORT_WORK_KEEP`); every finished import is logged to `data/imports/<date>_<time>-<id>.json` and the screen shows the last five. `curl` and the `xmlreader`, `zip` and `dom` extensions are needed. The HTML cleaner `import_clean_html()` is standalone and has tests: `php lib/features/importers.php --test`.

## Alt text and the accessibility check

**Alt text** (`lib/features/alt-text.php`, `admin/alt-text.js`) needs the same two things as the assistant: `anthropic_api_key` in `data/config.php` (set in Settings) and the SDK in `vendor/`; the assistant's Features switch is not needed. Without them the buttons do not appear and Images and files says why. The endpoint `/admin/?a=alt_text` accepts only files under `uploads/` (JPEG, PNG, GIF, WebP, up to 5 MB) and sends the image to `ASSISTANT_MODEL`. Descriptions requested from Images and files are kept in `data/alt-text.json`; calls are counted per day in `data/alt-text-usage.json` and refused after 200.

**Accessibility check** (`lib/features/a11y-check.php`): a `before_save` item hook for pages, posts, cheat sheets and stories parses the body with DOMDocument and stores plain-language warnings in the item as `a11y`; an `after_save` hook appends them to the "Saved" message, and the edit forms show them above the fields. The Dashboard card and the Accessibility screen check every item live. No settings, no cron, no public output.

## Cron jobs

In cPanel, Cron Jobs, add these three lines, replacing `YOURUSER` with the account name. `/usr/local/bin/php` is the usual cPanel path; `which php` tells you yours.

```
*/10 * * * * /usr/local/bin/php /home/YOURUSER/public_html/tools/send-updates.php
15 * * * * /usr/local/bin/php /home/YOURUSER/public_html/tools/check-links.php
30 3 * * * /bin/sh /home/YOURUSER/public_html/tools/backup.sh
```

- `send-updates.php` every 10 minutes: sends queued email updates 40 at a time and removes unconfirmed sign-ups after 7 days.
- `check-links.php` hourly: once a day after 6 am it checks the SSL certificate and the error log and runs the features' daily jobs (the kit update check, review-link pruning); once a month, or soon after "Check all links now" is pressed, it checks every link on the site and emails the "Send messages to" addresses if anything is broken.
- `backup.sh` nightly: saves `content`, `data`, `uploads` and the stylesheet as `site-content-YYYY-MM-DD.tgz` plus a `.json` manifest (kit version, site name, size, counts), keeping the newest 30. It works out the site folder from its own location. It writes to the first of `$BACKUP_DIR` in the environment, `backup_dir` in `data/config.php`, or `~/site-backups/content`; the Backups screen reads the same folder through the same rule, so set `'backup_dir' => '/path'` in `data/config.php` when the default does not suit a host.

## Backups, restore, export and import

The **Backups** screen (owner) lists the nightly archives with Download, "Restore one item" (a page, post or other item from the archive; the current one is kept as a revision) and "Restore everything" (content, uploads and data except `config.php` and `users.json`; two steps, the archive date typed back; a `before-restore-<time>.zip` of the current site goes into the backup folder first). **Export this site** makes a zip of `content/`, `uploads/`, `data/` minus secrets and caches, `assets/css/site.css`, and `manifest.json`; **Import a site** loads that zip into an empty site directly, or over a site with content after its name is typed; the site keeps its own `site_url`. Export uploads go through PHP's upload limit (`.user.ini` sets 64 MB); bigger sites move over SSH.

`sh tools/restore.sh <archive.tgz>` is the command-line twin of "Restore everything": it checks the archive only holds the four site folders, saves `before-restore-<time>.tgz` beside the archive, and copies the archive over the live folders, skipping `data/config.php` and the people list.

The dashboard's "Download a backup now" and the export leave out `config.php`, `users.json`, the password-token files, the Graph token, stats salts, login attempts and caches. The nightly archive leaves out `config.php` and the same transient files.

## Versions and updating a site

The kit's version is the one line in `VERSION`; `CHANGELOG.md` has an entry per release, newest first; `cms_version()` in `lib/version.php` reads it, and the control panel footer shows it on every screen.

```bash
php tools/update.php           # shows the installed version, fetches, and lists what is new on origin/main
php tools/update.php --apply   # git pull --ff-only, composer install --no-dev if vendor/ exists, new migrations, changelog
```

`--apply` refuses to run when the kit's own files have local changes and when the branch has diverged. Migrations are `tools/migrations/NNN-name.php`: each runs once with the kit loaded, is recorded in `data/migrations.json`, and must be safe to run twice. After updating, the `CHANGELOG.md` entries newer than the version that was installed are printed. To take updates from a different branch, set `'update_branch' => 'origin/stable'` in `data/config.php`.

The daily cron job does `git fetch` and compares, storing the answer in `data/update-status.json`. The **System** screen (owner) shows the version, PHP version, disk use, git branch, PHP errors logged today, where the backups are, and "Up to date", "Version X.Y.Z is available: ask your host to update" or "Not a git checkout", with a "Check now" button; the Dashboard gets a card when an update is waiting. The check needs git on the server, `proc_open` enabled, and a remote that works without a password (a public https address, or a deploy key for a private repository). It never prompts.

## Deploying a new site from the Deploy screen

For the person who hosts several sites. Turn on **Deploy new sites** under Settings, Features, and a **Deploy** screen (owner only) appears under Checks and tools. Give it the cPanel account that will hold the new site and the site's details, and it does the set-up from here:

1. Signs in with the cPanel API (`https://host:2083`), finds the account's home folder and the web folder that serves the domain (the main domain, or one added under Domains), and reads the PHP version the domain runs; if it is older than 8.1, the newest installed 8.x is chosen in MultiPHP Manager.
2. Puts this site's own SSH key on the account: with the cPanel password over SSH (through `sshpass`), or with an API token through the cPanel API's SSH functions. Everything after this uses the key. The password or token is used in that one request and never written anywhere.
3. Over SSH: the command-line PHP (8.1 or newer, preferring `/opt/cpanel/ea-php8x`), git, and whether the web folder is empty apart from cPanel's own `cgi-bin` and `.well-known`. An old website in the folder stops the deployment unless "Move anything already in the web folder aside" is ticked, which moves it to `~/before-fullcircle-<date>`.
4. Turns the web folder into a clone of the kit repository (`git init`, `fetch`, `checkout`), so `tools/update.php` works later. The address comes from the form, with `kit_repo` in `data/config.php` as the default.
5. Runs `tools/setup.php --yes` with the details given. The owner account gets a random password nobody sees.
6. Adds the three cron lines, writing their output to `~/site-backups/*.log`.
7. Makes a fleet key on the new site and lists it on this site's Sites screen; makes a one-hour set-password link for the owner, shown on the screen and emailed from this site if asked. "Send the owner a new link by email" makes another any time.
8. Opens the new site's home page and sign-in screen (a missing certificate is reported, not treated as a failure: AutoSSL follows within an hour).

Each deployment is a record in `data/deploys/<id>.json` with every step's lines; a deployment that stops can be carried on from that step after the cause is fixed. The key pair is `data/deploy-key` (mode 600). This server needs `ssh`, `ssh-keygen`, `git` and, for the password route, `sshpass`; the account on the other end needs shell access (jailed is fine) and git. The screen does not create cPanel accounts; make the account or the domain in cPanel or WHM first.

## Fleet status: /status.json and the Sites screen

Each site can answer `/status.json` with its version, site name, PHP version, last backup time, unsent contact messages, link-check problems, days left on its SSL certificate, whether an update is pending, and a timestamp. The address exists only when `fleet_secret` is set in `data/config.php` (made on the **Sites** screen with "Make a key for this site"), and only for a signed request:

```
/status.json?t=<unix time>&sig=<hex hmac-sha256 of t, keyed with fleet_secret>
```

`t` must be within 5 minutes of the server's clock; anything else is a plain 404.

The Sites screen (owner) lists the other sites under `fleet_sites` in `data/config.php` (name, url, key). "Check all" fetches every status with curl and a 10-second timeout, saves the answers in `data/fleet-status.json`, and shows a plain-words summary per site. Things it flags: unsent messages, link problems, a certificate with under 14 days left, no backup or one older than two days, and a pending kit update. The Dashboard shows "3 of 4 sites are fine". A site cannot check itself through the `php -S` preview server unless it was started with `PHP_CLI_SERVER_WORKERS=2` or more.

## Alerts by email

The site that lists the others on its Sites screen also watches them. Every 10 minutes (the email updates cron job) it asks each listed site, and itself, for its status, and emails the owner (the contact recipients under Settings) when:

- a site has not answered for two checks in a row, about 20 minutes, and again when it is back;
- a site's SSL certificate has under 14 days left;
- a site has no backup from the last two nights;
- a site has contact messages it could not email.

Down and back are always sent; the other concerns at most once a day per site, and several found in one check go in one email. The switch and a "Send me a test alert" button are on the Sites screen under Alerts by email. State is kept in `data/fleet-alerts.json`.

## Updating with a safety net

`php tools/update.php --apply --verify` pulls the update, runs the migrations, then runs `tools/selftest.php` on the updated site. If the checks fail, the code is put back exactly as it was (`git reset --hard` to the previous commit, with the site's own template and stylesheet kept) and the command exits with code 2, so a pipeline can tell the difference between "nothing to do", "updated" and "rolled back". Finished migrations are not undone; every migration is written to be safe to run twice. The first update from a kit older than 1.5.0 runs without the check, because it is the old update tool that runs.

## Deploying code changes by rsync

For a site that is not a git clone, or for pushing uncommitted work to a test server: copy `tools/deploy.conf.example` to `tools/deploy.conf`, fill in `SERVER`, `REMOTE`, `KEY` and `SITE_URL`, and run `sh tools/deploy.sh`. It checks every PHP file for syntax errors first, then uses rsync over SSH. Deploying never touches `content/`, `data/`, `uploads/` or `assets/css/site.css`; `uploads/.htaccess` is sent separately. `README.md`, `OWNER-GUIDE.md`, `router.php`, `.git` and `deploy.conf` are not uploaded. `sh tools/deploy.sh --pull` downloads the live `content/`, `uploads/` and stylesheet into your working copy. Prefer `git clone` and `tools/update.php` for live sites.

## Extending the kit

Features live in `lib/features/<name>.php` and a site's own additions in `site/<name>.php`; both register routes, shortcodes, control panel screens, settings sections, dashboard cards, feature switches, cron jobs and item hooks through `lib/registry.php` without editing the core. docs/EXTENDING.md has the registry, the rules every feature follows and how to add a Blocks editor block type.

## Subdomains in subfolders

cPanel often puts a subdomain's files inside `public_html` (for example `public_html/shop` for `shop.example.com`). Apache applies the parent folder's `.htaccess` to those requests too, so a request to the subdomain that is not a real file would be sent to the kit's `index.php` and shown as "Page not found". Gate the rules on the host name by adding a block like this just after the `.well-known` rule in `.htaccess`, and keep in mind the www and https redirects also run for every host unless you gate them too:

```
RewriteCond %{HTTP_HOST} !^(www\.)?example\.com$ [NC]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
RewriteCond %{HTTP_HOST} !^(www\.)?example\.com$ [NC]
RewriteRule ^ - [L]
```

## Folder map

- `index.php`: the public site; every address comes through here.
- `router.php`: only for `php -S` on your computer.
- `.htaccess`, `.user.ini`: Apache rules and PHP upload limits (64 MB).
- `VERSION`, `CHANGELOG.md`: the version and what changed.
- `admin/`: the control panel (`index.php`, `oauth.php`, `admin-lib.php`, `features.php`, `accounts.php`, `people.php`, `ops.php`, `payments.php`, `redirects.php`, `importers.php`, CSS and JS, `admin/vendor/tinymce/`).
- `lib/`: core (settings, items, revisions, registry, version), `i18n.php` and `lib/languages/<code>.php`, mail, Graph, contact, blog, resources, braille, docx, quiz, search, stats, subscribers, bookings, assistant, link check, shortcodes, starter pages; `lib/features/`: accounts, a11y-check, alt-text, events, footer, forms, importers, nested-pages, ops, payments, redirects, review-links, scheduled-pages, translations.
- `site/`: this one site's own PHP additions (empty in the kit).
- `tools/`: `setup.php`, `set-password.php`, `update.php`, `migrations/`, `deploy.sh`, `backup.sh`, `restore.sh`, `check-links.php`, `send-updates.php`.
- `assets/`: stylesheet, self-hosted font, site script. The stylesheet is edited in the control panel.
- `content/`: `layout.html`, `settings.json`, `pages/`, `posts/`, `resources/`, `videos/`, `stories/`, `testimonials/`, `forms/`, `events/`, `products/`, `update-archive/`, `revisions/`, `languages/` (a site's own translations).
- `data/`: `config.php` (secrets), `users.json`, `activity.jsonl`, `messages/`, `forms/`, `orders/`, `imports/`, `subscribers.json`, `outbox/`, `stats/`, `cache/`, redirects, 404s, review links, alt text, fleet and update status, migrations, link and health reports.
- `uploads/`: files from Images and files, in `YYYY/MM/` folders, plus `uploads/audio/` and `uploads/import/`.
- `vendor/`: Composer packages for the assistant and alt text.

## Security notes

- `.htaccess` returns 403 for `content`, `data`, `lib`, `tools`, `vendor`, `backups`, `composer.json` and `.lock`, any dot-file, `error_log`, `README.md`, `OWNER-GUIDE.md` and `router.php`. The private folders also carry their own `.htaccess` with "Require all denied", and `admin/.htaccess` hides the helper files.
- Uploaded files cannot run as code: `uploads/.htaccess` denies PHP, HTML, SVG and script extensions and removes the PHP handler. Uploads are checked by extension and detected type, images are verified, and file names are slugified.
- Secrets live in `data/config.php` (Graph secret, SMTP password, Anthropic key, Stripe keys, fleet keys, site secret), written with mode 600 and valid PHP so nothing readable comes back even if the rules fail. Password hashes, two-factor secrets and hashed recovery codes live in `data/users.json`, also mode 600.
- The web first run needs `data/setup-code.txt` unless the request comes from the server itself; the file is deleted on success.
- Sign-in is paused for 15 minutes after 5 wrong tries from one address; sessions end after 8 hours idle; every form carries a CSRF token; redirects only ever go to paths on the site or `https://` addresses the owner typed.
- Security headers: nosniff, referrer policy, frame options, HSTS when on https. Public forms use a time-limited signed token, a honeypot and a rate limit; the assistant and alt-text help are rate-limited; Stripe webhooks are signature-checked with a 5-minute tolerance; `/status.json` is HMAC-signed.

## Accessibility notes

Every public page has a skip link to `main`, landmark regions (header, navigation labelled "Main", main, footer), exactly one `h1`, `aria-current` on the current menu item (and on the section's item for pages nested inside it), a "You are here" breadcrumb navigation on nested pages, a language switch with `lang` and `hreflang` when a second language is on, `dir="rtl"` for right-to-left languages, visible focus styles, and no motion or autoplay. Form results move focus to a status message. The site uses a self-hosted font and no third-party scripts. The Display options button in the header opens a panel with Text size (Standard, Large, Extra large) and Colors (Match my device, Light, Dark, High contrast yellow on black), with Reset and Close buttons; Escape closes it and the choice is stored in `localStorage` only. Cheat sheets download as Word, 24-point Word and braille (uncontracted UEB, 40 by 25). The control panel has the same skip link and landmarks, visible labels on every field, hints and errors tied with `aria-describedby`, an error summary that moves focus and links to each field, status messages that take focus, and an "On this screen" navigation on long screens. The accessibility check warns the author after every save about missing alt text, heading problems, weak link text, tables without headers, untitled frames, all-capitals text and very long paragraphs.

## Checklist for a new client site

1. On the server, `git clone <kit repository address>` into the web folder (or a folder the domain points at). Never copy the files.
2. `php tools/setup.php` with the client's details and the owner's name, email and password (or `SETUP_*` variables and `--yes`).
3. If the assistant or alt-text help will be used: `composer install --no-dev`.
4. Add the three cron lines (email updates, hourly health and link check, nightly backup). If the default backup folder does not suit the host, set `backup_dir` in `data/config.php`.
5. Sign in at `/admin/` as the owner. Under Settings, Email sending, choose PHP mail, SMTP or Microsoft 365, fill it in and send a test email. Password links, form answers and order notices depend on it.
6. Under Settings, Features, turn on what the client needs (Events, Payments, Videos and so on), and under Site details, Languages, choose the second language if there is one. For a language other than Spanish, fill in the Translations screen.
7. If Payments is on: Stripe test key, currency, thank-you message, webhook, a test payment with 4242 4242 4242 4242, then the live key and live webhook.
8. If Microsoft 365 sign-in is wanted: the app registration's redirect URI, then tick the switch; the people's accounts must use their Microsoft 365 addresses.
9. For the assistant or alt text: paste the Anthropic API key under Ask a question assistant.
10. Invite the client's people on the People screen with the right roles, and walk the owner through My account and two-factor sign-in.
11. Fill in the Footer screen, the home page blocks, the About and Contact pages, and any pages the shortcodes need (Updates, Testimonials, forms, events, products). If the client has an old website, bring it in from the Import screen (WordPress export, zip of HTML, or the live address) and add the redirects the report offers.
12. Check the Dashboard for warnings, the Accessibility screen for warnings, and the Link check screen after the first run.
13. If you run several sites: on each site, "Make a key for this site" under Sites, and list the others on your own site's Sites screen. The Deploy screen on your own site does steps 1 to 5 and this one in a single form.
14. Write the host's name and contact details at the end of OWNER-GUIDE.md and hand it over.

Later, `php tools/update.php` on each site shows what a kit update brings and `--apply` installs it.
