<?php

declare(strict_types=1);

use Plugin\AzuracastSweepers\Service\Arranger;
use Plugin\AzuracastSweepers\Service\Media;
use Plugin\AzuracastSweepers\Service\Planner;
use Plugin\AzuracastSweepers\Service\Queue;
use Plugin\AzuracastSweepers\Service\Schedule;
use Plugin\AzuracastSweepers\Service\State;
use Plugin\AzuracastSweepers\ValueObject\Arrangement;
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

$settings = new Settings(enabled: true, playlistIds: [1], margin: 0.8);
$timing = Timing::fromArray(['cue_in' => 1, 'cue_out' => 10, 'cross_start_next' => 7], 12);
check($timing !== null, 'Valid jingle timing.');
$fit = $timing->fit(12, 2, 180, $settings);
check(abs($fit['offset'] - 3.2) < 0.001, 'Subtract song cue-in once.');
check(abs($fit['duck_duration'] - 6) < 0.001, 'Use body handover, not jingle file end.');
check(abs($timing->fit(30, 0, 180, $settings)['offset'] - 23.2) < 0.001, 'Default handover aligns to intro minus margin.');
$limited = static fn(float $maxWait, float $earlyStart = 2.0): Settings
    => new Settings(margin: 0.8, limitDelay: true, maxWait: $maxWait, earlyStart: $earlyStart);
