#!/usr/bin/env bash
set -euo pipefail
source_dir=$(cd "$(dirname "$0")/.." && pwd -P)
work=$(mktemp -d /tmp/sweepers-setup-test.XXXXXX)
trap 'rm -rf "$work"' EXIT
export REAL_DOCKER
REAL_DOCKER=$(command -v docker)
export TEST_ROOT="$work/azuracast"
export TEST_SOURCE="$source_dir"
mkdir -p "$TEST_ROOT/plugins/azuracast-sweepers/bin" "$work/bin"
cp "$source_dir/bin/setup.sh" "$source_dir/bin/setup-compose.mjs" "$TEST_ROOT/plugins/azuracast-sweepers/bin/"
ln -s "$source_dir/node_modules" "$TEST_ROOT/plugins/azuracast-sweepers/node_modules"
printf 'services:\n  web:\n    image: test\n' > "$TEST_ROOT/docker-compose.yml"
cat > "$work/bin/docker" <<'SH'
#!/usr/bin/env bash
set -eu
printf '%s\n' "$*" >> "$TEST_ROOT/commands"
if [[ "$1" == run && "$*" == *setup-compose.mjs* ]]; then
    exec "$REAL_DOCKER" run -v "$TEST_SOURCE:$TEST_SOURCE:ro" "${@:2}"
elif [[ "$1" == run ]]; then
    mkdir -p "$TEST_ROOT/plugins/azuracast-sweepers/dist"
    printf 'bundle\n' > "$TEST_ROOT/plugins/azuracast-sweepers/dist/app.js"
elif [[ "$1" == inspect && "$*" == *Mounts* ]]; then
    [[ "${TEST_STALE:-false}" == true ]] ||
        printf '%s /var/azuracast/www/plugins/azuracast-sweepers false\n' "$TEST_ROOT/plugins/azuracast-sweepers"
elif [[ "$1" == inspect && "$*" == *State.Running* ]]; then
    [[ "${TEST_STOPPED:-false}" == true ]] && printf 'false\n' || printf 'true\n'
elif [[ "$1" == inspect ]]; then
    printf 'COMPOSER_PLUGIN_MODE=true\n'
elif [[ "$1" == compose && "$2" == ps ]]; then
    [[ "${TEST_RECREATE:-false}" == true ]] || printf 'container\n'
elif [[ "$1" == compose && "$2" == up ]]; then
    [[ "${TEST_UP_FAILS:-false}" != true ]] || { echo 'Injected container start failure' >&2; exit 1; }
elif [[ "$*" == *'config --services'* && "${TEST_NO_WEB:-false}" == true ]]; then
    printf 'custom-web\nredis\n'
elif [[ "$*" == *'config --services'* ]]; then
    exec "$REAL_DOCKER" "$@"
elif [[ "$*" == *'config --quiet'* && "$*" == *'.sweepers-setup.'* && "${TEST_INVALID:-false}" == true ]]; then
    echo 'Injected invalid Compose configuration' >&2
    exit 1
elif [[ "$*" == *'config --quiet'* ]]; then
    exec "$REAL_DOCKER" "$@"
elif [[ "$1" == compose && "$*" == *'supervisorctl status'* ]]; then
    printf 'php-fpm RUNNING\nphp-worker-fast RUNNING\nphp-worker-slow RUNNING\nphp-nowplaying RUNNING\n'
