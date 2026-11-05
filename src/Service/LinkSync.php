<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\Service;

use App\Entity\Enums\PlaylistOrders;
use App\Entity\Station;
use App\Entity\StationPlaylist;
use App\Entity\StationPlaylistMedia;
use App\Lock\LockFactory;
use Doctrine\ORM\EntityManagerInterface;
use Plugin\AzuracastSweepers\ValueObject\JingleRotation;
use Plugin\AzuracastSweepers\ValueObject\Settings;

// Linked sweepers and jingles share one rotation: the least recently heard link group plays next.
final readonly class LinkSync
{
    public function __construct(
        private EntityManagerInterface $em,
        private LockFactory $locks,
        private PlaylistReplacement $replacement,
        private State $state
    ) {
    }

    /**
     * Runs right before AzuraCast sends its next queue entry to Liquidsoap. A jingle gives way to a better
     * one from its playlist's current round; AzuraCast's choice stands when none is better.
     */
    public function beforeSend(Station $station, Settings $settings, int $deadline): void
    {
        $next = $this->nextEntry($station);
        if (
            $next === null || $next['request_id'] !== null || $next['playlist_id'] === null
            || $next['media_id'] === null
        ) {
            return;
        }
        $playlist = $this->em->find(StationPlaylist::class, $next['playlist_id']);
        if (
            $playlist === null
            || (!$playlist->is_jingle && !in_array($playlist->id, $settings->jinglePlaylistIds, true))
        ) {
            return;
        }
        $entries = $this->entries($playlist);
        if (array_filter(array_keys($entries), $settings->links->linked(...)) === []) {
            return;
        }
        $random = $playlist->order === PlaylistOrders::Random;
        $rotation = $this->rotation($station, $settings, $playlist, $next['id']);
        $keys = [];
        foreach ($entries as $mediaId => $entry) {
            $keys[$mediaId] = $rotation->key($mediaId, $random || $entry['queued'], $entry['last_played']);
        }
        $chosen = $rotation->key($next['media_id'], true, null, true);
        $better = static fn(int $id): bool => isset($keys[$id]) && $keys[$id] < $chosen;
        if (array_filter(array_keys($keys), $better) === []) {
            return;
        }
        $reason = null;
        $mediaId = $this->replacement->replace(
            $station,
            $next,
            '',
            $settings,
            $better,
            $reason,
            $deadline,
            true,
            static fn(int $id): array => $keys[$id]
        );
        if ($mediaId === null) {
            return;
        }
        $this->state->update($station->getRadioConfigDir(), static function (array $data) use ($next): array {
            $data['moved'][(string)$next['id']] = [
                'at' => time(), 'before' => $next['media_id'], 'replacement' => true,
            ];
            $data['moved'] = array_slice($data['moved'], -50, preserve_keys: true);

            return $data;
        });
    }

    // Version 1.3.0 held linked jingles back by marking them as queued; those marks are returned once.
    public function releaseMarks(Station $station, int $deadline): void
    {
        $directory = $station->getRadioConfigDir();
        $data = $this->state->read($directory);
        if (!isset($data['link_marks']) && !isset($data['link_finalised'])) {
            return;
        }
        $lock = $this->locks->createLock('nowplaying_' . $station->short_name, 60);
        while (!$lock->acquire()) {
            if (hrtime(true) >= $deadline) {
                return;
            }
            usleep(25_000);
        }
        try {
            foreach ($this->state->read($directory)['link_marks'] ?? [] as $mark) {
                $this->restore($mark);
            }
            $this->em->flush();
            $this->state->update($directory, static function (array $data): array {
                unset($data['link_marks'], $data['link_finalised']);

                return $data;
            });
        } finally {
            $lock->release();
        }
    }

    // Unless AzuraCast changed the entry since, for example in a new round.
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

    private function rotation(
        Station $station,
        Settings $settings,
        StationPlaylist $playlist,
        int $nextId
    ): JingleRotation {
        $data = $this->state->read($station->getRadioConfigDir());
        $now = time();
        $reserved = [];
        foreach ($data['plans'] ?? [] as $plan) {
            if (
                !($plan['played'] ?? false) && ($plan['expires_at'] ?? 0) > $now
                && is_int($plan['jingle_id'] ?? null)
            ) {
                $reserved[] = $plan['jingle_id'];
            }
        }
        $previous = $this->em->createQuery(
            <<<'DQL'
                SELECT IDENTITY(sq.media) AS media_id, sq.timestamp_played FROM App\Entity\StationQueue sq
                WHERE sq.station = :station AND sq.playlist = :playlist AND sq.id <> :next
                AND (sq.sent_to_autodj = 1 OR sq.is_played = 1)
                ORDER BY sq.timestamp_cued DESC, sq.id DESC
            DQL
        )->setParameter('station', $station)
            ->setParameter('playlist', $playlist)
            ->setParameter('next', $nextId)
            ->setMaxResults(1)
            ->getArrayResult()[0] ?? null;

        return new JingleRotation(
            $settings->links,
            $data['history'] ?? [],
            $reserved,
            isset($previous['media_id']) ? (int)$previous['media_id'] : null,
            isset($previous['timestamp_played']) ? (float)$previous['timestamp_played']->getTimestamp() : null,
            microtime(true),
            $playlist->order === PlaylistOrders::Random
        );
    }

    /**
     * @return array<int, array{queued: bool, last_played: int}> By media ID.
     */
    private function entries(StationPlaylist $playlist): array
    {
        $rows = $this->em->createQuery(
            <<<'DQL'
                SELECT IDENTITY(spm.media) AS media_id, spm.is_queued, spm.last_played
                FROM App\Entity\StationPlaylistMedia spm WHERE spm.playlist = :playlist
            DQL
        )->setParameter('playlist', $playlist)->getArrayResult();
        $entries = [];
        foreach ($rows as $row) {
            $entries[(int)$row['media_id']] = [
                'queued' => (bool)$row['is_queued'],
                'last_played' => (int)$row['last_played'],
            ];
        }

        return $entries;
    }

    /**
     * @return ?array{id: int, media_id: ?int, playlist_id: ?int, request_id: ?int, playlist_chain: list<string>}
     */
    private function nextEntry(Station $station): ?array
    {
        $row = $this->em->createQuery(
            'SELECT sq.id, IDENTITY(sq.media) AS media_id, IDENTITY(sq.playlist) AS playlist_id,'
            . ' IDENTITY(sq.request) AS request_id, sq.playlist_chain'
            . ' FROM App\Entity\StationQueue sq WHERE sq.station = :station AND sq.sent_to_autodj = 0'
            . ' AND sq.is_played = 0 ORDER BY sq.timestamp_cued ASC, sq.id ASC'
        )->setParameter('station', $station)
            ->setMaxResults(1)
            ->getArrayResult()[0] ?? null;
        if ($row === null) {
            return null;
        }
        $id = static fn(mixed $value): ?int => $value === null ? null : (int)$value;

        return [
            'id' => (int)$row['id'],
            'media_id' => $id($row['media_id']),
            'playlist_id' => $id($row['playlist_id']),
            'request_id' => $id($row['request_id']),
            'playlist_chain' => array_map('strval', (array)($row['playlist_chain'] ?? [])),
        ];
    }
}
