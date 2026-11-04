<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\Service;

use App\Entity\Enums\PlaylistOrders;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Repository\StationPlaylistMediaRepository;
use App\Entity\Station;
use App\Entity\StationPlaylistMedia;
use App\Lock\LockFactory;
use Doctrine\ORM\EntityManagerInterface;
use Plugin\AzuracastSweepers\ValueObject\Settings;

// Keeps AzuraCast's jingle rotation in step with linked sweepers.
final readonly class LinkSync
{
    public function __construct(
        private EntityManagerInterface $em,
        private StationPlaylistMediaRepository $playlistMedia,
        private LockFactory $locks,
        private PlaylistReplacement $replacement,
        private State $state
    ) {
    }

    /**
     * Linked jingles of an audible sweeper count as played in their jingle playlists, and any of them
     * already waiting unsent in the queue is swapped, because AzuraCast chose it without knowing.
     *
     * @param list<array{media_id: int, at: float}> $played
     */
    public function sweepersPlayed(Station $station, Settings $settings, array $played, int $deadline): void
    {
        foreach ($played as ['media_id' => $sweeper, 'at' => $at]) {
            $linked = $settings->links->members($sweeper);
            if ($linked === []) {
                continue;
            }
            foreach ($this->unsent($station, $linked) as $row) {
                $this->swap($station, $settings, $row, $linked, $deadline);
            }
            // After the swaps, which return a displaced jingle to its round.
            $this->markPlayed($station, $settings, $linked, (int)$at, $deadline);
        }
    }

    // Runs right before AzuraCast sends its next queue entry to Liquidsoap.
    public function beforeSend(Station $station, Settings $settings, int $deadline): void
    {
        $next = $this->unsent($station)[0] ?? null;
        if (
            $next === null || $next['request_id'] !== null || $next['playlist_id'] === null
            || $next['media_id'] === null || !$settings->links->linked($next['media_id'])
        ) {
            return;
        }
        $data = $this->state->read($station->getRadioConfigDir());
        $reason = $this->busy($settings, $data, $next['media_id']);
        if ($reason !== null && $this->swap($station, $settings, $next, [], $deadline, $data) && $reason === 'recent') {
            // Its group already played, so the displaced jingle sits out the rest of this round too.
            $this->markPlayed($station, $settings, [$next['media_id']], time(), $deadline);
        }
    }

    // Reserved: a linked sweeper is planned. Recent: a linked track played within the configured window.
    private function busy(Settings $settings, array $data, int $mediaId): ?string
    {
        $links = $settings->links;
        $reserved = array_map('intval', array_column(array_filter(
            $data['plans'] ?? [],
            static fn(array $plan): bool => !($plan['played'] ?? false)
        ), 'jingle_id'));

        return match (true) {
            array_intersect($links->members($mediaId), $reserved) !== [] => 'reserved',
            $links->recent($data['history'] ?? [], $mediaId, microtime(true)) => 'recent',
            default => null,
        };
    }

    /**
     * @param list<int> $avoid Tracks that must not take the entry's place.
     */
    private function swap(
        Station $station,
        Settings $settings,
        array $row,
        array $avoid,
        int $deadline,
        ?array $data = null
    ): bool {
        $data ??= $this->state->read($station->getRadioConfigDir());
        $reason = null;
        $mediaId = $this->replacement->replace(
            $station,
            $row,
            '',
            $settings,
            fn(int $id): bool => !in_array($id, $avoid, true) && $this->busy($settings, $data, $id) === null,
            $reason,
            $deadline,
            true
        );
        if ($mediaId === null) {
            // Both play: AzuraCast's own choice stands when no other jingle is free.
            return false;
        }
        $this->state->update($station->getRadioConfigDir(), static function (array $data) use ($row): array {
            $data['moved'][(string)$row['id']] = ['at' => time(), 'before' => $row['media_id'], 'replacement' => true];
            $data['moved'] = array_slice($data['moved'], -50, preserve_keys: true);

            return $data;
        });

        return true;
    }

    /**
     * @param list<int> $mediaIds
     */
    private function markPlayed(Station $station, Settings $settings, array $mediaIds, int $at, int $deadline): void
    {
        // Share the lock held by AzuraCast's NowPlaying station worker, which builds the native queue.
        $lock = $this->locks->createLock('nowplaying_' . $station->short_name, 60);
        while (!$lock->acquire()) {
            if (hrtime(true) >= $deadline) {
                return;
            }
            usleep(25_000);
        }
        try {
            /** @var list<StationPlaylistMedia> $slots */
            $slots = $this->em->createQuery(
                <<<'DQL'
                    SELECT spm, sp FROM App\Entity\StationPlaylistMedia spm JOIN spm.playlist sp
                    WHERE sp.station = :station AND IDENTITY(spm.media) IN (:media) AND sp.source = :songs
                    AND (sp.is_jingle = true OR sp.id IN (:jingles))
                DQL
            )->setParameter('station', $station)
                ->setParameter('media', $mediaIds)
                ->setParameter('songs', PlaylistSources::Songs->value)
                ->setParameter('jingles', $settings->jinglePlaylistIds === [] ? [0] : $settings->jinglePlaylistIds)
                ->getResult();
            $playlists = [];
            foreach ($slots as $slot) {
                $slot->played($at);
                $this->em->persist($slot);
                $playlists[$slot->playlist->id] = $slot->playlist;
            }
            $this->em->flush();
            foreach ($playlists as $playlist) {
                // Like AzuraCast, a used-up round starts over; the linked jingles still sit this one out.
                if ($playlist->order === PlaylistOrders::Random || !$this->playlistMedia->isQueueEmpty($playlist)) {
                    continue;
                }
                $this->playlistMedia->resetQueue($playlist);
                foreach ($slots as $slot) {
                    if ($slot->playlist === $playlist) {
                        $slot->played($at);
                        $this->em->persist($slot);
                    }
                }
                $this->em->flush();
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * @param list<int>|null $mediaIds Only jingle entries playing these tracks; null for the very next entry.
     * @return list<array{id: int, media_id: ?int, playlist_id: ?int, request_id: ?int, playlist_chain: list<string>}>
     */
    private function unsent(Station $station, ?array $mediaIds = null): array
    {
        $query = $this->em->createQuery(
            'SELECT sq.id, IDENTITY(sq.media) AS media_id, IDENTITY(sq.playlist) AS playlist_id,'
            . ' IDENTITY(sq.request) AS request_id, sq.playlist_chain'
            . ' FROM App\Entity\StationQueue sq WHERE sq.station = :station AND sq.sent_to_autodj = 0'
            . ' AND sq.is_played = 0'
            . ($mediaIds === null ? '' : ' AND sq.request IS NULL AND sq.playlist IS NOT NULL'
                . ' AND IDENTITY(sq.media) IN (:media)')
            . ' ORDER BY sq.timestamp_cued ASC, sq.id ASC'
        )->setParameter('station', $station);
        if ($mediaIds === null) {
            $query->setMaxResults(1);
        } else {
            $query->setParameter('media', $mediaIds);
        }
        $id = static fn(mixed $value): ?int => $value === null ? null : (int)$value;

        return array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'media_id' => $id($row['media_id']),
            'playlist_id' => $id($row['playlist_id']),
            'request_id' => $id($row['request_id']),
            'playlist_chain' => array_map('strval', (array)($row['playlist_chain'] ?? [])),
        ], $query->getArrayResult());
    }
}
