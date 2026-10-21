#!/usr/bin/env bash
# Builds the deployable site: production Composer dependencies installed, development files left out, and
# composer.json/composer.lock removed so a server-side "composer install" can never run or add packages.
#   tools/build-release.sh            -> dist/site/ and dist/patterns-site-<version>.zip
# The bundle carries its own .gitignore (never the development one, which ignores vendor/), so the deploy branch
# committed from it holds vendor/ and the storage and vault backstops; the build fails rather than ship without them.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
out="$root/dist/site"
version="$(git -C "$root" describe --always --dirty 2>/dev/null || date -u +%Y%m%d%H%M)"

rm -rf "$root/dist"
mkdir -p "$out"

# rsync applies the first rule that matches, so each include comes before the exclude it makes an exception to.
rsync -a "$root/" "$out/" \
  --exclude '.git/' --exclude '.github/' --exclude 'dist/' --exclude 'node_modules/' --exclude 'vendor/' \
  --exclude 'tests/' --exclude 'tools/phpunit/' --exclude 'tools/e2e/' --exclude 'docs/' --exclude 'design/' \
  --exclude '/.gitignore' \
  --include '/storage/.htaccess' --include '/storage/README.md' --exclude '/storage/*' \
  --include '/downloads-vault/README.md' --exclude '/downloads-vault/*' \
  --exclude '/tools/og/' --exclude '/tools/dev-router.php' --exclude '/tools/check-mysql.php' \
  --exclude '/tools/build-release.sh' --exclude '/tools/flush-cdn.sh' --exclude '/config/dev.example.php' \
  --exclude 'config/local.php' --exclude 'phpunit.xml' --exclude '.phpunit.cache/' --exclude 'package*.json' \
  --exclude 'playwright.config.*' --exclude '*.swp' --exclude '.DS_Store'

(cd "$out" && composer install --no-dev --prefer-dist --optimize-autoloader --classmap-authoritative --no-interaction --no-progress --quiet)
# A failed archive download makes Composer clone the package instead, which ships its .git and tests.
if find "$out/vendor" -name .git -type d | grep -q .; then
  echo 'Composer installed packages from source (vendor/*/.git): the bundle is not built.' >&2
  exit 1
fi
rm -f "$out/composer.json" "$out/composer.lock"
mkdir -p "$out/storage" "$out/downloads-vault"
printf '%s\n' "$version" > "$out/VERSION"

# The deploy branch's own ignore list: server-side data never committed, and vendor/ never ignored.
printf '%s\n' '/config/local.php' '/storage/*' '!/storage/.htaccess' '!/storage/README.md' \
  '/downloads-vault/*' '!/downloads-vault/README.md' > "$out/.gitignore"

for f in storage/.htaccess storage/README.md downloads-vault/README.md vendor/autoload.php vendor/anthropic-ai/sdk/src/Client.php; do
  [ -f "$out/$f" ] || { echo "The bundle is missing $f." >&2; exit 1; }
done

(cd "$out" && find . -name '*.php' -print0 | xargs -0 -n 50 php -l > /dev/null)
(cd "$root/dist/site" && zip -qr "../patterns-site-$version.zip" .)
echo "Built dist/site and dist/patterns-site-$version.zip"
