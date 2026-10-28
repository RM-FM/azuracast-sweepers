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
station Media permission). Optional query parameters:

| Parameter | Meaning |
| --- | --- |
| `start`, `end` | ISO 8601 times; only tracks that started in this range (either bound may be omitted) |
| `search` | Matches title, artist or path of the song, sweeper, overtaken or replaced song (up to 200 characters) |
| `played` | `1` returns only songs with a played sweeper; default `0` |
| `page` | One-based page, default 1 |
| `per_page` | 10, 25 (default), 50, 100, 250, 500, or 0 for all rows |

It returns `rows` (newest first), `total` (all matching tracks), `page`, `per_page` and
`retention_days` (7). Each row describes one
played track, with `at` (Unix time when the track started; for entries recorded
before all tracks were listed, when the sweeper started), `song` and `sweeper`
(`id`, `title`, `artist`, `path`, `intro` with the current intro end in seconds or `null`; `null` for a deleted file), `result`, `offset`
(seconds after the song's cue-in when a sweeper played, otherwise `null`), `upcoming`
(the newest track's sweeper is still to come), `moved`
(the song was moved forward), `moved_before` (the overtaken song, or `null`),
`replaced` (the song replaced another from the same playlist), `replaced_before`
(the displaced song, or `null`) and
`unmoved` (why a needed move was not possible: `move_none`, `move_jingle`,
`move_sequential`, `replace_none`, `replace_ineligible`, `replace_duplicates`,
`replace_changed`, `replace_timeout`, `replace_busy`, or `null`).
`result` is `played`, `scheduled` (sweeper not started yet) or the reason for no
sweeper: `no_intro`, `intro_zero`, `intro_before_cue`, `no_fit`, `repeat`, `no_sweepers`,
`off_schedule`, `jingle_follows`, `excluded`, `after_jingle`, `after_sweeper`, `jingle`, `other`,
`live`, `disabled`, `late`, `skipped`, `settings`, `unavailable`, `error` or
`not_planned`.

The reverb preview reads `GET /sweepers/{station_id}/media?playlist={playlist_ids}`
(same permissions; comma-separated IDs). For Advanced playlists of the station it returns `rows` with
`id`, `title`, `artist`, `path`, `cue_in`/`cue_out` (the AutoCue range Liquidsoap
plays) and `audio_url`, each file once. The browser applies the reflections itself; nothing is saved.
`GET /sweepers/{station_id}/media?id={media_id}` returns that single library file in
the Intro Manager row format, so the timeline can open the intro editor on it.

`GET /sweepers/{station_id}/media?search={query}&page={page}&per_page={size}` lists all
matching library files, ordered by path then media ID. `page` is one-based (default 1);
`per_page` accepts the same sizes as the timeline (default 25, 0 for all).
It returns `rows`, `total` (all matching files), `page` and `per_page`.
Search matches filename/path, title or artist; there is no 100-result ceiling.
The optional `unknown=1` filter returns only files with a missing, null or blank
`intro_end` custom field, excluding saved zeroes. Default `unknown=0` includes all
files; `total` and pagination reflect the filter.
These session-authenticated requests require station Media permission.

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
| `arrange` | Before each AutoDJ fetch: optionally move a queued song forward, then try a replacement from the same playlist |

The `arrange` request carries the module's spacing state (`counted` queue ID of the
last counted track, `songs_since_jingle`, `songs_since_sweeper`, `previous_song`,
`previous_clear`) and the sweeper measurements (`jingles`, as for `plan`). The
response is `{"moved": "<queue id>", "before": "<queue id>", "replaced": "<queue id>"}`.
Forward moves set `moved` and `before`; replacements set only `replaced`, retaining
the target queue ID and playlist chain. All are empty when nothing changed.
`swap_for_intro` and `swap_from_playlist` independently enable the two strategies,
in that order. Replacement uses the current native playlist rotation and native
duplicate prevention without allowing duplicates or resetting an exhausted rotation.
The displaced row is removed from the native history used for candidate checks;
actual played history and all other pending queue entries remain in effect.
The displaced playlist membership becomes queued again; its weight and history are
preserved. The replacement membership is consumed at the target's expected play time.
Replacement shares the native `nowplaying_{station short name}` lock and skips a
busy queue builder rather than waiting. Matching has a 500 ms budget measured from
the start of `arrange`; an expired budget refuses replacement, even if a candidate
was found. Database operations can add response overhead; the final queue update
also checks that the original entry is still next, unchanged and unsent.

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

Heartbeat `events` carry `event`, `token`, `detail`, `media_id`, `title` and
`artist`. `start` is sent once per started track with the module's outcome in
`detail` (`scheduled`, a reason such as `after_jingle`, or `none` when the plugin
decided at planning time); `played` marks an actual sweeper start; `release` frees
a reservation, with the reason in `detail` when the sweeper did not play.

## Panel rotation settings

The session/CSRF-protected `POST /sweepers/{station_id}/settings` requires station
Broadcasting permission. It accepts the existing settings plus:

| Field | Default | Meaning |
| --- | --- | --- |
| `enabled` | `true` | Use sweepers; without `playlist_ids` no sweeper plays |
| `playlist_ids` | `[]` | Advanced sweeper playlists (integer array); see below |
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
| `sweeper_gap` | `1` | Other sweepers that must play before the same sweeper is used again, 0–100 (0 allows repeats) |
| `longest_fit` | `false` | Prefer bodies closest to the available intro length |
| `length_tolerance` | `20` | Preferred range below the available intro length, percent 0–100; not an eligibility restriction |
| `swap_for_intro` | `false` | Move a later queued song with a fitting intro forward when a sweeper is due |
| `swap_from_playlist` | `false` | Replace with a fitting candidate from the same native playlist rotation |
| `swap_sequential` | `false` | Allow both movement and replacement for sequential playlists |
| `excluded_playlist_ids` | `[]` | Station playlists (or groups) whose tracks never receive a sweeper, move or get replaced |
| `reverb` | `false` | Add a gentle reverb to the sweeper voice |
| `reverb_intensity` | `25` | Reverb level, percent 0–100; sent to the module as `reverb` (0–1) in each plan |

Saved settings from earlier versions remain valid: a `max_wait` of `null` reads as
`limit_delay: false` with `max_wait: 10`, a numeric `max_wait` as `limit_delay: true`,
and a single `playlist_id` as `playlist_ids: [playlist_id]`.

`longest_fit` only changes candidate priority. Timing, active schedules, reserved
sweepers and repeat protection are checked first. Preferred bodies are at least
`(intro_end - cue_in - margin) * (1 - length_tolerance / 100)` seconds long.
Within that range, oldest actual playback wins (media ID breaks ties).
Other eligible fitting bodies remain in regular oldest-played order after the
preferred group. If that group is empty, the choice is exactly regular rotation;
there is no longest-only fallback and tolerance alone never produces an empty plan.
At 0%, only exact fills receive priority; if none exists, regular rotation applies.
At 100%, all fitting bodies share the regular rotation.

The `catalog` lists the sweepers of all selected playlists, so they are measured
before their time slot starts. Planning and both song-matching options use only the
playlists active now: enabled and inside one of their AzuraCast schedule items
(checked with AzuraCast's scheduler, without its queue-resetting special rules), or
without any schedule. All active playlists form one pool: each file counts once,
rotation follows the shared play history and `sweeper_gap` counts across them.
When playlists are selected but none is active, the timeline reports `off_schedule`.
Deleted or no longer Advanced playlists are skipped at runtime and rejected on save.

The panel state supplies Advanced `playlists` for sweeper selection (with `enabled`
and `scheduled`), all station
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
