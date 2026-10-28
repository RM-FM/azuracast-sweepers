# Development

- All commits use `RM-FM <40538914+RM-FM@users.noreply.github.com>` as author and committer.
- Commit messages must not contain `Co-authored-by` trailers.
- Work on `develop`; never commit or push to `main` directly. `main` changes only by merging `develop`
  when the user asks to release; production deploys from `main`.
- Follow AzuraCast's PHP style and Vue component conventions.
- Prefer self-explanatory code: clear names and small functions instead of comments. Comment only what the
  code cannot show (why, external constraints, non-obvious behaviour), in one short line where possible.
  Never restate the code, narrate steps or describe features; that belongs in the README. PHPDoc only for
  types PHP cannot express.
- Keep detection code, models, credentials and private analysis data out of this repository.
- `intro_end` is original-file seconds. Blank, zero and positive values remain distinct.
- Use effective resolved AutoCue metadata, including cached annotations; cue fields may be blank.
- Do not replace AzuraCast scheduling, normalize twice or count prefetch as jingle playback.
- The plugin uses only `intro_end`; its authenticated cue endpoint updates that field alone and preserves other custom fields.
- Keep detection, reviews, vetoes, import workflows and their storage in the private detector.
- The plugin page configures sweepers and edits only `intro_end` on a waveform; do not add review fields or plugin-owned database tables.
- Before pushing, run the fast checks locally: PHP tests/lint, Node tests, the frontend build and the native
  Liquidsoap type and spacing checks. The playback scenarios are slow; they run in CI only.
- When the plugin starts using another AzuraCast class, method, property, route or hook line, add it to
  `tests/compat.php` so the daily compatibility workflow covers it. Fix open `azuracast-compat` issues on `develop`.
- State which checks actually ran. Keep the plugin disabled until station playback is validated.

## Releases

- The agent owns version selection, release tags and release notes for every `develop` -> `main` PR.
  Prepare the proposed version and compact notes with the PR; publish the annotated tag and GitHub
  Release on the resulting `main` merge commit only after the user authorizes the release.
  Never tag unmerged development work as a published release or move an existing release tag.
- Use `MAJOR.MINOR.PATCH` tags without a `v` prefix. Fixes and improvements normally increment PATCH;
  new features normally increment MINOR and reset PATCH to zero. A MAJOR increment is never automatic:
  discuss and obtain explicit agreement first. Respect explicitly agreed version assignments.
- Maintain [CHANGELOG.md](CHANGELOG.md) and use the same version-specific notes in the GitHub Release.
  Draft notes for unreleased work are clearly marked pending and are not a release authorization.
  The plugin reports the newest `## X.Y.Z` heading without "Pending" as its installed version, so
  replace the pending heading with `## X.Y.Z - YYYY-MM-DD` in the release PR before merging.
- Group relevant user/product changes under Features, Fixes and Improvements; omit empty sections.
  Summarize related changes rather than enumerating code edits, tests or internal refactors. For a
  small single-purpose release, one concise user-relevant bullet is sufficient. End each release with
  an `### Update notes` section holding the restart statement and any update hints.
- Every release and release PR must explicitly state **AzuraCast Broadcast (Liquidsoap) restart:
  Required / Not required**, with a short reason. Compare the shipped Liquidsoap module and generated
  configuration with the preceding release; distinguish a broadcast restart from frontend rebuilds
  and PHP/worker reloads. Initial installation may need a broadcast restart even if an update does not.
  Keep the exact wording `AzuraCast Broadcast (Liquidsoap) restart: Required` or `... restart: Not
  required` (bold allowed) in the GitHub Release body: the Settings page parses it to tell users whether
  an available update needs a restart; any other wording is shown as unknown.
- The module status compares a fingerprint of the Liquidsoap code without comments and blank lines.
  A release that changes Liquidsoap code requires a restart; comment-only changes do not.
- Verify the exact commit boundary, tag uniqueness, notes and restart requirement before publishing.
  Record checks actually run; never claim CI or station playback passed without evidence.
  A test/release hold forbids creating the release PR, tag or published release until the user lifts it.
- Agreed baseline: `1.0.0` is `bda1c3e69206f0b0a922aa2fb6dc7541b5f71395`;
  `1.0.1` is PR #2's merge `ecd7ff209d48f28951cdb1b77898d99e8af17183`.
  This historical `1.0.1` assignment is explicit even though it contains features.
  `1.1.0` is the merge of the following `develop` -> `main` release PR.
