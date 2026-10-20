#!/usr/bin/env bash
# Builds the deployable site: production Composer dependencies installed, development files left out, and
# composer.json/composer.lock removed so a server-side "composer install" can never run or add packages.
#   tools/build-release.sh            -> dist/site/ and dist/patterns-site-<version>.zip
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
out="$root/dist/site"
version="$(git -C "$root" describe --always --dirty 2>/dev/null || date -u +%Y%m%d%H%M)"

rm -rf "$root/dist"
mkdir -p "$out"

rsync -a "$root/" "$out/" \
  --exclude '.git/' --exclude '.github/' --exclude 'dist/' --exclude 'node_modules/' --exclude 'vendor/' \
  --exclude 'tests/' --exclude 'tools/phpunit/' --exclude 'tools/e2e/' --exclude 'docs/' --exclude 'design/' \
  --exclude 'storage/*' --include 'storage/.htaccess' --include 'storage/README.md' \
  --exclude 'downloads-vault/*' --include 'downloads-vault/README.md' \
  --exclude 'config/local.php' --exclude 'phpunit.xml' --exclude '.phpunit.cache/' --exclude 'package*.json' \
  --exclude 'playwright.config.*' --exclude '*.swp' --exclude '.DS_Store'

(cd "$out" && composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-progress --quiet)
rm -f "$out/composer.json" "$out/composer.lock"
mkdir -p "$out/storage" "$out/downloads-vault"
printf '%s\n' "$version" > "$out/VERSION"

(cd "$out" && find . -name '*.php' -print0 | xargs -0 -n 50 php -l > /dev/null)
(cd "$root/dist/site" && zip -qr "../patterns-site-$version.zip" .)
echo "Built dist/site and dist/patterns-site-$version.zip"
