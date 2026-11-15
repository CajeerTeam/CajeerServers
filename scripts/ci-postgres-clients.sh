#!/usr/bin/env bash
# The matching client lives in the already running PostgreSQL service image.
# Avoid runner apt package collisions and keep pg_dump/restore streaming over stdin/stdout.
set -euo pipefail
[[ ${GITHUB_ACTIONS:-} == true && ${PG_CONTAINER:-} =~ ^[0-9a-f]{64}$ && -n ${GITHUB_ENV:-} ]]
dir=$(mktemp -d "$RUNNER_TEMP/cajeer-pg-client.XXXXXX")
for binary in pg_dump pg_restore; do
  cat > "$dir/$binary" <<EOF
#!/bin/bash
exec /usr/bin/docker exec -i -e PGPASSWORD -e PGSSLMODE -e PGCONNECT_TIMEOUT "$PG_CONTAINER" $binary "\$@"
EOF
  chmod 0755 "$dir/$binary"
  "$dir/$binary" --version
 done
printf 'PG_DUMP_BINARY=%s/pg_dump\nPG_RESTORE_BINARY=%s/pg_restore\n' "$dir" "$dir" >> "$GITHUB_ENV"
