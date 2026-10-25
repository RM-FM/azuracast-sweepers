<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\Service;

use App\Entity\Station;
use Doctrine\ORM\EntityManagerInterface;
use Plugin\AzuracastSweepers\ValueObject\Settings;

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
}
