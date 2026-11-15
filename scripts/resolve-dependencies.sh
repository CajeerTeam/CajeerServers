#!/usr/bin/env bash
# Maintainer-only; resolve on a trusted network-connected host. Never called by build-release.sh.
set -euo pipefail
cd "$(dirname "$0")/.."
source scripts/build-toolchain.sh
root=$PWD
# Resolve in a clean temporary directory, not against old node_modules (npm can omit platform optional dependencies).
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
mkdir -p "$stage/admin" "$stage/packages/ts-sdk"
for file in composer.json admin/package.json packages/ts-sdk/package.json; do cp -- "$file" "$stage/$file"; done
# Preserve existing locked versions unless a maintainer intentionally updates the manifest/lock.
for file in composer.lock admin/package-lock.json packages/ts-sdk/package-lock.json; do
  if [[ -e "$file" ]]; then [[ -f "$file" && ! -L "$file" ]] || exit 2; cp -- "$file" "$stage/$file"; fi
done
(
  cd "$stage"
  composer validate --no-check-lock
  composer update --no-install --no-interaction --no-scripts --no-plugins --prefer-dist
  composer validate --strict
  composer audit --locked --abandoned=fail
  for dir in admin packages/ts-sdk; do
    npm install --prefix "$dir" --package-lock-only --ignore-scripts --include=dev --include=optional --no-audit --no-fund
    npm ci --prefix "$dir" --ignore-scripts --include=dev --include=optional --no-audit --no-fund --dry-run
    npm audit --prefix "$dir" --package-lock-only --audit-level=high
  done
)
node scripts/verify-dependency-locks.mjs "$stage"
# No source lock is replaced until every resolver/validator succeeded.
for file in composer.lock admin/package-lock.json packages/ts-sdk/package-lock.json; do
  install -m 0644 "$stage/$file" "$root/$file.new"
  mv -f -- "$root/$file.new" "$root/$file"
done
printf 'Review and commit the three resolved lockfiles, then run bash scripts/build-release.sh in a clean checkout.\n'
