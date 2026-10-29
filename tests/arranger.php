<?php

declare(strict_types=1);

namespace App\Entity {
    final class Station
    {
        public object $backend_config;

        public function __construct(private string $directory)
        {
            $this->backend_config = (object)['autodj_queue_length' => 3];
        }

        public function getRadioConfigDir(): string
        {
            return $this->directory;
        }
    }
}

namespace Plugin\AzuracastSweepers\Service {
    use App\Entity\Station;
    use Plugin\AzuracastSweepers\ValueObject\Settings;

    final class Media
    {
        public array $intros = [21 => 0, 22 => 10, 33 => 10];
        public array $jingleIds = [9];

        public function jingles(): array
        {
            return array_map(
                static fn(int $id): object => (object)['id' => $id, 'path' => $id . '.mp3'],
                $this->jingleIds
            );
        }

        public function get(Station $station, int $id): object
        {
            return (object)['id' => $id, 'length' => 180.0];
        }

        public function intro(object $media): ?float
        {
            return $this->intros[$media->id] ?? null;
        }

        public function uri(): string
        {
            return 'annotate:liq_cue_in="0",liq_cue_out="180":song';
        }

        public static function identity(object $media): array
        {
            return [$media->id];
        }
    }

    final class Schedule
    {
        public function active(): array
        {
            return [9];
        }
    }

    final class Queue
    {
        public array $rows = [];
        public array $exchanges = [];
        public bool $exchangeResult = true;

        public static function length(): int
        {
            return 3;
        }

        public static function isExcluded(): bool
        {
            return false;
        }

        public function excludedNames(): array
        {
            return [];
        }

        public function excluded(): bool
        {
            return false;
        }

        public function blocked(): array
        {
            return [];
        }

        public function upcoming(): array
        {
            return $this->rows;
        }

        public function exchange(Station $station, int $first, int $second): bool
        {
            $this->exchanges[] = [$first, $second];

            return $this->exchangeResult;
        }
    }

    final class PlaylistReplacement
    {
        public array $targets = [];
        public ?int $result = 33;

        public function replace(
            Station $station,
            array $target,
            string $counted,
            Settings $settings,
            callable $usable,
            ?string &$reason,
            int $deadline
        ): ?int {
            $this->targets[] = $target['id'];
            $reason = $this->result === null ? 'replace_none' : null;
            if ($this->result !== null && !$usable($this->result)) {
                throw new \RuntimeException('The replacement must use the resolved fitting-intro predicate.');
            }

            return $this->result;
        }
    }
}

namespace {
    use App\Entity\Station;
    use Plugin\AzuracastSweepers\Service\Arranger;
    use Plugin\AzuracastSweepers\Service\Media;
    use Plugin\AzuracastSweepers\Service\Planner;
    use Plugin\AzuracastSweepers\Service\PlaylistReplacement;
    use Plugin\AzuracastSweepers\Service\Queue;
    use Plugin\AzuracastSweepers\Service\Schedule;
    use Plugin\AzuracastSweepers\Service\State;
    use Plugin\AzuracastSweepers\ValueObject\Settings;
    use Plugin\AzuracastSweepers\ValueObject\Timing;

    require dirname(__DIR__) . '/vendor/autoload.php';

