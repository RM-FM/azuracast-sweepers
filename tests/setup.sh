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
cp "$source_dir/detector.version" "$TEST_ROOT/plugins/azuracast-sweepers/"
# Plugin scenarios first, without the Intro Detector question; its own scenarios follow at the end.
export SWEEPERS_DETECTOR_DIR="$work/detector"
: > "$TEST_ROOT/plugins/azuracast-sweepers/.detector-declined"
ln -s "$source_dir/node_modules" "$TEST_ROOT/plugins/azuracast-sweepers/node_modules"
printf 'services:\n  web:\n    image: test\n' > "$TEST_ROOT/docker-compose.yml"
cat > "$work/bin/docker" <<'SH'
#!/usr/bin/env bash
set -eu
printf '%s\n' "$*" >> "$TEST_ROOT/commands"
if [[ "$1" == run || "$*" == *supervisorctl* ]]; then printf 'TECHNICAL_OUTPUT_TEST\n'; fi
printf 'TECHNICAL_ERROR_TEST\n' >&2
if [[ "$1" == run && "$*" == *setup-compose.mjs* ]]; then
    exec "$REAL_DOCKER" run -v "$TEST_SOURCE:$TEST_SOURCE:ro" "${@:2}"
elif [[ "$1" == run ]]; then
    mkdir -p "$TEST_ROOT/plugins/azuracast-sweepers/dist"
    printf 'bundle\n' > "$TEST_ROOT/plugins/azuracast-sweepers/dist/app.js"
    ln -sfn "$TEST_SOURCE/node_modules" "$TEST_ROOT/plugins/azuracast-sweepers/node_modules"
elif [[ "$*" == *'sweepers:detector-account --new-key'* ]]; then
    printf 'https://radio.example\n0123abcd:4567ef89\n'
elif [[ "$*" == *'sweepers:detector-account --sync'* ]]; then
    printf 'synced\n' >> "$TEST_ROOT/detector-sync"
elif [[ "$1" == ps && "$*" == *ancestor=azuracast-intro-detector* ]]; then
    [[ -z "${TEST_DETECTOR_RUNNING:-}" ]] || printf '%s\n' "$TEST_DETECTOR_RUNNING"
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
grep -q 'Preparing plugin files' "$work/result"
[[ ! -e "$TEST_ROOT/plugins/azuracast-sweepers/node_modules" ]]
grep -q 'Applying plugin update' "$work/result"
! grep -q 'TECHNICAL_' "$work/result"
grep -q 'supervisorctl restart php-worker-fast php-worker-slow php-nowplaying' "$TEST_ROOT/commands"
! grep -q 'compose up' "$TEST_ROOT/commands"
cp "$TEST_ROOT/docker-compose.override.yml" "$work/expected"
bash "$setup" > "$work/result"
cmp "$TEST_ROOT/docker-compose.override.yml" "$work/expected"
[[ $(find "$TEST_ROOT" -name '*.sweepers-backup.*' | wc -l) == 0 ]]
printf '# Existing override\nservices:\n  web:\n    logging: !reset\n    ports: !override []\n    environment:\n      OTHER: keep\n' > "$TEST_ROOT/docker-compose.override.yml"
cp "$TEST_ROOT/docker-compose.override.yml" "$work/original"
if TEST_INVALID=true bash "$setup" > "$work/result" 2>&1; then echo 'Invalid Compose unexpectedly accepted' >&2; exit 1; fi
cmp "$TEST_ROOT/docker-compose.override.yml" "$work/original"
bash "$setup" > "$work/result"
grep -q 'logging: !reset' "$TEST_ROOT/docker-compose.override.yml"
grep -q 'ports: !override' "$TEST_ROOT/docker-compose.override.yml"
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
        log=$(grep -o '/[^[:space:]]*sweepers-setup\.[^[:space:]]*\.log' "$work/result" | tail -1 | tr -d '\r')
        [[ -f "$log" ]]
        grep -q 'Injected container start failure' "$log"
        rm -f "$log"
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

