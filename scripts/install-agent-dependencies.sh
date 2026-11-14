#!/usr/bin/env bash
# Use the interpreter of a newly created, operator-owned virtualenv (CI can pass its isolated interpreter).
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
python=${1:-python3}
"$python" - <<'PY'
import platform, sys
if sys.version_info[:2] != (3,12) or platform.python_implementation() != 'CPython' or platform.system() != 'Linux' or platform.machine() != 'x86_64':
    raise SystemExit('Locked agent dependencies require CPython 3.12 on Linux x86_64')
lib, version = platform.libc_ver()
if lib != 'glibc' or tuple(map(int, version.split('.'))) < (2,34):
    raise SystemExit('Locked agent dependencies require glibc >= 2.34')
if sys.prefix == sys.base_prefix:
    raise SystemExit('Use a fresh virtualenv, not the system interpreter')
PY
"$python" -m pip --isolated install --require-hashes --only-binary=:all: --force-reinstall --index-url https://pypi.org/simple -r "$root/core/Connector/agents/requirements-linux-py312.lock"
"$python" -m pip check
"$python" - <<'PY'
import paramiko, bcrypt, cryptography, invoke, nacl, cffi, pycparser
assert paramiko.__version__ == '5.0.1'
print('SSH agent dependencies imported successfully')
PY
