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
    $planner = new \Plugin\AzuracastSweepers\Service\Planner($state, $media);
    $seed = static fn(int $expires): array => ['plans' => ['123' => [
        'jingle_id' => 42, 'expires_at' => $expires, 'played' => false,
    ]]];
    try {
        $state->update($directory, static fn() => $seed(time() + 1));
        $planner->heartbeat($station, ['123'], []);
        check($state->read($directory)['plans'][123]['expires_at'] >= time() + 29, 'Heartbeat renews a live reservation.');
        check(empty($state->read($directory)['history']), 'Prefetch does not count as playback.');
        $planner->heartbeat($station, [], [['event' => 'played', 'token' => '123']]);
        $history = $state->read($directory)['history'];
        $planner->heartbeat($station, [], [['event' => 'played', 'token' => '123']]);
        check($state->read($directory)['history'] === $history, 'Playback retries are idempotent.');
        $planner->heartbeat($station, [], [['event' => 'release', 'token' => '123']]);
        check($state->read($directory)['plans'] === [], 'Release frees a reserved sweeper.');
        $state->update($directory, static fn() => $seed(time() - 1));
        $planner->heartbeat($station, ['123'], []);
        check($state->read($directory)['plans'] === [], 'Expired leases cannot be revived.');
        $state->update($directory, static fn() => $seed(time() + 20));
        $planner->heartbeat($station, [], [['event' => 'release', 'token' => '123']]);
        check(empty($state->read($directory)['history']), 'A skipped sweeper does not advance rotation.');
        $liveSettings = new \Plugin\AzuracastSweepers\ValueObject\Settings(enabled: true, playlistId: 7, minimumSongs: 3);
        $state->update($directory, static fn(array $data) => ['settings' => $liveSettings->toArray()] + $data);
        check(
            $planner->heartbeat($station, [], [])->runtime() === $liveSettings->runtime(),
            'Heartbeat supplies all runtime settings without a restart.'
        );
        $state->update($directory, static fn(array $data) => ['settings' => ['enabled' => false]] + $data);
        check(!$planner->heartbeat($station, [], [])->enabled, 'Heartbeat picks up disabling immediately.');
    } finally {
        foreach (glob($directory . '/*') as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
    echo "Reservation lifecycle passed.\n";
}
