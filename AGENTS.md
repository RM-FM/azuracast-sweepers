# Development

- All commits use `RM-FM <40538914+RM-FM@users.noreply.github.com>` as author and committer.
- Follow AzuraCast's PHP style and Vue component conventions. Comments stay short.
- Keep detection code, models, credentials and private analysis data out of this repository.
- `intro_end` is original-file seconds. Blank, zero and positive values remain distinct.
- Use effective resolved AutoCue metadata, including cached annotations; cue fields may be blank.
- Do not replace AzuraCast scheduling, normalize twice or count prefetch as jingle playback.
- Manual review locks, vetoes and recording identity checks must survive updates.
- Keep the native waveform editor separate; this plugin owns its own editor page.
- Run PHP tests/lint, Node tests, the frontend build and native Liquidsoap checks.
- State which checks actually ran. Keep the plugin disabled until station playback is validated.
