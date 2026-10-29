<?php

declare(strict_types=1);

namespace App\Entity {
    final class Station
    {
        public function __construct(private string $directory)
        {
        }

        public function getRadioConfigDir(): string
        {
            return $this->directory;
        }
    }
}

namespace {
    require __DIR__ . '/run.php';

    $directory = sys_get_temp_dir() . '/sweepers-reservations-' . bin2hex(random_bytes(6));
    mkdir($directory);
    $station = new \App\Entity\Station($directory);
    $state = new \Plugin\AzuracastSweepers\Service\State();
    $media = (new ReflectionClass(\Plugin\AzuracastSweepers\Service\Media::class))->newInstanceWithoutConstructor();
    $queue = (new ReflectionClass(\Plugin\AzuracastSweepers\Service\Queue::class))->newInstanceWithoutConstructor();
    $schedule = (new ReflectionClass(\Plugin\AzuracastSweepers\Service\Schedule::class))->newInstanceWithoutConstructor();
    $planner = new \Plugin\AzuracastSweepers\Service\Planner($state, $media, $queue, $schedule);
    $seed = static fn(int $expires): array => ['plans' => ['123' => [
        'song_id' => 7, 'jingle_id' => 42, 'expires_at' => $expires, 'played' => false, 'payload' => ['offset' => 3.5],
    ]]];
    try {
        $state->update($directory, static fn() => $seed(time() + 1));
        $planner->heartbeat($station, ['123'], []);
        check($state->read($directory)['plans'][123]['expires_at'] >= time() + 29, 'Heartbeat renews a live reservation.');
        check(empty($state->read($directory)['history']), 'Prefetch does not count as playback.');
        $planner->heartbeat($station, [], [['event' => 'played', 'token' => '123']]);
        $history = $state->read($directory)['history'];
        $log = $state->read($directory)['log'];
        check(
            count($log) === 1 && $log[0]['song_id'] === 7 && $log[0]['jingle_id'] === 42 && $log[0]['offset'] === 3.5,
            'An actually played sweeper is recorded with its song, sweeper and start offset.'
        );
        $planner->heartbeat($station, [], [['event' => 'played', 'token' => '123']]);
        check($state->read($directory)['history'] === $history, 'Playback retries are idempotent.');
        check(count($state->read($directory)['log']) === 1, 'A repeated playback event is recorded once.');
        $planner->heartbeat($station, [], [['event' => 'release', 'token' => '123']]);
        check($state->read($directory)['plans'] === [], 'Release frees a reserved sweeper.');
        $state->update($directory, static fn() => $seed(time() - 1));
        $planner->heartbeat($station, ['123'], []);
        check($state->read($directory)['plans'] === [], 'Expired leases cannot be revived.');
        $state->update($directory, static fn() => $seed(time() + 20));
        $planner->heartbeat($station, [], [['event' => 'release', 'token' => '123']]);
        check(empty($state->read($directory)['history']), 'A skipped sweeper does not advance rotation.');
        check(!isset($state->read($directory)['log']), 'A released (never heard) sweeper is not recorded.');
        $limit = \Plugin\AzuracastSweepers\Service\Planner::LOG_LIMIT;
        $recent = microtime(true) - 60;
        $expired = microtime(true) - \Plugin\AzuracastSweepers\Service\Planner::LOG_RETENTION - 60;
        $state->update($directory, static fn(array $data) => ['log' => [
            ...array_fill(0, 3, ['at' => $recent]),
            ['at' => $expired],
        ]] + $data);
        $state->update($directory, static fn(array $data) => $seed(time() + 20) + $data);
        $planner->heartbeat($station, [], [['event' => 'played', 'token' => '123']]);
        $log = $state->read($directory)['log'];
        check(
            count($log) === 4 && $log[0]['song_id'] === 7 && $log[3]['at'] === $recent,
            'The timeline keeps recent entries and forgets those past the retention.'
        );
        $state->update($directory, static fn(array $data) => ['log' => array_fill(0, $limit, ['at' => $recent])] + $data);
        $state->update($directory, static fn(array $data) => $seed(time() + 20) + $data);
        $planner->heartbeat($station, [], [['event' => 'played', 'token' => '123']]);
        $log = $state->read($directory)['log'];
        check(count($log) === $limit && $log[0]['song_id'] === 7, 'A full timeline keeps the newest entries.');
        check(!array_key_exists('moved_before', $log[0]), 'A song in its planned order is not marked as moved.');
        $state->update($directory, static fn(array $data) => ['moved' => ['124' => ['at' => time(), 'before' => 9]]]
            + ['plans' => ['124' => $seed(time() + 20)['plans']['123']]] + $data);
        $planner->heartbeat($station, [], [['event' => 'played', 'token' => '124']]);
        $data = $state->read($directory);
        check(
            $data['log'][0]['moved_before'] === 9 && !isset($data['moved']['124']),
            'A moved song that played with a sweeper records the song it moved ahead of.'
        );
        $liveSettings = new \Plugin\AzuracastSweepers\ValueObject\Settings(
            enabled: true,
            playlistIds: [7],
            songsAfterJingle: 3,
            songsAfterSweeper: 2
        );
        $state->update($directory, static fn(array $data) => ['settings' => $liveSettings->toArray()] + $data);
        $result = $planner->heartbeat($station, ['123'], []);
        check(
            $result['settings']->runtime() === $liveSettings->runtime(),
            'Heartbeat supplies all runtime settings without a restart.'
        );
        check($result['blocked'] === [], 'Without a before-jingle gap the queue is not queried.');
        $state->update($directory, static fn(array $data) => ['settings' => ['enabled' => false]] + $data);
        check(!$planner->heartbeat($station, [], [])['settings']->enabled, 'Heartbeat picks up disabling immediately.');
        $at = microtime(true) - 300;
        $events = [
            ['event' => 'release', 'token' => '500', 'detail' => 'error', 'at' => $at - 1],
            ['event' => 'start', 'token' => '500', 'detail' => 'none', 'at' => $at],
            ['event' => 'start', 'token' => '501', 'detail' => 'after_jingle', 'at' => $at + 120],
        ];
        $state->update($directory, static fn(): array => []);
        $planner->heartbeat($station, [], $events);
        $log = $state->read($directory)['log'];
        check(
            array_column($log, 'at') === [$at + 120, $at] && $log[1]['result'] === 'error',
            'Buffered starts keep their playback times and API failures through the heartbeat and state file.'
        );
        $planner->heartbeat($station, [], $events);
        check(
            $state->read($directory)['log'] === $log,
            'Replaying a buffered batch never duplicates tracks or changes their timestamps.'
        );
    } finally {
        foreach (glob($directory . '/*') as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
    echo "Reservation lifecycle passed.\n";
}
