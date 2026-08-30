#!/usr/bin/env bash
# Build, sign and publish a Pharos release. Vendor side only — needs the Ed25519
# secret in /root/secrets and SSH to edge-01. Runs on CT102.
#
#   scripts/build-release.sh 0.5.0 [--notes "One line for the operator"] [--no-upload] [--no-tag]
#
# Produces in dist/:
#   pharos-<v>.zip          the app with vendor/, no dev packages, no .env, no data
#   pharos-<v>.zip.sha256   checksum
#   latest.json             signed manifest (payload.signature), what the app's Updates tab reads
# and uploads them to https://pharos.solutionmax.net/releases/ (Caddy handle_path → /var/www/pharos-releases).
set -euo pipefail

VERSION="${1:?usage: build-release.sh <version> [--notes ...] [--no-upload] [--no-tag]}"; shift
NOTES="Pharos ${VERSION}"; UPLOAD=1; TAG=1
while [ $# -gt 0 ]; do
  case "$1" in
    --notes) NOTES="$2"; shift 2 ;;
    --no-upload) UPLOAD=0; shift ;;
    --no-tag) TAG=0; shift ;;
    *) echo "unknown option $1" >&2; exit 2 ;;
  esac
done

REPO="$(cd "$(dirname "$0")/.." && pwd)"
SECRET="${PHAROS_LICENSE_SECRET_FILE:-/root/secrets/pharos-license-secret.hex}"
REMOTE="contabo:/var/www/pharos-releases"
BASE_URL="https://pharos.solutionmax.net/releases"
STAGE="$(mktemp -d)/pharos-${VERSION}"
DIST="${REPO}/dist"

[ -r "$SECRET" ] || { echo "no readable secret key at $SECRET" >&2; exit 1; }
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+([-+][0-9A-Za-z.]+)?$ ]] || { echo "version must be semver, got $VERSION" >&2; exit 1; }

cd "$REPO"
[ -z "$(git status --porcelain)" ] || { echo "working tree not clean — commit first" >&2; exit 1; }

echo "== 1/6 gates"
vendor/bin/pint --test -q
vendor/bin/phpstan analyse --memory-limit=1G --no-progress -q
php artisan test --compact 2>&1 | tail -1 | grep -q '"result":"passed"' || { echo "tests failed" >&2; exit 1; }

echo "== 2/6 stage ${STAGE}"
mkdir -p "$STAGE"
rsync -a --delete \
  --exclude '/.git' --exclude '/.github' --exclude '/tests' --exclude '/node_modules' --exclude '/dist' \
  --exclude '/vendor' --exclude '.env' --exclude '.env.*.local' --exclude 'phpstan.neon' --exclude 'phpunit.xml' \
  --exclude 'storage/app/*' --exclude 'storage/logs/*' --exclude 'storage/framework/cache/*' \
  --exclude 'storage/framework/sessions/*' --exclude 'storage/framework/views/*' --exclude 'storage/framework/phpstan' \
  --exclude 'database/*.sqlite*' --exclude 'bootstrap/cache/*.php' --exclude 'public/storage' \
  --exclude 'public/brand/uploads' --exclude 'scripts/build-release.sh' \
  ./ "$STAGE/"
# the version travels with the code, never with .env
sed -i "s/'version' => env('PHAROS_VERSION', '[^']*')/'version' => env('PHAROS_VERSION', '${VERSION}')/" "$STAGE/config/pharos.php"
grep -q "'${VERSION}'" "$STAGE/config/pharos.php" || { echo "version not stamped" >&2; exit 1; }
# a release must be able to migrate on first boot: keep the empty writable dirs
for d in storage/app/public storage/app/backups storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache; do
  mkdir -p "$STAGE/$d"; touch "$STAGE/$d/.gitkeep"
done

echo "== 3/6 vendor (production only)"
( cd "$STAGE" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --no-progress -q )
[ -d "$STAGE/vendor/laravel/framework" ] || { echo "composer install failed" >&2; exit 1; }
rm -rf "$STAGE/vendor/*/*/tests" 2>/dev/null || true

