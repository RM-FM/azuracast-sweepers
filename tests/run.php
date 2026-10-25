<?php

declare(strict_types=1);

use Plugin\AzuracastSweepers\Service\Media;
use Plugin\AzuracastSweepers\Service\Queue;
use Plugin\AzuracastSweepers\Service\State;
use Plugin\AzuracastSweepers\ValueObject\Settings;
use Plugin\AzuracastSweepers\ValueObject\Selection;
use Plugin\AzuracastSweepers\ValueObject\Timing;

spl_autoload_register(static function (string $class): void {
    $prefix = 'Plugin\\AzuracastSweepers\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rejects(callable $callback): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        check(true, 'Rejected invalid input.');

        return;
    }
    check(false, 'Invalid input was accepted.');
}

$settings = new Settings(enabled: true, playlistId: 1);
$timing = Timing::fromArray(['cue_in' => 1, 'cue_out' => 10, 'cross_start_next' => 7], 12);
check($timing !== null, 'Valid jingle timing.');
$fit = $timing->fit(12, 2, 180, $settings);
check(abs($fit['offset'] - 3.2) < 0.001, 'Subtract song cue-in once.');
check(abs($fit['duck_duration'] - 6) < 0.001, 'Use body handover, not jingle file end.');
check(abs($timing->fit(30, 0, 180, $settings)['offset'] - 23.2) < 0.001, 'Default handover aligns to intro minus margin.');
check(abs($timing->fit(30, 0, 180, new Settings(maxWait: 5))['offset'] - 2.0) < 0.001, 'Long delay starts at the default early start.');
check(
    abs($timing->fit(30, 0, 180, new Settings(maxWait: 5, earlyStart: 3.5))['offset'] - 3.5) < 0.001,
    'Configured early start.'
);
check(
    abs($timing->fit(30, 0, 180, new Settings(maxWait: 5, earlyStart: 30))['offset'] - 23.2) < 0.001,
    'Early start never exceeds the aligned start, so the body still ends before the intro.'
);
check(
    abs($timing->fit(30, 5, 180, new Settings(maxWait: 5))['offset'] - 2.0) < 0.001,
    'Early start is measured from the song cue-in.'
);
check($timing->fit(30, 0, 180, new Settings(maxWait: 5, earlyStart: 0))['offset'] === 0.0, 'Early start 0 starts at cue-in.');
check(Settings::fromArray([])->earlyStart === 2.0, 'Early start defaults to 2 seconds.');
check(abs($timing->fit(12, 2, 180, new Settings(maxWait: 5))['offset'] - 3.2) < 0.001, 'Short delay retains end alignment.');
check(Settings::fromArray([])->maxWait === null, 'Start delay disabled by default.');
check(Settings::fromArray(['max_wait' => null])->maxWait === null, 'Explicit null disables delay limit.');
check(Settings::fromArray(['max_wait' => 5])->maxWait === 5.0, 'Keep previously saved delay setting.');
check($timing->fit(5, 0, 180, $settings) === null, 'Reject a jingle body that does not fit.');
check($timing->fit(12, 2, 13, $settings) === null, 'The tail must fit before the next transition.');
check($timing->fit(12, 2, 180, new Settings(maxWait: 0, earlyStart: 0))['offset'] === 0.0, 'Allow an immediate overlay.');
check(
    abs($timing->fit(12, 2, 180, new Settings(maxWait: 0))['offset'] - 2.0) < 0.001,
    'Limit 0 uses the early start.'
);
check($timing->fit(NAN, 0, 100, $settings) === null, 'Reject nonfinite intro.');