check(abs($timing->fit(30, 0, 180, $limited(5))['offset'] - 2.0) < 0.001, 'Long delay starts at the default early start.');
check(abs($timing->fit(30, 0, 180, $limited(5, 3.5))['offset'] - 3.5) < 0.001, 'Configured early start.');
check(
    abs($timing->fit(30, 0, 180, $limited(5, 30))['offset'] - 23.2) < 0.001,
    'Early start never exceeds the aligned start, so the body still ends before the intro.'
);
check(abs($timing->fit(30, 5, 180, $limited(5))['offset'] - 2.0) < 0.001, 'Early start is measured from the song cue-in.');
check($timing->fit(30, 0, 180, $limited(5, 0))['offset'] === 0.0, 'Early start 0 starts at cue-in.');
check(abs($timing->fit(12, 2, 180, $limited(5))['offset'] - 3.2) < 0.001, 'Short delay retains end alignment.');
check(
    abs($timing->fit(30, 0, 180, new Settings(margin: 0.8, limitDelay: false, maxWait: 5))['offset'] - 23.2) < 0.001,
    'A disabled limit keeps end alignment regardless of the saved maximum.'
);
$defaults = Settings::fromArray([]);
check(
    $defaults->enabled && $defaults->playlistIds === [] && $defaults->songsAfterJingle === 1
        && $defaults->songsAfterSweeper === 1 && $defaults->songsBeforeJingle === 0 && $defaults->countJingleMode
        && !$defaults->longestFit && $defaults->margin === 0.5 && !$defaults->limitDelay
        && $defaults->maxWait === 10.0 && $defaults->earlyStart === 2.0 && $defaults->duckDb === -7.0
        && $defaults->jingleGain === 1.0,
    'New installations start with the agreed defaults.'
);
check(Settings::fromArray([])->toArray() === (new Settings())->toArray(), 'Constructor and stored defaults agree.');
$legacyOff = Settings::fromArray(['max_wait' => null]);
check(!$legacyOff->limitDelay && $legacyOff->maxWait === 10.0, 'A saved disabled limit stays disabled.');
$legacyOn = Settings::fromArray(['max_wait' => 5]);
check($legacyOn->limitDelay && $legacyOn->maxWait === 5.0, 'A saved numeric limit stays enabled with its value.');
$kept = Settings::fromArray(['limit_delay' => false, 'max_wait' => 7]);
check(!$kept->limitDelay && $kept->maxWait === 7.0, 'The maximum is kept while the limit is off.');
check(Settings::fromArray(['minimum_songs' => 3])->songsAfterJingle === 3, 'A saved legacy gap overrides the default.');
check($timing->fit(5, 0, 180, $settings) === null, 'Reject a jingle body that does not fit.');
check($timing->fit(12, 2, 13, $settings) === null, 'The tail must fit before the next transition.');
check($timing->fit(12, 2, 180, $limited(0, 0))['offset'] === 0.0, 'Allow an immediate overlay.');
check(abs($timing->fit(12, 2, 180, $limited(0))['offset'] - 2.0) < 0.001, 'Limit 0 uses the early start.');
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
$computed = Timing::fromMetadata([
    'liq_cue_in' => '', 'liq_cue_out' => '', 'liq_cross_start_next' => '', 'liq_fade_out' => '',
    'liq_amplify' => '1.887 dB', 'autocue_cue_in' => '0.0', 'autocue_cue_out' => '2.9',
    'autocue_start_next' => '', 'autocue_fade_out' => '0.10002267574',
], 3.5);
check(
    $computed !== null && $computed->cueOut === 2.9 && abs($computed->handover - 2.79997732426) < 0.0001
        && abs($computed->gainDb - 1.887) < 0.0001,
    'Values from the built-in AutoCue fallback are accepted as Liquidsoap formats them.'
);
$metadata['liq_amplify'] = '0.5';
check(abs(Timing::fromMetadata($metadata, 12)->gainDb + 6.0206) < 0.001, 'Linear amplification metadata.');
check(Settings::fromArray($settings->toArray())->toArray() === $settings->toArray(), 'Settings round trip.');
check(Settings::fromArray($settings->toArray())->revision() === $settings->revision(), 'Stable settings revision.');
foreach ([
    'enabled' => false, 'playlist_ids' => [1, 2], 'songs_after_jingle' => 3, 'songs_after_sweeper' => 2,
    'songs_before_jingle' => 1, 'count_jingle_mode' => false,
    'jingle_playlist_ids' => [4, 5], 'max_wait' => 0, 'limit_delay' => true, 'margin' => 1.2, 'duck_db' => -10,
    'jingle_gain' => 0.5, 'longest_fit' => true, 'length_tolerance' => 50, 'early_start' => 4,
    'swap_for_intro' => true, 'reverb' => true, 'reverb_intensity' => 70, 'swap_sequential' => true,
    'excluded_playlist_ids' => [6], 'sweeper_gap' => 3,
] as $key => $value) {
    check(
        Settings::fromArray([$key => $value] + $settings->toArray())->revision() !== $settings->revision(),
        'Every changed setting invalidates old plans: ' . $key
    );
}
check((new Settings())->runtime()['jingle_playlists'] === [], 'No null playlist in disabled runtime settings.');
check(
    (new Settings(playlistIds: [2, 3], jinglePlaylistIds: [2, 4]))->runtime()['jingle_playlists'] === [2, 3, 4],
    'Runtime recognition includes every sweeper playlist once.'
);
check(Settings::fromArray(['playlist_id' => 7])->playlistIds === [7], 'A saved single sweeper playlist is kept.');
check(Settings::fromArray(['playlist_id' => null])->playlistIds === [], 'No saved sweeper playlist stays empty.');
check(
    Settings::fromArray(['playlist_ids' => [3, 5, 3], 'playlist_id' => 9])->playlistIds === [3, 5],
    'The playlist list replaces the legacy value and lists each playlist once.'
);
check(!array_key_exists('playlist_id', $settings->toArray()), 'Only the playlist list is stored.');
rejects(static fn() => Settings::fromArray(['playlist_ids' => 7]));
rejects(static fn() => Settings::fromArray(['playlist_ids' => ['7']]));
rejects(static fn() => Settings::fromArray(['playlist_ids' => [0]]));
$playlist = static fn(int $id, bool $enabled = true): object => (object)['id' => $id, 'is_enabled' => $enabled];
$slots = [2 => true, 3 => false, 4 => true];
check(
    Schedule::filter(
        [$playlist(1), $playlist(2), $playlist(3), $playlist(4, false)],
        static fn(object $p): bool => $slots[$p->id] ?? true
    ) === [1, 2],
    'Playlists in their time slot and those without a schedule are active together; disabled ones are skipped.'
);
foreach ([['enabled' => 'false'], ['limit_delay' => 1], ['duck_db' => -21], ['max_wait' => -1], ['playlist_id' => 0]] as $invalid) {
    rejects(static fn() => Settings::fromArray($invalid));
}

