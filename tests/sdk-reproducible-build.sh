#!/usr/bin/env bash
# Compare real SDK output from two independent clean installations of the reviewed npm lock.
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
work=$(mktemp -d)
trap 'rm -rf -- "$work"' EXIT
export CI=true TZ=UTC LC_ALL=C
for n in 1 2; do
  dir="$work/$n"
  mkdir -p "$dir"
  cp -a "$root/packages/ts-sdk/"{src,package.json,package-lock.json,tsconfig.json} "$dir/"
  npm ci --prefix "$dir" --include=dev --no-audit --no-fund
  npm run --prefix "$dir" build
  [[ -s "$dir/dist/index.js" && -s "$dir/dist/index.d.ts" ]]
  (cd "$dir"; find dist -type f -print0 | sort -z | xargs -0 -r sha256sum) > "$work/$n.sha256"
  cmp -- "$root/packages/ts-sdk/package-lock.json" "$dir/package-lock.json"
done
cmp -- "$work/1.sha256" "$work/2.sha256"
printf 'PASS: two independent SDK installations produced identical output checksums\n'
