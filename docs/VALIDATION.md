# Validation status

## 2026-10-28 matching and selection update

The new same-playlist replacement and revised longest-fit preference are opt-in
changes on `develop`, not a production playback acceptance. Replacement is off by
default. Keep sweepers disabled on an unvalidated station until the acceptance
checks below pass.

Isolated checks use PHP 8.5.12 and MariaDB 11, including the pinned native
AzuraCast queue/rotation entities, playlist repositories and duplicate prevention.
Replacement tests cover sequential/shuffle/random candidates, restored rotation
slots, inherited repeat rules, native queue-builder locking, the matching time
budget, changed/sent entries and atomic rollback. Orchestration tests cover
independent switches, forward-move priority and timeline bookkeeping without
counting prefetch as playback. Longest-fit tests check preferred-first ordering
and regular-rotation fallback when the preferred range is empty or unavailable.

Frontend checks cover both matching switches, the shared sequential option,
replacement labels, intro scanning through all pages, live search and unknown-intro
filtering. The tolerance field uses the same responsive column as handover margin.
The production frontend build, bundle smoke test, PHP syntax/style, native
Liquidsoap type and spacing checks are also part of verification.

Passed on the monitoring VM on 2026-10-28: 200 PHP behavior checks, 43 navigation
checks, route/reservation/settings suites, 10 matching orchestration checks,
8 longest-fit planner checks, 37 native replacement integration checks, queue/media
integration tests and all 26 Node tests. The production build and bundle smoke
test passed, as did PHPCS, PHP syntax and Liquidsoap 2.5 type/spacing checks.
Liquidsoap reported its existing top-level `audio` shadowing warning.

Slow rendered playback scenarios run in CI, not as part of these VM checks.
Real-station playback, audible timing and production queue concurrency still need
acceptance testing before enabling the new option.

## Earlier validation (2026-10-25)

On 2026-10-25, Evergreens (station 3) loaded the plugin and reported an actual
Dropin 04 playback over ABBA - Voulez-Vous, followed by the rotation-history
update. This confirms the technical playback path, not an audible ducking/timing
review. The subsequent live-settings, separate song-gap and fail-safe loading
implementations passed isolated VM checks but have not been deployed to that station.

Completed in an isolated VM copy:

- Twelve Node tests covering sidebar insertion, station switches, delayed
  rendering, permissions and sidebar replacement, plus editor value handling,
  marker/playhead inputs, switching tracks during a save, the rotation settings
  form (three song gaps, queue-length limit), the live-save notice and the module
  status (active or one-time restart required).
- 97 PHP behavior checks (including legacy gap migration and upcoming-queue
  lookahead), navigation, routes, reservation checks and configuration-generation
  tests using AzuraCast's exact line API: the module loads on fresh installations
  with sweepers disabled; editing views and previews are untouched; a rejected
  module is rolled back exactly; an unrelated configuration error keeps it; an
  unsupported layout, manual AutoDJ, a failing custom-field initialization,
  damaged state and invalid settings never raise; a changed AzuraCast runtime
  invalidates the cached check; heartbeat-based status (active, outdated, restart).
- Real Liquidsoap 2.4.5 fail-safe check (`tests/failsafe.sh`): with a runtime
  that renames a function the module uses, `liquidsoap --check -` (AzuraCast's
  `verifyConfig`) exits with 1, and the plugin's own rollback of that
  configuration passes against the same runtime.
- MariaDB 11: single-field custom-field updates and the real upcoming-queue DQL
  (queue order, other stations, played rows, queue-length cap, playlist rules).
- Native Liquidsoap 2.4.5 renders using the pinned AzuraCast request factory:
  normal playback, skip, live takeover, unavailable planning and spacing after
  Jingle Mode/selected-playlist tracks. The rendered
  audio checks onset, ducking, tail, metadata and reservation events. Twelve
  scenarios also cover live enable, disable during audible playback, cancellation
  before activation, changed revisions, a failed heartbeat with event retry and a
  jingle queued after planning that cancels the pending sweeper. The native
  spacing fixture checks independent after-jingle and after-sweeper counters,
  that invalid initial settings never stop Liquidsoap, and heartbeats report the
  loaded module version.
- PHP syntax/style checks with PHP 8.4 and Composer dependencies.
- JavaScript syntax checks, including both Vue component script sections.
- Vue production build.
- Native Liquidsoap 2.4.5 type check against the pinned AzuraCast runtime.
- Shell syntax and Git whitespace checks.
- Source review against AzuraCast `19d68c47b1e4d569885ca7cf4c40609cbabefda7`.

Not verified by these checks:

- Deployment of these versions, real-station live-setting and song-gap behavior
  against an actual AzuraCast queue, permissions or waveform/audio auditioning.
- The fail-safe check inside a real AzuraCast container (binary path, run time of
  the extra type check during restarts) and an actual AzuraCast upgrade.

