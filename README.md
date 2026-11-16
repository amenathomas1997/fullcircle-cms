# Full Circle CMS

Full Circle CMS is a small website system written in plain PHP. There is no database: pages, blog posts and settings are JSON files in the `content` folder, and private data (the password, messages, email settings) lives in `data`. It runs on ordinary shared hosting (cPanel, Apache or LiteSpeed, PHP 8.1 or later) and comes with an accessible control panel at `/admin/`. Everything was built to work well with screen readers, both for visitors and for the person running the site. This README is for the person who installs and hosts a site. OWNER-GUIDE.md is for the person who writes on it day to day.

## What's in the box

Always on:

- Pages, written as HTML in the control panel, with earlier versions kept (25 per item) and a preview before saving.
- A blog with a visual editor (TinyMCE 7), drafts, scheduled posts, topics, reading time, related posts, an RSS feed, and an audio version per post with a podcast feed.
- A contact form (`[contact-form]`) that saves every message in the control panel and emails it to you.
- Images and files (JPEG, PNG, GIF, WebP, PDF, Word), a menu editor, a site template editor (layout, footer, stylesheet), private visitor counts with no cookies, a monthly link check with a daily health check, nightly backups and a one-click backup download.
- Display options for visitors: text size and light, dark or high-contrast colors, remembered in their browser.

Optional, each with a switch in Settings, then Features:

- Site search (on by default): a search link in the header.
- Cheat sheets (on by default): reference sheets at `/resources/`, each downloadable as Word, large-print Word and a braille-ready file, with a practice quiz. Shortcode `[cheat-sheets]`.
- Testimonials (on by default): visitors send one with `[testimonial-form]`; you approve it; `[testimonials]` shows the approved ones.
- Email updates (on by default): sign-up with `[subscribe-form]`, double opt-in, updates sent in the background, an archive at `/updates/archive/`.
- Videos (off): a videos page at `/videos/` with transcripts.
- Success stories (off): stories at `/success-stories/` with a consent check.
- Ask a question, Claude (off): `[ask-assistant]` answers visitors' questions from the site's own content. Needs an Anthropic API key and Composer.
- Microsoft Bookings next opening (off): `[next-opening]` shows the next free slot in a Bookings calendar.
- Spanish pages (off): a Spanish side of the site at `/es/`, with its own menu, footer, blog and a language switch.

Turning a feature off hides its pages, screens and shortcodes. Nothing is deleted.

## Requirements

- PHP 8.1 or later with the `curl`, `fileinfo`, `mbstring`, `json` and `zip` extensions (all standard on cPanel).
- Apache or LiteSpeed with `mod_rewrite` and `.htaccess` files allowed. A cPanel-style account whose web folder is `public_html` fits the tools best.
- SSH access for the command-line setup, deploying and cron jobs. Web-only setup is possible too (see below).
- Composer, only if you turn on the Ask a question assistant: run `composer install` in the site folder (it installs the Anthropic PHP SDK into `vendor/`).

## Try it on your computer

```bash
php -S localhost:8000 router.php
```

Open http://localhost:8000/admin/. Because the request comes from the same computer, the first-run screen appears without a set-up code. Fill in the site name, address, optional contact email, whether to add Spanish pages, and a password of at least 12 characters, then choose "Create my site". You are signed in and taken to Settings.

## Create a new site from the kit

Copy the kit to the server first (upload the files, or `git clone` into the web folder). Then set it up in one of two ways.

### On the server, over SSH

1. In the website folder, run `php tools/setup.php`.
2. Answer the questions: site name, legal name, tagline, site address, contact email, phone, time zone, color set, Spanish pages yes or no, and the control panel password (at least 12 characters, typed twice).
3. Sign in at `https://your-domain/admin/`.

Every answer can be given as an environment variable instead, and `php tools/setup.php --yes` accepts the defaults for anything not given, so the whole thing can run without a keyboard:

- `SETUP_SITE_NAME` (default "My website")
- `SETUP_LEGAL_NAME` (default: the site name)
- `SETUP_TAGLINE`
- `SETUP_SITE_URL` (default `https://example.com`; nothing after the domain)
- `SETUP_EMAIL` and `SETUP_PHONE`
- `SETUP_TIMEZONE` (default `America/New_York`)
- `SETUP_PALETTE`: `purple`, `blue`, `green`, `teal`, `rust` or `charcoal`
- `SETUP_SPANISH=yes`
- `SETUP_PASSWORD` (12 characters or more)

