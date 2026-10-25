# Intro cue API

`POST /api/station/{station_id}/sweepers/intro/{media_id}`

Authenticate with an AzuraCast API key whose account has Media permission for the
station. Use `X-API-Key` over HTTPS, following AzuraCast's normal API configuration.

The request contains exactly one field:

```json
{"intro_end": 14.5}
```

The value is seconds from the original file start, before AutoCue cue-in is
subtracted. It must be finite, nonnegative and no greater than the file duration.
`0` means no usable intro. `null` or an empty string clears the field. A missing
`intro_end` or additional keys are rejected. The plugin stores no review state,
locks, detection type or analysis results.

Successful response:

```json
{"success": true, "intro_end": 14.5}
```

The endpoint creates or updates only the media's `intro_end` entry in AzuraCast's
existing custom-field table. Other custom fields, playlists, cue-in/out, fades
and loudness values are preserved. Concurrent calls to this endpoint serialize
updates for the same media record.

The visual editor uses `POST /sweepers/{station_id}/intro/{media_id}` with the same
single-field body. This route requires a signed-in session, station Media permission
and the `sweepers` CSRF token in `X-CSRF-Token`. It calls the same save operation.

The History page reads `GET /sweepers/{station_id}/history` (signed-in session,
station Media permission). It returns `limit` (200) and `rows`, newest first, each
with `at` (Unix time when the sweeper started), `song` and `sweeper` (`title`,
`artist`, `path`, or `null` for a deleted file) and `offset` (seconds after the
song's cue-in, or `null`). Only sweepers that actually started playing are recorded.

## Using the native media API

AzuraCast also supports `GET` and `PUT /api/station/{station_id}/file/{media_id}`.
Its `custom_fields` update replaces the full field collection. Read the current
collection, change `intro_end`, and send the entire collection back if using that
API. This read/write sequence can race with other edits; the plugin endpoint
avoids replacing unrelated fields.

This behavior is verified against AzuraCast's
[FilesController](https://github.com/AzuraCast/AzuraCast/blob/19d68c47b1e4d569885ca7cf4c40609cbabefda7/backend/src/Controller/Api/Stations/FilesController.php)
and [CustomFieldRepository](https://github.com/AzuraCast/AzuraCast/blob/19d68c47b1e4d569885ca7cf4c40609cbabefda7/backend/src/Entity/Repository/CustomFieldRepository.php).

## Internal playback endpoints

The Liquidsoap module uses `/api/internal/{station_id}/liquidsoap/sweepers/`:

| Endpoint | Purpose |
| --- | --- |
| `catalog` | Get normally annotated sweeper requests |
| `plan` | Select/reserve a fitting sweeper using resolved request metadata |
| `played` | Legacy playback acknowledgment |
| `heartbeat` | Renew reservations, acknowledge events and return current live settings |

These endpoints require AzuraCast's internal connection and the station's adapter
API key. They are not public control endpoints.

The heartbeat response contains `success: true`, `settings` with `enabled`,
`songs_after_jingle`, `songs_after_sweeper`, `count_jingle_mode`, merged
`jingle_playlists` and a `revision` covering every saved setting, and `blocked`:
the reserved, not yet played plan tokens whose song is now followed by a queued
jingle within `songs_before_jingle` tracks. Liquidsoap cancels those plans before
activation. Every plan (including an empty plan) carries the settings revision.
Liquidsoap checks it before activation and releases obsolete plans; empty plans
need no release. Already started overlays retain their original timing and gain
and still report actual playback. Heartbeats continue while sweepers are disabled.
Each heartbeat request includes the running module `version`; the plugin records it
at most every 30 seconds so the panel can tell whether the module runs, is outdated
or still needs the one-time restart after installation or update.

## Panel rotation settings

The session/CSRF-protected `POST /sweepers/{station_id}/settings` requires station
Broadcasting permission. It accepts the existing settings plus:

| Field | Default | Meaning |
| --- | --- | --- |
| `songs_after_jingle` | `0` | Songs without sweeper after a jingle, integer 0–1000 |
| `songs_after_sweeper` | `0` | Songs without sweeper after a sweeper, integer 0–1000 |
| `songs_before_jingle` | `0` | Queued tracks checked for a following jingle, 0 to the AutoDJ queue length |
| `count_jingle_mode` | `true` | Recognize the native `jingle_mode` annotation |
| `jingle_playlist_ids` | `[]` | Additional station playlist IDs (integer array) |
| `max_wait` | `null` | Disabled; numeric 0–3600 is the longest wait before the early start is used |
| `early_start` | `2` | Seconds after the song cue-in used when `max_wait` is exceeded, 0–60; never later than the aligned start |
| `longest_fit` | `false` | Prefer bodies closest to the available intro length |
| `length_tolerance` | `20` | Eligible range below the intro length, percent 0–100 |

The panel state supplies Advanced `playlists` for sweeper selection, all station
`jingle_playlists` for spacing classification and the effective `queue_length`
(`max(autodj_queue_length, 2)`). Foreign or missing jingle playlist IDs and a
`songs_before_jingle` above `queue_length` are rejected before saving. A saved
legacy `minimum_songs` value is read as both after-gaps. Saving does
not set or clear AzuraCast's `needs_restart` flag; the response reports its existing
value, which may reflect unrelated station changes. All settings update through
the five-second heartbeat after the module has been installed and loaded once.
The current audible sweeper finishes; old pending plans are cancelled, and new
settings apply to subsequent plans. Enabling with manual AutoDJ is rejected.
The panel state and the save response include `module` with `state` (`active`,
`outdated`, `restart` or `unavailable`) and a human-readable `message`.
