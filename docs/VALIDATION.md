# Validation status

The plugin has not yet been validated on a running AzuraCast station.

Locally completed:

- Eight Node tests covering sidebar insertion, station switches, delayed
  rendering, permissions and sidebar replacement, plus editor value handling,
  marker/playhead inputs, switching tracks during a save and the rotation settings form.
- 62 PHP behavior checks, 33 navigation checks and reservation checks.
- Native Liquidsoap 2.4.5 renders using the pinned AzuraCast request factory:
  normal playback, skip, live takeover, unavailable planning and spacing after
  Jingle Mode/selected-playlist tracks. The rendered
  audio checks onset, ducking, tail, metadata and reservation events.
- PHP syntax/style checks with PHP 8.4 and Composer dependencies.
- JavaScript syntax checks, including both Vue component script sections.
- Vue production build.
- Native Liquidsoap 2.4.5 type check against the pinned AzuraCast runtime.
- Shell syntax and Git whitespace checks.
- Source review against AzuraCast `19d68c47b1e4d569885ca7cf4c40609cbabefda7`.

Not executed in the authoring environment:

- MariaDB-backed single-field update test locally (the workflow runs it).
- Actual AzuraCast installation, permissions, playback or waveform auditioning.

GitHub Actions checks the frontend, PHP, MariaDB custom-field preservation and rendered
native Liquidsoap playback on each push. A green workflow does not replace
station audio testing.

AutoDJ startup is confirmed by its track callback, without a competing readiness
probe; a 120-second timeout still ends startup loading when no track is available.

Song spacing policy also checks one/two-song gaps, cancelled overlays, multiple
playlist IDs, disabled Jingle Mode recognition and unknown sources.

## Station acceptance

Use a disposable station and verify:

- Reload the dashboard and open Sweepers. Test station switching, browser
  back/forward, mobile sidebar and a user without Media permission. The link must
  appear once for the current station and disappear outside station pages.
- Check the browser console and Content Security Policy on the deployment. The
  plugin loads an external same-origin module; it does not relax the site's policy.
- Remove the plugin and reload: the link and injected script must disappear.
- Select a track on the plugin page: its original waveform and saved `intro_end`
  marker should appear. Check playback at the marker and three seconds earlier;
  drag the marker, set it at the playhead and save. Reload and check the cue
  persists. Set zero, then clear it and check both remain distinct. Other custom
  fields must retain their values.

1. First visit/config generation creates exactly one `intro_end` field. Existing
   custom fields and their values remain unchanged.
2. Users without station Media permission cannot access the panel or cue API.
   Settings require Broadcasting permission. Browser cue and settings edits
   require CSRF protection.
3. Positive, zero and blank intro values remain distinct. Cue API updates preserve
   all other custom fields and reject additional request fields.
4. Jingles with blank native cue fields work using cached AutoCue annotations.
   Resolved cue-in, handover, cue-out and loudness take precedence.
5. A nonzero song cue-in is subtracted once. Jingle handover and tail timing are
   audible as expected, including during crossfades.
6. Prefetching and cancelled tracks do not count as jingle plays. Concurrent
   requests reserve different jingles where alternatives fit.
7. Skipping a song, live takeover, fallback audio and returning from live do not
   leak a pending or stale overlay.
8. Song metadata/Now Playing remain the song's metadata. Jingles receive one
   normalization gain and pass through the station's final processor.
9. Missing AutoCue metadata, API failures and unresolvable jingles leave music
   playing. A late activation is skipped.
10. Disabling/removing the plugin restores normal AzuraCast playback without
    deleting media or custom-field values.

11. Set minimum songs to 1: after a Jingle Mode track or a track played from a
    selected jingle playlist, the next song remains clear. After an actual sweeper,
    one other song remains clear before another overlay. A prefetched plan must
    not bypass this restriction. Try 0 and 2, plus a skipped/prefetched track.
12. Disable the delay limit and check end alignment. Enable a limit smaller than
    the natural offset and check immediate playback; 0 always starts immediately.
13. Enable longest-fit selection at 20%, using several different-length jingles.
    Verify rotation within the 80–100% range, longest-fit fallback when no body is
    in range and continued exclusion of bodies too long for the intro.
