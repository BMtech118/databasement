#!/usr/bin/env bash
# BMT deploy of our Databasement fork on bmt-backup-01, run by
# ~/bmt/scripts/deploy databasement after the checkout is fast-forwarded.
#
# Builds the image from this checkout, snapshots the app's own data (SQLite
# database, keys) while the container is stopped, then starts the new image.
# Rollback: set the previous tag in docker-compose.yml and `docker compose up -d`;
# restore data from the snapshot if a migration has to be undone.
set -euo pipefail

SRC="$(cd "$(dirname "$0")/.." && pwd)"
APP_DIR=/home/ubuntu/databasement
SHA="$(git -C "$SRC" rev-parse --short HEAD)"
VERSION="$(git -C "$SRC" describe --tags --abbrev=0 2>/dev/null || echo dev)-bmt"
IMAGE="bmt/databasement:$SHA"

echo "→ building $IMAGE ($VERSION)"
docker build --pull \
  --build-arg APP_COMMIT_HASH="$SHA" --build-arg APP_VERSION="$VERSION" \
  -t "$IMAGE" "$SRC"

PREV="$(docker inspect databasement --format '{{.Config.Image}}' 2>/dev/null || echo none)"
STAMP="$(date -u +%Y%m%d-%H%M%S)"
mkdir -p "$APP_DIR/snapshots"

echo "→ stopping databasement (was $PREV) for a consistent data snapshot"
docker compose -f "$APP_DIR/docker-compose.yml" stop -t 60 databasement
tar -C "$APP_DIR" -czf "$APP_DIR/snapshots/data-$STAMP-before-$SHA.tgz" data
cp "$APP_DIR/docker-compose.yml" "$APP_DIR/snapshots/docker-compose-$STAMP.yml"

sed -i -E "s|^(\s*image:\s*)bmt/databasement:.*$|\1$IMAGE|" "$APP_DIR/docker-compose.yml"
grep -q "image: $IMAGE" "$APP_DIR/docker-compose.yml"

echo "→ starting $IMAGE"
docker compose -f "$APP_DIR/docker-compose.yml" up -d databasement

ok=false
for _ in $(seq 1 60); do
  if curl -fsS -o /dev/null --max-time 3 http://127.0.0.1:2226/up; then ok=true; break; fi
  sleep 3
done
if ! $ok; then
  echo "✗ databasement did not come up on $IMAGE. Rollback: set image to $PREV in $APP_DIR/docker-compose.yml, docker compose up -d; data snapshot $APP_DIR/snapshots/data-$STAMP-before-$SHA.tgz" >&2
  docker logs --tail 40 databasement >&2 || true
  exit 1
fi

# Keep the last 10 data snapshots.
ls -1t "$APP_DIR"/snapshots/data-*.tgz | tail -n +11 | xargs -r rm -f
echo "✓ databasement live on $IMAGE (previous $PREV)"
