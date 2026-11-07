# AzuraCast Sweepers

Plays short station sweepers over song intros in the AzuraCast AutoDJ: the sweeper
ends right where the vocals start, and the music ducks under the voice.

Works with AzuraCast stable (0.24, Liquidsoap 2.4)
and rolling releases with Liquidsoap 2.5.

> [!TIP]
> Hear it in action on any [RadioMonster.FM](https://www.radiomonster.fm) station.

## Quick Start

Run these commands on your AzuraCast server.

### Install

```bash
mkdir -p /var/azuracast/plugins
git clone --branch main https://github.com/RM-FM/azuracast-sweepers.git /var/azuracast/plugins/azuracast-sweepers
cd /var/azuracast/plugins/azuracast-sweepers
bash bin/setup.sh
```

The script handles installation for you. At the end it offers the optional
[Intro Detector](#intro-detector-optional); answer **N** if you do not want it.

> [!WARNING]
> If installation requires interrupting broadcasting, the script asks first.
> Confirm only when ready.

Continue with [First station setup](#first-station-setup).
For other installation options, see [manual installation](docs/ADVANCED.md#manual-installation).

<a id="updating"></a>

### Update

Read the [release notes](CHANGELOG.md), then run:

```bash
cd /var/azuracast/plugins/azuracast-sweepers
git pull --ff-only origin main
bash bin/setup.sh
```

Settings and intro values are kept. If an interruption is needed, the script asks first.
If no Intro Detector is installed, setup offers it once, also on an update. An installed
Intro Detector is updated to the matching release at the same time; this needs
`sudo bash bin/setup.sh`.

**Sweepers → Settings** shows the installed version and available updates.
After updating, check whether Settings asks you to **Restart Broadcasting**.
AzuraCast's own updater does not update this plugin.

The [manual update procedure](docs/ADVANCED.md#manual-update) is available if you
do not want to use the setup script.

## First station setup

1. Keep sweepers disabled while preparing the station.
2. Create one or more **Advanced** playlists containing your sweepers. Advanced
   playlists keep them out of the normal music rotation. Add schedules if a set
   should only be used at certain times.
3. Open **Sweepers → Settings** in the station sidebar below Media. Select your
   **Sweeper Playlists** and use **Exclude Playlists from Sweepers** for news,
   shows or other content that should never carry a sweeper. Click **Save Changes**.
4. Set the songs' [intro ends](#intro-end). Songs with an unknown intro or no intro
   do not receive a sweeper.
5. Check the module status at the top of Settings. If it requests a broadcasting
   restart, arrange a suitable time and restart before testing.
6. Enable sweepers for a controlled playback test. Check the sound and
   **Playback Timeline** before leaving the feature enabled for normal operation.

Settings changes apply live after saving; changing settings alone does not require
a broadcasting restart.

The pages require station Media permission; changing settings and restarting
broadcasting also require Broadcasting permission.

Sweeper pages use AzuraCast's station layout and client-side router. Switching
between them does not reload the dashboard; direct links and browser history
open the selected page. Former `?sweepers=...` station links redirect to the
corresponding page.

## Everyday use

| Page | Use it to |
| --- | --- |
| **Settings** | Select playlists, adjust spacing, timing and sound, preview a sweeper, and check module/update status |
| **Intro Manager** | Search songs, edit intro ends on a waveform, or listen through saved intro points |
| **Playback Timeline** | See which tracks carried a sweeper and why others did not |

### Intro end

The plugin automatically creates the `intro_end` custom field. It records where
the sweeper should end: where the vocals start or, in songs that open without
vocals, where the musical intro ends. Values are seconds from the **original file start**:

| Value | Meaning |
| --- | --- |
| Blank | Unknown, no sweeper |
| `0` | No intro, no sweeper |
| Positive number | The intro ends here |

Open **Sweepers → Intro Manager** and select a song. Use the waveform timeline,
zoom and volume controls with Play/Stop, then **Set Intro** at the playhead or drag
the intro marker. **No Intro** sets zero. **Save Changes** saves only the intro end;
**Close** discards unsaved edits. There is no numeric input or Clear action.
You can also open the editor from a track's intro value in the Playback Timeline.

Search updates as you type. **Only unknown/untagged tracks** shows songs with blank
intro ends; a saved zero counts as tagged. Pagination covers all matching songs.

- **Intro** plays five seconds starting at the saved intro end, without opening the editor.
- **Scan Intros** listens through the current page and subsequent result pages
  automatically. Blank values are skipped; zero starts at the file beginning.
  Press **Stop** to end scanning. Changing the search/page, opening an editor or
  leaving the page also stops it.

Previews play only in your browser and do not affect the station.

For bulk editing, use AzuraCast's **Music Files → Bulk Media Import/Export** and the
CSV column `custom_field_intro_end`. Individual values can also be edited under
**Music Files → Edit → Custom Fields**. External tools can use the [Intro cue API](docs/API.md).

> [!WARNING]
> Deleting a custom field in AzuraCast deletes its values on all stations. The plugin
> protects `Intro End` from deletion and renaming, but you should still export the
> media CSV regularly as a backup.

### Settings

The five tabs are **General**, **Sweeper Rules**, **Spacing & Jingles**,
**Selection & Timing** and **Audio & Preview**. **Save Changes** saves all tabs together.

- Select playlists with **Add Playlist**; uncheck an entry or remove its tag to deselect it.
- Optionally give listener requests or songs from a playlist their own sweepers under
  **Sweeper Rules**.
- Set spacing around jingles and sweepers. A value of `0` turns that spacing rule off.
- Optionally link sweepers and jingles that say the same, so they share one rotation.
- Optionally move or swap songs to find a fitting intro; both options are off by default.
- Adjust ducking and optional reverb, using the preview to check the sound.

Scheduled sweeper playlists follow their AzuraCast schedules. Several active
playlists share one rotation; live DJs are never overlaid. You do not need to change
your station's AutoCue setup.

The page provides help for each option. See [settings reference](docs/ADVANCED.md#settings-reference)
for defaults, matching rules and timing details.

### Playback Timeline

- **Played**: a sweeper played.
- **Missed**: a sweeper was due but could not play; the reason is shown.
- **Upcoming**: the current song's sweeper is still to come.
- No tag: the rules deliberately skipped the track.

Use the date range, live search and sweeper filter to check playback over the last
seven days. Times use the station's time zone.

## Intro Detector (optional)

The [Intro Detector](https://github.com/RM-FM/azuracast-intro-detector) fills in the
intro end of your songs for you. It detects both vocal intros (where the singing starts)
and musical intros (in songs that open without vocals). Intros entered by hand are never
overwritten; correct any result in the Intro Manager.

Analysis uses paid GPU time at [Runpod](https://www.runpod.io/), about USD 4 per
1,000 songs. Each song is analyzed only once, also when several stations play it.
The detector is a separate project under the MIT license; its models keep their own
licenses, listed in its [third-party notices](https://github.com/RM-FM/azuracast-intro-detector/blob/main/THIRD_PARTY_NOTICES.md).

**Install:** answer **Y** when setup offers it, or later run `sudo bash bin/setup.sh --detector`.

**Switch it on for a station:**

1. Create a Runpod API key with read/write access in the
   [Runpod console](https://console.runpod.io/user/credentials) and add funds to the
   account. Several stations may use the same key.
2. Open **Sweepers → Intro Detector**, tab **Settings**. Switch the detector on, enter
   the key and save. New uploads are analyzed automatically from now on.
3. On the **Status** tab, **Older songs** shows how many songs in the library still
   have no intro, what analyzing them would cost and the budget left. **Analyze them**
   starts it.

**Status tab:** the number of analyzed, waiting and failed songs, a live progress card
while an analysis runs, recent runs and failed songs with **Retry**.

**Settings tab:**

| Setting | Meaning |
| --- | --- |
| Use the Intro Detector for this station | Switches detection on or off |
| Analyze new uploads automatically | Otherwise new songs wait for **Analyze now** |
| Minimum / maximum song length | Shorter files (station IDs) and longer files (DJ mixes) are skipped |
| Skip playlists | Songs in these playlists are not analyzed; Jingle Mode playlists are always skipped |
| Runpod API key | Stored securely and never shown again |
| Monthly budget | Analysis pauses for the station when its spending reaches it |
| Idle timeout | How long the GPU waits for more songs before it shuts down (default 5 minutes) |
| Analyze this station's songs in the EU only | Uses GPU workers in EU data centers only; fewer GPUs are available there, so songs may wait longer. Each station sharing a Runpod key keeps its own choice |

Below the settings, the page shows the station's spending this month, the spending of
the whole Runpod account and how many stations use it, the Runpod balance and whether a
GPU is running, with a countdown to its shutdown.

Viewing the page needs the station's Media permission; changing settings or starting an
analysis needs Broadcasting, like the sweeper settings.

The detector manages its disk space on its own. It keeps about 0.1 MB of analysis data
per song, so a later detector update can re-evaluate songs without renting a GPU again.
Data of songs removed from every library is deleted after a day. When less than 1 GB of
disk space is free, analysis pauses and the page says so.

## More documentation

- [Advanced guide](docs/ADVANCED.md): Compose setup and backups, settings reference,
  playback internals, troubleshooting, removal and development.
- [Intro cue API](docs/API.md): authenticated integration for external tools.
- [Release notes](CHANGELOG.md): changes and broadcasting restart requirements.

AGPL-3.0-or-later. Copyright 2026 RM-FM. Independent of the AzuraCast project.
