#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
php -r 'if (!extension_loaded("snmp")) {fwrite(STDERR,"ext-snmp is required\n");exit(1);}'
command -v snmpd >/dev/null || { echo 'snmpd is required' >&2; exit 1; }
tmp=$(mktemp -d); chmod 700 "$tmp"
pid=''
cleanup(){ if [[ -n "$pid" ]]; then kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; fi; rm -rf "$tmp"; }
trap cleanup EXIT
port=$(python3 -c 'import socket; s=socket.socket(socket.AF_INET,socket.SOCK_DGRAM); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')
cat > "$tmp/snmpd.conf" <<CONF
agentAddress udp:127.0.0.1:$port
sysName cajeer-snmp-fixture
rocommunity cajeer-test-community 127.0.0.1 .1
createUser cajeer-reader SHA-256 cajeer-test-auth AES cajeer-test-priv
rouser cajeer-reader priv .1
CONF
SNMP_PERSISTENT_DIR="$tmp" MIBS='' snmpd -f -Lo -C -c "$tmp/snmpd.conf" -p "$tmp/pid" >"$tmp/log" 2>&1 &
pid=$!
sleep 1
kill -0 "$pid" || { cat "$tmp/log" >&2; exit 1; }
MIBS='' php tests/snmp-protocol-integration.php "$port"
kill "$pid"; wait "$pid" || true; pid=''
MIBS='' php tests/snmp-protocol-integration.php "$port" --offline
