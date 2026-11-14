#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d); trap 'rm -rf "$tmp"' EXIT
cd "$root/packages/ts-sdk"
compiler=node_modules/.bin/tsc
[[ -x "$compiler" ]] || { echo 'Install SDK dependencies with npm ci before testing' >&2; exit 2; }
[[ $("$compiler" --version) == 'Version 5.8.3' ]] || { echo 'SDK compiler version does not match the lock' >&2; exit 2; }
"$compiler" -p tsconfig.json --outDir "$tmp"
printf '{"type":"module"}\n' > "$tmp/package.json"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -keyout "$tmp/key.pem" -out "$tmp/cert.pem" -subj /CN=127.0.0.1 -addext subjectAltName=IP:127.0.0.1 >/dev/null 2>&1
cp "$root/tests/connector-sdk-integration.mjs" "$tmp/test.mjs"
NODE_EXTRA_CA_CERTS="$tmp/cert.pem" node "$tmp/test.mjs" "$tmp"
