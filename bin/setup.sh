#!/usr/bin/env bash
set -euo pipefail

plugin_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)
azuracast_dir=$(cd "$plugin_dir/../.." && pwd -P)
override="$azuracast_dir/docker-compose.override.yml"

fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
trap 'printf "Setup failed at line %s. Read the error above before retrying.\n" "$LINENO" >&2' ERR

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
docker compose version >/dev/null
cd "$azuracast_dir"
docker compose config --quiet

work=$(mktemp -d "$azuracast_dir/.sweepers-setup.XXXXXX")
cleanup() {
    rm -f "$work/original" "$work/docker-compose.override.yml" "$work/services"
    rmdir "$work"
}
trap cleanup EXIT
had_override=false
if [[ -e "$override" ]]; then
    [[ -f "$override" ]] || fail "The Compose override must be a regular file."
    cp -p "$override" "$work/original"
    had_override=true
fi

printf 'Building the plugin frontend...\n'
docker run --rm --user "$(id -u):$(id -g)" -e npm_config_cache=/tmp/npm-cache \
    -v "$plugin_dir:/plugin" -w /plugin node:24-alpine \
    sh -c 'npm ci --include=dev --ignore-scripts && npm run build'
[[ -s "$plugin_dir/dist/app.js" ]] || fail "The frontend bundle is missing."

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
    printf 'Compose override prepared; other plugins and services were preserved.\n'
fi

container=$(docker compose ps -q web)
recreate=true
if [[ -n "$container" ]]; then
    mounts=$(docker inspect --format '{{range .Mounts}}{{println .Source .Destination .RW}}{{end}}' "$container")
    environment=$(docker inspect --format '{{range .Config.Env}}{{println .}}{{end}}' "$container")
    if grep -Fxq "$plugin_dir /var/azuracast/www/plugins/azuracast-sweepers false" <<< "$mounts" &&
        grep -Fxq 'COMPOSER_PLUGIN_MODE=true' <<< "$environment"; then
        recreate=false
    fi
fi

if $recreate; then
    printf '\nThe web container needs to be created/recreated to load the plugin mount or environment.\n'
    printf 'WARNING: This interrupts broadcasting on all stations in this container.\n'
    if [[ ! -t 0 ]]; then
        fail "Interactive confirmation is required. Run: cd \"$azuracast_dir\" && docker compose up -d --no-deps web"
    fi
    read -r -p 'Apply now? [y/N] ' answer
    if [[ "$answer" != y && "$answer" != Y && "$answer" != yes ]]; then
        printf 'Prepared only; NOT applied. When ready, run:\n  cd "%s" && docker compose up -d --no-deps web\n' "$azuracast_dir"
        exit 0
    fi
    docker compose up -d --no-deps web
    printf 'Waiting for AzuraCast PHP services (up to 5 minutes)...\n'
    ready=false
    for ((attempt=0; attempt<60; attempt++)); do
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
    docker compose exec -T web supervisorctl signal USR2 php-fpm
    docker compose exec -T web supervisorctl restart php-worker-fast php-worker-slow php-nowplaying
    sleep 10
fi
docker compose exec -T web rm -f /var/azuracast/www_tmp/app_routes.cache.php
printf '\nPlugin installed/updated. Station settings and intro values were not changed.\n'
printf 'Open Sweepers > Settings to check module status. If it reports an older module,\n'
printf 'restart that station broadcasting when convenient. Setup never enables sweepers.\n'
