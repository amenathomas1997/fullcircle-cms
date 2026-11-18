#!/bin/sh
# Nightly backup of everything edited in the control panel: pages, posts,
# cheat sheets, testimonials, the template and stylesheet, settings, contact
# messages and uploads. Runs on the server from cron; keeps the newest 30.
#   sh ~/public_html/tools/backup.sh
# The site is the folder this script lives in. Backups go to $BACKUP_DIR if set,
# otherwise to the backup_dir value in data/config.php, otherwise ~/site-backups/content.
# The control panel's Backups screen reads the same folder, through the same rule.
set -eu
SITE="$(cd "$(dirname "$0")/.." && pwd)"
# A command-line PHP. Under cron on cPanel a bare "php" can be the php-cgi wrapper, which cannot run -r.
PHPBIN="${PHP_BIN:-}"
if [ -z "$PHPBIN" ]; then
  for c in /usr/local/bin/php /opt/cpanel/ea-php85/root/usr/bin/php /opt/cpanel/ea-php84/root/usr/bin/php /opt/cpanel/ea-php83/root/usr/bin/php /opt/cpanel/ea-php82/root/usr/bin/php /opt/cpanel/ea-php81/root/usr/bin/php php; do
    if command -v "$c" >/dev/null 2>&1 && "$c" -r 'exit(PHP_SAPI === "cli" ? 0 : 1);' >/dev/null 2>&1; then
      PHPBIN="$c"
      break
    fi
  done
fi
if [ -z "${BACKUP_DIR:-}" ] && [ -n "$PHPBIN" ]; then
  BACKUP_DIR="$("$PHPBIN" -r 'require $argv[1] . "/lib/bootstrap.php"; echo (string) (config()["backup_dir"] ?? "");' "$SITE" 2>/dev/null || true)"
fi
DEST="${BACKUP_DIR:-$HOME/site-backups/content}"
mkdir -p "$DEST"
chmod 700 "$DEST"
[ "$DEST" = "$HOME/site-backups/content" ] && chmod 700 "$HOME/site-backups"
FILE="$DEST/site-content-$(date +%Y-%m-%d).tgz"
# Secrets stay out: data/config.php holds the password hash and API keys (reset with tools/set-password.php).
tar czf "$FILE.tmp" -C "$SITE" --exclude='data/cache' --exclude='data/graph-token.php' --exclude='data/appt-token.php' --exclude='data/book-rate.json' --exclude='data/deploy-key' --exclude='data/deploy-key.pub' --exclude='data/deploy-known-hosts' --exclude='data/stats/salt-*' \
  --exclude='data/config.php' --exclude='data/login-attempts.json' --exclude='data/outbox/.lock' \
  content data uploads assets/css/site.css
mv "$FILE.tmp" "$FILE"
chmod 600 "$FILE"
# A manifest beside the archive: what it is, which version of the kit made it, and how big it is.
[ -n "$PHPBIN" ] && "$PHPBIN" -r '
[$site, $file] = [$argv[1], $argv[2]];
$version = trim((string) @file_get_contents("$site/VERSION")) ?: "unknown";
$settings = json_decode((string) @file_get_contents("$site/content/settings.json"), true) ?: [];
$count = function (string $dir): int { $n = 0; if (!is_dir($dir)) { return 0; } foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) { $n++; } return $n; };
$manifest = ["archive" => basename($file), "created" => date("c"), "version" => $version, "site_name" => (string) ($settings["site_name"] ?? ""),
    "site_url" => (string) ($settings["site_url"] ?? ""), "size" => filesize($file), "php" => PHP_VERSION,
    "items" => ["pages" => count(glob("$site/content/pages/*.json") ?: []), "posts" => count(glob("$site/content/posts/*.json") ?: []), "uploads" => $count("$site/uploads")]];
file_put_contents(preg_replace("/\.tgz$/", ".json", $file), json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
' "$SITE" "$FILE"
[ -f "${FILE%.tgz}.json" ] && chmod 600 "${FILE%.tgz}.json"
# Keep the 30 newest backups, and their manifests.
ls -1t "$DEST"/site-content-*.tgz | tail -n +31 | while read -r old; do rm -f "$old" "${old%.tgz}.json"; done
echo "$(date '+%Y-%m-%d %H:%M') backed up to $FILE ($(du -h "$FILE" | cut -f1))"