foreach ([
    ['cue_in' => 2, 'cue_out' => 1],
    ['cue_in' => 0, 'cue_out' => 20],
    ['cue_in' => 0, 'cue_out' => 10, 'cross_start_next' => 11],
    ['cue_in' => 0, 'cue_out' => 10, 'amplify' => INF],
    [],
] as $invalid) {
    check(Timing::fromArray($invalid, 12) === null, 'Invalid AutoCue timing.');
}
check(Timing::fromArray(['cue_in' => 0, 'cue_out' => -2, 'fade_out' => 1], 12)->handover === 9.0, 'Relative cue-out.');
check(Timing::fromArray(['cue_in' => 0, 'cue_out' => 10, 'amplify' => '-3 dB'], 12)->gainDb === -3.0, 'dB gain.');
$metadata = [
    'liq_cue_in' => '',
    'liq_cue_out' => '',
    'liq_cross_start_next' => '',
    'liq_amplify' => '-3 dB',
    'autocue_cue_in' => '1',
    'autocue_cue_out' => '10',
    'autocue_start_next' => '7',
];
$cachedTiming = Timing::fromMetadata($metadata, 12);
check($cachedTiming->cueIn === 1.0 && $cachedTiming->handover === 7.0, 'Cached annotations need no cue fields.');
check($cachedTiming->gainDb === -3.0, 'Use annotation loudness.');
$metadata['liq_cue_in'] = '2';
check(Timing::fromMetadata($metadata, 12)->cueIn === 2.0, 'Resolved cue-in takes precedence.');
$metadata['autocue_start_next'] = '';
$metadata['autocue_fade_out'] = '2';
check(Timing::fromMetadata($metadata, 12)->handover === 8.0, 'Use effective fade when crossfade is absent.');
check(Timing::fromMetadata([], 12) === null, 'Missing annotations cannot invent a jingle window.');
$metadata['liq_amplify'] = '0.5';
check(abs(Timing::fromMetadata($metadata, 12)->gainDb + 6.0206) < 0.001, 'Linear amplification metadata.');
check(Settings::fromArray($settings->toArray())->toArray() === $settings->toArray(), 'Settings round trip.');
check(Settings::fromArray($settings->toArray())->revision() === $settings->revision(), 'Stable settings revision.');
foreach ([
    'enabled' => false, 'playlist_id' => 2, 'songs_after_jingle' => 3, 'songs_after_sweeper' => 2,
    'songs_before_jingle' => 1, 'count_jingle_mode' => false,
    'jingle_playlist_ids' => [4, 5], 'max_wait' => 0, 'margin' => 1.2, 'duck_db' => -10,
    'jingle_gain' => 0.5, 'longest_fit' => true, 'length_tolerance' => 50, 'early_start' => 4,
] as $key => $value) {
    check(
        Settings::fromArray([$key => $value] + $settings->toArray())->revision() !== $settings->revision(),
        'Every changed setting invalidates old plans: ' . $key
    );
}
check((new Settings())->runtime()['jingle_playlists'] === [], 'No null playlist in disabled runtime settings.');
check(
    (new Settings(playlistId: 2, jinglePlaylistIds: [2, 4]))->runtime()['jingle_playlists'] === [2, 4],
    'Runtime recognition includes the sweeper playlist once.'
);
foreach ([['enabled' => 'false'], ['enabled' => true], ['duck_db' => -21], ['max_wait' => -1]] as $invalid) {
    rejects(static fn() => Settings::fromArray($invalid));
}

$rotation = [
    ['id' => 1, 'body' => 10.0, 'last_played' => 1],
    ['id' => 2, 'body' => 24.0, 'last_played' => 2],
    ['id' => 3, 'body' => 29.0, 'last_played' => 3],
];
check(Selection::order($rotation, 30, new Settings())[0]['id'] === 1, 'Default rotates all fitting sweepers.');
$longest = new Settings(longestFit: true);
check(array_column(Selection::order($rotation, 30, $longest), 'id') === [2, 3], '20% includes 24 to 30 seconds.');
check(Selection::order($rotation, 30, new Settings(longestFit: true, lengthTolerance: 0))[0]['id'] === 3,
    'If no exact-length body exists, choose the longest fitting one.');
check(Selection::order($rotation, 100, $longest)[0]['id'] === 3, 'No candidate in tolerance falls back to longest.');
check(Selection::order([], 30, $longest) === [], 'No fitting sweeper remains empty.');
$rotation[1]['last_played'] = 4;
check(Selection::order($rotation, 30, $longest)[0]['id'] === 3, 'Within tolerance actual playback advances rotation.');
check(Selection::order($rotation, 30, new Settings(longestFit: true, lengthTolerance: 100))[0]['id'] === 1,
    '100% tolerance admits every fitting body.');
$custom = new Settings(
    songsAfterJingle: 3,
    countJingleMode: false,
    jinglePlaylistIds: [3, 7],
    longestFit: true,
    songsAfterSweeper: 2,
    songsBeforeJingle: 1
);
check(Settings::fromArray($custom->toArray())->toArray() === $custom->toArray(), 'New settings persist without conversion.');
check(!array_key_exists('minimum_songs', $custom->toArray()), 'The shared legacy gap is no longer saved.');
$legacy = Settings::fromArray(['minimum_songs' => 2]);
check(
    $legacy->songsAfterJingle === 2 && $legacy->songsAfterSweeper === 2 && $legacy->songsBeforeJingle === 0,
    'A saved shared gap migrates to both after-gaps without adding a before-gap.'
);
check(
    Settings::fromArray(['minimum_songs' => 2, 'songs_after_sweeper' => 0])->songsAfterSweeper === 0,
    'Explicit separate gaps take precedence over the legacy value.'
);
$runtime = $custom->runtime();
check(
    $runtime['songs_after_jingle'] === 3 && $runtime['songs_after_sweeper'] === 2
        && !array_key_exists('songs_before_jingle', $runtime),
    'Liquidsoap receives both after-gaps; the queue lookahead stays server-side.'
);
foreach ([
    ['songs_after_jingle' => -1], ['songs_after_sweeper' => 1.5], ['songs_before_jingle' => -1],
    ['songs_before_jingle' => 1001], ['minimum_songs' => -1], ['minimum_songs' => 1.5],
    ['early_start' => -1], ['early_start' => 61], ['early_start' => '2'],
    ['count_jingle_mode' => 'false'],
    ['jingle_playlist_ids' => '2'], ['jingle_playlist_ids' => ['2']], ['jingle_playlist_ids' => [0]],
    ['longest_fit' => 1], ['length_tolerance' => -1], ['length_tolerance' => 101], ['max_wait' => ''],
] as $invalid) {
    rejects(static fn() => Settings::fromArray($invalid));
}

