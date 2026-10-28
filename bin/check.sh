#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

if command -v php >/dev/null 2>&1; then
    php_command=(php)
elif command -v docker >/dev/null 2>&1; then
    php_command=(docker run --rm -v "$PWD:/plugin:ro" -w /plugin php:8.4-cli php)
else
    echo "PHP 8.4 or Docker is required for backend checks." >&2
    exit 1
fi

while IFS= read -r file; do
    "${php_command[@]}" -l "$file"
done < <(find src tests -name '*.php')
"${php_command[@]}" -l events.php
"${php_command[@]}" tests/run.php
if [[ -f vendor/autoload.php ]]; then
    "${php_command[@]}" tests/navigation.php
    "${php_command[@]}" tests/routes.php
    "${php_command[@]}" tests/arranger.php
    "${php_command[@]}" tests/reservations.php
    "${php_command[@]}" tests/settings.php
    "${php_command[@]}" tests/release.php
fi
docker run --rm --user "$(id -u):$(id -g)" -e npm_config_cache=/tmp/npm-cache \
    -v "$PWD:/plugin" -w /plugin node:24-alpine \
    sh -c 'npm ci --ignore-scripts && npm test && npm run build'

if [[ "${1:-}" == "--liquidsoap" ]]; then
    runtime_dir=$(mktemp -d)
    trap 'rm -rf "$runtime_dir"' EXIT
    revision=19d68c47b1e4d569885ca7cf4c40609cbabefda7
    for file in azuracast crossfade utilities; do
        curl --fail --silent --show-error --location \
            "https://raw.githubusercontent.com/AzuraCast/AzuraCast/$revision/util/docker/stations/liquidsoap/$file.liq" \
            --output "$runtime_dir/$file.liq"
    done
    docker run --rm --entrypoint liquidsoap \
        -v "$PWD:/plugin:ro" -v "$runtime_dir:/azuracast:ro" \
        savonet/liquidsoap:v2.4.5 --check /plugin/tests/liquidsoap.liq
    bash tests/failsafe.sh "$runtime_dir"
fi
