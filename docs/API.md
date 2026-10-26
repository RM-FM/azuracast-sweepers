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

The Playback Timeline page reads `GET /sweepers/{station_id}/history` (signed-in session,
station Media permission). It returns `limit` (200) and `rows`, newest first, each
with `at` (Unix time when the sweeper started), `song` and `sweeper` (`title`,
`artist`, `path`, or `null` for a deleted file), `offset` (seconds after the
song's cue-in, or `null`), `moved` (the song was moved forward for this sweeper)
and `moved_before` (the overtaken song, or `null`). Only sweepers that actually
started playing are recorded.

The reverb preview reads `GET /sweepers/{station_id}/media?playlist={playlist_id}`
(same permissions). For an Advanced playlist of the station it returns `rows` with
`id`, `title`, `artist`, `path`, `cue_in`/`cue_out` (the AutoCue range Liquidsoap
plays) and `audio_url`. The browser applies the reflections itself; nothing is saved.

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
| `arrange` | Before each AutoDJ fetch: move a queued song with a fitting intro forward when enabled |

The `arrange` request carries the module's spacing state (`counted` queue ID of the
last counted track, `songs_since_jingle`, `songs_since_sweeper`, `previous_song`,
`previous_clear`) and the sweeper measurements (`jingles`, as for `plan`). The
response is `{"moved": "<queue id>", "before": "<queue id>"}`, both empty when the
order is unchanged or `swap_for_intro` is off.

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
| `enabled` | `true` | Use sweepers; without `playlist_id` no sweeper plays |
| `songs_after_jingle` | `1` | Songs without sweeper after a jingle, integer 0–1000 |
| `songs_after_sweeper` | `1` | Songs without sweeper after a sweeper, integer 0–1000 |
| `songs_before_jingle` | `0` | Queued tracks checked for a following jingle, 0 to the AutoDJ queue length |
| `count_jingle_mode` | `true` | Recognize the native `jingle_mode` annotation |
| `jingle_playlist_ids` | `[]` | Additional station playlist IDs (integer array) |
| `margin` | `0.5` | Handover margin in seconds, 0–5 |
| `limit_delay` | `false` | Use the early start when the aligned start would wait longer than `max_wait` |
| `max_wait` | `10` | Longest wait in seconds, 0–3600; kept while `limit_delay` is off |
| `early_start` | `2` | Seconds after the song cue-in used when `max_wait` is exceeded, 0–60; never later than the aligned start |
| `duck_db` | `-7` | Maximum ducking in dB, −20–0 |
| `jingle_gain` | `1` | Sweeper volume factor, 0–2 (shown as 0–200% in the panel) |
| `longest_fit` | `false` | Prefer bodies closest to the available intro length |
| `length_tolerance` | `20` | Eligible range below the intro length, percent 0–100 |
| `swap_for_intro` | `false` | Move a later queued song with a fitting intro forward when a sweeper is due |
| `swap_sequential` | `false` | Also move songs from sequential playlists |
| `excluded_playlist_ids` | `[]` | Station playlists (or groups) whose tracks never receive a sweeper and never move |
| `reverb` | `false` | Add a gentle reverb to the sweeper voice |
| `reverb_intensity` | `25` | Reverb level, percent 0–100; sent to the module as `reverb` (0–1) in each plan |

Saved settings from earlier versions remain valid: a `max_wait` of `null` reads as
`limit_delay: false` with `max_wait: 10`, a numeric `max_wait` as `limit_delay: true`.

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