Example:

```bash
SETUP_SITE_NAME="Example Co" SETUP_SITE_URL="https://example.com" SETUP_EMAIL="hello@example.com" SETUP_PASSWORD="a long sentence here" php tools/setup.php --yes
```

The script refuses to run on a site that already has a password and pages. To change the password later, run `php tools/set-password.php`.

### Over the web

1. Upload the files.
2. Create a file `data/setup-code.txt` containing any code of 12 or more characters. This stops a stranger who finds `/admin/` first from claiming the site.
3. Open `https://your-domain/admin/`. The first-run form asks for the one-time code, site name, site address, public contact email (optional), Spanish pages (tick box), and the password twice.
4. Choose "Create my site". The code file is deleted and you are signed in.

Without the code file the screen explains both options and waits.

### What setup creates

- Pages: Home (with a welcome heading), About, Contact us (with `[contact-form]`), Accessibility statement and Privacy, all published. With Spanish turned on, the same five in Spanish, linked as translations.
- A first blog post, "Welcome to the new … website", published now.
- The main menu: Home, About, Blog, Contact (and a Spanish menu when Spanish is on).
- A footer with your contact details and links to About, Blog, Accessibility statement and Privacy.
- Settings: the features at their defaults, contact page addresses, and `data/config.php` with the password hash and "Send messages to" set to the contact email.
- Empty folders `data/cache`, `data/stats` and `uploads/audio`.

Pages the shortcodes need but setup does not create, such as an "Updates" page with `[subscribe-form]` or a "Testimonials" page with `[testimonial-form]`, are added later in Pages.

## Settings walk-through

Settings has an "On this screen" navigation at the top with one link per section.

**Site details.** Site name, tagline (shown in the browser tab on the home page), site description (used in search results when a page has none), site address (used for every absolute link and for the Microsoft sign-in redirect), mailing address for email updates (required by CAN-SPAM; not shown on the site), and blog posts per page (1 to 100). Under "Contact details": full or legal name (copyright line and emails), public contact email, public phone number and the contact page address (plus a Spanish one when Spanish is on). These are used wherever the site tells people how to reach you: error pages, email footers, the 404 page and the contact links in downloads. Under "Look and time": the time zone (post dates and all times shown), the color set, and the logo.

**Six color sets.** Purple (default), Blue, Green, Teal, Rust red and Charcoal. Each has light and dark values checked for WCAG AA contrast, and all of them give way to the yellow-on-black high-contrast mode.

**Logo.** A drop-down of images uploaded under Images and files, or "No logo". The logo appears in the header inside the home link (with empty alt text, so screen readers hear the site name once) and as the browser tab icon.

**Features.** One tick box per optional feature, described above.

**Email sending.** Choose PHP mail or Microsoft 365, the From address and name (PHP mail), "Send messages to" (one or more addresses for contact messages and reports), the contact email subject, and the Microsoft 365 app details. "Save and send a test email" sends to the "Send messages to" addresses. The dashboard shows a warning until this section is complete.

**Ask a question assistant.** An Anthropic API key and an on/off tick box. The assistant also needs the Features switch and `composer install`.

**Next opening on Book Services.** The Bookings page address (an email address), minimum notice in hours and session length. "Save and check now" tests it.

**Microsoft 365 sign-in.** An on/off tick box and the list of people (name and Microsoft 365 email) allowed to sign in.

**Change password.** Current password, new password twice. Anyone else signed in with the old password is signed out.

## Email

Everything that sends mail (contact form, update confirmations and updates, the link report, test emails) goes through one of two methods chosen in Settings, then Email sending.

### PHP mail (default)

Uses the server's own mail program. Enter a From address on your own domain. On cPanel, first create that mailbox under Email Accounts; many hosts refuse to send, or other mail servers distrust the message, when the From address is not a real mailbox on the account. The envelope sender is set to the same address.

### Microsoft 365 (Microsoft Graph)

Mail is sent from a Microsoft 365 mailbox through an app registration. The same registration is reused for Bookings and for Microsoft 365 sign-in.

