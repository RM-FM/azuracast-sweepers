# AzuraCast Sweepers

Intro-aware jingle overlays for AzuraCast AutoDJ.

**Alpha preparation.** Playback integration requires validation on a disposable
AzuraCast station before use on air. The reference source is AzuraCast
`19d68c47b1e4d569885ca7cf4c40609cbabefda7` with Liquidsoap 2.4.5 and PHP 8.4.

The plugin provides:

- A sweeper configuration page with playlist selection and visual intro cue editor.
- An automatically created `intro_end` custom field.
- Rotation by oldest playback or longest fitting sweeper, with configurable length tolerance.
- Song spacing after jingles/sweepers and an optional early start for long intros.
- AutoCue-aware start, handover, loudness and tail handling.
- Music ducking that follows the sweeper's voice.
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

Build the frontend in a temporary Node container (Node does not need to be
installed on the host):

```bash
cd /var/azuracast/plugins/azuracast-sweepers
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
After reloading the dashboard, open **Sweepers** in the station sidebar. It is a
fold-out group directly below Media, like AzuraCast's own menu groups, with three
pages that open inside the station dashboard:

| Page | Contents |
| --- | --- |
| **Settings** | All sweeper settings, the module status and per-setting help |
| **Intros** | Search songs and set their intro end on a waveform |
| **Playback Timeline** | Songs that played with a sweeper: date/time, song, sweeper, start offset, and whether the song was moved forward |

The group appears for users with station Media permission, even when Media is collapsed.
Direct standalone access at `/sweepers/1` (replace `1` with the station ID) remains
available and offers the same three pages as tabs.
The plugin creates or reuses `intro_end` on the first page visit or station
configuration generation. It does not overwrite an existing field or change other custom fields.

Create an **Advanced** playlist containing the sweeper jingles, select it in the
plugin settings and enable sweepers. After installing (or updating) the plugin,
restart broadcasting once per station through AzuraCast. This is the only restart
needed: the module is loaded on every station, whether sweepers are enabled or
not, and **Enable sweepers** only decides whether sweepers are used. All later
settings changes, including enabling and disabling, apply live. The Sweepers page
shows whether the module runs on the station or still needs that restart.
Advanced playlists prevent the jingles from also entering the normal song rotation.
The page requires station Media permission; changing settings also requires
Broadcasting permission. The page includes an audio waveform with a movable
intro marker, a time input, and playback at the cue or three seconds earlier.
The cue editor belongs to the plugin; it does not modify AzuraCast's native cue editor.

The sidebar group uses a small plugin-owned script injected into successful HTML
responses from the authenticated dashboard. It follows station switches and Vue
sidebar redraws without modifying core files or Vue internals. It uses the same
markup as AzuraCast's own groups, so AzuraCast's Bootstrap handles opening and
closing it. The pages load the native station route with `?sweepers=settings`,
`?sweepers=intros` or `?sweepers=history` (older `?sweepers=1` links open
Settings). The script adds the plugin panel beside AzuraCast's own content
container and restores the original content when leaving the route. Plugin styles
are scoped to that panel; the surrounding header, sidebar and theme remain
AzuraCast's. If the expected Media menu is absent, the script leaves the sidebar
unchanged. This DOM integration is not an official navigation hook; verify it
after AzuraCast upgrades.

The Playback Timeline records a sweeper only when it actually started playing, not
when it was planned or cancelled. A song that **Move a song with an intro forward**
brought ahead is marked "Moved forward", with the song it overtook. The newest
200 entries per station are kept in
the plugin's station state file (`sweepers.json`); deleted files remain listed as
"Deleted file".

## Updating from GitHub

Update the existing checkout, rebuild the frontend in a temporary Node container
and reload PHP:

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
  docker compose exec web supervisorctl signal USR2 php-fpm
  docker compose exec web supervisorctl restart php-worker-fast php-worker-slow php-nowplaying
  sleep 10
  docker compose exec web rm -f /var/azuracast/www_tmp/app_routes.cache.php
)
```

The graceful PHP reload loads the updated plugin code without interrupting
broadcasting. Keep the order: AzuraCast's route cache must be deleted only after
the web processes **and** the background processes run the new code. Otherwise
an old process can rebuild the cache with the previous route list, and requests
to plugin routes reach the wrong handler until the cache is rebuilt again.
Reload the dashboard afterward. The Sweepers page then tells you
for each station whether its broadcasting must be restarted once to load the
updated Liquidsoap module. The initial Compose override only needs to be added once.

If Git reports local changes or diverging history, resolve those before updating;
do not reset the checkout blindly. Plugin settings, media and `intro_end` values
are stored by AzuraCast outside this checkout and are preserved by these commands.

If AzuraCast fails to start immediately after enabling the plugin, inspect
`docker compose logs --tail=120 web` from `/var/azuracast`. To temporarily
disable the plugin without removing its settings or media, rename its entry point
on the host and restart the web service:

```bash
mv /var/azuracast/plugins/azuracast-sweepers/events.php \
  /var/azuracast/plugins/azuracast-sweepers/events.php.disabled
cd /var/azuracast
docker compose restart web
```

