# Advanced guide

For installation, first station setup and everyday use, start with the
[README](../README.md). This guide covers technical details and less common cases.
The [Intro cue API](API.md) is documented separately.

## Installation and Compose

Keep the directory name `azuracast-sweepers` below your AzuraCast installation's
`plugins` directory. Run `bash bin/setup.sh` as a user with Docker access and write
permission to that directory and the Compose override. Frontend builds run in a
temporary `node:24-alpine` container, not in a host Node installation.

### Supported configuration

Standard AzuraCast `docker-compose.yml` and optional `docker-compose.override.yml`
files with a `web` service are supported. A missing `web` service stops setup before
building or changing files; custom service names require manual setup.

Conflicting plugin mounts, ambiguous YAML aliases/merge keys and custom
`COMPOSE_FILE` configurations stop setup with an error instead of being overwritten.
Resolve these manually, then rerun the script.

Compose `!reset` and `!override` tags in unrelated sections are preserved. Tagged
sections that setup must edit, such as `web.environment` or `web.volumes`, require
manual setup so their merge behavior is not changed.

### Override changes and backups

The script builds the frontend and creates or merges `docker-compose.override.yml`.
It adds these entries:

```yaml
services:
  web:
    environment:
      COMPOSER_PLUGIN_MODE: "true"
    volumes:
      - ./plugins/azuracast-sweepers:/var/azuracast/www/plugins/azuracast-sweepers:ro
```

Existing services, comments, other environment entries and mounts (including
Moonbase AutoCue/LS-Config-Replace) are kept. The script sets `COMPOSER_PLUGIN_MODE`
to the string `"true"`; existing YAML formatting or quoting may change.
Changed existing overrides receive a backup named
`docker-compose.override.yml.sweepers-backup.*`. The candidate is validated with
Docker Compose before replacing the override.

`COMPOSER_PLUGIN_MODE` is an AzuraCast setting: on start, the web container runs
`composer update` with the plugins' `composer.json` merged in, so AzuraCast can load
the plugin's PHP classes. This makes the first container start slower.

Setup does not automatically roll back an applied override after a later failure.
Keep the backup; restoring it does not itself reconfigure a running container.
If an error prints a restore command, use the exact backup path it names.

### Container lifecycle

Setup shows short progress messages instead of build and service output. On failure,
it prints the path to a private technical log in the host's temporary directory.
Successful runs remove that log. Broadcasting interruption warnings and confirmation
prompts remain visible.

- **Running and already configured:** reload PHP-FPM, restart PHP workers and
  NowPlaying, wait, then clear the route cache. Broadcasting is not restarted.
- **Running but missing the mount or environment flag:** ask before running
  `docker compose up -d --no-deps web`. This interrupts broadcasting on every
  station in that container.
- **Missing or stopped web container:** ask before starting the whole stack with
  `docker compose up -d`.

Answering no leaves the files prepared and prints the command to run later.
Noninteractive runs never recreate or start containers. After applying, setup waits
up to ten minutes for the PHP services and clears the route cache. It does not
enable sweepers in station settings.

### Updates and module status

