# AzuraCast Sweepers

Intro-aware jingle overlays for AzuraCast AutoDJ.

**Alpha preparation.** Playback integration requires validation on a disposable
AzuraCast station before use on air. The reference source is AzuraCast
`19d68c47b1e4d569885ca7cf4c40609cbabefda7` with Liquidsoap 2.4.5 and PHP 8.4.

The plugin provides:

- A sweeper configuration page with playlist selection and visual intro cue editor.
- An automatically created `intro_end` custom field.
- Rotation by oldest playback or longest fitting sweeper, with configurable length tolerance.
- Song spacing after jingles/sweepers and optional immediate starts for long intros.
- AutoCue-aware start, handover, loudness and tail handling.
- Sidechain ducking with 150 ms attack and 750 ms release.
- An authenticated endpoint that updates only `intro_end`.

Intro detection is external. No detection models, inference code or GPU services
are included.

## Installation

Run these commands on the AzuraCast Docker host with Git and Docker Compose
installed. The examples assume AzuraCast is installed in `/var/azuracast`;
adjust that base path if needed. Use an account that can write there and run Docker.

Clone the public repository from GitHub (no GitHub login or token required):

```bash
mkdir -p /var/azuracast/plugins
git clone --branch main https://github.com/RM-FM/azuracast-sweepers.git \
  /var/azuracast/plugins/azuracast-sweepers
cd /var/azuracast/plugins/azuracast-sweepers
```

Keep the directory name `azuracast-sweepers`: AzuraCast derives the PHP namespace
from it. If the repository is already installed, use the update instructions below.

Build the frontend with Node 24:

```bash
cd /var/azuracast/plugins/azuracast-sweepers
npm ci --ignore-scripts
npm run build
```

Alternatively, build in a temporary Node container:

```bash
docker run --rm --user "$(id -u):$(id -g)" \
  -e npm_config_cache=/tmp/npm-cache \
  -v "$PWD:/plugin" -w /plugin node:24-alpine \
  sh -c 'npm ci --ignore-scripts && npm run build'
```

Merge this into `/var/azuracast/docker-compose.override.yml`:

```yaml
services:
  web:
    environment:
      COMPOSER_PLUGIN_MODE: "true"
    volumes:
      - ./plugins/azuracast-sweepers:/var/azuracast/www/plugins/azuracast-sweepers:ro
```

Apply the override from the AzuraCast installation directory:

```bash
cd /var/azuracast
docker compose up -d
```

This may recreate the web container and briefly interrupt broadcasting.
After reloading the dashboard, open **Sweepers** in the station sidebar.
The link appears directly below Media at the top level, even when Media is collapsed,
for users with station Media permission.
Direct access at `/sweepers/1` (replace `1` with the station ID) remains available.
The plugin creates or reuses `intro_end` on the first page visit or station
configuration generation. It does not overwrite an existing field or change other custom fields.

Create an **Advanced** playlist containing the sweeper jingles, select it in the
plugin settings, enable sweepers and restart broadcasting through AzuraCast.
Advanced playlists prevent the jingles from also entering the normal song rotation.
The page requires station Media permission; changing settings also requires
Broadcasting permission. The page includes an audio waveform with a movable
intro marker, a time input, and playback at the cue or three seconds earlier.
It is a separate page, not a modification of the native visual cue editor.

The sidebar link uses a small plugin-owned script injected into successful HTML
responses from the authenticated dashboard. It follows station switches and Vue
sidebar redraws without modifying core files or Vue internals. If the expected
Media menu is absent, the script leaves the sidebar unchanged. This DOM integration
is not an official navigation hook; verify the link after AzuraCast upgrades.

## Updating from GitHub

Update the existing checkout and rebuild the frontend. This example uses Docker,
so Node does not need to be installed on the host:

```bash
(
  set -e
  cd /var/azuracast/plugins/azuracast-sweepers
  git pull --ff-only origin main
  docker run --rm --user "$(id -u):$(id -g)" \
    -e npm_config_cache=/tmp/npm-cache \
    -v "$PWD:/plugin" -w /plugin node:24-alpine \
    sh -c 'npm ci --ignore-scripts && npm run build'

  cd /var/azuracast
  docker compose restart web
)
```

The web restart loads updated PHP code and briefly interrupts broadcasting.
Reload the dashboard afterward. If the update changes Liquidsoap integration,
restart broadcasting for each affected station through AzuraCast to regenerate
its configuration. The initial Compose override only needs to be added once.

With Node 24 installed locally, replace the `docker run` build command with
`npm ci --ignore-scripts` followed by `npm run build` in the plugin directory.
If Git reports local changes or diverging history, resolve those before updating;
do not reset the checkout blindly. Plugin settings, media and `intro_end` values
are stored by AzuraCast outside this checkout and are preserved by these commands.

## Rotation and timing

Configure these options on **Sweepers**, then restart broadcasting:

- **Minimum songs** defaults to `0` (disabled). With `1`, a jingle is followed by
  one song without a sweeper; the next song may carry one. A song carrying a
  sweeper does not itself count as an intervening song. Only tracks that reached
  playback count, including a played track subsequently skipped. Prefetch and
  cancelled overlays never reset the gap or advance sweeper rotation.
