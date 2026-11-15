#!/usr/bin/env bash
# Each strict scenario gets its own empty database, never the staging/source DB.
set -euo pipefail
if [[ $# -lt 1 || ${CAJEER_PG_TEST:-} != 1 || ! ${CAJEER_PG_TEST_DATABASE:-} =~ ^cajeer_test_[a-z0-9_]+$ ]]; then
    echo 'Refused: explicit disposable PostgreSQL test configuration required.' >&2
    exit 2
fi
cd "$(dirname "$0")/.."
database="cajeer_test_ci_$(openssl rand -hex 8)"
# The shared CI provisioner only accepts generated names and never the configured source DB.
CI=true php tests/ci-postgres-database.php create "$database"
cleanup() {
    local rc=$?
    trap - EXIT
    if ! CI=true php tests/ci-postgres-database.php drop "$database"; then
        echo "Acceptance database cleanup failed: $database" >&2
        rc=1
    fi
    exit "$rc"
}
trap cleanup EXIT
trap 'exit 143' TERM
trap 'exit 130' INT
CAJEER_PG_TEST_DATABASE="$database" "$@"