plugin="$TEST_ROOT/plugins/azuracast-sweepers"
detector_source="$work/detector-source"
release=$(cat "$source_dir/detector.version")
mkdir -p "$detector_source/bin"
git_test() { git -C "$1" -c user.name=test -c user.email=test@example.invalid "${@:2}"; }
fake_script() {
    cat > "$detector_source/bin/$1.sh" <<SH
#!/usr/bin/env bash
printf '%s\n' "\$*" > "\$TEST_ROOT/detector-$1-args"
cat > "\$TEST_ROOT/detector-$1-input"
SH
}
# The first release has no update script, like detector installations before 1.0.
fake_script setup
git -C "$detector_source" init -q
git -C "$detector_source" add -A
git_test "$detector_source" commit -qm legacy
git -C "$detector_source" tag v0.1.0
fake_script update
git -C "$detector_source" add -A
git_test "$detector_source" commit -qm release
git -C "$detector_source" tag "$release"
export SWEEPERS_DETECTOR_REPO="$detector_source"

legacy="$work/legacy-detector"
git clone -q "$detector_source" "$legacy"
git -C "$legacy" -c advice.detachedHead=false checkout -q v0.1.0
SWEEPERS_DETECTOR_DIR="" TEST_DETECTOR_RUNNING="$legacy" bash "$setup" < /dev/null > "$work/result"
grep -q "Updating the Intro Detector in $legacy" "$work/result"
[[ $(git -C "$legacy" describe --tags) == "$release" ]]
grep -qx -- "--azuracast-dir $TEST_ROOT --unattended" "$TEST_ROOT/detector-setup-args"
grep -qx '0123abcd:4567ef89' "$TEST_ROOT/detector-setup-input"
[[ ! -e "$TEST_ROOT/detector-update-args" ]]
git -C "$legacy" -c advice.detachedHead=false checkout -q v0.1.0
if SWEEPERS_DETECTOR_DIR="" TEST_DETECTOR_RUNNING="$legacy" SWEEPERS_DETECTOR_VERSION=v9.9.9 \
    bash "$setup" < /dev/null > "$work/result" 2>&1; then
    echo 'Missing detector release unexpectedly accepted' >&2; exit 1
fi
[[ $(git -C "$legacy" describe --tags) == v0.1.0 ]]
if SWEEPERS_DETECTOR_DIR="" TEST_DETECTOR_RUNNING=$'/srv/one\n/srv/two' bash "$setup" < /dev/null > "$work/result" 2>&1; then
    echo 'Several detector installations unexpectedly accepted' >&2; exit 1
fi
grep -q 'Several Intro Detector installations' "$work/result"
rm -f "$TEST_ROOT"/detector-*-args "$TEST_ROOT"/detector-*-input "$TEST_ROOT/detector-sync"

rm -f "$plugin/.detector-declined"
bash "$setup" < /dev/null > "$work/result"
grep -q 'sudo bash bin/setup.sh --detector' "$work/result"
grep -q 'already runs on another server, answer N' "$work/result"
[[ ! -e "$plugin/.detector-declined" && ! -e "$SWEEPERS_DETECTOR_DIR" ]]
printf 'n\n' | script -qec "bash '$setup'" /dev/null > "$work/result"
[[ -e "$plugin/.detector-declined" && ! -e "$SWEEPERS_DETECTOR_DIR" ]]
printf 'n\n' | script -qec "bash '$setup'" /dev/null > "$work/result"
! grep -q 'Install the Intro Detector' "$work/result"
printf 'y\n' | script -qec "bash '$setup' --detector" /dev/null > "$work/result"
grep -q 'Intro Detector installed' "$work/result"
[[ ! -e "$plugin/.detector-declined" ]]
[[ $(git -C "$SWEEPERS_DETECTOR_DIR" describe --tags) == "$(cat "$source_dir/detector.version")" ]]
grep -qx -- "--azuracast-dir $TEST_ROOT --unattended" "$TEST_ROOT/detector-setup-args"
grep -qx 'https://radio.example' "$TEST_ROOT/detector-setup-input"
grep -qx '0123abcd:4567ef89' "$TEST_ROOT/detector-setup-input"
grep -q synced "$TEST_ROOT/detector-sync"
! grep -q '4567ef89' "$work/result"
bash "$setup" < /dev/null > "$work/result"
grep -q 'Updating the Intro Detector' "$work/result"
grep -qx -- "--version $(cat "$source_dir/detector.version") --azuracast-dir $TEST_ROOT" "$TEST_ROOT/detector-update-args"
grep -qx '0123abcd:4567ef89' "$TEST_ROOT/detector-update-input"
printf '{}' > "$plugin/.detector.json"
bash "$setup" < /dev/null > "$work/result"
[[ ! -s "$TEST_ROOT/detector-update-input" ]]
[[ $(grep -c synced "$TEST_ROOT/detector-sync") == 3 ]]
printf 'Setup workflow checks passed (mocked Docker lifecycle; no station restarted).\n'
