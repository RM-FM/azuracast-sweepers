# Release notes

## Pending

### Features

- Limit how often the same song can be replaced before keeping its next turn.
  The default is two replacements; zero allows unlimited replacements.

### Fixes

- Preserve sweeper playback confirmations and rotation history across expired
  reservations and API outages. Unconfirmed timeline entries no longer claim a
  sweeper failed to start; prefetch and cancelled reverb sources do not count as playback.
- Load Sweeper pages through AzuraCast's router and station layout without a
  dashboard reload or a hidden station page; preserve direct links and browser history.
- Stabilize waveform zoom in the Intro Manager and align its timeline, zoom,
  volume, Play/Stop and Close/Save controls with AzuraCast's visual editor.
  Intro editing uses Set Intro and No Intro instead of a numeric input or Clear.
- Retry a failed sweeper-planning request once and report persistent failures as
  planning errors. Buffered playback events retain their original timestamps;
  missing decisions no longer imply a station restart.
- Keep station state readable after root-run maintenance and show loading errors
  instead of a blank plugin page.
- The Settings restart button restarts only Liquidsoap, leaving Icecast running.
- Song matching releases sweeper reservations for prefetched songs blocked by spacing,
  so they no longer prevent a fitting queued-song move or same-playlist replacement.
  Played-sweeper repetition protection is unchanged.

### Update notes

- **AzuraCast Broadcast (Liquidsoap) restart: Required** to load reservation releases,
  planning retries and self-contained playback confirmations. This change is not yet released.

## 1.2.0 - 2026-10-28

### Features

- One setup script handles installation and updates, preserves existing Compose
  plugin settings and asks before a required container recreation interrupts broadcasting.

### Improvements

- Settings use four tabs, native half-width and full-width inputs, and removable
  playlist source tags with a checkbox dropdown and one shared save action.
- The intro-editing page is named Intro Manager.
- Saving settings no longer cancels a sweeper already prepared for an upcoming song;
  only switching sweepers off does.
- Same-playlist replacement also considers songs already played in the current
  shuffle/sequential round when none of the remaining songs fits, instead of giving up
  near the end of a round.

### Fixes

- A song counts as fitting for a move or replacement only when a sweeper is actually
  free for it: reservations the spacing rules will drop do not relax the repeat
  protection.
- Same-playlist replacement waits for AzuraCast's queue builder within the matching
  budget instead of giving up at once.
- Replacement runs on its own when queued-song moving is off.
- The timeline tells sweepers reserved for upcoming songs apart from a recent repeat.

### Update notes

- **AzuraCast Broadcast (Liquidsoap) restart: Required** when upgrading from 1.1.0,
  to load the updated prepared-sweeper handling and queue-matching request budget.
  Until then, stations keep the previous module behaviour.
- Run `bash bin/setup.sh` to rebuild the frontend and reload PHP/worker processes.
  This does not replace the required station broadcasting restart.

## 1.1.0 - 2026-10-28

### Features

- The Settings page shows the installed plugin version, checks GitHub for newer
  releases and states from their release notes whether updating needs a broadcasting
  restart. When a station must restart to load the sweeper module, a Restart
  Broadcasting button is offered to users with Broadcasting permission.
- Optional same-playlist song replacement improves sweeper matching using
  AzuraCast's native playlist order and repetition rules. Displaced songs return to
  their rotation; queued-song movement remains an independent, first-choice option.

### Improvements

- Longest-fit selection prefers eligible sweepers in the tolerance range, then falls
  back to regular rotation instead of excluding shorter fitting alternatives.
  Repeat, reservation and timing protection still apply.
- Clearer matching controls and timeline explanations, a compact tolerance field,
  and updated guidance on selection and queue behavior.
- The Playback Timeline matches AzuraCast's Song Playback Timeline: date range in the
  header (default last 24 hours, station time zone), live search, refresh button,
  page sizes and pagination. It now keeps 7 days instead of the newest 300 tracks,
  stored separately in `sweepers-history.json`; existing entries move there
  automatically.
- Settings and Intros follow AzuraCast's form and data-table layout; playlist choices
  are checkbox lists instead of multi-select boxes, and Intros offers page sizes.

### Fixes

- Stations no longer ask for a broadcasting restart after updates that only changed
  comments in the Liquidsoap module.

### Update notes

**AzuraCast Broadcast (Liquidsoap) restart: Not required** when upgrading from
1.0.1. The Liquidsoap module code is unchanged and running stations are recognized
as current. Rebuild the frontend and reload PHP/worker processes using the normal update steps.
New same-playlist replacement is off by default.

## 1.0.1 - 2026-10-28

### Features

- Listen to five seconds from a saved intro end directly per track, or scan intros
  continuously across all result pages.
- Search the library as you type, with complete pagination and an unknown/untagged
  intro filter that keeps saved zero cues distinct from missing cues.

### Improvements

- Streamlined waveform playback and intro-editing controls.
- Clearer AutoCue documentation: existing resolved cue metadata is used; internal
  analysis is only a fallback for sweepers missing cue points, not intro detection.

### Update notes

**AzuraCast Broadcast (Liquidsoap) restart: Not required** when upgrading from
1.0.0. This release changes the browser interface and media listing, not the
Liquidsoap module or generated broadcast configuration. Rebuild the frontend and
reload PHP/worker processes using the normal update steps.

## 1.0.0 - Baseline

Historical baseline: the `main` commit immediately before PR #2.

### Features

- Intro-timed sweeper overlays with voice ducking, shared rotation across scheduled
  sweeper playlists, spacing rules and playlist exclusions.
- Optional queued-song movement for better intro matching, longest-fit preference
  and reverb preview.
- Waveform intro editing, a playback timeline and live settings.

### Update notes

**AzuraCast Broadcast (Liquidsoap) restart: Required for initial installation**
to load the sweeper module into each station. Tagging an already running installation
does not itself require a restart.
