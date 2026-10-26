<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\Service;

use App\Entity\Station;
use App\Entity\StationPlaylist;
use BackedEnum;
use Doctrine\ORM\EntityManagerInterface;
use Plugin\AzuracastSweepers\ValueObject\Settings;
use Throwable;

final readonly class Queue
{
    public function __construct(
        private EntityManagerInterface $em
    ) {
    }

    public static function length(Station $station): int
    {
        // AzuraCast never builds a shorter upcoming queue.
        return max((int)$station->backend_config->autodj_queue_length, 2);
    }

    /**
     * @return list<string> Queue tokens followed by a jingle within the configured number of tracks.
     */
    public function blocked(Station $station, array $tokens, Settings $settings): array
    {
        if ($settings->songsBeforeJingle === 0 || $tokens === []) {
            return [];
        }
        $count = min($settings->songsBeforeJingle, self::length($station));
        $ids = array_values(array_unique(array_filter(
            array_map(static fn(mixed $token): int => (int)$token, $tokens),
            static fn(int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return [];
        }

        $rows = $this->em->createQuery(
            <<<'DQL'
                SELECT sq.id, sq.is_played, sq.sent_to_autodj, sq.timestamp_cued,
                    IDENTITY(sq.playlist) AS playlist_id, sp.is_jingle
                FROM App\Entity\StationQueue sq
                LEFT JOIN sq.playlist sp
                WHERE sq.station = :station
                AND (sq.is_played = 0 OR sq.id IN (:ids))
                ORDER BY sq.sent_to_autodj DESC, sq.timestamp_cued ASC, sq.id ASC
            DQL
        )->setParameter('station', $station)
            ->setParameter('ids', $ids)
            ->getArrayResult();

        return self::select($rows, $ids, $count, $settings);
    }

    /**
     * @param array<array<string, mixed>> $rows Queue rows in AzuraCast's upcoming order.
     * @param list<int> $ids
     * @return list<string>
     */
    public static function select(array $rows, array $ids, int $count, Settings $settings): array
    {
        $upcoming = array_values(array_filter($rows, static fn(array $row): bool => !$row['is_played']));
        $upcomingIds = array_map(static fn(array $row): int => (int)$row['id'], $upcoming);
        $playlists = $settings->runtime()['jingle_playlists'];
        $blocked = [];
        foreach ($ids as $id) {
            $row = null;
            foreach ($rows as $candidate) {
                if ((int)$candidate['id'] === $id) {
                    $row = $candidate;
                }
            }
            if ($row === null) {
                continue;
            }

            // A played row has left the upcoming queue; later rows were sent afterwards or not yet sent.
            $position = array_search($id, $upcomingIds, true);
            $following = $position !== false
                ? array_slice($upcoming, $position + 1)
                : array_values(array_filter(
                    $upcoming,
                    static fn(array $next): bool => !$next['sent_to_autodj']
                        || $next['timestamp_cued'] > $row['timestamp_cued']
                ));
            foreach (array_slice($following, 0, $count) as $next) {
                if (
                    ($settings->countJingleMode && $next['is_jingle'])
                    || in_array((int)$next['playlist_id'], $playlists, true)
                ) {
                    $blocked[] = (string)$id;
                    break;
                }
            }
        }

        return $blocked;
    }

    /**
     * Excluded when the row's own playlist is selected, or a selected playlist (group) appears in its
     * playlist chain; AzuraCast records the chain by playlist name.
     *
     * @param list<int> $ids
     * @param list<string> $names
     */
    public static function isExcluded(?int $playlistId, ?array $chain, array $ids, array $names): bool
    {
        return ($playlistId !== null && in_array($playlistId, $ids, true))
            || array_intersect(array_map('strval', $chain ?? []), $names) !== [];
    }

    /**
     * @return list<string> Names of the station's playlists without sweepers.
     */
    public function excludedNames(Station $station, Settings $settings): array
    {
        if ($settings->excludedPlaylistIds === []) {
            return [];
        }

        return array_map('strval', $this->em->createQuery(
            <<<'DQL'
                SELECT sp.name FROM App\Entity\StationPlaylist sp
                WHERE sp.station = :station AND sp.id IN (:ids)
            DQL
        )->setParameter('station', $station)
            ->setParameter('ids', $settings->excludedPlaylistIds)
            ->getSingleColumnResult());
    }

    /**
     * Whether a queued track comes from a playlist without sweepers.
     */
    public function excluded(Station $station, string $token, Settings $settings): bool
    {
        if ($settings->excludedPlaylistIds === [] || !preg_match('/^\d{1,20}$/D', $token)) {
            return false;
        }
        $rows = $this->em->createQuery(
            <<<'DQL'
                SELECT IDENTITY(sq.playlist) AS playlist_id, sq.playlist_chain
                FROM App\Entity\StationQueue sq
                WHERE sq.station = :station AND sq.id = :id
            DQL
        )->setParameter('station', $station)
            ->setParameter('id', (int)$token)
            ->getArrayResult();
        if ($rows === []) {
            return false;
        }
        $playlistId = $rows[0]['playlist_id'] === null ? null : (int)$rows[0]['playlist_id'];

        return self::isExcluded(
            $playlistId,
            $rows[0]['playlist_chain'],
            $settings->excludedPlaylistIds,
            $this->excludedNames($station, $settings)
        );
    }

    /**
     * Queue rows after the module's last counted track, in AzuraCast's play order.
     *
     * @return list<array<string, mixed>>|null Null when the counted track is unknown.
     */
    public function upcoming(Station $station, string $counted): ?array
    {
        if (!preg_match('/^\d{1,20}$/D', $counted)) {
            return null;
        }
        $current = $this->em->createQuery(
            <<<'DQL'
                SELECT sq.timestamp_cued FROM App\Entity\StationQueue sq
                WHERE sq.station = :station AND sq.id = :id
            DQL
        )->setParameter('station', $station)
            ->setParameter('id', (int)$counted)
            ->getArrayResult();
        if ($current === []) {
            return null;
        }

        $rows = $this->em->createQuery(
            <<<'DQL'
                SELECT sq.id, sq.sent_to_autodj, sq.timestamp_cued, sq.playlist_chain,
                    IDENTITY(sq.playlist) AS playlist_id, IDENTITY(sq.media) AS media_id,
                    IDENTITY(sq.request) AS request_id
                FROM App\Entity\StationQueue sq
                WHERE sq.station = :station AND sq.id != :id
                AND (sq.is_played = 0 OR (sq.sent_to_autodj = 1 AND sq.timestamp_cued > :cued))
                ORDER BY sq.sent_to_autodj DESC, sq.timestamp_cued ASC, sq.id ASC
            DQL
        )->setParameter('station', $station)
            ->setParameter('id', (int)$counted)
            ->setParameter('cued', $current[0]['timestamp_cued'])
            ->getArrayResult();

        $playlists = [];
        $result = [];
        foreach ($rows as $row) {
            $playlistId = $row['playlist_id'] === null ? null : (int)$row['playlist_id'];
            if ($playlistId !== null && !array_key_exists($playlistId, $playlists)) {
                $playlists[$playlistId] = $this->em->find(StationPlaylist::class, $playlistId);
            }
            $playlist = $playlistId === null ? null : $playlists[$playlistId];
            $result[] = [
                'id' => (int)$row['id'],
                'sent' => (bool)$row['sent_to_autodj'],
                'media_id' => $row['media_id'] === null ? null : (int)$row['media_id'],
                'request_id' => $row['request_id'] === null ? null : (int)$row['request_id'],
                'playlist_id' => $playlistId,
                'is_jingle' => (bool)($playlist?->is_jingle ?? false),
                'playlist_type' => self::value($playlist?->type),
                'playlist_order' => self::value($playlist?->order),
                'playlist_chain' => array_map('strval', (array)($row['playlist_chain'] ?? [])),
            ];
        }

        return $result;
    }

    /**
     * Exchanges the play order of two rows not yet sent to Liquidsoap. AzuraCast orders by cue time
     * and recalculates the following times on its next queue build.
     */
    public function exchange(Station $station, int $first, int $second): bool
    {
        // A plain connection transaction: a lost race must not close the shared entity manager.
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $rows = $this->em->createQuery(
                <<<'DQL'
                    SELECT sq.id, sq.timestamp_cued, sq.timestamp_played FROM App\Entity\StationQueue sq
                    WHERE sq.station = :station AND sq.id IN (:ids)
                    AND sq.sent_to_autodj = 0 AND sq.is_played = 0
                DQL
            )->setParameter('station', $station)
                ->setParameter('ids', [$first, $second])
                ->getArrayResult();
            $times = array_column($rows, null, 'id');
            $updated = 0;
            if (count($times) === 2) {
                foreach ([[$first, $second], [$second, $first]] as [$id, $from]) {
                    $updated += $this->em->createQuery(
                        <<<'DQL'
                            UPDATE App\Entity\StationQueue sq
                            SET sq.timestamp_cued = :cued, sq.timestamp_played = :played
                            WHERE sq.station = :station AND sq.id = :id
                            AND sq.sent_to_autodj = 0 AND sq.is_played = 0
                        DQL
                    )->setParameter('cued', $times[$from]['timestamp_cued'])
                        ->setParameter('played', $times[$from]['timestamp_played'])
                        ->setParameter('station', $station)
                        ->setParameter('id', $id)
                        ->execute();
                }
            }
            if ($updated !== 2) {
                $connection->rollBack();

                return false;
            }
            $connection->commit();

            return true;
        } catch (Throwable $error) {
            $connection->rollBack();
            throw $error;
        }
    }

    private static function value(mixed $value): ?string
    {
        return $value instanceof BackedEnum ? (string)$value->value : ($value === null ? null : (string)$value);
    }
}
