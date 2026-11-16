#!/bin/sh
# Make an audio version of a blog post with a Mac's built-in voice and put it on the website.
#   sh tools/make-audio.sh <post-address>            for example: sh tools/make-audio.sh my-first-post
#   VOICE="Samantha" sh tools/make-audio.sh <post>   use a different voice (say -v '?' lists them)
#   sh tools/make-audio.sh <post> --local            make the file here only, don't upload it
#
# Needs macOS (the say and afconvert commands) and tools/deploy.conf for the server.
# The post is read from the live website, so drafts work too. The finished audio
# appears at the top of the post and in the podcast feed straight away.
# Prefer a real voice? Record it and upload it on the post's edit screen instead.

set -eu
cd "$(dirname "$0")/.."

SLUG="${1:-}"
[ -n "$SLUG" ] || { echo "Usage: sh tools/make-audio.sh <post-address>"; exit 1; }
echo "$SLUG" | grep -Eq '^[a-z0-9]+(-[a-z0-9]+)*$' || { echo "That isn't a post address."; exit 1; }
[ -f tools/deploy.conf ] || { echo "Copy tools/deploy.conf.example to tools/deploy.conf and fill it in first."; exit 1; }
. ./tools/deploy.conf
: "${SERVER:?SERVER is missing from tools/deploy.conf}"
: "${REMOTE:=public_html}"
KEY="${KEY:-}"
SSH="ssh -o IdentitiesOnly=yes${KEY:+ -i $KEY}"

# Spanish posts (addresses starting with es-) use a Spanish voice.
case "$SLUG" in es-*) VOICE="${VOICE:-Paulina}" ;; *) VOICE="${VOICE:-Samantha}" ;; esac
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

if [ "${2:-}" = "--local" ] && [ -f "content/posts/$SLUG.json" ]; then
  cp "content/posts/$SLUG.json" "$WORK/post.json"
else
  $SSH "$SERVER" "cat $REMOTE/content/posts/$SLUG.json" > "$WORK/post.json" || { echo "No post called $SLUG on the website."; exit 1; }
fi

php tools/post-text.php "$SLUG" < "$WORK/post.json" > "$WORK/post.txt"
echo "Recording with the voice \"$VOICE\"..."
say -v "$VOICE" -r 175 -f "$WORK/post.txt" -o "$WORK/post.aiff"
afconvert -f m4af -d aac -c 1 -b 64000 "$WORK/post.aiff" "$WORK/$SLUG.m4a"

mkdir -p uploads/audio
cp "$WORK/$SLUG.m4a" "uploads/audio/$SLUG.m4a"
echo "Made uploads/audio/$SLUG.m4a ($(du -h "uploads/audio/$SLUG.m4a" | cut -f1 | tr -d ' '))."

if [ "${2:-}" != "--local" ]; then
  $SSH "$SERVER" "mkdir -p $REMOTE/uploads/audio && rm -f $REMOTE/uploads/audio/$SLUG.mp3"
  rsync -tz -e "$SSH" "$WORK/$SLUG.m4a" "$SERVER:$REMOTE/uploads/audio/$SLUG.m4a"
  echo "Uploaded. Listen at ${SITE_URL:-the website}/blog/$SLUG/"
fi
