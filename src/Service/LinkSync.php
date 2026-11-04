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
use Plugin\AzuracastSweepers\ValueObject\LinkMarks;
use Plugin\AzuracastSweepers\ValueObject\Settings;
use Symfony\Component\Lock\LockInterface;

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
     * Mirrors sweepers into the jingle rotation the way AzuraCast treats its own queue: a reserved sweeper
     * marks its linked jingles as queued, which is undone when the reservation is dropped, and a played
     * sweeper makes that final with the actual play time.
     */
    public function reconcile(Station $station, Settings $settings, int $deadline): void
    {
        $directory = $station->getRadioConfigDir();
        $settled = LinkMarks::empty(
            LinkMarks::plan($this->state->read($directory), $settings->links, $settings->enabled, time())
        );
        if ($settled) {
            return;
        }
        $lock = $this->lock($station, $deadline);
        if ($lock === null) {
            // The next heartbeat tries again.
            return;
        }
        try {
            $data = $this->state->read($directory);
            $now = time();
            $plan = LinkMarks::plan($data, $settings->links, $settings->enabled, $now);
            $marks = $data['link_marks'] ?? [];
            $finalised = array_filter(
                $data['link_finalised'] ?? [],
                static fn(mixed $at): bool => (int)$at >= $now - LinkMarks::PLAYED_SECONDS
            );
            foreach ($plan['restore'] as $mediaId) {
                $this->restore($marks[$mediaId] ?? []);
                unset($marks[$mediaId]);
            }
            foreach ($plan['drop'] as $mediaId) {
                unset($marks[$mediaId]);
            }
            foreach ($plan['finalize'] as $played) {
                $this->applyPlayed($station, $settings, $played['media_ids'], $played['at']);
                foreach ($played['media_ids'] as $mediaId) {
                    unset($marks[$mediaId]);
                }
                $finalised[$played['token']] = $played['at'];
            }
            foreach ($plan['reserve'] as $mediaId => $token) {
                if (isset($marks[$mediaId])) {
                    $marks[$mediaId]['token'] = $token;
                    continue;
                }
                $slots = [];
                foreach ($this->jingleSlots($station, $settings, [$mediaId]) as $slot) {
                    $slots[$slot->id] = ['queued' => $slot->is_queued, 'last' => $slot->last_played];
                    // What AzuraCast writes when it queues a track; a used-up round starts over natively.
                    $slot->played($now);
                    $this->em->persist($slot);
                }
                $marks[$mediaId] = ['token' => $token, 'at' => $now, 'slots' => $slots];
            }
            $this->em->flush();
            $this->state->update($directory, static function (array $data) use ($marks, $finalised): array {
                $data['link_marks'] = $marks;
                $data['link_finalised'] = $finalised;

                return $data;
            });
        } finally {
            $lock->release();
        }
    }

    // Puts a dropped reservation back, unless AzuraCast changed the entry since (for example a new round).
    private function restore(array $mark): void
    {
        foreach ($mark['slots'] ?? [] as $slotId => $previous) {
            $slot = $this->em->find(StationPlaylistMedia::class, (int)$slotId);
            if ($slot === null || $slot->is_queued || $slot->last_played !== (int)($mark['at'] ?? 0)) {
                continue;
            }
            $slot->is_queued = (bool)$previous['queued'];
            $slot->last_played = (int)$previous['last'];
            $this->em->persist($slot);
        }
    }

    /**
     * Swaps linked jingles of an audible sweeper that already wait unsent in the queue, because AzuraCast
     * chose them before the sweeper was reserved. The rotation itself follows through reconcile().
     *
     * @param list<array{media_id: int, at: float}> $played
     */
    public function sweepersPlayed(Station $station, Settings $settings, array $played, int $deadline): void
    {
        foreach ($played as ['media_id' => $sweeper]) {
            $linked = $settings->links->members($sweeper);
            if ($linked === []) {
                continue;
            }
            foreach ($this->unsent($station, $linked) as $row) {
                $this->swap($station, $settings, $row, $linked, $deadline);
            }
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
        $mediaId = $next['media_id'];
        $reason = $this->busy($settings, $data, $mediaId);
        if ($reason === null || !$this->swap($station, $settings, $next, [], $deadline, $data)) {
            return;
        }
        // The swap returns the displaced jingle to its round; it stays held back for its group.
        $mark = $data['link_marks'][$mediaId] ?? null;
        $this->markPlayed($station, $settings, [$mediaId], (int)($mark['at'] ?? time()), $deadline);
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
        $lock = $this->lock($station, $deadline);
        if ($lock === null) {
            return;
        }
        try {
            $this->applyPlayed($station, $settings, $mediaIds, $at);
        } finally {
            $lock->release();
        }
    }

    // Shares the lock held by AzuraCast's NowPlaying station worker, which builds the native queue.
    private function lock(Station $station, int $deadline): ?LockInterface
    {
        $lock = $this->locks->createLock('nowplaying_' . $station->short_name, 60);
        while (!$lock->acquire()) {
            if (hrtime(true) >= $deadline) {
                return null;
            }
            usleep(25_000);
        }

        return $lock;
    }

    /**
     * @param list<int> $mediaIds
     * @return list<StationPlaylistMedia> The tracks' entries in jingle playlists.
     */
    private function jingleSlots(Station $station, Settings $settings, array $mediaIds): array
    {
        return $this->em->createQuery(
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
    }

    /**
     * @param list<int> $mediaIds
     */
    private function applyPlayed(Station $station, Settings $settings, array $mediaIds, int $at): void
    {
        $slots = $this->jingleSlots($station, $settings, $mediaIds);
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