After updating to a fixed version, rename `events.php.disabled` back to
`events.php` and restart `web` again. Do not discard an existing Compose override
file to disable the plugin; it may contain unrelated configuration.

## Rotation and timing

Configure these options on **Sweepers** and save. The running module checks for
changes every five seconds; no broadcasting restart is needed.

A new installation starts with these defaults; stations with saved settings keep
their values:

| Setting | Default |
| --- | --- |
| Enable sweepers | On (no sweeper plays until a sweeper playlist is selected) |
| Songs without sweeper after a jingle / after a sweeper / before a jingle | 1 / 1 / 0 |
| Count Jingle Mode playlists | On |
| Prefer the longest fitting sweepers | Off (tolerance 20%) |
| Move a song with an intro forward | Off |
| Handover margin | 0.5 s |
| Limit start delay | Off (maximum start delay 10 s, early start 2 s) |
| Maximum ducking | −7 dB |
| Jingle gain | 100% |

- **Song spacing** has three independent rules; `0` switches a rule off.
  A song must satisfy all of them to carry a sweeper:
  - **Songs without sweeper after a jingle** (e.g. `3`): after a recognized jingle,
    that many complete songs play before the next sweeper.
  - **Songs without sweeper after a sweeper** (e.g. `2`): after an actual sweeper,
    that many further songs play without one. The song carrying the sweeper does
    not count.
  - **Songs without sweeper before a jingle** (e.g. `1`): a song does not carry a
    sweeper if a recognized jingle is queued within this many following tracks.
    The maximum is AzuraCast's AutoDJ queue length (**Broadcasting → AutoDJ queue
    length**, effectively at least 2), because AzuraCast does not know tracks
    beyond its queue. The check runs at planning and on each five-second heartbeat
    until the overlay starts, because AzuraCast extends its queue during playback.
    Interrupting or otherwise unqueued jingles, queue rebuilds within the last
    seconds before activation, and listener requests are not foreseen.

  Only tracks that reached playback count, including a played track subsequently
  skipped. A jingle is not a song for the sweeper gap. Prefetch and cancelled
  overlays never reset a gap or advance sweeper rotation. A previously saved single
  **Minimum songs** value becomes both after-gaps; the before-gap starts disabled.
- **Count Jingle Mode playlists** is on by default. **Additional jingle
  playlists** accepts multiple station playlists, including ordinary playlists.
  Recognition follows the playing track's playlist annotation, like AzuraCast's
  Jingle Mode, not every playlist the file belongs to. The sweeper playlist itself
  always counts. Recognized jingles never receive an overlay.
- **Limit start delay** is off by default: align the sweeper handover to the
  intro boundary minus the safety margin. When switched on, retain that timing if
  the wait after the song's cue-in does not exceed **Maximum start delay**;
  otherwise start the sweeper at **Early start after song start** after the
  song's cue-in. The early start is never later than the aligned start, so the
  sweeper still ends before the intro. A maximum of `0` always uses the early
  start. The maximum is kept while the limit is off. Previously saved limits are
  converted: an empty value becomes "off", a number "on" with that maximum.
- **Jingle gain** is entered in percent (100% = as loud as the songs) and stored
  as a factor (`1.0`).
- **Prefer the longest fitting sweepers** is off by default. When enabled,
  the tolerance defines an eligible length range: with 30 seconds available and
  20%, bodies of 24–30 seconds participate in oldest-played rotation. If none are
  in range, choose the longest body that still fits (oldest-played for ties).
  `0%` prefers an exact fit or the longest fitting body; `100%` restores the full
  fitting pool. Small pools can repeat often.
- **Move a song with an intro forward** is off by default. When the spacing rules
  allow a sweeper but the next queued song has no intro that fits one, that song
  changes places with the first later queued song that has a fitting intro. The
  check runs right before Liquidsoap fetches the next song, so a song that was
  already sent to Liquidsoap never moves. Listener requests and songs from standard
  playlists with random or shuffled order can move; jingles and songs from
  sequential, "once per x" and other timed playlists keep their place. A moved
  request plays one position later or earlier than AzuraCast planned.
  The exchanged song must still respect **Songs without sweeper before a jingle**.
  If nothing fits, the order stays unchanged. Intro fit uses AzuraCast's cue
  values for the file (the file start when AutoCue is off) and the same sweeper
  measurements as planning. AzuraCast's upcoming-songs list and expected play
  times follow the new order. Moved songs are logged by Liquidsoap
  ("Song with intro moved forward"). The station needs the updated module once
  (one broadcasting restart after updating the plugin).

Available intro time subtracts song AutoCue cue-in and the configured handover
margin. Sweeper length means AutoCue cue-in to handover; its reverb tail may
continue afterward but must finish before the song's next transition. Start-delay
limits do not make an otherwise oversized sweeper eligible.

Spacing after jingles and sweepers is enforced in Liquidsoap at playback, even for
prefetched plans. After restarting Liquidsoap, the configured number of songs is
required before the first sweeper. Unknown sources reset both counts conservatively.
Settings and rotation history use the existing station configuration file; these
options introduce no database tables or new cue fields.

