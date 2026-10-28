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
        $reset(true, true);
        $media->intros[22] = 0;
        $replacement->result = null;
        $check(
            $arrange() === $none && $state->read($directory)['unmoved']['21']['reason'] === 'replace_none',
            'An unsuccessful fallback is explained, not reported as a successful move.'
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
        echo "8 longest-fit planner checks passed.\n";
    } finally {
        foreach (glob($directory . '/*') as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
}