$rotation = [
    ['id' => 1, 'body' => 10.0, 'last_played' => 1],
    ['id' => 2, 'body' => 24.0, 'last_played' => 2],
    ['id' => 3, 'body' => 29.0, 'last_played' => 3],
];
check(Selection::order($rotation, 30, new Settings())[0]['id'] === 1, 'Default rotates all fitting sweepers.');
$longest = new Settings(longestFit: true);
check(
    array_column(Selection::order($rotation, 30, $longest), 'id') === [2, 3, 1],
    '20% prefers 24 to 30 seconds without discarding shorter fitting sweepers.'
);
check(Selection::order($rotation, 30, new Settings(longestFit: true, lengthTolerance: 0))[0]['id'] === 1,
    'If no exact-length body exists, regular oldest-played rotation applies.');
check(
    Selection::order($rotation, 100, $longest) === Selection::order($rotation, 100, new Settings()),
    'No candidate in tolerance falls back to regular rotation, not the longest remaining body.'
);
foreach ([
    ['log' => [['result' => 'played', 'jingle_id' => 2], ['result' => 'played', 'jingle_id' => 3]]],
    ['plans' => ['a' => ['jingle_id' => 2, 'played' => false], 'b' => ['jingle_id' => 3, 'played' => false]]],
] as $unavailable) {
    $blockedIds = Selection::unavailable($unavailable, 2, 3);
    $eligible = array_values(array_filter(
        $rotation,
        static fn(array $row): bool => !in_array($row['id'], $blockedIds, true)
    ));
    check(
        array_column(Selection::order($eligible, 30, $longest), 'id') === [1],
        'Unavailable preferred sweepers do not block an eligible shorter sweeper.'
    );
}
check(
    array_column(Selection::order([$rotation[1], $rotation[0]], 30, $longest), 'id') === [2, 1],
    'A preferred eligible sweeper still precedes the older short sweeper.'
);
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
$lookahead = new Settings(playlistIds: [9], jinglePlaylistIds: [8], songsBeforeJingle: 2);
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

