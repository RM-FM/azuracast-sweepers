# Release notes

Tags use `MAJOR.MINOR.PATCH` without a `v` prefix. Fixes and improvements normally
increment PATCH; features normally increment MINOR. Major versions require explicit
agreement. Each release states whether AzuraCast Broadcast (Liquidsoap) must restart;
this is separate from rebuilding the frontend or reloading PHP/worker processes.

## 1.1.0 - Pending testing approval

Not released. The next `develop` -> `main` PR (expected #3), tag and GitHub Release
are on hold until testing is approved.

**AzuraCast Broadcast (Liquidsoap) restart: Not required** when upgrading from
1.0.1. The Liquidsoap module and generated broadcast configuration are unchanged.
Rebuild the frontend and reload PHP/worker processes using the normal update steps.
New same-playlist replacement is off by default; validate station playback before
enabling it.

### Features

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

## 1.0.1 - 2026-10-28

**AzuraCast Broadcast (Liquidsoap) restart: Not required** when upgrading from
1.0.0. This release changes the browser interface and media listing, not the
Liquidsoap module or generated broadcast configuration. Rebuild the frontend and
reload PHP/worker processes using the normal update steps.

### Features

- Listen to five seconds from a saved intro end directly per track, or scan intros
  continuously across all result pages.
- Search the library as you type, with complete pagination and an unknown/untagged
  intro filter that keeps saved zero cues distinct from missing cues.

### Improvements

- Streamlined waveform playback and intro-editing controls.
- Clearer AutoCue documentation: existing resolved cue metadata is used; internal
  analysis is only a fallback for sweepers missing cue points, not intro detection.

## 1.0.0 - Baseline

Historical baseline: the `main` commit immediately before PR #2.

**AzuraCast Broadcast (Liquidsoap) restart: Required for initial installation**
to load the sweeper module into each station. Tagging an already running installation
does not itself require a restart.

### Features

- Intro-timed sweeper overlays with voice ducking, shared rotation across scheduled
  sweeper playlists, spacing rules and playlist exclusions.
- Optional queued-song movement for better intro matching, longest-fit preference
  and reverb preview.
- Waveform intro editing, a playback timeline and live settings.
