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
| `heartbeat` | Renew live reservations and acknowledge playback or release events |

These endpoints require AzuraCast's internal connection and the station's adapter
API key. They are not public control endpoints.
