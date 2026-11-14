#!/usr/bin/env bash
# Shared by maintainer resolution and release build; never silently substitute a tool.
set -euo pipefail
ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
for tool in php composer node npm python3 sha256sum git flock; do
  command -v "$tool" >/dev/null || { echo "Missing build tool: $tool" >&2; exit 2; }
done
[[ $(node -p 'process.versions.node') == "$(cat "$ROOT/.nvmrc")" ]] || { echo 'Use the exact Node version in .nvmrc' >&2; exit 2; }
[[ $(npm --version) == 10.9.9 ]] || { echo 'npm 10.9.9 required' >&2; exit 2; }
[[ $(composer --version --no-ansi) =~ ^Composer\ version\ 2\.11\.2([[:space:]]|$) ]] || { echo 'Composer 2.11.2 required' >&2; exit 2; }
php -r 'if(PHP_VERSION_ID<80400 || PHP_VERSION_ID>=80600){fwrite(STDERR,"PHP 8.4 or 8.5 required\n");exit(2);} foreach(["dom","xml","xmlwriter","mbstring","json","pdo","openssl","sodium"] as $ext){if(!extension_loaded($ext)){fwrite(STDERR,"Missing PHP extension: $ext\n");exit(2);}}'
python3 -c 'import sys; sys.exit(0 if sys.version_info[:2] == (3, 12) else "Python 3.12 required")'
export CI=true TZ=UTC LC_ALL=C
