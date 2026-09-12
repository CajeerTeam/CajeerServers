#!/usr/bin/env bash
set -euo pipefail
printf '{"hostname":"%s","kernel":"%s","uptime":"%s"}
' "$(hostname)" "$(uname -r)" "$(uptime -p)"