$song = static fn(int $id, bool $sent = false, int $media = 0, array $extra = []): array => [
    'id' => $id, 'sent' => $sent, 'media_id' => $media ?: $id, 'request_id' => null, 'playlist_id' => 1,
    'is_jingle' => false, 'playlist_type' => 'default', 'playlist_order' => 'shuffle', 'planned' => false,
    ...$extra,
];
$due = ['songs_since_jingle' => 5, 'songs_since_sweeper' => 5, 'previous_song' => true, 'previous_clear' => true];
$gapSettings = new Settings(playlistIds: [9], songsAfterJingle: 1, songsAfterSweeper: 1);
$intros = static fn(int ...$ids): Closure => static fn(int $media): bool => in_array($media, $ids, true);
check(
    Arrangement::choose([$song(21), $song(22), $song(23)], $due, $gapSettings, 3, $intros(23)) === [21, 23],
    'A due sweeper on a song without intro moves the first later song with one forward.'
);
check(
    Arrangement::choose([$song(21), $song(22)], $due, $gapSettings, 3, $intros(21, 22)) === null,
    'Nothing moves when the next song has a usable intro.'
);
check(
    Arrangement::choose([$song(21), $song(22)], $due, $gapSettings, 3, $intros(), $blocked) === null
        && $blocked === 'move_none',
    'Nothing moves when no queued song has an intro, and the reason is reported.'
);
check(
    Arrangement::choose([$song(21), $song(22)], $due, $gapSettings, 3, $intros(21, 22), $blocked) === null
        && $blocked === null,
    'No reason is reported when no move is needed.'
);
$sequential = ['playlist_order' => 'sequential'];
check(
    Arrangement::choose([$song(21), $song(23, extra: $sequential)], $due, $gapSettings, 3, $intros(23), $blocked) === null
        && $blocked === 'move_sequential',
    'A sequential song with an intro is reported as the reason.'
);
$fresh = ['songs_since_jingle' => 0, 'songs_since_sweeper' => 5, 'previous_song' => false, 'previous_clear' => true];
check(
    Arrangement::choose([$song(21), $song(22)], $fresh, $gapSettings, 3, $intros(22)) === null,
    'Nothing moves while the spacing rules do not allow a sweeper yet.'
);
check(
    Arrangement::choose([$song(20, true, extra: ['planned' => true]), $song(21), $song(22)], $due, $gapSettings, 3, $intros(22))
        === null,
    'A sweeper planned on the song already sent resets the gap for the next song.'
);
check(
    Arrangement::choose([$song(20, true), $song(21), $song(22)], $due, $gapSettings, 3, $intros(22)) === [21, 22],
    'A sent song without sweeper keeps the sweeper due.'
);
$withSequential = new Settings(playlistIds: [9], songsAfterJingle: 1, songsAfterSweeper: 1, swapSequential: true);
check(
    Arrangement::choose(
        [$song(21), $song(23, extra: $sequential), $song(25, extra: ['is_jingle' => true]),
            $song(26, extra: ['playlist_id' => 8]), $song(27, extra: ['excluded' => true]),
            $song(22, extra: ['request_id' => 4, 'playlist_id' => null, 'playlist_type' => null])],
        $due,
        new Settings(playlistIds: [9], songsAfterJingle: 1, songsAfterSweeper: 1, jinglePlaylistIds: [8]),
        6,
        $intros(22, 23, 25, 26, 27)
    ) === [21, 22],
    'Sequential songs (by default), jingles, additional jingle playlists and excluded playlists never move; '
        . 'a listener request with an intro may.'
);
check(
    Arrangement::choose([$song(21), $song(23, extra: $sequential)], $due, $withSequential, 3, $intros(23)) === [21, 23],
    'Sequential songs move when allowed.'
);
check(
    Arrangement::choose([$song(21, extra: $sequential), $song(22)], $due, $gapSettings, 3, $intros(22)) === null
        && Arrangement::choose([$song(21, extra: $sequential), $song(22)], $due, $withSequential, 3, $intros(22))
            === [21, 22],
    'A sequential next song keeps its place unless sequential songs may move.'
);
check(
    Arrangement::choose(
        [$song(21), $song(24, extra: ['playlist_type' => 'once_per_x_songs'])],
        $due,
        $gapSettings,
        3,
        $intros(24)
    ) === [21, 24],
    'Songs from interval playlists that are not jingles may move.'
);
check(
    Arrangement::choose([$song(21, extra: ['excluded' => true]), $song(22)], $due, $gapSettings, 3, $intros(22)) === null,
    'A song from a playlist without sweepers keeps its place.'
);
check(
    Queue::isExcluded(305, ['00 - Rotation', 'Full Day Mix'], [7], ['News'])
        === false
        && Queue::isExcluded(7, null, [7], ['News'])
        && Queue::isExcluded(305, ['00 - Rotation', 'Full Day Mix'], [322], ['00 - Rotation']),
    'Exclusion matches the playlist itself or a selected group in the playlist chain.'
);
check(
    Arrangement::choose(
        [$song(21, extra: ['request_id' => 3, 'playlist_id' => null, 'playlist_type' => null]), $song(22)],
        $due,
        $gapSettings,
        3,
        $intros(22)
    ) === [21, 22],
    'A requested song without intro may change places with a later song.'
);
$moveDefaults = Settings::fromArray([]);
check(!$moveDefaults->swapSequential && $moveDefaults->excludedPlaylistIds === [], 'New options are off and empty by default.');
rejects(static fn() => Settings::fromArray(['excluded_playlist_ids' => [0]]));
rejects(static fn() => Settings::fromArray(['excluded_playlist_ids' => 'news']));
rejects(static fn() => Settings::fromArray(['swap_sequential' => 'yes']));
check(
    Arrangement::choose(
        [$song(21), $song(22), $song(23, extra: ['is_jingle' => true]), $song(24)],
        $due,
        new Settings(playlistIds: [9], songsAfterJingle: 1, songsAfterSweeper: 1, songsBeforeJingle: 2),
        4,
        $intros(22, 24),
        $blocked
    ) === null && $blocked === 'move_jingle',
    'A moved song must still respect the songs-before-a-jingle rule.'
);
check(
    Arrangement::choose([$song(21), $song(22)], $due, $gapSettings, 3, $intros(22)) === [21, 22]
        && Arrangement::choose([$song(21, true), $song(22, true)], $due, $gapSettings, 3, $intros(22)) === null,
    'Rows already sent to Liquidsoap never move.'
);
check(
    Arranger::window('annotate:autocue_cue_in="1.5",autocue_cue_out="200",autocue_start_next="190":/m.mp3', 210)
        === [1.5, 190.0],
    'Planning window uses AzuraCast cue values like the module.'
);
check(Arranger::window('/media/song.mp3', 210) === [0.0, 210.0], 'Without cue values the whole file is used.');
check(!Settings::fromArray([])->swapForIntro, 'Moving songs forward is off by default.');

