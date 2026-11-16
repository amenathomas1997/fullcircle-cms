# Wave 1 build brief (shared by every builder)

You are building one feature set for Full Circle CMS, a flat-file PHP 8.1 website system with an accessible control panel, made for owners who use screen readers (JAWS, NVDA, VoiceOver). The main copy is at /Users/amenathomas/fullcircle-cms (branch main). Read these first, in full: README.md, docs/EXTENDING.md, lib/registry.php, lib/core.php, admin/admin-lib.php, and skim admin/index.php, lib/mail.php, lib/shortcodes.php, admin/blocks-editor.js.

## Work in your own worktree and branch

```
git -C /Users/amenathomas/fullcircle-cms worktree add /Users/amenathomas/fullcircle-cms-wt/<branch> -b <branch> main
cd /Users/amenathomas/fullcircle-cms-wt/<branch>
ln -s /Users/amenathomas/fullcircle-cms/vendor vendor        # Composer packages, only needed by the assistant / alt text
```

Set up a fresh test site there (pick the port you were given):

```
SETUP_SITE_NAME="Test" SETUP_SITE_URL="http://localhost:<port>" SETUP_EMAIL="owner@example.com" SETUP_PHONE="555-010-0100" \
SETUP_TIMEZONE="America/Chicago" SETUP_PALETTE="purple" SETUP_SPANISH="no" SETUP_PASSWORD="test-password-123456" php tools/setup.php --yes
php -S localhost:<port> router.php > /tmp/cms-<port>.log 2>&1 &
```

Sign in at http://localhost:<port>/admin/ with that password (it is a throwaway test value on localhost). Test with curl and, where you need a browser, with the Playwright or similar tooling if present; otherwise test the PHP directly with small scripts. Check `php -l` on every PHP file you touch and that /tmp/cms-<port>.log shows no warnings or notices. Stop your server when you finish.

Commit on your branch with clear messages (end each with `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`). Do not push, do not merge, do not touch main or other worktrees. Your final report names the branch, lists the files you added and the core files you changed (and why), what you tested, and anything unfinished.

## Rules

1. Put new code in `lib/features/<name>.php` (public + shared), new admin screens inside that same file or `admin/<name>.php` required from it, scripts in `admin/<name>.js` or `assets/js/<name>.js`, and register everything through `lib/registry.php` (routes, shortcodes, admin screens, settings sections, dashboard cards, features, cron, item hooks). Touch core files (index.php, lib/core.php, admin/index.php, admin/admin-lib.php, lib/shortcodes.php, lib/bootstrap.php, admin/blocks-editor.js, admin/editor.js, assets/css/site.css, admin/admin.css) only where your brief says you may, keep those edits small and local, and never reformat or reorder existing code. Another builder is editing other parts of the same files at the same time; small, local edits merge cleanly, sweeping ones do not.
2. Follow docs/EXTENDING.md to the letter: escape with `h()`, build forms with the existing field helpers and `csrf_field()`, errors keyed by field id, POST for every change, confirm screens for destructive actions, `flash()` for outcomes, `feature_on()` switches, settings not code, atomic writes, email only through `send_mail_to()` / `send_mail_owner()`, English and Spanish strings for anything public via `t()`.
3. Accessibility is the product. Every screen: one h1 (from `admin_screen()`), headings in order, every control labelled, tables with `<th scope>`, buttons that say what they do, focus moved to results and errors (`tabindex="-1"` + `data-focus-on-load` on the public site), status messages in a live region or via `flash()`, no reliance on colour alone, works with the keyboard alone. Public output must pass axe with no violations and validate as HTML.
4. Keep the owner in mind: a blind person running a small business, not a developer. Plain words on every label, hint and message. Never show raw exceptions.
5. No new Composer packages. PHP 8.1, `declare(strict_types=1)` in every file, curl for HTTP, no frameworks.
6. Data: private state under `DATA_DIR` (data/), content that belongs to the site under `CONTENT_DIR` (content/). New item types that behave like pages/posts may use `save_item()` / `get_item()` / `all_items()` with a new type name (add it to `ITEM_TYPES` in lib/core.php: a one-line edit you are allowed).
7. Write a short section for OWNER-GUIDE.md (for the owner) and README.md (for the installer) describing your feature, as new files `docs/owner-<name>.md` and `docs/readme-<name>.md`; the integrator merges them into the guides.
