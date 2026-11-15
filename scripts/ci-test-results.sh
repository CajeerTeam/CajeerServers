#!/usr/bin/env bash
# Source in an Actions integration step. Run every scenario and propagate failure.
# In particular exit 77 (SKIP) is a failure, never a successful integration check.
ci_failed=0
ci_passed=0
ci_test() {
    local rc
    printf '::group::%s\n' "$*"
    if [[ ${CAJEER_CI_ISOLATE_PG:-} == 1 ]]; then
        local database="cajeer_test_ci_$(openssl rand -hex 8)"
        local result=0
        if php tests/ci-postgres-database.php create "$database"; then
            CAJEER_PG_TEST_DATABASE="$database" "$@" || result=$?
            php tests/ci-postgres-database.php drop "$database" || result=1
        else
            result=1
        fi
        (exit "$result") && rc=0 || rc=$?
    else
        "$@" && rc=0 || rc=$?
    fi
    if [[ $rc -eq 0 ]]; then
        ci_passed=$((ci_passed+1))
        printf 'PASS: %s\n' "$*"
    else
        ci_failed=$((ci_failed+1))
        printf '::error::%s failed (exit %d)\n' "$*" "$rc" >&2
    fi
    printf '::endgroup::\n'
}
ci_finish() {
    printf 'Integration scenarios: %d passed, %d failed\n' "$ci_passed" "$ci_failed"
    [[ $ci_failed -eq 0 ]]
}