$plan = static fn(int $jingle): array => ['jingle_id' => $jingle, 'song_id' => 7, 'played' => false, 'payload' => ['offset' => 3.5]];
$start = static fn(string $token, string $detail, string $title = 'Song'): array
    => ['event' => 'start', 'token' => $token, 'detail' => $detail, 'media_id' => '7', 'title' => $title, 'artist' => 'Artist'];
$timeline = ['plans' => ['5' => $plan(42), '9' => $plan(43), '10' => $plan(44)], 'decisions' => ['6' => 'no_intro', '11' => 'planned']];
$timeline = Planner::apply($timeline, $start('5', 'scheduled'));
check(
    $timeline['log'][0]['result'] === 'scheduled' && $timeline['log'][0]['jingle_id'] === 42
        && $timeline['log'][0]['song_id'] === 7 && $timeline['log'][0]['title'] === 'Song',
    'A song with a reserved sweeper is recorded when it starts.'
);
$timeline = Planner::apply($timeline, $start('5', 'scheduled'));
check(count($timeline['log']) === 1, 'A re-sent start event is recorded once.');
$timeline = Planner::apply($timeline, ['event' => 'played', 'token' => '5']);
check(
    count($timeline['log']) === 1 && $timeline['log'][0]['result'] === 'played' && $timeline['log'][0]['offset'] === 3.5
        && isset($timeline['history'][42]),
    'The sweeper start completes the song entry.'
);
$timeline = Planner::apply($timeline, $start('6', 'none'));
check($timeline['log'][0]['result'] === 'no_intro' && !isset($timeline['decisions']['6']), 'Planning reasons are shown.');
$timeline = Planner::apply($timeline, $start('7', 'after_jingle'));
check($timeline['log'][0]['result'] === 'after_jingle', 'Module reasons are shown.');
$timeline = Planner::apply($timeline, $start('8', 'none'));
check($timeline['log'][0]['result'] === 'not_planned', 'A song the plugin never saw is marked as not planned.');
$timeline = Planner::apply($timeline, $start('9', 'scheduled'));
$timeline = Planner::apply($timeline, ['event' => 'release', 'token' => '9', 'detail' => 'skipped']);
check(
    $timeline['log'][0]['result'] === 'skipped' && $timeline['log'][0]['jingle_id'] === null && !isset($timeline['plans']['9']),
    'A released sweeper shows why it did not play.'
);
$timeline = Planner::apply($timeline, ['event' => 'release', 'token' => '10', 'detail' => 'settings']);
$timeline = Planner::apply($timeline, $start('10', 'none'));
check($timeline['log'][0]['result'] === 'settings', 'A release before the song starts keeps its reason.');
$timeline = Planner::apply($timeline, $start('11', 'none'));
check($timeline['log'][0]['result'] === 'unavailable', 'A planned sweeper the module could not load is reported.');
$timeline = Planner::apply($timeline, ['event' => 'start', 'token' => '', 'detail' => 'other', 'media_id' => '', 'title' => 'Live show', 'artist' => '']);
check(
    $timeline['log'][0]['result'] === 'other' && $timeline['log'][0]['song_id'] === null
        && $timeline['log'][0]['title'] === 'Live show',
    'Tracks outside the queue are listed with their title.'
);
$legacy = Planner::apply(['plans' => ['12' => $plan(45)]], ['event' => 'played', 'token' => '12']);
check(
    $legacy['log'][0]['result'] === 'played' && $legacy['log'][0]['jingle_id'] === 45,
    'Modules without start events still record played sweepers.'
);
$moved = Planner::apply(['moved' => ['13' => ['at' => 1, 'before' => 9]]], $start('13', 'none'));
check($moved['log'][0]['moved_before'] === 9 && !isset($moved['moved']['13']), 'A moved song is marked when it starts.');
$unmoved = Planner::apply(['unmoved' => ['14' => ['at' => 1, 'reason' => 'move_none']]], $start('14', 'none'));
check(
    $unmoved['log'][0]['unmoved'] === 'move_none' && !isset($unmoved['unmoved']['14']),
    'A song that could not be moved forward records why.'
);
check(Settings::fromArray([])->sweeperGap === 1, 'At least one other sweeper plays before a repeat by default.');
check(Settings::fromArray(['sweeper_gap' => 0])->sweeperGap === 0, 'Repeats can be allowed.');
rejects(static fn() => Settings::fromArray(['sweeper_gap' => -1]));
rejects(static fn() => Settings::fromArray(['sweeper_gap' => '2']));
$history = ['log' => [['jingle_id' => 5], ['jingle_id' => 6], ['jingle_id' => 7]]];
check(Selection::unavailable($history, 0, 4) === [], 'Gap 0 blocks nothing that is not reserved.');
check(Selection::unavailable($history, 1, 4) === [5], 'The last played sweeper waits for one other.');
check(Selection::unavailable($history, 2, 4) === [5, 6], 'A gap of two blocks the last two sweepers.');
check(Selection::unavailable($history, 1, 1) === [], 'A single sweeper can always repeat.');
check(Selection::unavailable($history, 5, 3) === [5, 6], 'The gap never exceeds the number of other sweepers.');
$timeline = ['log' => [
    ['jingle_id' => null, 'result' => 'no_intro'], ['jingle_id' => 44, 'result' => 'skipped'],
    ['jingle_id' => 5, 'result' => 'played'], ['jingle_id' => 6],
]];
check(
    Selection::unavailable($timeline, 2, 4) === [5, 6],
    'Only sweepers that actually played count; tracks without one do not.'
);
$pending = ['plans' => ['9' => ['jingle_id' => 8, 'played' => false], '10' => ['jingle_id' => 5, 'played' => true]]] + $history;
check(
    Selection::unavailable($pending, 1, 4) === [8] && Selection::unavailable($pending, 2, 4) === [8, 5],
    'A sweeper reserved for an upcoming song plays first and counts towards the gap.'
);
check(
    Selection::unavailable($pending, 1, 4, []) === [8, 5] && Selection::unavailable($pending, 1, 4, ['9']) === [8],
    'Only reservations that will play relax the repeat protection; dropped ones stay blocked.'
);
$prefetched = [$song(20, true, extra: ['planned' => true]), $song(23, true), $song(21)];
check(
    Arrangement::playing($prefetched, $due, $gapSettings) === ['20']
        && Arrangement::playing($prefetched, $fresh, $gapSettings) === [],
    'A sent song keeps its reservation only when the spacing rules let its sweeper play.'
);
$reverbDefaults = Settings::fromArray([]);
check(
    !$reverbDefaults->reverb && $reverbDefaults->reverbIntensity === 25.0 && $reverbDefaults->reverbLevel() === 0.0,
    'Reverb is off by default with a gentle 25% intensity ready.'
);
$withReverb = new Settings(margin: 0.3, reverb: true, reverbIntensity: 40);
check(abs($withReverb->reverbLevel() - 0.4) < 0.0001, 'Reverb level follows the intensity.');
check((new Settings(reverb: true, reverbIntensity: 0))->reverbLevel() === 0.0, 'Intensity 0 adds no reverb.');
$tight = Timing::fromArray(['cue_in' => 0, 'cue_out' => 3, 'cross_start_next' => 3], 3);
check(
    $tight->fit(10, 0, 10, new Settings(margin: 0.3)) == $tight->fit(10, 0, 10, $withReverb),
    'The reverb tail does not change which sweepers fit or their timing.'
);
rejects(static fn() => Settings::fromArray(['reverb_intensity' => 101]));
rejects(static fn() => Settings::fromArray(['reverb' => 1]));
check(Settings::fromArray(['swap_for_intro' => true])->swapForIntro, 'Moving songs forward can be switched on.');
rejects(static fn() => Settings::fromArray(['swap_for_intro' => 'yes']));
check(!Settings::fromArray([])->swapFromPlaylist, 'Same-playlist replacement is off by default.');
$replacementOnly = Settings::fromArray(['swap_from_playlist' => true]);
check(
    $replacementOnly->swapFromPlaylist && !$replacementOnly->swapForIntro
        && Settings::fromArray($replacementOnly->toArray())->swapFromPlaylist
        && $replacementOnly->revision() !== Settings::fromArray([])->revision(),
    'Replacement is independent, persists and refreshes the runtime revision.'
);
rejects(static fn() => Settings::fromArray(['swap_from_playlist' => 'yes']));
check(
    Arrangement::replacement([$song(21), $song(22)], $due, $replacementOnly, 3, $intros())['id'] === 21,
    'Only the next unsent song is proposed for replacement.'
);
foreach ([
    ['request_id' => 3], ['playlist_id' => null], ['planned' => true],
    ['playlist_order' => 'sequential'], ['excluded' => true], ['is_jingle' => true],
] as $extra) {
    check(
        Arrangement::replacement([$song(21, extra: $extra)], $due, $replacementOnly, 3, $intros()) === null,
        'Protected entries cannot be replaced: ' . json_encode($extra)
    );
}
check(
    Arrangement::replacement([$song(21, extra: $sequential)], $due, $withSequential, 3, $intros())['id'] === 21,
    'The shared sequential opt-in also allows replacement.'
);
check(
    Arrangement::replacement([$song(21)], $fresh, $gapSettings, 3, $intros()) === null
        && Arrangement::replacement([$song(21)], $due, $gapSettings, 3, $intros(21)) === null,
    'Replacement respects spacing and leaves an already usable intro alone.'
);
check(
    Arrangement::replacement(
        [$song(21), $song(22, extra: ['is_jingle' => true])],
        $due,
        new Settings(songsBeforeJingle: 1),
        3,
        $intros(),
        $blocked
    ) === null && $blocked === 'move_jingle',
    'Replacement cannot bypass the before-jingle spacing rule.'
);
$replaced = Planner::apply(
    ['moved' => ['13' => ['at' => 1, 'before' => 9, 'replacement' => true]]],
    $start('13', 'none')
);
check(
    $replaced['log'][0]['replaced_before'] === 9 && !isset($replaced['log'][0]['moved_before'])
        && !isset($replaced['moved']['13']),
    'The replacement is distinguished from a forward move only when the song actually starts.'
);

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
    file_put_contents($directory . '/sweepers.json', json_encode(['history' => ['42' => 100], 'log' => [['at' => 5.5]]]));
    check($state->read($directory)['log'] === [['at' => 5.5]], 'An existing timeline is still read.');
    check(
        json_decode(file_get_contents($directory . '/sweepers-history.json'), true) === [['at' => 5.5]]
            && json_decode(file_get_contents($directory . '/sweepers.json'), true) === ['history' => ['42' => 100]],
        'An existing timeline moves into its own file.'
    );
    $state->update($directory, static fn(array $data) => ['history' => ['42' => 200]] + $data);
    check(
        $state->read($directory)['log'] === [['at' => 5.5]] && $state->read($directory)['history'][42] === 200,
        'State changes keep the timeline.'
    );
    $state->update($directory, static function (array $data): array {
        unset($data['log']);

        return $data;
    });
    check(!isset($state->read($directory)['log']), 'A cleared timeline stays cleared.');
} finally {
    foreach (glob($directory . '/*') as $path) {
        unlink($path);
    }
    rmdir($directory);
}

echo $checks . " checks passed.\n";
