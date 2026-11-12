#!/usr/bin/env bash
# Maintainer-only: create or update dependency locks on a trusted network-connected build host.
# Review the resulting locks, commit them, then run scripts/build-release.sh in a clean checkout.
set -euo pipefail
cd "$(dirname "$0")/.."
command -v composer >/dev/null || { echo 'Composer is required' >&2; exit 2; }
command -v npm >/dev/null || { echo 'npm is required' >&2; exit 2; }
composer validate
composer update --no-install --no-interaction --no-scripts --prefer-dist
npm install --prefix admin --package-lock-only --ignore-scripts --no-audit --no-fund
npm install --prefix packages/ts-sdk --package-lock-only --ignore-scripts --no-audit --no-fund
composer validate --strict
printf 'Review and commit composer.lock, admin/package-lock.json and packages/ts-sdk/package-lock.json.\nThen run bash scripts/build-release.sh from a clean checkout.\n'