fi
SH
printf '#!/usr/bin/env bash\nexit 0\n' > "$work/bin/sleep"
chmod +x "$work/bin/docker" "$work/bin/sleep"
export PATH="$work/bin:$PATH"
setup="$TEST_ROOT/plugins/azuracast-sweepers/bin/setup.sh"
bash -n "$source_dir/bin/setup.sh"
if TEST_NO_WEB=true bash "$setup" > "$work/result" 2>&1; then echo 'Missing web service unexpectedly accepted' >&2; exit 1; fi
grep -q "service 'web' not found" "$work/result"
[[ ! -e "$TEST_ROOT/docker-compose.override.yml" ]]
! grep -q '^run ' "$TEST_ROOT/commands"
bash "$setup" > "$work/result"
grep -q 'Plugin installed/updated' "$work/result"
grep -q 'supervisorctl restart php-worker-fast php-worker-slow php-nowplaying' "$TEST_ROOT/commands"
! grep -q 'compose up' "$TEST_ROOT/commands"
cp "$TEST_ROOT/docker-compose.override.yml" "$work/expected"
bash "$setup" > "$work/result"
cmp "$TEST_ROOT/docker-compose.override.yml" "$work/expected"
[[ $(find "$TEST_ROOT" -name '*.sweepers-backup.*' | wc -l) == 0 ]]
printf '# Existing override\nservices:\n  web:\n    environment:\n      OTHER: keep\n' > "$TEST_ROOT/docker-compose.override.yml"
cp "$TEST_ROOT/docker-compose.override.yml" "$work/original"
if TEST_INVALID=true bash "$setup" > "$work/result" 2>&1; then echo 'Invalid Compose unexpectedly accepted' >&2; exit 1; fi
cmp "$TEST_ROOT/docker-compose.override.yml" "$work/original"
bash "$setup" > "$work/result"
backup=$(find "$TEST_ROOT" -name '*.sweepers-backup.*')
cmp "$backup" "$work/original"
if TEST_RECREATE=true bash "$setup" < /dev/null > "$work/result" 2>&1; then echo 'Noninteractive recreation unexpectedly accepted' >&2; exit 1; fi
grep -q 'Interactive confirmation is required' "$work/result"
! grep -q 'compose up' "$TEST_ROOT/commands"
if command -v script >/dev/null; then
    printf 'n\n' | TEST_RECREATE=true script -qec "bash '$setup'" /dev/null > "$work/result"
    grep -q 'Prepared only; NOT applied' "$work/result"
    grep -q 'AzuraCast is not running' "$work/result"
    ! grep -q 'compose up' "$TEST_ROOT/commands"
    printf 'y\n' | TEST_RECREATE=true script -qec "bash '$setup'" /dev/null > "$work/result"
    grep -q 'Plugin installed/updated' "$work/result"
    grep -qx 'compose up -d' "$TEST_ROOT/commands"
    : > "$TEST_ROOT/commands"
    printf 'y\n' | TEST_STOPPED=true script -qec "bash '$setup'" /dev/null > "$work/result"
    grep -qx 'compose up -d' "$TEST_ROOT/commands"
    ! grep -q 'no-deps' "$TEST_ROOT/commands"
    : > "$TEST_ROOT/commands"
    printf 'y\n' | TEST_STALE=true script -qec "bash '$setup'" /dev/null > "$work/result"
    grep -q 'interrupts broadcasting' "$work/result"
    grep -qx 'compose up -d --no-deps web' "$TEST_ROOT/commands"
    printf '\n' | TEST_STALE=true script -qec "bash '$setup'" /dev/null > "$work/result"
    grep -q 'Prepared only; NOT applied' "$work/result"
    printf 'services:\n  web:\n    environment:\n      OTHER: keep\n' > "$TEST_ROOT/docker-compose.override.yml"
    if printf 'y\n' | TEST_STALE=true TEST_UP_FAILS=true script -qec "bash '$setup'" /dev/null > "$work/result" 2>&1; then
        echo 'Container start failure unexpectedly reported success' >&2; exit 1
    fi
    grep -q 'To revert it: cp -p' "$work/result"
    restore=$(grep -o 'cp -p "[^"]*" "[^"]*"' "$work/result" | tail -1)
    eval "$restore"
    grep -q 'OTHER: keep' "$TEST_ROOT/docker-compose.override.yml"
    ! grep -q 'azuracast-sweepers' "$TEST_ROOT/docker-compose.override.yml"
else
    echo 'The script utility is required to test interactive confirmation.' >&2
    exit 1
fi
if COMPOSE_FILE=custom.yml bash "$setup" > "$work/result" 2>&1; then echo 'Custom Compose unexpectedly accepted' >&2; exit 1; fi
grep -q 'Custom COMPOSE_FILE' "$work/result"
printf 'Setup workflow checks passed (mocked Docker lifecycle; no station restarted).\n'
