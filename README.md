# AzuraCast Sweepers

Intro-aware jingle overlays for AzuraCast AutoDJ.

**Alpha preparation.** Playback integration requires validation on a disposable
AzuraCast station before use on air. The reference source is AzuraCast
`19d68c47b1e4d569885ca7cf4c40609cbabefda7` with Liquidsoap 2.4.5 and PHP 8.4.

The plugin provides:

- A sweeper configuration page with playlist selection and read-only intro cue preview.
- An automatically created `intro_end` custom field.
- Selection of the oldest actually played jingle that fits the intro.
- AutoCue-aware start, handover, loudness and tail handling.
- Sidechain ducking with 150 ms attack and 750 ms release.
- An authenticated endpoint that updates only `intro_end`.

Intro detection is external. No detection models, inference code or GPU services
are included.

## Installation

Clone this repository into `/var/azuracast/plugins/azuracast-sweepers`. Keep this
directory name: AzuraCast derives the PHP namespace from it.

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

Recreate the AzuraCast containers using the normal installation procedure.
After reloading the dashboard, open **Media → Sweepers** in the station sidebar.
The link appears alongside Music Files for users with station Media permission.
Direct access at `/sweepers/1` (replace `1` with the station ID) remains available.
The plugin creates or reuses `intro_end` on the first page visit or station
configuration generation. It does not overwrite an existing field or change other custom fields.

Create an **Advanced** playlist containing the sweeper jingles, select it in the
plugin settings, enable sweepers and restart broadcasting through AzuraCast.
Advanced playlists prevent the jingles from also entering the normal song rotation.
The page requires station Media permission; changing settings also requires
Broadcasting permission. The page includes an audio waveform and saved intro
marker for listening at the cue or three seconds earlier. It is a separate
page, not a modification of the native visual cue editor.

The sidebar link uses a small plugin-owned script injected into successful HTML
responses from the authenticated dashboard. It follows station switches and Vue
sidebar redraws without modifying core files or Vue internals. If the expected
Media menu is absent, the script leaves the sidebar unchanged. This DOM integration
is not an official navigation hook; verify the link after AzuraCast upgrades.

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
The plugin's waveform preview reads `intro_end` and displays it without offering
edits. Refresh the search results after changing a cue elsewhere.

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
determines its fit. Playback is delayed by at most the configured maximum wait;
ducking ends at handover and the remaining tail plays out. The tail must fit
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
