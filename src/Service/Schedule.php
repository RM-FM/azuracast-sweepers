<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\Service;

use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Radio\AutoDJ\Scheduler;
use DateTimeImmutable;
use DateTimeZone;

final readonly class Schedule
{
    public function __construct(
        private Media $media,
        private Scheduler $scheduler
    ) {
    }

    /**
     * @param list<int> $ids
     * @return list<int> Enabled sweeper playlists scheduled now; playlists without a schedule always are.
     */
    public function active(Station $station, array $ids): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // Without special rules AzuraCast only reads the schedule and never resets playlist queues.
        return self::filter(
            $this->media->sweeperPlaylists($station, $ids),
            fn(StationPlaylist $playlist): bool => $this->scheduler->isPlaylistScheduledToPlayNow($playlist, $now, true)
        );
    }

    /**
     * @param iterable<object{id: int, is_enabled: bool}> $playlists
     * @param callable(object): bool $scheduled
     * @return list<int>
     */
    public static function filter(iterable $playlists, callable $scheduled): array
    {
        $active = [];
        foreach ($playlists as $playlist) {
            if ($playlist->is_enabled && $scheduled($playlist)) {
                $active[] = $playlist->id;
            }
        }

        return $active;
    }
}
