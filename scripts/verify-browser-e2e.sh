#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../admin"
npm run test:e2e:preflight
[[ ${E2E_CONNECTOR_UI:-} == 1 ]] || { echo 'Critical collection/connector E2E must be enabled' >&2; exit 2; }
[[ -x node_modules/.bin/playwright ]] || { echo 'Install locked Playwright dependencies first' >&2; exit 2; }
report=$(mktemp)
trap 'rm -f "$report"' EXIT
# Do not publish report contents: test results may contain staging credentials.
if ! node_modules/.bin/playwright test --reporter=json > "$report"; then
  echo 'Playwright failed; inspect private staging test-results.' >&2; exit 1
fi
node tests/e2e/verify-report.mjs "$report"