echo "== 4/6 archive"
mkdir -p "$DIST"; rm -f "$DIST/pharos-${VERSION}.zip"
( cd "$(dirname "$STAGE")" && zip -qr "$DIST/pharos-${VERSION}.zip" "pharos-${VERSION}" -x '*.DS_Store' )
( cd "$DIST" && sha256sum "pharos-${VERSION}.zip" > "pharos-${VERSION}.zip.sha256" )
SIZE=$(du -h "$DIST/pharos-${VERSION}.zip" | cut -f1)

echo "== 5/6 sign"
php artisan pharos:release:sign "$VERSION" "${BASE_URL}/pharos-${VERSION}.zip" "$DIST/pharos-${VERSION}.zip" \
  --notes="$NOTES" --key="$SECRET" | tail -1 > "$DIST/latest.json"
php artisan tinker --execute="\$m = app(\App\Services\Updater::class)->verify(file_get_contents('${DIST}/latest.json')); if (! \$m || \$m['version'] !== '${VERSION}') { fwrite(STDERR, \"manifest does not verify\n\"); exit(1); } echo 'manifest verifies: '.\$m['version'].' '.\$m['sha256'].PHP_EOL;" -q

if [ "$UPLOAD" = 1 ]; then
  echo "== 6/6 upload → ${BASE_URL}/"
  # the same signed manifest under the version's own name, so an installer can be pinned to it
  cp "$DIST/latest.json" "$DIST/pharos-${VERSION}.json"
  rsync -a "$DIST/pharos-${VERSION}.zip" "$DIST/pharos-${VERSION}.zip.sha256" "$DIST/pharos-${VERSION}.json" "$DIST/latest.json" "$REMOTE/"
  curl -fsS "${BASE_URL}/latest.json" | cmp -s - "$DIST/latest.json" && echo "latest.json live"
  # the human-readable side: /releases/ rendered from CHANGELOG.md + releases.json (sizes, checksums)
  curl -fsS "${BASE_URL}/releases.json" -o "$DIST/releases.json" 2>/dev/null || echo '[]' > "$DIST/releases.json"
  python3 - "$VERSION" "$DIST" <<'PY'
import json,os,sys,datetime
v,d=sys.argv[1],sys.argv[2]; rows=[r for r in json.load(open(f"{d}/releases.json")) if r["version"]!=v]
rows.insert(0,{"version":v,"date":datetime.date.today().isoformat(),"size":os.path.getsize(f"{d}/pharos-{v}.zip"),"sha256":open(f"{d}/pharos-{v}.zip.sha256").read().split()[0]})
json.dump(rows,open(f"{d}/releases.json","w"),indent=1)
PY
  python3 scripts/release-page.py CHANGELOG.md "$DIST/latest.json" "$DIST/releases.json" > "$DIST/index.html"
  rsync -a "$DIST/index.html" "$DIST/releases.json" "$REMOTE/" && echo "release page live: ${BASE_URL}/"
else
  echo "== 6/6 upload skipped"
fi

if [ "$TAG" = 1 ]; then
  git tag -a "v${VERSION}" -m "Pharos ${VERSION}" 2>/dev/null && echo "tagged v${VERSION}" || echo "tag v${VERSION} already exists"
  git push -q origin "v${VERSION}" 2>/dev/null || true
  # GitHub Release carries the same artefacts as a mirror (assets are token-only while the repo is private)
  if command -v gh >/dev/null 2>&1; then
    awk -v v="$VERSION" '$0 ~ "^## \\[" v "\\]" {f=1; next} /^## \[/ {f=0} f' CHANGELOG.md > "$DIST/notes-${VERSION}.md"
    gh release view "v${VERSION}" >/dev/null 2>&1 || gh release create "v${VERSION}" --title "Pharos ${VERSION}" --notes-file "$DIST/notes-${VERSION}.md" \
      "$DIST/pharos-${VERSION}.zip" "$DIST/pharos-${VERSION}.zip.sha256" >/dev/null && echo "GitHub release v${VERSION} created"
  fi
fi

rm -rf "$(dirname "$STAGE")"
echo "done: pharos-${VERSION}.zip (${SIZE}) · sha256 · latest.json"