- **Count Jingle Mode playlists** defaults to enabled. **Additional jingle
  playlists** accepts multiple station playlists, including ordinary playlists.
  Recognition follows the playing track's playlist annotation, like AzuraCast's
  Jingle Mode, not every playlist the file belongs to. The sweeper playlist itself
  always counts. Recognized jingles never receive an overlay.
- **Limit start delay** defaults to disabled (`max_wait: null`): align the sweeper
  handover to the intro boundary minus the safety margin. With a limit, retain
  that timing if it fits; otherwise start immediately. `0` always starts at song
  start. Previously saved numeric limits remain enabled after an update.
- **Prefer the longest fitting sweepers** defaults to disabled. When enabled,
  the tolerance defines an eligible length range: with 30 seconds available and
  20%, bodies of 24–30 seconds participate in oldest-played rotation. If none are
  in range, choose the longest body that still fits (oldest-played for ties).
  `0%` prefers an exact fit or the longest fitting body; `100%` restores the full
  fitting pool. Small pools can repeat often.

Available intro time subtracts song AutoCue cue-in and the configured handover
margin. Sweeper length means AutoCue cue-in to handover; its reverb tail may
continue afterward but must finish before the song's next transition. Start-delay
limits do not make an otherwise oversized sweeper eligible.

Spacing is enforced in Liquidsoap at playback, even for prefetched plans. After
restarting Liquidsoap, the configured number of intervening songs is required
before the first sweeper. Unknown sources reset that count conservatively.
Settings and rotation history use the existing station configuration file; these
options introduce no database tables or new cue fields.

## Intro cues

`intro_end` contains seconds from the original file start:

| Value | Meaning |
| --- | --- |
| Blank | Unknown; no sweeper |
| `0` | No usable intro |
| Positive number | Available intro boundary |

Edit this value in AzuraCast's Music Files editor under Custom Fields, or use the
[one-field API](docs/API.md). Detection, review and approval happen outside this
plugin. Only the resulting numeric cue is published to AzuraCast. A verified
30-second opening can be published as `30`; the plugin needs no separate type.
The native waveform editor does not display custom fields as cue markers.
The plugin editor shows the saved `intro_end` and lets you drag the marker, set
it at the playhead, enter seconds, set zero or clear the field. Saving uses the
session-protected plugin route and changes only this custom field. Refresh the
search results after changing a cue elsewhere.

## AutoCue and playback

The AutoDJ still selects songs through AzuraCast. The plugin replaces the native
AutoDJ factory call with its callback-aware factory and adds the overlay after the
standard crossfade, before the
station's final audio processing and live-DJ fallback.

Jingles use `AnnotateNextSong` and normal request resolution. The planner receives
the resolved `liq_*` and `autocue_*` metadata, including cached AutoCue values.
Native cue fields may remain blank. The plugin does not populate those fields or
reimplement AutoCue. Enable AzuraCast AutoCue, or provide valid manual jingle timing.
The sweeper catalogue resolves in the background; a cold start can leave the first
track without an overlay until its jingle measurements are ready. Slow catalogue
requests cannot delay music prefetch.

Song cue-in is subtracted once. A jingle's handover, rather than its file end,
determines its fit. The handover aligns to the intro boundary unless the optional
start-delay limit selects immediate playback. Ducking ends at handover and the remaining tail plays out. The tail must fit
inside the song's remaining playback window. Jingle normalization is applied once.

Planning reserves a jingle; only its playback callback updates rotation history.
Network calls and request resolution run outside synchronous audio callbacks.
Preparation failures leave the original song available. Late overlay activation
is skipped. Live inputs bypass the overlay branch.

Settings and rotation state live in the station config directory as `sweepers.json`
and `sweepers.lock`. The intro cue uses AzuraCast's existing custom-field storage;
the plugin creates no database tables. Intro edits affect future plans; an
already-prefetched track may retain its previous plan.

## External cue updates

See [docs/API.md](docs/API.md). The endpoint accepts only `intro_end`, uses
AzuraCast authentication and station Media permission, and preserves every other
custom field. No review status, detector metadata or import workflow is stored.

## Development

```bash
composer install
composer test
find src tests -name '*.php' -print0 | xargs -0 -n1 php -l
npm test
npm run build
```

`./bin/check.sh --liquidsoap` runs these checks together, using a PHP container
when PHP is not installed locally, and checks the pinned Liquidsoap runtime.
Install Node dependencies first. Run `composer install && vendor/bin/phpcs` for
the PHP style checks used by CI.

GitHub Actions also checks the module against the pinned AzuraCast Liquidsoap
runtime. See [docs/VALIDATION.md](docs/VALIDATION.md) for the on-air acceptance checks.
Use AzuraCast's PHP conventions: PSR-12, strict types, typed methods, sorted imports,
four-space indentation and trailing commas in multiline arrays. Keep code comments
brief and limited to non-obvious behavior.

## Removal

Disable sweepers and restart broadcasting before removing the plugin mount.
Preserve `intro_end` and `sweepers.json` if their data is still needed. Removal
does not require deleting media, rebuilding the database or resetting AutoCue.

Earlier development builds created `azuracast_sweeper_review` and stored review
metadata in station state. These are no longer read or written. Updating the
plugin leaves any existing data in place; no database cleanup is required.

AGPL-3.0-or-later. Copyright 2026 RM-FM. This project is independent of AzuraCast.
