#!/usr/bin/env bash
# Build only from reviewed lockfiles. No update, fallback resolution or skipped tests.
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p storage/tmp
# A failed or concurrent invocation must not leave a previous success report as evidence.
exec 9>storage/tmp/release-build.lock
flock -n 9 || { echo 'Another release build is active' >&2; exit 2; }
rm -f storage/tmp/release-build.sha256 storage/tmp/release-build.tar.gz
inputs=(VERSION release.json composer.json composer.lock admin/package.json admin/package-lock.json packages/ts-sdk/package.json packages/ts-sdk/package-lock.json .nvmrc core/Connector/agents/requirements-linux-py312.lock)
for file in "${inputs[@]}"; do
  [[ -s "$file" && ! -L "$file" ]] || { echo "Missing reviewed build input: $file (run maintainer resolution first)" >&2; exit 2; }
done
source scripts/build-toolchain.sh
export PYTHONDONTWRITEBYTECODE=1
node scripts/verify-dependency-locks.mjs
composer validate --strict
before=$(sha256sum "${inputs[@]}")
# Require a clean committed source tree for release provenance. Local archives may build but are labeled uncommitted.
commit=unavailable
if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  commit=$(git rev-parse HEAD)
  [[ -z $(git status --porcelain --untracked-files=normal) ]] || { echo 'Build requires a clean committed checkout' >&2; exit 2; }
fi
# Audit before dependency scripts run; vulnerabilities or network errors fail the build.
composer audit --locked --abandoned=fail
npm audit --prefix admin --package-lock-only --audit-level=high
npm audit --prefix packages/ts-sdk --package-lock-only --audit-level=high
python3 scripts/audit-python-lock.py
# A fresh isolated agent environment verifies every downloaded wheel hash.
agent_env=$(mktemp -d "$PWD/storage/tmp/release-agent.XXXXXX")
report=''
archive=''
trap 'rm -rf -- "$agent_env"; if [[ -n "$report" ]]; then rm -f -- "$report"; fi; if [[ -n "$archive" ]]; then rm -f -- "$archive"; fi' EXIT
python3 -m venv "$agent_env"
bash scripts/install-agent-dependencies.sh "$agent_env/bin/python"
export PATH="$agent_env/bin:$PATH"
# Eliminate stale binaries, generated output and installed Composer packages from prior builds.
rm -rf -- vendor admin/.nuxt admin/.output packages/ts-sdk/dist
composer install --no-interaction --prefer-dist --optimize-autoloader --no-plugins --no-scripts
composer check-platform-reqs
npm ci --prefix admin --include=dev --include=optional --no-audit --no-fund
npm ci --prefix packages/ts-sdk --include=dev --include=optional --no-audit --no-fund
npm run --prefix packages/ts-sdk build
npm run --prefix admin typecheck
npm run --prefix admin build
# Exercise the actual build output, not only source syntax.
node scripts/smoke-admin-build.mjs
bash tests/connector-sdk-integration.sh
bash tests/sdk-reproducible-build.sh
vendor/bin/phpunit
php scripts/verify-release.php
[[ -s packages/ts-sdk/dist/index.js && -s packages/ts-sdk/dist/index.d.ts && -s admin/.output/server/index.mjs ]] || { echo 'Build outputs missing' >&2; exit 1; }
[[ "$before" == "$(sha256sum "${inputs[@]}")" ]] || { echo 'Build inputs changed during build' >&2; exit 1; }
if [[ $commit != unavailable ]]; then
  [[ $commit == "$(git rev-parse HEAD)" && -z $(git status --porcelain --untracked-files=normal) ]] || { echo 'Source changed during build' >&2; exit 1; }
fi
# Package exactly the compiled frontend + SDK outputs. Normalize archive metadata;
# Nuxt output bytes can still vary across platforms or compiler versions.
archive=$(mktemp storage/tmp/release-artifact.XXXXXX)
tar --sort=name --mtime=@0 --owner=0 --group=0 --numeric-owner -cf - admin/.output packages/ts-sdk/dist | gzip -n > "$archive"
tar -tzf "$archive" >/dev/null
report=$(mktemp storage/tmp/release-build.XXXXXX)
{
  printf 'version=%s\nsource_commit=%s\n' "$(cat VERSION)" "$commit"
  php -r 'echo "php=".PHP_VERSION.PHP_EOL;'
  printf 'node=%s\nnpm=%s\npython=%s\n' "$(node -v)" "$(npm -v)" "$(python3 --version)"
  composer --version --no-ansi
  printf '%s\n' "$before"
  printf '%s  storage/tmp/release-build.tar.gz\n' "$(sha256sum "$archive" | cut -d' ' -f1)"
  printf 'dependency_inventory=lockfiles_above\n'
  find vendor admin/.output packages/ts-sdk/dist -type f -print0 | sort -z | xargs -0 -r sha256sum
} > "$report"
mv "$archive" storage/tmp/release-build.tar.gz
mv "$report" storage/tmp/release-build.sha256
printf 'Locked build and tests completed; checksums: storage/tmp/release-build.sha256\n'