All saved settings have a shared revision. A change cancels old prefetched or
delayed overlays that have not started; their songs continue without an overlay.
Subsequent plans use the new playlist, timing, gain, ducking and selection settings.
An already audible sweeper finishes with its original gain, handover and tail.
Disabling prevents new overlays but keeps tracking played songs, so re-enabling
and changing a gap preserve the spacing history. No mid-song overlay is
inserted retroactively. New playlists may need additional background AutoCue time.
Failed heartbeat requests retain the last valid settings and are logged; changes
take effect after communication recovers.

Upgrading from an older release requires one broadcasting restart per station to
load the new Liquidsoap module; the Sweepers page reports an outdated module until
then. Installing, removing or updating the plugin requires that restart; settings
changes do not. Other pending AzuraCast changes can independently require one.

### Fail-safe loading

The plugin must never prevent a station from starting:

- Configuration generation never raises an error. If the custom field, the saved
  state or the settings cannot be read, the module loads with sweepers disabled.
- After AzuraCast has generated the complete configuration, the plugin checks it
  with AzuraCast's own Liquidsoap check (`liquidsoap --check`). If Liquidsoap
  rejects it only because of the sweeper module (for example after an AzuraCast
  upgrade changed its runtime), every plugin line is restored to AzuraCast's
  original and the station starts normally without sweepers. A configuration that
  fails without the module too is left unchanged, as removing the module would
  not help. A successful check is cached until the configuration changes.
- An unexpected configuration layout or manual AutoDJ mode (which lacks the
  AzuraCast queue) also starts the station without the module.
- Invalid initial settings inside Liquidsoap keep sweepers disabled instead of
  stopping the station. At runtime, API, catalogue and planning failures leave the
  music playing without an overlay.

The Sweepers page shows the reason whenever a station started without the module.
The next broadcasting restart tries again. The check adds one Liquidsoap type
check (a few seconds) to a restart whose configuration changed.

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

Jingles use `AnnotateNextSong` and normal request resolution, exactly as AzuraCast
would play them. The plugin measures every sweeper in the background before its
first use and passes the resolved `liq_*` and `autocue_*` metadata to the planner.
Timing comes from, in this order:

1. **Manual cue points** set on the file in AzuraCast.
2. **AzuraCast AutoCue** (when enabled for the station). The measurement itself
   triggers the analysis, so the result is stored in AzuraCast's AutoCue cache even
   for sweepers that AzuraCast never plays natively.
3. **Fallback: Liquidsoap's built-in AutoCue**, when AzuraCast supplies no values
   (AutoCue disabled, no manual cue points). It is the same analysis AzuraCast
   uses and honours the station's Liquidsoap `settings.autocue.*` tuning. Results
   stay in the plugin's memory and are re-measured after a station restart; they
   are never written to AzuraCast's cache, so AzuraCast's own playback of those
   files is unchanged.

Native cue fields may remain blank; the plugin does not populate them. A sweeper
that cannot be measured at all is not used. On stations without AutoCue, songs are
not loudness-normalised while the computed sweeper loudness is, so adjust
**Jingle gain** if the balance differs.
The sweeper catalogue resolves in the background; a cold start can leave the first
track without an overlay until its jingle measurements are ready. Slow catalogue
requests cannot delay music prefetch.

Song cue-in is subtracted once. A jingle's handover, rather than its file end,
determines its fit. The handover aligns to the intro boundary unless the optional
start-delay limit selects the early start. Ducking ends at handover and the remaining tail plays out. The tail must fit
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
docker run --rm --user "$(id -u):$(id -g)" -e npm_config_cache=/tmp/npm-cache \
  -v "$PWD:/plugin" -w /plugin node:24-alpine \
  sh -c 'npm ci --ignore-scripts && npm test && npm run build'
```

`./bin/check.sh --liquidsoap` runs these checks together, using containers for
PHP (when not installed locally) and Node, and checks the pinned Liquidsoap
runtime. Run `composer install && vendor/bin/phpcs` for the PHP style checks used by CI.

GitHub Actions also checks the module against the pinned AzuraCast Liquidsoap
runtime.
Use AzuraCast's PHP conventions: PSR-12, strict types, typed methods, sorted imports,
four-space indentation and trailing commas in multiline arrays. Keep code comments
brief and limited to non-obvious behavior.

## Removal

Disable sweepers and let any audible sweeper finish. Remove the plugin and
regenerate/restart broadcasting so the generated configuration no longer includes
the plugin module. Keep the module files available until the old configuration is
no longer in use.
Preserve `intro_end` and `sweepers.json` if their data is still needed. Removal
does not require deleting media, rebuilding the database or resetting AutoCue.

Earlier development builds created `azuracast_sweeper_review` and stored review
metadata in station state. These are no longer read or written. Updating the
plugin leaves any existing data in place; no database cleanup is required.

AGPL-3.0-or-later. Copyright 2026 RM-FM. This project is independent of AzuraCast.