GitHub Actions checks the frontend, PHP, MariaDB custom-field preservation, the
upcoming-queue lookahead and rendered
native Liquidsoap playback on each push. A green workflow does not replace
station audio testing.

AutoDJ startup is confirmed by its track callback, without a competing readiness
probe; a 120-second timeout still ends startup loading when no track is available.

Song spacing policy also checks one/two-song gaps, cancelled overlays, multiple
playlist IDs, disabled Jingle Mode recognition and unknown sources.

## Station acceptance

Use a disposable station and verify:

- Reload the dashboard and open the Sweepers group. Check that it opens and closes
  like Media, that Settings, Intro Manager and History open their pages, that the group
  stays open and marks the current page, and that an old `?sweepers=1` link opens
  Settings. Test station switching, browser back/forward, mobile sidebar and a
  user without Media permission. The group must appear once for the current
  station and disappear outside station pages.
- The timeline lists started tracks, including tracks without a sweeper and their
  reasons. An audible sweeper must show its song, sweeper and start offset;
  a cancelled reservation must never be reported as a played sweeper.
- Check the browser console and Content Security Policy on the deployment. The
  plugin loads an external same-origin module; it does not relax the site's policy.
- Remove the plugin and reload: the link and injected script must disappear.
- Select a track on the plugin page: its original waveform and saved `intro_end`
  marker should appear. Check playback at the marker with Listen Intro;
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

11. Set after-jingle 3, after-sweeper 2 and before-jingle 1. After a Jingle Mode
    track or a track from a selected jingle playlist, three songs remain clear.
    After an actual sweeper, two further songs remain clear. A song directly
    before a queued jingle receives no sweeper, including when the jingle enters
    the queue after the song was prefetched. Check that a prefetched plan cannot
    bypass any rule, that the before-jingle field cannot exceed the AutoDJ queue
    length, and that an existing single Minimum songs value migrated to both
    after-gaps. Try 0 for each rule, plus a skipped/prefetched track.
12. Disable the delay limit and check end alignment. Enable a limit smaller than
    the natural offset and check that the sweeper starts at the early start
    (default 2 s after cue-in, audibly not on the first downbeat); change the
    early start live. A long early start must never push the handover past the
    intro.
13. Enable longest-fit selection at 20%, using several different-length jingles.
    Verify rotation within the 80–100% range of the available intro after cue-in
    and margin. When no eligible body is in range (including repeat-protected or
    reserved long sweepers), regular oldest-played rotation must choose a shorter
    eligible sweeper. Test 0% without an exact fill and 100%. Bodies too long for
    the intro remain excluded; no repetition or reservation rule is relaxed.
14. Load the plugin once while disabled, then enable without restarting. Verify
    heartbeat updates, new planning and actual playback. Disable during a sweeper:
    it must finish including its tail, while pending overlays are cancelled.
15. Change each song gap (for example 1 to 3 and back), jingle recognition,
    playlist, timing, gain, ducking and selection settings without restarting.
    Confirm the settings revision changes, stale plans are released and already
    counted songs remain counted. Saving must not create or clear unrelated
    AzuraCast restart requirements.
16. Interrupt the heartbeat API in an isolated test: retain the last valid settings,
    log the failure and retain unacknowledged events for retry. Restore it and
    confirm live settings recover without a restart.
17. After installing the plugin, restart a station that never enabled sweepers.
    It must start normally, and the Sweepers page must report the module as
    running. Enabling it then must work without another restart. Check the
    restart duration with the additional Liquidsoap check.
18. In a disposable station, make the module incompatible (for example by
    editing a copy of the plugin's Liquidsoap file) and restart: the station must
    start without sweepers, and the Sweepers page must show the reason.
19. On a station with AutoCue disabled and sweepers without manual cue points,
    restart once: the Liquidsoap log shows "AutoCue computed for sweeper …", and
    sweepers play with trimmed silence and sensible handover timing. Set manual
    cue points on one sweeper and check that they take priority. Check that
    AzuraCast's own playback of those files is unchanged.
20. In Intro Manager, test live search and the unknown/untagged filter across more than
    100 matching tracks. Saved zeroes are not unknown. Listen Intro must begin at
    the saved original-file cue (zero starts at file start). Scan Intros plays five
    seconds per tagged track, skips missing cues, advances through all result pages
    and stops at the last result or immediately on Stop, search/filter changes or
    opening the editor.
21. On a disposable station, test the two matching switches independently and
    together. A forward move takes priority when both are on. For replacement,
    verify same-playlist sequential/shuffle/random selection, duplicate protection,
    unchanged playlist chain, correct Now Playing metadata and timing, and return of
    the displaced song to its rotation slot rather than the playback queue's end.
    Sequential tracks remain fixed unless the shared option is enabled. Requests,
    merged groups, excluded playlists and sent entries must not be replaced. Test a
    busy native queue builder and an exhausted rotation: neither forces a match.
    The timeline must distinguish replacement, movement and failed matching.
