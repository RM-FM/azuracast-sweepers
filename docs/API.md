# Intro import API

`POST /api/station/{station_id}/sweepers/intro/{media_id}`

Authenticate with an AzuraCast API key whose account has Media permission for the
station. Use `X-API-Key` over HTTPS, following AzuraCast's normal API configuration.
Never embed a key in frontend assets or media metadata.

Read `unique_id` and `mtime` from AzuraCast's media API before submitting a result.
Include `size` when available; all values must describe the reviewed recording.

```json
{
  "unique_id": "current-media-unique-id",
  "mtime": 1792640000,
  "intro_end": 14.5,
  "kind": "vocal",
  "status": "confirmed"
}
```

`kind`: `vocal`, `musical` or `30s+`.

`status`:

- `confirmed`: publish the numeric boundary.
- `no_intro`: publish zero.
- `unknown`: clear the active boundary.
- `veto`: clear the active boundary and retain the supplied candidate for review.

For `30s+`, submit `intro_end: 30`. The plugin validates all times against the file
duration. Changed media identity or a protected manual review returns HTTP 409.
Omitting a cue does not mean zero. Importing an intro does not modify cue-in/out,
fades, gain or unrelated custom fields.

The private detector can use any implementation. Only this result contract crosses
the boundary. Importing results does not start a GPU worker or scan media.

## Internal playback endpoints

The Liquidsoap module uses `/api/internal/{station_id}/liquidsoap/sweepers/`:

| Endpoint | Purpose |
| --- | --- |
| `catalog` | Get normally annotated jingle requests |
| `plan` | Select/reserve a fitting jingle using resolved request metadata |
| `played` | Legacy playback acknowledgment |
| `heartbeat` | Renew live reservations and acknowledge playback or release events |

These endpoints require AzuraCast's internal connection and the station's adapter
API key. They are not public control endpoints.