check(Media::parseIntro(null, 300) === null, 'Unknown is null.');

$at = static fn(int $second): DateTimeImmutable => new DateTimeImmutable('@' . $second);
$queueRow = static fn(int $id, bool $sent, int $cued, ?int $playlist = null, bool $jingle = false, bool $played = false)
    => [
        'id' => $id, 'is_played' => $played, 'sent_to_autodj' => $sent, 'timestamp_cued' => $at($cued),
        'playlist_id' => $playlist, 'is_jingle' => $playlist === null ? null : $jingle,
    ];
$lookahead = new Settings(playlistId: 9, jinglePlaylistIds: [8], songsBeforeJingle: 2);
$queue = [
    $queueRow(10, true, 100, 1),
    $queueRow(11, true, 200, 1),
    $queueRow(12, false, 300, 1),
    $queueRow(13, false, 400, 5, true),
];
check(Queue::select($queue, [11], 2, $lookahead) === ['11'], 'A Jingle Mode track two songs ahead blocks.');
check(Queue::select($queue, [11], 1, $lookahead) === [], 'A jingle beyond the lookahead does not block.');
check(Queue::select($queue, [10], 2, $lookahead) === [], 'Count tracks after the planned song, not the queue head.');
check(Queue::select($queue, [12], 1, $lookahead) === ['12'], 'Unsent planned rows use their queue position.');
check(
    Queue::select($queue, [11], 2, new Settings(countJingleMode: false, songsBeforeJingle: 2)) === [],
    'Disabled Jingle Mode recognition is honoured.'
);
$played = [$queueRow(10, true, 100, 1, played: true), $queueRow(11, true, 200, 1), $queueRow(14, false, 300, 8)];
check(Queue::select($played, [10], 2, $lookahead) === ['10'], 'A playing song still sees later queued jingles.');
check(
    Queue::select([$queueRow(10, true, 100, 1, played: true), $queueRow(9, true, 50, 8), $queueRow(11, false, 300, 1)], [10], 1, $lookahead) === [],
    'An earlier sent but unplayed row is not after the playing song.'
);
check(Queue::select($played, [10], 1, $lookahead) === [], 'Respect the configured count for playing songs.');
check(
    Queue::select([$queueRow(11, true, 200, 1), $queueRow(12, false, 300, 9)], [11], 1, $lookahead) === ['11'],
    'The sweeper playlist itself counts as a jingle.'
);
check(
    Queue::select([$queueRow(11, true, 200, 1), $queueRow(12, false, 300)], [11], 2, $lookahead) === [],
    'Listener requests without a playlist are not jingles.'
);
check(Queue::select($queue, [99], 2, $lookahead) === [], 'Unknown or removed queue rows never block.');
check(Media::parseIntro('0', 300) === 0.0, 'No intro is zero.');
check(Media::parseIntro('11.5', 300) === 11.5, 'Decimal cue.');
foreach ([-1, 301, NAN, 'vocals', true, '30s+'] as $invalid) {
    rejects(static fn() => Media::parseIntro($invalid, 300));
}

$directory = sys_get_temp_dir() . '/sweepers-test-' . bin2hex(random_bytes(6));
mkdir($directory);
$state = new State();
try {
    check($state->read($directory) === [], 'Empty state.');
    $state->update($directory, static fn() => ['history' => ['42' => 100]]);
    check($state->read($directory)['history'][42] === 100, 'State persists.');
    try {
        $state->update($directory, static fn() => throw new RuntimeException('Interrupted update.'));
    } catch (RuntimeException) {
    }
    check($state->read($directory)['history'][42] === 100, 'An interrupted update preserves the previous state.');
    check(glob($directory . '/sweepers-*') === [], 'No abandoned temporary state.');
} finally {
    foreach (glob($directory . '/*') as $path) {
        unlink($path);
    }
    rmdir($directory);
}

echo $checks . " checks passed.\n";
