## Backups, restoring and moving your site

Open **Backups** in the control panel. It has three parts.

### Nightly backups

Every night the server saves a copy of your pages, posts, uploads, settings and contact messages, and keeps the newest 30. Your password and email settings are never in a backup. The list shows each backup's date, size and the version of the kit that made it, with three actions:

- **Download** saves that night's backup to your computer as a `.tgz` file.
- **Restore one item** puts back a single page, post, cheat sheet, video or story from that night. Choose it from the list (each one is named "Page: About", "Blog post: Welcome…" and so on) and press "Restore this item". The version that was on the website is kept under Earlier versions, so you can undo this.
- **Restore everything** puts back the whole site as it was that night: every page, post, upload, setting and message. Because it replaces everything, you type the backup's date (for example `2026-11-16`) to confirm. Before anything changes, a copy of the site as it is now is saved in the backup folder, and the message afterwards tells you where. Your password, email settings and the people who can sign in are never touched.

If no backup is listed, the nightly job has not been set up yet: ask your host (it is one cron line, in the README).

### Export this site

"Export this site" downloads one zip file with everything that makes the site yours: pages, posts, uploads, settings, messages and the stylesheet, plus a note of the kit version. Passwords and keys are left out. Keep it somewhere safe, or use it to move the site to another server.

### Import a site

"Import a site" loads an export zip made by another copy of the kit. On a brand-new site it just loads. On a site that already has content, importing replaces that content, so you type this site's name to confirm, and a copy of the current site is saved in the backup folder first. The site keeps its own web address after the import; everything else (name, pages, posts, settings, uploads) comes from the file. Check Settings and the home page afterwards.

### System and Sites

Two more screens are mostly for whoever hosts the site:

- **System** shows the kit version, PHP version, how much space the site uses, and whether a newer version of the kit is available. "Check now" looks straight away. Updates are applied by your host; the screen tells you when to ask.
- **Sites** is for people who run more than one website built from this kit. Each site has a key; paste the other sites' keys here and "Check all" asks every one how it is doing, answering in plain words: "All good" or "2 things to look at: 1 contact message not emailed; the last backup was November 10, 2026". The Dashboard then shows "3 of 4 sites are fine".
