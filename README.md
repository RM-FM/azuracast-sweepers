# AzuraCast Drop-ins

Intro-aware jingle overlays for AzuraCast AutoDJ.

**Alpha preparation.** Playback integration requires validation on a disposable
AzuraCast station before use on air. The reference source is AzuraCast
`19d68c47b1e4d569885ca7cf4c40609cbabefda7` with Liquidsoap 2.4.5 and PHP 8.4.

The plugin provides:

- A standalone drop-in settings page and waveform intro editor.
- An automatically created `intro_end` custom field.
- Selection of the oldest actually played jingle that fits the intro.
- AutoCue-aware start, handover, loudness and tail handling.
- Sidechain ducking with 150 ms attack and 750 ms release.
- An authenticated API for importing externally detected intro cues.

Intro detection is external. No detection models, inference code or GPU services
are included.

## Installation

Clone this repository into `/var/azuracast/plugins/azuracast-dropins`. Keep this
directory name: AzuraCast derives the PHP namespace from it.

Build the frontend with Node 24:

```bash
cd /var/azuracast/plugins/azuracast-dropins
npm install --ignore-scripts
npm run build
```

Alternatively, build in a temporary Node container:

```bash
docker run --rm --user "$(id -u):$(id -g)" \
  -e npm_config_cache=/tmp/npm-cache \
  -v "$PWD:/plugin" -w /plugin node:24-alpine \
  sh -c 'npm install --ignore-scripts && npm run build'
```

Merge this into `/var/azuracast/docker-compose.override.yml`:

```yaml
services:
  web:
    environment:
      AZURACAST_PLUGIN_MODE: "true"
    volumes:
      - ./plugins/azuracast-dropins:/var/azuracast/www/plugins/azuracast-dropins:ro
```

Recreate the AzuraCast containers using the normal installation procedure.
Open `/dropins/1`, replacing `1` with the station ID, while signed in. The plugin
creates or reuses `intro_end` on the first page visit or station configuration
generation. It does not overwrite an existing field or change other custom fields.

Create an **Advanced** playlist containing the drop-in jingles, select it in the
plugin settings, enable drop-ins and restart broadcasting through AzuraCast.
Advanced playlists prevent the jingles from also entering the normal song rotation.
The page requires station Media permission; changing settings also requires
Broadcasting permission. It is a separate page, not a modification of the native
station sidebar or visual cue editor.

## Intro cues

`intro_end` contains seconds from the original file start:

| Value | Meaning |
| --- | --- |
| Blank | Unknown; no drop-in |
| `0` | No usable intro |
| Positive number | Available intro boundary |

The plugin editor also records vocal/musical/30s+ type, approval/veto state and
whether a manual review is protected. A 30s+ bound stores numeric `30`, with its
type recorded separately. Vetoes block drop-ins. A changed media identity
invalidates the plugin review. The editor supports auditioning, setting a cue at
the playhead and dragging the Intro marker.

The native media editor exposes `intro_end` under Custom Fields. It does not
display this custom field as a marker in AzuraCast's native waveform editor.

## AutoCue and playback

The AutoDJ still selects songs through AzuraCast. The plugin wraps its existing
prefetch callback and adds the overlay after the standard crossfade, before the
station's final audio processing and live-DJ fallback.

Jingles use `AnnotateNextSong` and normal request resolution. The planner receives
the resolved `liq_*` and `autocue_*` metadata, including cached AutoCue values.
Native cue fields may remain blank. The plugin does not populate those fields or
reimplement AutoCue. Enable AzuraCast AutoCue, or provide valid manual jingle timing.
AutoCue cache misses may add startup/prefetch latency.

Song cue-in is subtracted once. A jingle's handover, rather than its file end,
determines its fit. Playback is delayed by at most the configured maximum wait;
ducking ends at handover and the remaining tail plays out. The tail must fit
inside the song's remaining playback window. Jingle normalization is applied once.

Planning reserves a jingle; only its playback callback updates rotation history.
Network calls and request resolution run outside synchronous audio callbacks.
Preparation failures leave the original song available. Late overlay activation
is skipped. Live inputs bypass the overlay branch.

Settings and rotation state live in the station config directory as `dropins.json`
and `dropins.lock`. Keep these files when updating the plugin. Intro edits affect
future plans; an already-prefetched track may retain its previous plan.

## External cue import

See [docs/API.md](docs/API.md). The API accepts cue values, not audio analysis jobs.
It uses AzuraCast authentication and station permissions. Protected manual reviews
return HTTP 409 rather than being overwritten.

## Development

```bash
php tests/run.php
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

Disable drop-ins and restart broadcasting before removing the plugin mount.
Preserve `intro_end` and `dropins.json` if their data is still needed. Removal
does not require deleting media, rebuilding the database or resetting AutoCue.

AGPL-3.0-or-later. Copyright 2026 RM-FM. This project is independent of AzuraCast.