    $directory = sys_get_temp_dir() . '/sweepers-arranger-' . bin2hex(random_bytes(6));
    mkdir($directory);
    $station = new Station($directory);
    $state = new State();
    $media = new Media();
    $queue = new Queue();
    $replacement = new PlaylistReplacement();
    $arranger = new Arranger($state, $media, $queue, new Schedule(), $replacement);
    $song = static fn(int $id): array => [
        'id' => $id, 'sent' => false, 'media_id' => $id, 'request_id' => null, 'playlist_id' => 1,
        'playlist_chain' => [], 'is_jingle' => false, 'playlist_order' => 'shuffle',
    ];
    $reset = static function (bool $move, bool $replace) use (
        $state, $directory, $queue, $media, $replacement, $song
    ): void {
        $settings = new Settings(playlistIds: [9], swapForIntro: $move, swapFromPlaylist: $replace);
        $state->update($directory, static fn(): array => ['settings' => $settings->toArray()]);
        $queue->rows = [$song(21), $song(22)];
        $queue->exchanges = [];
        $queue->exchangeResult = true;
        $media->intros = [21 => 0, 22 => 10, 33 => 10];
        $replacement->targets = [];
        $replacement->result = 33;
    };
    $gap = ['songs_since_jingle' => 5, 'songs_since_sweeper' => 5, 'previous_song' => true, 'previous_clear' => true];
    $timings = [9 => Timing::fromArray(['cue_in' => 0, 'cue_out' => 3, 'cross_start_next' => 3], 3)];
    $arrange = static fn(): array => $arranger->arrange($station, $gap, '20', $timings);
    $none = ['moved' => '', 'before' => '', 'replaced' => ''];
    $checks = 0;
    $check = static function (bool $valid, string $message) use (&$checks): void {
        $checks++;
        if (!$valid) {
            throw new RuntimeException($message);
        }
    };
    try {
        $reset(false, false);
        $check($arrange() === $none && $replacement->targets === [] && $queue->exchanges === [], 'Both disabled.');
        foreach ([false, true] as $fallback) {
            $reset(true, $fallback);
            $check(
                $arrange() === ['moved' => '22', 'before' => '21', 'replaced' => '']
                    && $queue->exchanges === [[21, 22]] && $replacement->targets === [],
                'Existing queued-song movement has priority and works independently.'
            );
        }
        $reset(false, true);
        $check(
            $arrange() === ['moved' => '', 'before' => '', 'replaced' => '21']
                && $queue->exchanges === [] && $replacement->targets === [21],
            'Replacement works alone, even with an otherwise movable queued song.'
        );
        $reset(true, true);
        $media->intros[22] = 0;
        $state->update($directory, static fn(array $data): array => [
            'unmoved' => ['21' => ['at' => time(), 'reason' => 'move_none']],
            'decisions' => ['21' => 'no_intro'],
        ] + $data);
        $check($arrange()['replaced'] === '21' && $replacement->targets === [21], 'Fallback after no queued match.');
        $data = $state->read($directory);
        $check(
            ($data['moved']['21']['replacement'] ?? false) && $data['moved']['21']['before'] === 21
                && !isset($data['unmoved']['21']) && !isset($data['decisions']['21']) && !isset($data['log']),
            'Success clears stale decisions and records replacement without counting playback.'
        );
        $check(($data['replacement_counts'][21] ?? 0) === 1, 'Only the displaced song gains a replacement count.');
        $queue->rows[0]['id'] = 24;
        $check(
            $arrange()['replaced'] === '24' && $state->read($directory)['replacement_counts'][21] === 2,
            'The same media accumulates replacements across queue tokens.'
        );
        $queue->rows[0]['id'] = 25;
        $queue->rows[0]['playlist_id'] = 2;
        $media->intros[22] = 10;
        $queue->exchanges = [];
        $replacement->targets = [];
        $check(
            $arrange() === $none && $queue->exchanges === [] && $replacement->targets === []
                && $state->read($directory)['unmoved']['25']['reason'] === 'replace_limit',
            'At two replacements the next turn is protected from both strategies across playlists.'
        );
        $restart = new Arranger(new State(), $media, $queue, new Schedule(), $replacement);
        $check(
            $restart->arrange($station, $gap, '20', $timings) === $none && $queue->exchanges === [],
            'Replacement protection survives service recreation.'
        );
        $planner = new Planner($state, $media, $queue, new Schedule());
        $planner->plan($station, 21, '25', 0, 180, $timings);
        $check(
            $state->read($directory)['replacement_counts'][21] === 2,
            'Prefetching and planning do not reset replacement counts.'
        );
        $planner->heartbeat($station, [], [['event' => 'release', 'token' => '25']]);
        $check(
            $state->read($directory)['replacement_counts'][21] === 2,
            'Cancelling a prefetched plan does not reset replacement counts.'
        );
        $start = ['event' => 'start', 'token' => '25', 'media_id' => '21', 'detail' => 'none'];
        $planner->heartbeat($station, [], [$start]);
        $check(
            !isset($state->read($directory)['replacement_counts'][21]),
            'Actual song start resets the replacement count even without a sweeper.'
        );
        $queue->rows[0]['id'] = 26;
        $media->intros[22] = 0;
        $check(
            $arrange()['replaced'] === '26' && $state->read($directory)['replacement_counts'][21] === 1,
            'A played song starts a fresh replacement allowance.'
        );
        $planner->heartbeat($station, [], [$start]);
        $check(
            $state->read($directory)['replacement_counts'][21] === 1,
            'A duplicate start event cannot clear a later replacement count.'
        );
        $state->update($directory, static function (array $data): array {
            $data['settings']['replacement_limit'] = 1;
            $data['settings']['swap_from_playlist'] = false;
            $data['replacement_counts'][33] = 2;

            return $data;
        });
        $media->intros[22] = 10;
        $queue->exchanges = [];
        $check(
            $arrange() === $none && $queue->exchanges === [],
            'A configured one-replacement limit protects movement even when replacement is switched off.'
        );
        $planner->heartbeat($station, [], [
            ['event' => 'start', 'token' => '28', 'media_id' => '33', 'detail' => 'none'],
        ]);
        $check(
            $state->read($directory)['replacement_counts'][21] === 1
                && !isset($state->read($directory)['replacement_counts'][33]),
            'Only the song that actually starts has its replacement allowance reset.'
        );
        $media->intros[22] = 0;
        $state->update($directory, static function (array $data): array {
            $data['settings']['replacement_limit'] = 0;
            $data['settings']['swap_from_playlist'] = true;
            $data['replacement_counts'][21] = 1000;

            return $data;
        });
        $queue->rows[0]['id'] = 27;
        $check(
            $arrange()['replaced'] === '27' && $state->read($directory)['replacement_counts'][21] === 1000,
            'Zero allows unlimited replacements while counters remain bounded.'
        );
        $reset(true, false);
        $check(
            $arrange()['moved'] === '22' && !isset($state->read($directory)['replacement_counts']),
            'Queued-song movement does not consume the replacement allowance.'
        );
        $reset(true, true);
        $media->intros[22] = 0;
        $replacement->result = null;
        $check(
            $arrange() === $none && $state->read($directory)['unmoved']['21']['reason'] === 'replace_none',
            'An unsuccessful fallback is explained, not reported as a successful move.'
        );
        $check(
            !isset($state->read($directory)['replacement_counts']),
            'An unsuccessful replacement does not consume the allowance.'
        );
        $reset(true, true);
        $media->intros[22] = 0;
        $queue->rows[0]['request_id'] = 4;
        $check(
            $arrange() === $none && $replacement->targets === []
                && $state->read($directory)['unmoved']['21']['reason'] === 'move_none',
            'An ineligible replacement retains the original forward-move failure reason.'
        );
        $reset(true, true);
        $queue->exchangeResult = false;
        $check($arrange() === $none && $replacement->targets === [], 'A movement race never uses a stale fallback.');
        $reset(true, true);
        $state->update($directory, static fn(array $data): array => ['plans' => ['21' => ['played' => false]]] + $data);
        $check($arrange() === $none && $queue->exchanges === [] && $replacement->targets === [], 'Planned slot fixed.');
        $media->jingleIds = [9, 10];
        $recent = ['log' => [['result' => 'played', 'jingle_id' => 9]]];
        $dropped = [
            'songs_since_jingle' => 0, 'songs_since_sweeper' => 5, 'previous_song' => false, 'previous_clear' => true,
        ];
        $reset(true, false);
        $queue->rows = [['sent' => true] + $song(20), ['sent' => true] + $song(23), $song(21), $song(22)];
        $state->update($directory, static fn(array $data): array => [
            'plans' => ['20' => ['jingle_id' => 10, 'played' => false]],
        ] + $recent + $data);
        $check(
            $arranger->arrange($station, $dropped, '19', $timings) === $none && $queue->exchanges === []
                && $state->read($directory)['unmoved']['21']['reason'] === 'move_none',
            'A reservation the module will drop for spacing does not relax the repeat protection.'
        );
        $reset(true, false);
        $queue->rows = [['sent' => true] + $song(20), ['sent' => true] + $song(23), $song(21), $song(22)];
        $state->update($directory, static fn(array $data): array => [
            'plans' => ['20' => ['jingle_id' => 10, 'played' => false]],
        ] + $recent + $data);
        $check(
            $arranger->arrange($station, $gap, '19', $timings)['moved'] === '22',
            'A reservation that will play counts towards the gap and frees the recently played sweeper.'
        );
        $reset(true, false);
        $state->update($directory, static fn(array $data): array => [
            'plans' => ['20' => ['jingle_id' => 10, 'played' => false]],
        ] + $recent + $data);
        $check(
            $arranger->arrange($station, $gap, '20', $timings)['moved'] === '22',
            'The pending sweeper of the current song counts towards the gap.'
        );
        $reset(true, false);
        $state->update($directory, static fn(array $data): array => $recent + $data);
        $check(
            $arranger->arrange($station, $gap, '20', $timings) === $none,
            'Without a reservation that will play, the recently played sweeper stays blocked.'
        );
        $blockedGap = [
            'songs_since_jingle' => 0, 'songs_since_sweeper' => 5, 'previous_song' => true, 'previous_clear' => true,
        ];
        $allTimings = [
            9 => $timings[9],
            10 => $timings[9],
            11 => Timing::fromArray(['cue_in' => 0, 'cue_out' => 5, 'cross_start_next' => 5], 5),
            12 => Timing::fromArray(['cue_in' => 0, 'cue_out' => 6, 'cross_start_next' => 6], 6),
        ];
        $seedBlocked = static function () use ($reset, $state, $directory, $queue, $media, $song): void {
            $reset(true, true);
            $media->jingleIds = [9, 10, 11, 12];
            $media->intros[21] = 4;
            $queue->rows = [['sent' => true] + $song(23), $song(21), $song(22)];
            $settings = new Settings(
                playlistIds: [9],
                songsAfterJingle: 2,
                sweeperGap: 2,
                swapForIntro: true,
                swapFromPlaylist: true
            );
            $state->update($directory, static fn(): array => [
                'settings' => $settings->toArray(),
                'plans' => [
                    '20' => ['jingle_id' => 11, 'played' => false, 'expires_at' => time() + 30],
                    '23' => ['jingle_id' => 12, 'played' => false, 'expires_at' => time() + 30],
                ],
                'log' => [
                    ['result' => 'played', 'jingle_id' => 9],
                    ['result' => 'played', 'jingle_id' => 10],
                ],
            ]);
        };
        $seedBlocked();
        $replacement->result = null;
        $check(
            $arranger->arrange($station, $blockedGap, '20', $allTimings) === $none,
            'Older modules retain reservations until their normal release acknowledgement.'
        );
        $seedBlocked();
        $releasedMove = $arranger->arrange($station, $blockedGap, '20', $allTimings, releaseSpacing: true);
        $check(
            $releasedMove === ['moved' => '22', 'before' => '21', 'replaced' => '', 'released' => ['20', '23']],
            'Spacing-blocked current and fetched reservations free sweepers for queued-song matching.'
        );
        $check(
            count($state->read($directory)['plans']) === 2,
            'Reservations remain on the server until the module acknowledges cancellation.'
        );
        $planner = new Planner($state, $media, $queue, new Schedule());
        $planner->heartbeat($station, [], [
            ['event' => 'release', 'token' => '20'], ['event' => 'release', 'token' => '23'],
        ]);
        $check(
            $state->read($directory)['plans'] === [],
            'The cancellation acknowledgement releases server reservations before planning.'
        );
        $payload = $planner->plan($station, 22, '22', 0, 180, $allTimings);
        $check(
            $payload['uri'] !== '' && in_array($state->read($directory)['plans']['22']['jingle_id'], [11, 12], true)
                && array_column($state->read($directory)['log'], 'jingle_id') === [9, 10],
            'Planning uses a released sweeper without changing the played-sweeper repetition history.'
        );
        $seedBlocked();
        $media->intros[22] = 0;
        $check(
            $arranger->arrange($station, $blockedGap, '20', $allTimings, releaseSpacing: true)
                === ['moved' => '', 'before' => '', 'replaced' => '21', 'released' => ['20', '23']],
            'Released spacing-blocked reservations also permit same-playlist replacement.'
        );
        $seedBlocked();
        $state->update($directory, static function (array $data): array {
            $data['plans']['20']['played'] = true;

            return $data;
        });
        $check(
            $arranger->arrange($station, $blockedGap, '20', $allTimings, releaseSpacing: true)['released'] === ['23'],
            'Played reservations are never cancelled as spacing-blocked prefetch.'
        );
        $seedBlocked();
        $currentPlaying = ['songs_since_sweeper' => 0, 'previous_clear' => false] + $blockedGap;
        $check(
            !in_array('20', $arranger->arrange(
                $station,
                $currentPlaying,
                '20',
                $allTimings,
                releaseSpacing: true
            )['released'] ?? [], true),
            'An audible current sweeper stays protected before its played event reaches the server.'
        );
        $seedBlocked();
        $check(
            !isset($arranger->arrange($station, $gap, '20', $allTimings, releaseSpacing: true)['released']),
            'Reservations surviving the spacing rules remain protected.'
        );
        $media->jingleIds = [9];
        echo $checks . " arranger orchestration checks passed.\n";
        $planner = new Planner($state, $media, $queue, new Schedule());
        $media->intros[21] = 30.5;
        $media->jingleIds = [1, 2, 3];
        $measurements = [];
        foreach ([1 => 10, 2 => 24, 3 => 29] as $id => $body) {
            $measurements[$id] = Timing::fromArray([
                'cue_in' => 0, 'cue_out' => $body, 'cross_start_next' => $body,
            ], $body);
        }
        $settings = new Settings(playlistIds: [9], longestFit: true, sweeperGap: 2);
        foreach ([
            [[], 2],
            [['log' => [
                ['result' => 'played', 'jingle_id' => 2], ['result' => 'played', 'jingle_id' => 3],
            ]], 1],
            [['plans' => [
                '100' => ['jingle_id' => 2, 'played' => false, 'expires_at' => time() + 30],
                '101' => ['jingle_id' => 3, 'played' => false, 'expires_at' => time() + 30],
            ]], 1],
        ] as [$seed, $expected]) {
            $state->update($directory, static fn(): array => [
                'settings' => $settings->toArray(), 'history' => [1 => 1, 2 => 2, 3 => 3],
            ] + $seed);
            $payload = $planner->plan($station, 21, '200', 0, 180, $measurements);
            $data = $state->read($directory);
            $check(
                $payload['uri'] !== '' && $data['plans']['200']['jingle_id'] === $expected,
                'Actual planner prefers the tolerance range, then uses a shorter eligible sweeper.'
            );
            $check($data['history'] === [1 => 1, 2 => 2, 3 => 3], 'Planning never counts as sweeper playback.');
            $retry = $planner->plan($station, 21, '200', 0, 180, $measurements);
            $retried = $state->read($directory);
            $check(
                json_encode($retry) === json_encode($payload) && count($retried['plans']) === count($data['plans'])
                    && $retried['plans']['200']['jingle_id'] === $expected,
                'Retrying a lost response reuses the same reservation without reserving another sweeper.'
            );
        }
        $media->intros[21] = 100.5;
        $state->update($directory, static fn(): array => [
            'settings' => $settings->toArray(), 'history' => [1 => 1, 2 => 2, 3 => 3],
        ]);
        $planner->plan($station, 21, '200', 0, 180, $measurements);
        $check(
            $state->read($directory)['plans']['200']['jingle_id'] === 1,
            'When all bodies are below tolerance, the actual planner falls back to oldest play, not longest.'
        );
        $media->intros[21] = 5;
        $state->update($directory, static fn(): array => ['settings' => $settings->toArray()]);
        $check(
            $planner->plan($station, 21, '200', 0, 180, $measurements)['uri'] === '',
            'Fallback cannot force a sweeper that does not fit.'
        );
        $media->intros[21] = 12.5;
        foreach ([
            [['plans' => ['300' => ['jingle_id' => 1, 'played' => false, 'expires_at' => time() + 30]]], 'reserved'],
            [['log' => [['result' => 'played', 'jingle_id' => 1]]], 'repeat'],
        ] as [$seed, $expected]) {
            $state->update($directory, static fn(): array => ['settings' => $settings->toArray()] + $seed);
            $planner->plan($station, 21, '200', 0, 180, $measurements);
            $check(
                $state->read($directory)['decisions']['200'] === $expected,
                'The only fitting sweeper blocked by a reservation is reported apart from a recent repeat.'
            );
        }
        echo "13 longest-fit planner checks passed.\n";
    } finally {
        foreach (glob($directory . '/*') as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
}
