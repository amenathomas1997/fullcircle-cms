#!/bin/sh
# Put a nightly backup back from the command line, the same way the Backups
# screen's "Restore everything" does:
#   sh tools/restore.sh /path/to/site-content-2026-11-16.tgz
# Restores content/, uploads/, data/ and the stylesheet from the archive.
# data/config.php (the password and secrets) and the people list are never
# touched. The current content/, uploads/ and data/ are saved first to
# <backup folder>/before-restore-<date>.tgz, so the restore can be undone.
set -eu
SITE="$(cd "$(dirname "$0")/.." && pwd)"
ARCHIVE="${1:-}"
if [ -z "$ARCHIVE" ] || [ ! -f "$ARCHIVE" ]; then
  echo "Usage: sh tools/restore.sh <archive.tgz>   (an archive made by tools/backup.sh)"
  exit 1
fi
case "$ARCHIVE" in /*) ;; *) ARCHIVE="$(pwd)/$ARCHIVE" ;; esac
if ! tar tzf "$ARCHIVE" > /dev/null 2>&1; then
  echo "$ARCHIVE is not a readable .tgz archive. Nothing was changed."
  exit 1
fi
if tar tzf "$ARCHIVE" | grep -Ev '^(content|data|uploads|assets)(/|$)' | grep -q .; then
  echo "$ARCHIVE holds files outside content, data, uploads and assets, so it is not a site backup. Nothing was changed."
  exit 1
fi
SAFETY="$(dirname "$ARCHIVE")/before-restore-$(date +%Y-%m-%d_%H%M%S).tgz"
tar czf "$SAFETY" -C "$SITE" --exclude='data/cache' --exclude='data/config.php' content data uploads assets/css/site.css
chmod 600 "$SAFETY"
echo "Saved the current site to $SAFETY first."
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
tar xzf "$ARCHIVE" -C "$WORK"
# Copy over the top of the live folders; nothing in the archive can be config.php or the people list.
rm -f "$WORK/data/config.php" "$WORK/data/users.json"
rm -rf "$WORK/data/users"
for part in content uploads data assets; do
  [ -d "$WORK/$part" ] || continue
  (cd "$WORK/$part" && find . -type d -exec mkdir -p "$SITE/$part/{}" \; && find . -type f -exec cp -p "{}" "$SITE/$part/{}" \;)
done
rm -rf "$SITE/data/cache"/* 2>/dev/null || true
echo "$(date '+%Y-%m-%d %H:%M') restored $ARCHIVE into $SITE"