1. Go to https://entra.microsoft.com and sign in as an administrator.
2. Go to Identity, Applications, App registrations, and choose New registration. Name it after the website, leave the other options as they are, and choose Register.
3. On the app's Overview page, copy the Application (client) ID and the Directory (tenant) ID.
4. Go to API permissions, choose Add a permission, Microsoft Graph, Application permissions, find Mail.Send, tick it and choose Add permissions. Then choose "Grant admin consent for" your organization and confirm.
5. Go to Certificates and secrets, choose New client secret, give it a description and an expiry, and choose Add. Copy the secret's Value straight away; it is only shown once.
6. In the control panel, go to Settings, then Email sending. Choose "Microsoft 365 (Microsoft Graph)", paste the tenant ID, client ID and secret value, enter the mailbox to send from and the "Send messages to" addresses, and choose "Save and send a test email".

Mail.Send on its own lets the app send as any mailbox in the organization. To limit it to one mailbox, use an Exchange Online application access policy or RBAC for Applications: https://learn.microsoft.com/graph/auth-limit-mailbox-access. When the secret expires, make a new one (step 5) and paste it in. Saving the email settings clears the cached token in `data/graph-token.php`.

### Bookings next opening

On the same app registration, add the Microsoft Graph application permission Bookings.Read.All and grant admin consent. Turn the feature on under Features, fill in Settings, then Next opening on Book Services, and put `[next-opening]` on a page. It shows nothing until it works, and checks the calendar at most every 30 minutes.

### Microsoft 365 sign-in for the control panel

On the same app registration, under Authentication, add a Web redirect URI of `https://your-domain/admin/oauth.php` (the exact address is shown in Settings; it comes from the Site address setting). The sign-in uses the `openid`, `profile` and `email` scopes with PKCE and needs no admin consent, but the tenant ID must be the GUID form, not a domain name. In Settings, add each person's name and Microsoft 365 email to the allow-list, then tick "Allow Microsoft 365 sign-in". Only listed people can sign in; the site password keeps working as a fallback. Removing a person, or turning the switch off, ends their session at their next click.

## Cron jobs

In cPanel, Cron Jobs, add these three lines, replacing `YOURUSER` with the account name. `/usr/local/bin/php` is the usual cPanel path; `which php` tells you yours.

```
*/10 * * * * /usr/local/bin/php /home/YOURUSER/public_html/tools/send-updates.php
15 * * * * /usr/local/bin/php /home/YOURUSER/public_html/tools/check-links.php
30 3 * * * /bin/sh /home/YOURUSER/public_html/tools/backup.sh
```

- `send-updates.php` every 10 minutes: sends queued email updates 40 at a time and removes unconfirmed sign-ups after 7 days.
- `check-links.php` hourly: once a day after 6 am it checks the SSL certificate and the error log; once a month, or soon after "Check all links now" is pressed, it checks every link on the site and emails the "Send messages to" addresses if anything is broken.
- `backup.sh` nightly: saves `content`, `data`, `uploads` and the stylesheet to `~/site-backups/content/`, keeping 30 days. It assumes the site is in `~/public_html`; edit `SITE` at the top if not. The dashboard reads the same folder to show the latest backup.

## Deploying code changes

Work on a copy of the site on your computer, preview it with `php -S`, then upload:

1. Copy `tools/deploy.conf.example` to `tools/deploy.conf` and fill in `SERVER` (user@host), `REMOTE` (the web folder, default `public_html`), `KEY` (SSH private key, or empty for your default) and `SITE_URL`. The file is ignored by git.
2. Run `sh tools/deploy.sh`. It checks every PHP file for syntax errors first, then uses rsync over SSH.

Deploying never touches `content/`, `data/`, `uploads/` or `assets/css/site.css`: pages, posts, the template, the stylesheet, settings, messages and uploads are edited in the live control panel, so the server holds the real copy. `uploads/.htaccess` (which stops uploaded files running as code) is sent separately because the rest of `uploads/` is skipped. `README.md`, `OWNER-GUIDE.md`, `router.php`, `.git` and `deploy.conf` are not uploaded either. `vendor/` and `admin/vendor/` are uploaded, so run `composer install` locally before deploying if the assistant is on. The script ends by setting the web folder to mode 750.

`sh tools/deploy.sh --pull` downloads the live `content/`, `uploads/` and stylesheet into your working copy, useful before changing code that depends on them.

## Keeping a site up to date with the kit

Treat each site as its own git repository that tracks the kit:

```bash
git remote add kit <kit repository address>
git fetch kit
git merge kit/main
sh tools/deploy.sh
```

Because `content/*`, `data/*` and `uploads/*` are ignored, merges only ever touch code. Changes you made to the layout or stylesheet in the control panel live on the server, not in git, so pull them first if a kit update changes those files and merge by hand.