The Settings page shows the installed version from the release notes, plus the
checked-out branch and commit, and checks GitHub releases every few hours.
The plugin directory is read-only inside the web container; install updates on
the host using the [README update commands](../README.md#update).

PHP/worker reloads and frontend builds are separate from broadcasting restarts.
Only a changed Liquidsoap module requires one broadcasting restart per station;
comments and blank lines do not count toward its code fingerprint. Settings
offers **Restart Broadcasting** to users with Broadcasting permission.
An older-module warning means the running station has not yet loaded the
installed module; it does not by itself mean the module is inactive.

> [!IMPORTANT]
> For manual updates, delete the route cache only after all PHP processes run the
> new code. Otherwise an old process can rebuild it and plugin routes stop working.
> The setup script handles this order.

## Manual installation

This is the alternative to `bin/setup.sh`, not an additional step. Commands assume
the standard `/var/azuracast` directory and `web` service. Adjust paths, service names
and Compose file selection for a custom installation. Run commands on the host
with Docker access and write permission to the installation.

### 1. Clone and build

```bash
(
  set -e
  mkdir -p /var/azuracast/plugins
  git clone --branch main https://github.com/RM-FM/azuracast-sweepers.git /var/azuracast/plugins/azuracast-sweepers
  cd /var/azuracast/plugins/azuracast-sweepers
  docker run --rm --user "$(id -u):$(id -g)" -e npm_config_cache=/tmp/npm-cache \
    -v "$PWD:/plugin" -w /plugin node:24-alpine \
    sh -c 'npm ci --include=dev --ignore-scripts && npm run build'
)
```

### 2. Merge the Compose override

If `/var/azuracast/docker-compose.override.yml` exists, back it up before editing:

```bash
cp -p /var/azuracast/docker-compose.override.yml \
  "/var/azuracast/docker-compose.override.yml.backup.$(date +%Y%m%d-%H%M%S)"
```

Add the entries from [Override changes and backups](#override-changes-and-backups)
to that file, creating it if needed. **Merge into the existing `services.web`,
`environment` and `volumes` sections; do not replace them or add duplicate keys.**
Keep all other environment variables and mounts. For example, an installation with
Moonbase LS-Config-Replace could contain:

```yaml
services:
  web:
    environment:
      AZURACAST_PLUGIN_MODE: true
      COMPOSER_PLUGIN_MODE: "true"
    volumes:
      - /home/cue_file/ls-config-replace:/var/azuracast/www/plugins/ls-config-replace
      - /usr/bin/cue_file:/usr/bin/cue_file
      - ./plugins/azuracast-sweepers:/var/azuracast/www/plugins/azuracast-sweepers:ro
```

The Moonbase entries are illustrative: keep your own existing paths and settings,
and do not add them if you do not use that integration.

Validate before applying:

```bash
cd /var/azuracast
docker compose config --quiet
```

**Stop and fix any reported error before continuing.**

### 3. Apply and check

**The following can recreate the web container and interrupt broadcasting on all
stations in it. Choose a suitable maintenance window.**

If AzuraCast is running:

```bash
cd /var/azuracast
docker compose up -d --no-deps web
```

If AzuraCast is stopped or not yet started, start the whole stack instead:

```bash
cd /var/azuracast
docker compose up -d
```

Allow startup to finish; the first start may take several minutes for Composer.
Inspect logs and service status:

```bash
docker compose logs --tail=120 web
docker compose exec -T web supervisorctl status php-fpm php-worker-fast php-worker-slow php-nowplaying
```

Do not continue until all four PHP services report `RUNNING`. If startup fails,
see [Troubleshooting](#troubleshooting). Then clear the route cache:

```bash
docker compose exec -T web rm -f /var/azuracast/www_tmp/app_routes.cache.php
```

Continue with [First station setup](../README.md#first-station-setup). Check the
module status; keep sweepers disabled until ready for a controlled playback test.

## Manual update

Read the [release notes](../CHANGELOG.md) first. For an already configured, running
installation, the following replaces the setup script's normal update path:

```bash
(
  set -e
  cd /var/azuracast/plugins/azuracast-sweepers
  git pull --ff-only origin main
  docker run --rm --user "$(id -u):$(id -g)" -e npm_config_cache=/tmp/npm-cache \
    -v "$PWD:/plugin" -w /plugin node:24-alpine \
    sh -c 'npm ci --include=dev --ignore-scripts && npm run build'
  cd /var/azuracast
  docker compose exec -T web supervisorctl signal USR2 php-fpm
  docker compose exec -T web supervisorctl restart php-worker-fast php-worker-slow php-nowplaying
  sleep 10
  docker compose exec -T web supervisorctl status php-fpm php-worker-fast php-worker-slow php-nowplaying
  docker compose exec -T web rm -f /var/azuracast/www_tmp/app_routes.cache.php
)
```

If any command fails, stop and resolve the error rather than running the remaining
commands individually. These PHP reloads do not restart broadcasting.

If the mount/environment is missing or release notes require Compose changes,
follow the manual merge, validation and apply steps above instead of relying only
on PHP reloads. If AzuraCast is stopped, pull and build first, then use the
[apply and check steps](#3-apply-and-check) to start it.

Finally check **Sweepers → Settings** on each station. Restart broadcasting only
when the module status or release notes require it. Settings and intro values are
preserved.

## Settings reference

Main sections and their headings span the full width. Only related fields within
a section use columns on larger screens; the three song spacing values appear
side by side on large screens and stack on smaller screens.

Defaults for a new installation:

| Setting | Default |
| --- | --- |
| Enable sweepers | On (keep disabled while preparing and validating a station) |
| Sweeper playlists | None (nothing plays until one is selected) |
| Songs without sweeper after a jingle / after a sweeper / before a jingle | 1 / 1 / 0 |
| Count Jingle Mode playlists | On |
| Other sweepers before a repeat | 1 |
| Prefer the longest fitting sweepers | Off (tolerance 20%) |
| Move a song with an intro forward | Off (sequential playlists: off) |
| Swap with another song from same playlist | Off |
| Exclude Playlists from Sweepers | None |
| Handover margin | 0.5 s |
| Limit start delay | Off (maximum 10 s, early start 2 s) |
| Reverb on the sweeper voice | Off (intensity 25%) |
| Maximum ducking | −7 dB |
| Jingle gain | 100% |

### Playlists, spacing and rotation

- **Sweeper playlists:** select one or more Advanced playlists. A playlist with an
  AzuraCast schedule is used only during its time slots; one without a schedule is
  always used. Active playlists share one rotation and repeat rule; a sweeper in
  several of them counts once. Disabled playlists are skipped.
- **Spacing rules:** all three rules must allow a sweeper; `0` turns a rule off.
  "Before a jingle" looks ahead in the AutoDJ queue, so its maximum is the queue length.
- **Jingles:** tracks from Jingle Mode playlists, additional jingle playlists and
  sweeper playlists never get a sweeper.
- **Excluded playlists:** tracks never get a sweeper and are never moved or replaced.
  Selecting a playlist group covers all its playlists.
- **Other sweepers before a repeat:** if no other sweeper fits, the song gets none.
  With a single sweeper, repeats are always allowed.
- **Prefer the longest fitting sweepers:** at 20% tolerance, bodies filling 80–100%
  of the available intro are preferred, rotating by oldest actual play in that range.
  Available intro is `intro_end` minus song cue-in and handover margin. If no eligible
  sweeper is in range, regular oldest-played rotation chooses any other fitting one.
  Tolerance never bypasses timing, schedules, reservations or repetition protection.

### Song matching

**Move a song with an intro forward** exchanges the next song with the next queued
song that has a fitting intro. Jingles, excluded playlists and songs already sent
to Liquidsoap never move.

**Swap with another song from same playlist** replaces the next song with a fitting
candidate from AzuraCast's native playlist queue, respecting sequential/shuffle/random
order and repetition protection, including inherited group rules. Requests, merged
playlists/groups, excluded playlists and entries already sent to Liquidsoap are
never replaced.

Both options work independently; a queued forward move is tried first when both are
on. **Also move or swap songs from sequential playlists** applies to both options.

The displaced song returns to its existing playlist rotation slot, keeping its
weight and history; it is not appended directly to the playback queue. It may be
replaced again when encountered later. Songs still open in the current round are
tried first; if none fits, consumed songs follow, longest ago first, without
reshuffling or resetting the round. Consumed means selected by AutoDJ, not
necessarily already aired.

Repetition protection is not relaxed: no eligible match, a busy native queue builder
or an expired 500 ms matching budget leaves the original song in place. Matching
improves the chance of a sweeper; it does not guarantee one. AzuraCast continues
rebuilding the queue's expected times. The timeline distinguishes moves from
replacements and explains unsuccessful matches.

### Timing and sound

Normally the sweeper ends at the intro end minus the handover margin.
**Limit start delay** starts it at the configured early start instead if the normal
start would exceed the maximum delay. **Reverb** is computed live; files are unchanged.

Settings take effect within five seconds. A sweeper already prepared for an upcoming
song keeps its planned timing; only switching sweepers off drops it. A sweeper
already on air finishes normally.

## How playback works

AzuraCast schedules playlists and builds the playback queue. Optional matching
exchanges queued songs or replaces the next unsent song using the native playlist
rotation; it does not run a separate scheduler.

The plugin plans a sweeper when Liquidsoap fetches the next song and mixes it in
after the crossfade, before station audio processing. Live DJs are never overlaid.
Timing uses effective cue and crossfade metadata resolved by Liquidsoap, including
manual cue points and cached annotations. Song cue-in is taken into account.
The sweeper's handover, not its file end, must fit the intro; its tail may continue
into the song.

No AutoCue configuration change is needed. Resolved metadata can come from manual
settings, AzuraCast's built-in AutoCue or an external integration such as Moonbase.
Only when a **sweeper** lacks a complete cue-in/out pair does the plugin use
Liquidsoap's internal AutoCue analysis as a fallback to find cue and mix points.
This avoids timing distortions from silence or very quiet artefacts. It runs only
on sweepers, does not update AzuraCast cue fields or AutoCue cache, and does not
detect song intros. Song intros still come from `intro_end`.

### Safety and diagnostics

- Unreadable settings cause the station to start with sweepers off.
- Generated configuration is checked with `liquidsoap --check`. If the module breaks
  it, the station starts without sweepers and Settings shows why.
- Runtime errors leave music playing without a sweeper.

Decisions appear under `sweepers` in the station's Liquidsoap log. The Playback
Timeline retains seven days in the station file `sweepers-history.json`, with the
last 24 hours shown by default. It records forward moves, replacements and reasons
for unsuccessful matching.

Intro scanning stops and shows an error on audio or page-loading failures. Tracks
ending before the five-second preview finishes advance immediately.

## Troubleshooting

If AzuraCast does not start after installation, check:

```bash
cd /var/azuracast
docker compose logs --tail=120 web
```

To disable the plugin temporarily while keeping its data:

```bash
mv /var/azuracast/plugins/azuracast-sweepers/events.php{,.disabled}
cd /var/azuracast
docker compose restart web
```

**Restarting `web` interrupts broadcasting on all stations in that container.**
Rename the file back and restart `web` to enable it again.

## Removal

Disable sweepers, remove the plugin's entries from the Compose override and run
`docker compose up -d`. This can recreate the web container and interrupt broadcasting;
schedule it accordingly. Stations then start without the module. Afterwards delete
the plugin directory.

The plugin creates no database tables. The `intro_end` custom field and station
files `sweepers.json` and `sweepers-history.json` remain until you delete them.

## Development

```bash
./bin/check.sh --liquidsoap
```

Runs PHP lint and tests (run `composer install` first for the full set), frontend
tests and build, and the Liquidsoap type check in containers. CI additionally runs
`vendor/bin/phpcs` and native playback tests. Code follows AzuraCast's conventions
(PSR-12, strict types).

`tests/replacement.php` runs separately in CI's pinned AzuraCast image with an
isolated MariaDB `sweepers_test` database (`SWEEPER_TEST_DB_HOST`). It uses real
playlist repositories, duplicate prevention, queue and rotation entities with
minimal station/library fixtures. Never point integration tests at a station database.

CI tests every push against pinned AzuraCast versions. The daily **AzuraCast
compatibility** workflow tests `develop` against AzuraCast's rolling `latest` image
and matching `main` sources: dependencies listed in `tests/compat.php`, sidebar
markup, the Liquidsoap module and playback scenarios.

Scheduled runs skip unchanged AzuraCast commits after a passing run; failures retry
daily and manual runs always check. Failure opens an `azuracast-compat` issue,
notifying the repository owner by email. A new upstream commit that still fails adds
a comment; a passing run closes the issue. GitHub schedules start from the default
branch only; until the workflow reaches `main`, it runs when the workflow or
`tests/compat.php` changes on `develop`.

See also the [validation guide](VALIDATION.md).
