# AzuraCast Sweepers

Plays short station sweepers over song intros in the AzuraCast AutoDJ: the sweeper
ends right where the vocals start, and the music ducks under the voice.

Works with AzuraCast stable (0.24, Liquidsoap 2.4)
and rolling releases with Liquidsoap 2.5.

> [!TIP]
> Hear it in action on any [RadioMonster.FM](https://www.radiomonster.fm) station.

## Quick Start

Run these commands **on your AzuraCast host**, using an account with Docker access
and permission to write to the AzuraCast directory. The examples use
`/var/azuracast`; adjust the path if your installation is elsewhere.
**Node and npm are not needed on the host**: setup builds everything in a temporary
Docker container.

### Install

```bash
mkdir -p /var/azuracast/plugins
git clone --branch main https://github.com/RM-FM/azuracast-sweepers.git /var/azuracast/plugins/azuracast-sweepers
cd /var/azuracast/plugins/azuracast-sweepers
bash bin/setup.sh
```

Setup builds the frontend and prepares the Compose override for you, preserving
existing plugin entries such as Moonbase AutoCue. No manual YAML editing is needed
for a standard installation.

> [!WARNING]
> On first installation, setup asks before recreating the AzuraCast web container.
> This interrupts broadcasting on all stations in that container. Confirm only when
> ready; declining leaves the configuration prepared for later.

Then create an **Advanced** playlist containing your sweepers, open
**Sweepers → Settings** in AzuraCast, select the playlist and configure the plugin.
Set your songs' [intro ends](#intro-end), check the module status and validate station
playback before enabling sweepers. See [Setup](#setup) for details.

### Update

Read the [release notes](CHANGELOG.md), then run:

```bash
(
  set -e
  cd /var/azuracast/plugins/azuracast-sweepers
  git pull --ff-only origin main
  bash bin/setup.sh
)
```

For an already configured installation, setup rebuilds the frontend and reloads
PHP/worker processes without restarting broadcasting. If container configuration
needs to be applied, it asks first. A changed Liquidsoap module may require a separate
broadcasting restart per station; check the release notes and the module status in
**Sweepers → Settings**. Your settings and intro values are kept.

## Features

- Sweepers timed to the song intro using Liquidsoap's resolved cue and crossfade metadata
- Music ducking that follows the sweeper voice
- Several sweeper playlists, following their AzuraCast schedules, rotated as one pool
- Rotation by oldest play, optionally preferring the longest fitting sweeper
- Spacing rules around jingles and sweepers, and playlists that never get a sweeper
- Optionally moves a queued song with a fitting intro forward when a sweeper is due
- Optional same-playlist replacement using AzuraCast's native rotation and repetition rules
- Optional reverb on the sweeper voice, with a browser preview
- Waveform editor for the intro end of each song
- Five-second intro auditioning per track or continuously across all result pages
- Live library search and an unknown-intro filter, with complete server-side pagination
- Playback timeline showing for every track whether a sweeper played and why not, with
  date range, search and pagination over the last 7 days
- All settings apply live; a station never fails to start because of the plugin

## Installation

Use the [Quick Start installation commands](#install). The following explains what
setup changes and how it handles existing installations.

Keep the directory name `azuracast-sweepers` below your AzuraCast installation's
`plugins` directory. Run the script as a user with Docker access and write permission
to that directory and the Compose override.

The script builds the frontend and creates or safely merges
`docker-compose.override.yml`. It adds `COMPOSER_PLUGIN_MODE: "true"` under
`services.web.environment` and the read-only plugin mount under `services.web.volumes`.
Existing services, comments, environment entries and mounts (including Moonbase
AutoCue/LS-Config-Replace) are kept. Changed existing overrides receive a backup
named `docker-compose.override.yml.sweepers-backup.*`. The candidate configuration is
validated with Docker Compose before replacing the override.

Conflicting plugin mounts, ambiguous YAML aliases/merge keys and custom `COMPOSE_FILE`
configurations stop setup with an error instead of being overwritten. Resolve those
manually, then rerun the script. Standard AzuraCast `docker-compose.yml` and optional
`docker-compose.override.yml` files with a `web` service are supported. A missing
`web` service stops setup before building or changing files; custom service names
require manual setup.

`COMPOSER_PLUGIN_MODE` is an AzuraCast setting: on start, the web container runs
`composer update` with the plugins' `composer.json` merged in, so AzuraCast can load the
plugin's PHP classes. This makes the container start a little slower.

When the running web container lacks the mount or plugin environment flag, setup
asks before running `docker compose up -d --no-deps web`. **This interrupts broadcasting
on all stations in that container.** When AzuraCast is not running at all, setup asks
before starting the whole stack with `docker compose up -d` instead. Answering no leaves
the files prepared and prints the command to run later. Noninteractive runs never
recreate or start containers. After applying, setup waits up to ten minutes for the
PHP services (the first start runs `composer update`) and clears the route cache.
If a later step fails, the error output names the backup and the exact `cp` command
to revert the Compose override.
The plugin is not enabled automatically; check the module status on each station.

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
| **Settings** | All options with help texts, module status, plugin version, update notice and sweeper preview |
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

Like AzuraCast's Song Playback Timeline, the header selects the time range (default:
last 24 hours; presets for today, yesterday and the last 7 days, or any custom range),
shown in the station's time zone. The table offers live search, the grey refresh
button, page sizes and a filter for songs with a sweeper. The timeline keeps 7 days in
the station file `sweepers-history.json`.

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

On **Sweepers → Intros**, search updates automatically after typing, without a Search
button. All matching files are available with AzuraCast's page controls (25 per page by
default, up to all files), without a 100-file limit. The
**Only unknown/untagged tracks** switch beside the search box limits the list to files with a missing or blank
`intro_end`; a saved zero still counts as tagged. Search and pagination apply to
the filtered results. Saving an intro removes that track from the filtered list.
**Intro** (play icon) plays a track directly from its saved `intro_end` for 5 seconds
without opening the editor. **Scan Intros** in the card header starts at the first playable
track on the current page and continues automatically through subsequent matching
pages, stopping after the last result. Blank intro ends are skipped; zero starts at
the beginning of the file. A track ending sooner advances immediately.
The same button changes to **Stop** during playback. Pressing it, changing the search
or page, opening an editor, or leaving the
Intros page stops the preview. Audio or page-loading failures stop scanning and show
an error. These previews only play in the browser and do not affect station playback.

> [!WARNING]
> Deleting a custom field in AzuraCast deletes all its values on all stations. The plugin
> therefore blocks deleting `Intro End` and changing its programmatic name. Still export
> the media CSV regularly as a backup.

## Settings

Settings are grouped into **General**, **Spacing & Jingles**, **Selection & Timing**
and **Audio & Preview** tabs. Switching tabs keeps unsaved changes; **Save Changes**
saves all tabs together. Module status, plugin version and update notices appear
in the **Status** section of **General**, followed by separate sweeper playlist and
playlist exclusion sections. Module warnings and their restart action remain above
the settings card, visible regardless of the selected tab.

Related settings use two columns on large screens and stack on smaller screens.
Numeric inputs stay compact while their explanations use the available column width.

Playlist selections appear as removable source tags with playlist-type icons. Use **Add Playlist** to
check or uncheck playlists in the dropdown, or the **x** on a label to remove one. Changes take effect when you
save the settings.

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
| Swap with another song from same playlist | Off |
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
  never moved or replaced. Selecting a playlist group covers all its playlists.
- **Other sweepers before a repeat**: if no other sweeper fits, the song gets none. With
  a single sweeper, repeats are always allowed.
- **Prefer the longest fitting sweepers**: a preference, not an extra eligibility
  rule. At 20% tolerance, bodies filling 80–100% of the available intro are preferred,
  rotating by oldest actual play within that range. The available intro is
  `intro_end` minus the song cue-in and handover margin. If no eligible sweeper is in
  range, regular oldest-played rotation chooses any other fitting sweeper, not just
  the longest remaining one. For example, if the long sweepers are blocked by repeat
  protection or reservations, an eligible shorter sweeper can still play.
  Tolerance never bypasses timing, schedules, reservations or repetition protection;
  if none is eligible under the regular rules either, no sweeper plays.
- **Move a song with an intro forward**: when a sweeper is due but the next song has no
  fitting intro, it changes places with the next queued song that has one. Jingles,
  excluded playlists and songs already sent to Liquidsoap never move; sequential
  playlists only when allowed.
- **Swap with another song from same playlist**: optionally replaces that next song
  with a fitting candidate returned by AzuraCast's native playlist queue, respecting
  sequential/shuffle/random order and the playlist's repetition protection, including
  inherited group rules. Both matching options work independently; moving an already
  queued song is tried first when both are on. Requests, merged playlists/groups,
  excluded playlists and entries already sent to Liquidsoap are never replaced.
  **Also move or swap songs from sequential playlists** applies to both options.
  The displaced song returns to its existing playlist rotation slot, keeping its
  weight and history; it is not appended directly to the playback queue. It may be
  replaced again when encountered later. Songs still open in the current round are
  tried first; if none fits, songs already played in this round follow, longest ago
  first, without reshuffling or resetting the round. Repetition protection is not
  relaxed: no eligible match, a busy native queue builder or an expired 500 ms
  matching budget leaves the original song in place.
  Matching improves the chance of a sweeper; it does not guarantee one. AzuraCast
  continues rebuilding the queue's expected times. The timeline distinguishes
  a forward move from a same-playlist replacement and explains an unsuccessful match.
- **Limit start delay**: normally the sweeper ends at the intro end minus the margin.
  If that would start it later than the maximum delay, it starts at the early start
  instead.
- **Reverb** is computed live; the sweeper files are not changed.

Settings take effect within five seconds without a restart. A sweeper already prepared
for an upcoming song still plays with the timing it was planned with; only switching
sweepers off drops it. A sweeper on air finishes normally.

## How playback works

AzuraCast schedules the playlists and builds the playback queue. Optional matching
either exchanges two queued songs or replaces the next unsent song with a candidate
from the same native playlist rotation; it does not run a separate scheduler.
The plugin plans a sweeper when Liquidsoap fetches
the next song and mixes it in after the crossfade, before the station's audio
processing. Live DJs are never overlaid.

Timing uses the effective cue and crossfade metadata resolved by Liquidsoap, including
manual cue points and cached annotations. Song cue-in is taken into account. The
sweeper's handover, not its file end, must fit the intro; its tail may continue into
the song.

You do not need to enable or change your station's AutoCue setup. The plugin uses the
resolved metadata regardless of whether it comes from manual settings, AzuraCast's
built-in AutoCue or an external integration such as Moonbase.

Only when a **sweeper** lacks a complete pair of cue-in and cue-out values does the
plugin use Liquidsoap's internal AutoCue analysis as a fallback. This finds cue and
mix points so leading/trailing silence or very quiet artefacts do not distort the
sweeper's timing. The fallback runs on sweepers only, not songs, and does not write
its results to AzuraCast's cue fields or AutoCue cache. It does not detect song intros:
those still come from the song's `intro_end` field.

The station's Liquidsoap log shows the decisions under the `sweepers` label.

### Safe start

- If the plugin cannot read its settings, the station starts with sweepers off.
- AzuraCast's configuration is checked with `liquidsoap --check`. If the sweeper module
  breaks it (for example after an AzuraCast upgrade), the station starts without
  sweepers and the Settings page shows why.
- Any runtime error leaves the music playing without a sweeper.

## Updating

The Settings page shows the installed version (from the release notes, plus the checked-out
branch and commit) and checks GitHub's releases every few hours. When a newer release exists,
it links the release notes and states whether the update applies without restarting
broadcasting. Updates are installed on the host, as below; AzuraCast's own web updater only
updates AzuraCast itself, and the plugin directory is read-only inside the container.

Read the [release notes](CHANGELOG.md) before updating. Every release explicitly
states whether an **AzuraCast Broadcast (Liquidsoap) restart** is required.
Frontend rebuilds and PHP/worker reloads are separate and do not restart broadcasting.

Use the [Quick Start update commands](#update). The same setup script handles both
installation and updates.

For an already configured container, this rebuilds the frontend, reloads PHP-FPM,
restarts PHP workers/NowPlaying and then clears the route cache without interrupting
broadcasting. Missing mount/environment configuration follows the confirmed
container-recreation flow described above.

Only if the Liquidsoap module's code changed (comments
do not count), the Settings page asks for one broadcasting restart per station and offers a
**Restart Broadcasting** button to users with Broadcasting permission. Settings, media and
intro ends are kept.

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
station files `sweepers.json` and `sweepers-history.json` stay in place until you
delete them.

## Development

```bash
./bin/check.sh --liquidsoap
```

Runs PHP lint and tests (run `composer install` first for the full set), the frontend
tests and build, and the Liquidsoap type check in containers. CI additionally runs
`vendor/bin/phpcs` and native playback tests. Code follows AzuraCast's conventions
(PSR-12, strict types).

`tests/replacement.php` runs separately in CI's pinned AzuraCast image with an
isolated MariaDB `sweepers_test` database (`SWEEPER_TEST_DB_HOST`). It uses the real
playlist repositories, duplicate prevention, queue and rotation entities with
minimal station/library fixtures. Never point integration tests at a station database.

CI tests every push against pinned AzuraCast versions. The daily **AzuraCast
compatibility** workflow tests `develop` against AzuraCast's rolling `latest` image and the
matching `main` sources: the classes, methods, routes and Liquidsoap lines the plugin uses
(`tests/compat.php`), the sidebar markup, the Liquidsoap module and playback scenarios.
Scheduled runs skip the checks when the AzuraCast commit has not changed since the last
passing run; failed runs are retried daily, manual runs always check.
On failure it opens an issue labelled `azuracast-compat` with a short report, which
notifies the repository owner by email; a new AzuraCast commit that still fails adds a
comment, and a later passing run closes the issue. GitHub starts scheduled workflows from
the default branch only, so the schedule and manual runs activate once the workflow reaches
`main`; until then, it runs when the workflow or `tests/compat.php` changes on `develop`.

AGPL-3.0-or-later. Copyright 2026 RM-FM. Independent of the AzuraCast project.
