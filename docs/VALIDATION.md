# Validation status

This initial implementation has not been validated on a running station.

Locally completed:

- Nine Node tests covering cues, sidebar insertion, station switches, delayed
  rendering, missing permissions and sidebar replacement.
- JavaScript syntax checks, including both Vue component script sections.
- Vue production build.
- Native Liquidsoap 2.4.5 type check against the pinned AzuraCast runtime.
- Shell syntax and Git whitespace checks.
- Source review against AzuraCast `19d68c47b1e4d569885ca7cf4c40609cbabefda7`.

Not executed in the authoring environment:

- PHP lint/runtime checks: PHP is unavailable.
- Actual AzuraCast installation, permissions, playback or waveform auditioning.

GitHub Actions has also passed PHP lint/style checks, 39 backend checks and 33
navigation middleware checks. The workflow checks the frontend and native
Liquidsoap source types on each push. A green workflow does not replace station
audio testing.

## Station acceptance

Use a disposable station and verify:

- Reload the dashboard and open Media → Sweepers. Test station switching, browser
  back/forward, mobile sidebar and a user without Media permission. The link must
  appear once for the current station and disappear outside station pages.
- Check the browser console and Content Security Policy on the deployment. The
  plugin loads an external same-origin module; it does not relax the site's policy.
- Remove the plugin and reload: the link and injected script must disappear.

1. First visit/config generation creates exactly one `intro_end` field. Existing
   custom fields and their values remain unchanged.
2. Users without station Media permission cannot access the panel or edit cues.
   Settings require Broadcasting permission. Session edits require CSRF protection.
3. A positive cue, zero, unknown, veto and changed recording remain distinct.
   Protected manual reviews reject automatic imports.
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
