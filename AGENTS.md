# Development

- All commits use `RM-FM <40538914+RM-FM@users.noreply.github.com>` as author and committer.
- Follow AzuraCast's PHP style and Vue component conventions. Comments stay short.
- Keep detection code, models, credentials and private analysis data out of this repository.
- `intro_end` is original-file seconds. Blank, zero and positive values remain distinct.
- Use effective resolved AutoCue metadata, including cached annotations; cue fields may be blank.
- Do not replace AzuraCast scheduling, normalize twice or count prefetch as jingle playback.
- The plugin uses only `intro_end`; its authenticated cue endpoint updates that field alone and preserves other custom fields.
- Keep detection, reviews, vetoes, import workflows and their storage in the private detector.
- The plugin page configures sweepers and edits only `intro_end` on a waveform; do not add review fields or plugin-owned database tables.
- Run PHP tests/lint, Node tests, the frontend build and native Liquidsoap checks.
- State which checks actually ran. Keep the plugin disabled until station playback is validated.
