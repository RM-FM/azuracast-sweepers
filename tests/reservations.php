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
    $planner = new \Plugin\AzuracastSweepers\Service\Planner($state, $media, $queue);
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
        $state->update($directory, static fn(array $data) => ['log' => array_fill(0, 200, ['at' => 1.0])] + $data);
        $state->update($directory, static fn(array $data) => $seed(time() + 20) + $data);
        $planner->heartbeat($station, [], [['event' => 'played', 'token' => '123']]);
        $log = $state->read($directory)['log'];
        check(count($log) === 200 && $log[0]['song_id'] === 7, 'History keeps the newest 200 entries.');
        $liveSettings = new \Plugin\AzuracastSweepers\ValueObject\Settings(
            enabled: true,
            playlistId: 7,
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
    } finally {
        foreach (glob($directory . '/*') as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
    echo "Reservation lifecycle passed.\n";
}
