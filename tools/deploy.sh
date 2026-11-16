#!/bin/sh
# Upload code changes to the live site over SSH.
#   sh tools/deploy.sh            upload code
#   sh tools/deploy.sh --pull     download a copy of the live content, settings and uploads
#
# The server details live in tools/deploy.conf (copy tools/deploy.conf.example
# and fill it in; it is never committed). Pages, posts, the template, the
# stylesheet, settings, messages and uploads are edited in the control panel,
# so the live server holds the real copy of them. Deploying never overwrites them.

set -eu
cd "$(dirname "$0")/.."

if [ ! -f tools/deploy.conf ]; then
  echo "Copy tools/deploy.conf.example to tools/deploy.conf and fill in the server details first."
  exit 1
fi
. ./tools/deploy.conf
: "${SERVER:?SERVER is missing from tools/deploy.conf}"
: "${REMOTE:=public_html}"
KEY="${KEY:-}"
SSH="ssh -o IdentitiesOnly=yes${KEY:+ -i $KEY}"

if [ "${1:-}" = "--pull" ]; then
  rsync -az -e "$SSH" "$SERVER:$REMOTE/content/" content/
  rsync -az -e "$SSH" "$SERVER:$REMOTE/uploads/" uploads/
  rsync -az -e "$SSH" "$SERVER:$REMOTE/assets/css/site.css" assets/css/site.css
  echo "Downloaded the live content, uploads and stylesheet."
  exit 0
fi

for f in index.php lib/*.php admin/*.php tools/*.php; do
  php -l "$f" > /dev/null || { echo "Syntax error in $f. Nothing was uploaded."; exit 1; }
done

rsync -rltz -e "$SSH" \
  --exclude='.claude/' --exclude='.git/' --exclude='.gitignore' --exclude='scratchpad/' --exclude='backups/' --exclude='.DS_Store' \
  --exclude='/content/' --exclude='/data/' --exclude='/uploads/' \
  --exclude='/assets/css/site.css' \
  --exclude='/README.md' --exclude='/OWNER-GUIDE.md' --exclude='/router.php' --exclude='/tools/deploy.conf' \
  ./ "$SERVER:$REMOTE/"

# uploads/.htaccess keeps uploaded files from running as code; it travels separately because /uploads/ is skipped above.
rsync -rltz -e "$SSH" uploads/.htaccess "$SERVER:$REMOTE/uploads/.htaccess"

# The web folder must stay private to other accounts on the server.
$SSH "$SERVER" "chmod 750 ~/$REMOTE"
echo "Uploaded. Check ${SITE_URL:-the website}"
