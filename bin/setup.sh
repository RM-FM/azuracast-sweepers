#!/usr/bin/env bash
set -euo pipefail

plugin_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)
azuracast_dir=$(cd "$plugin_dir/../.." && pwd -P)
override="$azuracast_dir/docker-compose.override.yml"

log=$(mktemp "${TMPDIR:-/tmp}/sweepers-setup.XXXXXX.log")
exec 3>&1 4>&2
exec >> "$log" 2>&1
status() { printf "$@" >&3; }
fail() {
    printf 'ERROR: %s\n' "$*" >&4
    on_error
    exit 1
}
backup=""
on_error() {
    printf 'Setup could not be completed. Technical details: %s\n' "$log" >&4
    if [[ -n "$backup" ]]; then
        printf 'The Compose override was already replaced. To revert it: cp -p "%s" "%s"\n' "$backup" "$override" >&4
    fi
}
trap on_error ERR
work=""
cleanup() {
    result=$?
    if [[ -n "$work" ]]; then
        rm -f "$work/original" "$work/docker-compose.override.yml" "$work/services"
        rmdir "$work"
    fi
    if [[ "$result" == 0 ]]; then rm -f "$log"; fi
}
trap cleanup EXIT
status 'Checking installation...\n'

[[ $# == 0 ]] || fail "Usage: bash bin/setup.sh (run after cloning or pulling the plugin)."
[[ "$plugin_dir" == "$azuracast_dir/plugins/azuracast-sweepers" ]] ||
    fail "Keep this checkout at AZURACAST_DIR/plugins/azuracast-sweepers."
[[ -f "$azuracast_dir/docker-compose.yml" ]] || fail "AzuraCast docker-compose.yml not found."
[[ ! -L "$override" ]] || fail "Refusing to replace a symlinked Compose override."
[[ ! -e "$azuracast_dir/docker-compose.override.yaml" ]] ||
    fail "An override with the .yaml extension exists. Consolidate it into docker-compose.override.yml first."
[[ -z "${COMPOSE_FILE:-}" ]] || fail "Custom COMPOSE_FILE is not supported; merge the plugin configuration manually."
if [[ -f "$azuracast_dir/.env" ]] && grep -Eq '^[[:space:]]*(export[[:space:]]+)?COMPOSE_FILE[[:space:]]*=' "$azuracast_dir/.env"; then
    fail "COMPOSE_FILE in .env is not supported; merge the plugin configuration manually."
fi
command -v docker >/dev/null || fail "Docker is required."
docker compose version >/dev/null 2>&1 || fail "Docker Compose v2 (docker compose) is required."
cd "$azuracast_dir"
docker compose config --quiet || fail "The current Compose configuration is invalid. Fix it before installing the plugin."
services=$(docker compose config --services)
grep -Fxq web <<< "$services" ||
    fail "Expected AzuraCast service 'web' not found. No files were changed; custom service names require manual setup."

work=$(mktemp -d "$azuracast_dir/.sweepers-setup.XXXXXX")
had_override=false
if [[ -e "$override" ]]; then
    [[ -f "$override" ]] || fail "The Compose override must be a regular file."
    cp -p "$override" "$work/original"
    had_override=true
fi

status 'Preparing plugin files...\n'
docker run --rm --user "$(id -u):$(id -g)" -e npm_config_cache=/tmp/npm-cache \
    -v "$plugin_dir:/plugin" -w /plugin node:24-alpine \
    sh -c 'npm ci --include=dev --ignore-scripts && npm run build'
[[ -s "$plugin_dir/dist/app.js" ]] || fail "The frontend bundle is missing."

status 'Checking plugin configuration...\n'
docker run --rm --user "$(id -u):$(id -g)" \
    -v "$plugin_dir:/plugin:ro" -v "$azuracast_dir:$azuracast_dir" -w /plugin \
    node:24-alpine node bin/setup-compose.mjs "$azuracast_dir" "$work/docker-compose.override.yml"
docker compose --project-directory "$azuracast_dir" -f "$azuracast_dir/docker-compose.yml" \
    -f "$work/docker-compose.override.yml" config --quiet

if $had_override; then
    cmp -s "$override" "$work/original" || fail "The override changed during setup; retry without concurrent edits."
else
    [[ ! -e "$override" && ! -L "$override" ]] || fail "An override appeared during setup; retry."
fi
if ! $had_override || ! cmp -s "$override" "$work/docker-compose.override.yml"; then
    if $had_override; then
        backup=$(mktemp "$override.sweepers-backup.XXXXXX")
        cp -p "$override" "$backup"
        printf 'Original Compose override backed up to %s\n' "$backup"
    fi
    mv "$work/docker-compose.override.yml" "$override"
fi

container=$(docker compose ps -a -q web)
running=false
recreate=true
if [[ -n "$container" ]]; then
    running=$(docker inspect --format '{{.State.Running}}' "$container")
    mounts=$(docker inspect --format '{{range .Mounts}}{{println .Source .Destination .RW}}{{end}}' "$container" |
        while read -r mount_source mount_target mount_rw; do
            printf '%s %s %s\n' "$(readlink -f "$mount_source" || printf '%s' "$mount_source")" "$mount_target" "$mount_rw"
        done)
    environment=$(docker inspect --format '{{range .Config.Env}}{{println .}}{{end}}' "$container")
    if [[ "$running" == true ]] &&
        grep -Fxq "$plugin_dir /var/azuracast/www/plugins/azuracast-sweepers false" <<< "$mounts" &&
        grep -Fxq 'COMPOSER_PLUGIN_MODE=true' <<< "$environment"; then
        recreate=false
    fi
fi

# A stopped or missing stack must start completely; web alone cannot run without its dependencies.
if [[ "$running" == true ]]; then
    up_command=(docker compose up -d --no-deps web)
else
    up_command=(docker compose up -d)
fi
if $recreate; then
    if [[ "$running" == true ]]; then
        status '\nAzuraCast needs to apply the plugin configuration.\n'
        status 'WARNING: This interrupts broadcasting on all stations in this container.\n'
    else
        status '\nAzuraCast is not running. All services will be started to load the plugin.\n'
    fi
    if [[ ! -t 0 ]]; then
        fail "Interactive confirmation is required. Run: cd \"$azuracast_dir\" && ${up_command[*]}"
    fi
    status 'Apply now? [y/N] '
    read -r answer || answer=""
    if [[ "$answer" != y && "$answer" != Y && "$answer" != yes ]]; then
        status 'Prepared only; NOT applied. When ready, run:\n  cd "%s" && %s\n' "$azuracast_dir" "${up_command[*]}"
        exit 0
    fi
    status 'Applying plugin configuration...\n'
    "${up_command[@]}"
    status 'Waiting for AzuraCast to be ready (up to 10 minutes)...\n'
    ready=false
    for ((attempt=0; attempt<120; attempt++)); do
        if docker compose exec -T web supervisorctl status php-fpm php-worker-fast php-worker-slow php-nowplaying \
            > "$work/services" 2>&1; then
            if [[ $(grep -c 'RUNNING' "$work/services") == 4 ]]; then ready=true; break; fi
        fi
        sleep 5
    done
    cat "$work/services"
    rm "$work/services"
    $ready || fail "AzuraCast did not become ready. Inspect docker compose logs web; setup is incomplete."
else
    status 'Applying plugin update...\n'
    docker compose exec -T web supervisorctl signal USR2 php-fpm
    docker compose exec -T web supervisorctl restart php-worker-fast php-worker-slow php-nowplaying
    sleep 10
fi
docker compose exec -T web rm -f /var/azuracast/www_tmp/app_routes.cache.php
status '\nPlugin installed/updated. Station settings and intro values were not changed.\n'
status 'Open Sweepers > Settings to check module status and any required broadcasting restart.\n'
