#!/usr/bin/env bash
# Run once on a trusted, network-connected build host and REVIEW the resulting lockfiles.
# Never run during a production request or replace resolved versions with invented locks.
set -euo pipefail
cd "$(dirname "$0")/.."
command -v composer >/dev/null || { echo 'Composer is required' >&2; exit 2; }
command -v npm >/dev/null || { echo 'npm is required' >&2; exit 2; }
composer validate
composer update --no-install --no-interaction --no-scripts --prefer-dist
(
  cd admin
  npm install --package-lock-only --ignore-scripts --no-audit --no-fund
  npm ci --no-audit --no-fund
  npm run typecheck
  npm run build
)
composer validate --strict
composer install --no-interaction --prefer-dist
php scripts/verify-release.php
echo 'Dependency locks resolved, dependencies installed, native checks and Nuxt build completed.'
echo 'Review composer.lock and admin/package-lock.json before committing or publishing.'
