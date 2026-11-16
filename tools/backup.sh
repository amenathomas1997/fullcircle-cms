#!/bin/sh
# Nightly backup of everything edited in the control panel: pages, posts,
# cheat sheets, testimonials, the template and stylesheet, settings, contact
# messages and uploads. Runs on the server from cron; keeps the newest 30.
#   sh ~/public_html/tools/backup.sh
set -eu
SITE="$HOME/public_html"
DEST="$HOME/site-backups/content"
mkdir -p "$DEST"
chmod 700 "$HOME/site-backups" "$DEST"
FILE="$DEST/site-content-$(date +%Y-%m-%d).tgz"
# Secrets stay out: data/config.php holds the password hash and API keys (reset with tools/set-password.php).
tar czf "$FILE.tmp" -C "$SITE" --exclude='data/cache' --exclude='data/graph-token.php' --exclude='data/stats/salt-*' \
  --exclude='data/config.php' --exclude='data/login-attempts.json' --exclude='data/outbox/.lock' \
  content data uploads assets/css/site.css
mv "$FILE.tmp" "$FILE"
chmod 600 "$FILE"
# Keep the 30 newest backups.
ls -1t "$DEST"/site-content-*.tgz | tail -n +31 | while read -r old; do rm -f "$old"; done
echo "$(date '+%Y-%m-%d %H:%M') backed up to $FILE ($(du -h "$FILE" | cut -f1))"
