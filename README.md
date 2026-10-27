# AzuraCast Sweepers

Plays short station sweepers over song intros in the AzuraCast AutoDJ: the sweeper
ends right where the vocals start, and the music ducks under the voice.

Works with AzuraCast stable (0.24, Liquidsoap 2.4)
and rolling releases with Liquidsoap 2.5.

> [!TIP]
> Hear it in action on any [RadioMonster.FM](https://www.radiomonster.fm) station.

## Features

- Sweepers placed on the song intro, using AutoCue for cue points, loudness and handover
- Music ducking that follows the sweeper voice
- Several sweeper playlists, following their AzuraCast schedules, rotated as one pool
- Rotation by oldest play, optionally preferring the longest fitting sweeper
- Spacing rules around jingles and sweepers, and playlists that never get a sweeper
- Optionally moves a queued song with a fitting intro forward when a sweeper is due
- Optional reverb on the sweeper voice, with a browser preview
- Waveform editor for the intro end of each song
- Playback timeline showing for every track whether a sweeper played and why not
- All settings apply live; a station never fails to start because of the plugin

## Installation

On the AzuraCast host (default path `/var/azuracast`):

```bash
mkdir -p /var/azuracast/plugins
git clone --branch main https://github.com/RM-FM/azuracast-sweepers.git /var/azuracast/plugins/azuracast-sweepers
cd /var/azuracast/plugins/azuracast-sweepers
docker run --rm --user "$(id -u):$(id -g)" -e npm_config_cache=/tmp/npm-cache \
  -v "$PWD:/plugin" -w /plugin node:24-alpine sh -c 'npm ci --ignore-scripts && npm run build'
```

Keep the directory name `azuracast-sweepers`. Add to `/var/azuracast/docker-compose.override.yml`:

```yaml
services:
  web:
    environment:
      COMPOSER_PLUGIN_MODE: "true"
    volumes:
      - ./plugins/azuracast-sweepers:/var/azuracast/www/plugins/azuracast-sweepers:ro
```

Then run `docker compose up -d` in `/var/azuracast`. This briefly interrupts broadcasting
and loads the plugin's Liquidsoap module on all stations.

## Setup

1. Create one or more **Advanced** playlists with your sweepers (Advanced keeps them out
   of the normal rotation). Add a schedule to use a playlist only at certain times.
2. Open **Sweepers → Settings** in the station sidebar (below Media), select the
   playlists and save. The page also shows whether the module runs on the station.
3. Set the intro ends (see [Intro end](#intro-end)).

Settings apply live; no restart is needed.

The pages need station Media permission; changing settings also needs Broadcasting
permission.

## Pages

| Page | Contents |
| --- | --- |
| **Settings** | All options with help texts, module status and sweeper preview |
| **Intros** | Search songs and set the intro end on a waveform |
| **Playback Timeline** | Every played track with its sweeper status, intro end and start offset |

The timeline marks each track:

- **Played** (green): a sweeper played; the sweeper and its start offset are shown.
- **Missed** (red): a sweeper was due but could not play, for example "No intro end set"
  or "Intro too short for every sweeper".
- **Upcoming** (blue): the sweeper of the current song is still to come.
- No tag: the rules skip this track by design, for example jingles, "Too soon after a
  jingle" or "No intro".

Songs moved forward show which song they overtook; when a needed move was not possible,
the reason is shown. Click the intro value (or **Set**) to open the editor on that song.
The newest 300 tracks are kept per station.

## Intro end

`intro_end` is a custom field the plugin creates automatically. It holds seconds from
the start of the file:

| Value | Meaning |
| --- | --- |
| Blank | Unknown, no sweeper |
| `0` | No intro, no sweeper |
| Number | The vocals start here |

Ways to set it:

- **Intro editor**: **Sweepers → Intros**, or click the intro value in the Playback
  Timeline. Drag the marker on the waveform, set it at the playhead or type the seconds.
- **AzuraCast file editor**: Music Files → edit a file → Custom Fields → `intro_end`.
- **CSV import/export**: AzuraCast's bulk media CSV (Music Files → Bulk Media
  Import/Export) contains the column `custom_field_intro_end`. Export, fill in the values
  and import the file again to set many intros at once.
- **API**: `POST /api/station/{station_id}/sweepers/intro/{media_id}` with body
  `{"intro_end": 14.5}` and an AzuraCast API key, for example from an external intro
  detector. See [docs/API.md](docs/API.md).

> [!WARNING]
> Deleting a custom field in AzuraCast deletes all its values on all stations. The plugin
> therefore blocks deleting `Intro End` and changing its programmatic name. Still export
> the media CSV regularly as a backup.

## Settings

Defaults for a new installation:

| Setting | Default |
| --- | --- |
| Enable sweepers | On |
| Sweeper playlists | None (nothing plays until one is selected) |
| Songs without sweeper after a jingle / after a sweeper / before a jingle | 1 / 1 / 0 |
| Count Jingle Mode playlists | On |
| Other sweepers before a repeat | 1 |
| Prefer the longest fitting sweepers | Off (tolerance 20%) |
| Move a song with an intro forward | Off (sequential playlists: off) |
| Playlists without sweepers | None |
| Handover margin | 0.5 s |
| Limit start delay | Off (maximum 10 s, early start 2 s) |
| Reverb on the sweeper voice | Off (intensity 25%) |
| Maximum ducking | −7 dB |
| Jingle gain | 100% |

Notes on the less obvious options:

- **Sweeper playlists**: select one or more Advanced playlists. A playlist with an
  AzuraCast schedule is used only during its time slots; one without a schedule is
  always used. All playlists active at the same time share one rotation and one repeat
  rule, and a sweeper in several of them counts once. Disabled playlists are skipped.
  Example: everyday sweepers without a schedule plus a scheduled morning-show set.
- **Spacing rules**: a song carries a sweeper only if all three rules allow it; `0`
  turns a rule off. "Before a jingle" looks ahead in the AutoDJ queue, so its maximum
  is the AutoDJ queue length.
- **Jingles** are tracks from Jingle Mode playlists, the additional jingle playlists and
  the sweeper playlists. They never get a sweeper.
- **Playlists without sweepers**, for example news or shows, never get a sweeper and are
  never moved. Selecting a playlist group covers all its playlists.
- **Other sweepers before a repeat**: if no other sweeper fits, the song gets none. With
  a single sweeper, repeats are always allowed.
- **Prefer the longest fitting sweepers**: picks from the sweepers within the tolerance
  of the available intro length.
- **Move a song with an intro forward**: when a sweeper is due but the next song has no
  fitting intro, it changes places with the next queued song that has one. Jingles,
  excluded playlists and songs already sent to Liquidsoap never move; sequential
  playlists only when allowed.
- **Limit start delay**: normally the sweeper ends at the intro end minus the margin.
  If that would start it later than the maximum delay, it starts at the early start
  instead.
- **Reverb** is computed live; the sweeper files are not changed.

Settings take effect within five seconds without a restart. Sweepers already prepared
for upcoming songs are dropped; a sweeper on air finishes normally.

## How playback works

AzuraCast still picks every song. The plugin plans a sweeper when Liquidsoap fetches
the next song and mixes it in after the crossfade, before the station's audio
processing. Live DJs are never overlaid.

Sweeper timing comes from the file's manual cue points, otherwise from AzuraCast
AutoCue, otherwise from Liquidsoap's built-in AutoCue. Song cue-in is taken into
account. The sweeper's handover, not its file end, must fit the intro; its tail may
continue into the song.

The station's Liquidsoap log shows the decisions under the `sweepers` label.

### Safe start

- If the plugin cannot read its settings, the station starts with sweepers off.
- AzuraCast's configuration is checked with `liquidsoap --check`. If the sweeper module
  breaks it (for example after an AzuraCast upgrade), the station starts without
  sweepers and the Settings page shows why.
- Any runtime error leaves the music playing without a sweeper.

## Updating

```bash
(
  set -e
  cd /var/azuracast/plugins/azuracast-sweepers
  git pull --ff-only origin main
  docker run --rm --user "$(id -u):$(id -g)" -e npm_config_cache=/tmp/npm-cache \
    -v "$PWD:/plugin" -w /plugin node:24-alpine sh -c 'npm ci --ignore-scripts && npm run build'
  cd /var/azuracast
  docker compose exec web supervisorctl signal USR2 php-fpm
  docker compose exec web supervisorctl restart php-worker-fast php-worker-slow php-nowplaying
  sleep 10
  docker compose exec web rm -f /var/azuracast/www_tmp/app_routes.cache.php
)
```

This does not interrupt broadcasting. If the Liquidsoap module changed, the Settings page
asks for one broadcasting restart per station. Settings, media and intro ends are kept.

> [!IMPORTANT]
> Keep the order: delete the route cache only after all PHP processes run the new code.
> Otherwise an old process can rebuild it and plugin routes stop working.

## Troubleshooting

If AzuraCast does not start after installing, check `docker compose logs --tail=120 web`.
To disable the plugin temporarily, keeping its data:

```bash
mv /var/azuracast/plugins/azuracast-sweepers/events.php{,.disabled}
cd /var/azuracast && docker compose restart web
```

Rename the file back and restart `web` to enable it again.

## Removal

Disable sweepers, remove the plugin's entries from the Compose override and run
`docker compose up -d`; the stations then start without the module. Afterwards delete
the plugin directory. The plugin creates no database tables; `intro_end` and the
station file `sweepers.json` stay in place until you delete them.

## Development

```bash
./bin/check.sh --liquidsoap
```

Runs PHP lint and tests (run `composer install` first for the full set), the frontend
tests and build, and the Liquidsoap type check in containers. CI additionally runs
`vendor/bin/phpcs` and native playback tests. Code follows AzuraCast's conventions
(PSR-12, strict types).

AGPL-3.0-or-later. Copyright 2026 RM-FM. Independent of the AzuraCast project.
