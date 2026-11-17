## Running several sites from the kit

### Versions

The kit's version is the one line in `VERSION` (semantic: major.minor.patch); `CHANGELOG.md` has an entry per release, newest first, and `cms_version()` in `lib/version.php` reads it. The control panel footer shows "Full Circle CMS 1.0.0" on every screen, and the **System** screen (owner) shows it with the PHP version, disk use of `content/`, `uploads/` and `data/`, whether git is available and which branch the site is on, the result of the last update check, how many PHP errors the host log recorded today (cPanel's `~/logs/<domain>.php.error.log`, or `error_log` in the web folder), and where the backups are.

### Create a site with git clone, not by copying

Updates arrive through git, so every site must be a clone of the kit repository:

```bash
cd ~
git clone <kit repository address> public_html      # or clone into a new folder and point the domain at it
cd public_html
php tools/setup.php                                  # or SETUP_* variables and --yes, see "Create a new site"
composer install --no-dev                            # only if the Ask a question assistant will be on
```

Everything that belongs to one site is ignored by git and so survives every update: `content/*` (pages, posts, settings, revisions), `data/*` and `uploads/*`. Two files the owner edits in the control panel, `content/layout.html` and `assets/css/site.css`, are tracked by the kit; `tools/update.php` sets the site's versions aside for the pull and puts them back afterwards, printing a note if the kit changed them too. The `site/` folder is tracked by git but ships empty; a site's own PHP files there are untracked files in its working copy, so `git pull` leaves them alone too. Never put a site's own code or settings in `lib/`, `admin/` or `index.php`: those are the kit's files and a fast-forward pull would refuse to run over local edits.

If a site was installed by copying files, turn it into a clone without losing anything: `git init`, `git remote add origin <kit address>`, `git fetch origin`, `git reset origin/main` (the files already match, so this only writes the index), then `git checkout -- .` to be sure. The ignored folders are untouched.

### Updating a site

```bash
php tools/update.php           # shows the installed version, fetches, and lists what is new on origin/main
php tools/update.php --apply   # git pull --ff-only, composer install --no-dev if vendor/ exists, new migrations, changelog
```

`--apply` refuses to run when the kit's own files have local changes (so a pull cannot clobber them) and when the branch has diverged. Migrations are `tools/migrations/NNN-name.php`: each runs once with the kit loaded, is recorded in `data/migrations.json`, and must be safe to run twice (see `tools/migrations/README.md`). After updating, the entries from `CHANGELOG.md` newer than the version that was installed are printed. To take updates from a different branch, set `'update_branch' => 'origin/stable'` in `data/config.php`.

A daily cron job (part of `tools/check-links.php`, which already runs hourly) does `git fetch` and compares, storing the answer in `data/update-status.json`. The System screen shows "Up to date", "Version X.Y.Z is available: ask your host to update" or "Not a git checkout", with a "Check now" button; the Dashboard gets a card when an update is waiting. The check needs git on the server, `proc_open` enabled, and a remote that works without a password (an https address of a public repository, or a deploy key for a private one). It never prompts: a remote that asks for credentials fails and shows "Could not reach the kit repository".

### Fleet status: /status.json and the Sites screen

Each site can answer `/status.json` with its version, site name, PHP version, last backup time, unsent contact messages, link-check problems, days left on its SSL certificate (from the daily check, so the request never waits on a TLS handshake), whether an update is pending, and a timestamp. The address exists only when `fleet_secret` is set in `data/config.php` (made on the **Sites** screen with "Make a key for this site"), and only for a signed request:

```
/status.json?t=<unix time>&sig=<hex hmac-sha256 of t, keyed with fleet_secret>
```

`t` must be within 5 minutes of the server's clock; anything else is a plain 404, the same as a page that does not exist.

The **Sites** screen (owner) lists the other sites, each stored in `data/config.php` under `fleet_sites` as name, url and that site's key (they are secrets, so they are not in `content/settings.json`). "Check all" fetches every status in turn with curl and a 10-second timeout, saves the answers in `data/fleet-status.json`, and shows a table with a plain-words summary per site: "All good", or "2 things to look at: 1 contact message not emailed; the last backup was November 10, 2026". Things it looks at: unsent messages, link problems, a certificate with under 14 days left, no backup or one older than two days, and a pending kit update. The Dashboard shows "3 of 4 sites are fine". One site can list itself to check its own status, but not through the `php -S` preview server unless it was started with `PHP_CLI_SERVER_WORKERS=2` or more (a single worker cannot answer its own request).

### Backups

`tools/backup.sh` (nightly, from cron) works out the site folder from its own location, so it no longer assumes `~/public_html`. It writes `site-content-YYYY-MM-DD.tgz` plus a `site-content-YYYY-MM-DD.json` manifest (kit version, site name, size, counts) to the first of: `$BACKUP_DIR` in the environment, `backup_dir` in `data/config.php`, or `~/site-backups/content`. It keeps the newest 30. The control panel reads the same folder through the same rule (`backup_dir()` in `lib/features/ops.php`), so set `'backup_dir' => '/path'` in `data/config.php` when the default does not suit a host and both agree.

The **Backups** screen (owner) lists those archives with download, "Restore one item" (a page, post or other item from the archive; the current one is kept as a revision) and "Restore everything" (content, uploads and data except `config.php` and the people list; two steps, the archive date typed back; a `before-restore-<time>.zip` of the current site goes into the backup folder first). It also has **Export this site** (a zip of `content/`, `uploads/`, `data/` minus secrets and caches, `assets/css/site.css`, and `manifest.json` with the version and settings) and **Import a site** (that zip, into an empty site directly, or over a site with content after typing its name; the site keeps its own `site_url`). Restores and imports are written to the activity log when the kit has one (`log_activity()`).

`sh tools/restore.sh <archive.tgz>` is the command-line twin of "Restore everything": it checks the archive only holds the four site folders, saves `before-restore-<time>.tgz` beside the archive, and copies the archive over the live folders, skipping `data/config.php` and the people list.

Uploads of export files go through PHP's upload limit (`.user.ini` sets 64 MB); bigger sites move over SSH with `rsync` or `tools/restore.sh`.

### Folder map additions

- `VERSION`, `CHANGELOG.md`, `lib/version.php`: the version and what changed.
- `lib/features/ops.php`: shared helpers, the `/status.json` route, the daily update check, Dashboard cards; `admin/ops.php`: the System, Sites and Backups screens.
- `tools/update.php`, `tools/migrations/`, `tools/restore.sh`.
- `data/update-status.json`, `data/fleet-status.json`, `data/migrations.json`: state written by the above; private, never in git.
