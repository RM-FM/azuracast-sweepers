#!/usr/bin/env bash
# Proves the fail-safe premise with the real Liquidsoap binary: if an AzuraCast upgrade no longer provides
# what the module uses, `liquidsoap --check -` (AzuraCast's verifyConfig) exits with 1, and the plugin's
# exact rollback of the same configuration is accepted again.
set -euo pipefail
plugin=$(cd "$(dirname "$0")/.." && pwd)
runtime=$(cd "${1:?Usage: tests/failsafe.sh RUNTIME_DIR}" && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
mkdir "$work/upgraded"
cp "$runtime"/*.liq "$work/upgraded/"
sed -i 's/autodj_next_song/autodj_next_song_renamed/g' "$work/upgraded/azuracast.liq"
grep -q 'autodj_next_song_renamed' "$work/upgraded/azuracast.liq"

check() {
    docker run --rm -i --entrypoint liquidsoap -v "$plugin:/plugin:ro" -v "$1:/azuracast:ro" \
        savonet/liquidsoap:v2.4.5 --check - <"$2" >"$work/check.log" 2>&1
}

docker run --rm -i --entrypoint php -v "$plugin:/plugin:ro" php:8.4-cli /plugin/tests/rollback.php \
    <"$plugin/tests/liquidsoap.liq" >"$work/rolled.liq"
if grep -q 'sweepers' "$work/rolled.liq"; then
    echo 'Rollback left plugin lines behind.' >&2
    exit 1
fi

check "$runtime" "$plugin/tests/liquidsoap.liq" || { cat "$work/check.log"; echo 'Module rejected by the pinned runtime.' >&2; exit 1; }
status=0
check "$work/upgraded" "$plugin/tests/liquidsoap.liq" || status=$?
if [ "$status" -ne 1 ]; then
    cat "$work/check.log"
    echo "Expected exit code 1 for an incompatible runtime, got $status." >&2
    exit 1
fi
check "$work/upgraded" "$work/rolled.liq" || { cat "$work/check.log"; echo 'Rolled-back configuration rejected.' >&2; exit 1; }
echo 'Fail-safe premise verified: incompatible module exits 1; rolled-back configuration passes.'