## Subdomains in subfolders

cPanel often puts a subdomain's files inside `public_html` (for example `public_html/shop` for `shop.example.com`). Apache applies the parent folder's `.htaccess` to those requests too. The kit's rules are host-agnostic, so a request to the subdomain that is not a real file would be sent to the kit's `index.php` and shown as "Page not found".

The site this kit came from handled that by gating every rule on the host name. Before the site's own rules, it had a block like this, which lets other hosts keep their own behavior and stops processing:

```
RewriteCond %{HTTP_HOST} !^(www\.)?example\.com$ [NC]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
RewriteCond %{HTTP_HOST} !^(www\.)?example\.com$ [NC]
RewriteRule ^ - [L]
```

If other sites live inside your web folder, add the equivalent for your domain just after the `.well-known` rule in `.htaccess`, and keep in mind the www and https redirects also run for every host unless you gate them too.

## Folder map

- `index.php`: the public site; every address comes through here.
- `router.php`: only for `php -S` on your computer.
- `.htaccess`, `.user.ini`: Apache rules and PHP upload limits (64 MB).
- `admin/`: the control panel (`index.php`, `oauth.php`, helpers, CSS, JS) and `admin/vendor/tinymce/`.
- `lib/`: shared code: core (settings, items, revisions), mail, Graph, contact, blog, resources, braille, docx, quiz, search, stats, subscribers, bookings, assistant, link check, shortcodes, starter pages.
- `tools/`: `setup.php`, `set-password.php`, `deploy.sh`, `backup.sh`, `check-links.php`, `send-updates.php`.
- `assets/`: stylesheet, self-hosted font, site script. The stylesheet is edited in the control panel.
- `content/`: `layout.html`, `settings.json`, `pages/`, `posts/`, `resources/`, `videos/`, `stories/`, `testimonials/`, `update-archive/`, `revisions/`.
- `data/`: `config.php` (secrets), `messages/`, `subscribers.json`, `outbox/`, `stats/`, `cache/`, link and health reports.
- `uploads/`: files from Images and files, in `YYYY/MM/` folders, plus `uploads/audio/` for post recordings.
- `vendor/`: Composer packages for the assistant.
- `backups/`: empty on the server; a place for local copies.

## Security notes

- `.htaccess` returns 403 for `content`, `data`, `lib`, `tools`, `vendor`, `backups`, `composer.json` and `.lock`, any dot-file, `error_log`, `README.md`, `OWNER-GUIDE.md` and `router.php`. The private folders also carry their own `.htaccess` with "Require all denied", and `admin/.htaccess` hides the helper files.
- Uploaded files cannot run as code: `uploads/.htaccess` denies PHP, HTML, SVG and script extensions and removes the PHP handler. Uploads are checked by extension and detected type, images are verified, and file names are slugified.
- Secrets live in `data/config.php` (password hash, Graph secret, Anthropic key, site secret, people list), written with mode 600 and valid PHP so nothing readable comes back even if the rules fail.
- Backups (nightly and downloaded) leave out `config.php`, the Graph token, daily stats salts, login attempts and caches.
- The web first run needs `data/setup-code.txt` unless the request comes from the server itself; the file is deleted on success.
- Sign-in is paused for 15 minutes after 5 wrong passwords from one address; sessions end after 8 hours idle; every form carries a CSRF token; redirects only ever go to paths on the site.
- Security headers: nosniff, referrer policy, frame options, HSTS when on https. Forms use a time-limited signed token and a honeypot against bots; the assistant is rate-limited per visitor and per day.

## Accessibility notes

Every public page has a skip link to `main`, landmark regions (header, navigation labelled "Main", main, footer), exactly one `h1`, `aria-current` on the current menu item, a language switch with `lang` and `hreflang` when Spanish is on, visible focus styles, and no motion or autoplay. Form results move focus to a status message. The site uses a self-hosted font and no third-party scripts. The Display options button in the header opens a panel with Text size (Standard, Large, Extra large) and Colors (Match my device, Light, Dark, High contrast yellow on black), with Reset and Close buttons; Escape closes it and the choice is stored in `localStorage` only. Cheat sheets download as Word, 24-point Word and braille (uncontracted UEB, 40 by 25). The control panel has the same skip link and landmarks, visible labels on every field, hints and errors tied with `aria-describedby`, an error summary that moves focus and links to each field, status messages that take focus, and an "On this screen" navigation on long screens.
