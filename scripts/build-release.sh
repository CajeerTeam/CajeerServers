#!/usr/bin/env bash
# Build from reviewed, committed lockfiles. Never resolve/update dependencies in this script.
set -euo pipefail
cd "$(dirname "$0")/.."
for tool in php composer node npm sha256sum; do command -v "$tool" >/dev/null || { echo "Missing build tool: $tool" >&2; exit 2; }; done
for file in composer.lock admin/package-lock.json packages/ts-sdk/package-lock.json; do
  [[ -s "$file" && ! -L "$file" ]] || { echo "Missing reviewed lockfile: $file (resolve on trusted build host first)" >&2; exit 2; }
done
node -e 'if(process.versions.node.split(".")[0]!=="22")process.exit(1)' || { echo 'Node.js 22 required' >&2; exit 2; }
php -r 'if(PHP_VERSION_ID<80400)exit(1);' || { echo 'PHP 8.4+ required' >&2; exit 2; }
export CI=true TZ=UTC LC_ALL=C
before=$(sha256sum composer.lock admin/package-lock.json packages/ts-sdk/package-lock.json)
composer validate --strict
composer install --no-interaction --prefer-dist --optimize-autoloader
npm ci --prefix admin --no-audit --no-fund
npm ci --prefix packages/ts-sdk --no-audit --no-fund
npm run --prefix packages/ts-sdk build
npm run --prefix admin typecheck
npm run --prefix admin build
[[ "$before" == "$(sha256sum composer.lock admin/package-lock.json packages/ts-sdk/package-lock.json)" ]] || { echo 'Lockfiles changed during build' >&2; exit 1; }
php scripts/verify-release.php
# Record exact inputs and output checksums for this build. This is provenance, not a claim of bit-for-bit Nuxt determinism.
mkdir -p storage/tmp
report=$(mktemp storage/tmp/release-build.XXXXXX)
trap 'rm -f "$report"' EXIT
{
  printf 'version=%s\n' "$(cat VERSION)"
  php -r 'echo "php=".PHP_VERSION.PHP_EOL;'
  printf 'node=%s\nnpm=%s\n' "$(node -v)" "$(npm -v)"
  composer --version --no-ansi
  printf '%s\n' "$before"
  find admin/.output packages/ts-sdk/dist -type f -print0 | sort -z | xargs -0 sha256sum
} > "$report"
mv "$report" storage/tmp/release-build.sha256
printf 'Locked build completed; checksums: storage/tmp/release-build.sha256\n'
