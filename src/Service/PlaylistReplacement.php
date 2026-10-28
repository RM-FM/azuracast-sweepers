<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\Service;

use App\Entity\Enums\PlaylistOrders;
use App\Entity\Enums\PlaylistSources;
use App\Entity\Api\StationPlaylistQueue;
use App\Entity\Repository\StationPlaylistMediaRepository;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Entity\StationQueue;
use App\Lock\LockFactory;
use App\Radio\AutoDJ\DuplicatePrevention;
use App\Radio\AutoDJ\RecentSongHistory;
use DateTimeInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Plugin\AzuracastSweepers\ValueObject\Settings;
use Throwable;

final readonly class PlaylistReplacement
{
    public function __construct(
        private EntityManagerInterface $em,
        private StationPlaylistMediaRepository $playlistMedia,
        private StationQueueRepository $queue,
        private DuplicatePrevention $duplicates,
        private LockFactory $locks,
        private Media $media
    ) {
    }

    /**
     * @param callable(int): bool $usable
     */
    public function replace(
        Station $station,
        array $target,
        string $counted,
        Settings $settings,
        callable $usable,
        ?string &$reason = null,
        ?int $deadline = null
    ): ?int {
        $deadline ??= hrtime(true) + 500_000_000;
        // Share the lock held by AzuraCast's NowPlaying station worker, which builds the native queue.
        $lock = $this->locks->createLock('nowplaying_' . $station->short_name, 60);
        if (!$lock->acquire()) {
            $reason = 'replace_busy';

            return null;
        }
        try {
            return $this->select($station, $target, $counted, $settings, $usable, $reason, $deadline);
        } finally {
            $lock->release();
        }
    }

    private function select(
        Station $station,
        array $target,
        string $counted,
        Settings $settings,
        callable $usable,
        ?string &$reason,
        int $deadline
    ): ?int {
        if (hrtime(true) >= $deadline) {
            $reason = 'replace_timeout';

            return null;
        }
        $reason = 'replace_ineligible';
        $playlist = $this->em->find(StationPlaylist::class, $target['playlist_id']);
        if (
            $playlist === null || $playlist->station->id !== $station->id
            || !$playlist->is_enabled || $playlist->is_jingle || $playlist->source !== PlaylistSources::Songs
            || (!$settings->swapSequential && $playlist->order === PlaylistOrders::Sequential)
        ) {
            return null;
        }
        $ancestors = $target['playlist_chain'] === [] ? [] : $this->em->createQuery(
            'SELECT sp FROM App\Entity\StationPlaylist sp WHERE sp.station = :station AND sp.name IN (:names)'
        )->setParameter('station', $station)
            ->setParameter('names', $target['playlist_chain'])
            ->getResult();
        $avoidDuplicates = false;
        foreach ([$playlist, ...$ancestors] as $parent) {
            if (!$parent->is_enabled || $parent->backendMerge()) {
                return null;
            }
            $avoidDuplicates = $avoidDuplicates || $parent->avoid_duplicates;
        }
        $rows = $this->em->createQuery(
            <<<'DQL'
                SELECT sq.timestamp_played, sq.song_id, sq.title, sq.artist FROM App\Entity\StationQueue sq
                WHERE sq.station = :station AND sq.id = :id AND sq.sent_to_autodj = 0
                AND sq.is_played = 0 AND sq.autodj_custom_uri IS NULL AND sq.request IS NULL
                AND IDENTITY(sq.media) = :media AND sq.playlist = :playlist
            DQL
        )->setParameters([
            'station' => $station, 'id' => $target['id'], 'media' => $target['media_id'], 'playlist' => $playlist,
        ])->getArrayResult();
        if ($rows === [] || $rows[0]['timestamp_played'] === null) {
            return null;
        }
        $when = $rows[0]['timestamp_played'];
        $reserved = $this->reserved($station, $counted);
        $history = null;
        if ($avoidDuplicates) {
            $range = $station->backend_config->duplicate_prevention_time_range;
            $artistRange = $station->backend_config->getDuplicatePreventionArtistTimeRange();
            $played = self::withoutTarget(
                $this->queue->getRecentlyPlayedByTimeRange($station, $when, $range),
                $rows[0]
            );
            $artists = $range === $artistRange
                ? $played
                : self::withoutTarget(
                    $this->queue->getRecentlyPlayedByTimeRange($station, $when, $artistRange),
                    $rows[0]
                );
            $history = new RecentSongHistory($played, $artists);
        }
        $reason = 'replace_none';
        $selected = null;
        $selectedQueued = true;
        foreach ($this->candidates($playlist) as $queued => $candidate) {
            if (hrtime(true) >= $deadline) {
                $reason = 'replace_timeout';

                return null;
            }
            if (
                $candidate->media_id === $target['media_id']
                || in_array($candidate->song_id, $reserved, true)
                || !$usable($candidate->media_id)
            ) {
                continue;
            }
            if ($history !== null && $this->duplicates->preventDuplicates([$candidate], $history) === null) {
                $reason = 'replace_duplicates';
                continue;
            }
            $selected = $candidate;
            $selectedQueued = $queued;
            break;
        }
        if ($selected === null) {
            return null;
        }
        try {
            $media = $this->media->get($station, $selected->media_id);
        } catch (InvalidArgumentException) {
            $reason = 'replace_changed';

            return null;
        }
        $replacement = StationQueue::fromMedia($station, $media);
        if (hrtime(true) >= $deadline) {
            $reason = 'replace_timeout';

            return null;
        }
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            // Locking reads see a candidate consumed after the proposal, even under repeatable-read isolation.
            if (in_array($selected->song_id, $this->reserved($station, $counted, true), true)) {
                $connection->rollBack();
                $reason = 'replace_changed';

                return null;
            }
            $slots = $this->em->createQuery(
                <<<'DQL'
                    SELECT spm.id, spm.is_queued FROM App\Entity\StationPlaylistMedia spm
                    WHERE spm.playlist = :playlist AND (IDENTITY(spm.media) IN (:media))
                    ORDER BY spm.id ASC
                DQL
            )->setParameter('playlist', $playlist)
                ->setParameter('media', [$target['media_id'], $selected->media_id])
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->getArrayResult();
            $old = $this->playlistMedia->findByPlaylistAndMedia(
                $playlist,
                $this->em->getReference(StationMedia::class, $target['media_id'])
            );
            $flags = array_column($slots, 'is_queued', 'id');
            if (
                $old === null || !array_key_exists($old->id, $flags)
                || !array_key_exists($selected->spm_id, $flags)
                || ($playlist->order !== PlaylistOrders::Random && (bool)$flags[$selected->spm_id] !== $selectedQueued)
            ) {
                $connection->rollBack();
                $reason = 'replace_changed';

                return null;
            }
            $next = $this->em->createQuery(
                <<<'DQL'
                    SELECT sq.id FROM App\Entity\StationQueue sq
                    WHERE sq.station = :station AND sq.sent_to_autodj = 0 AND sq.is_played = 0
                    ORDER BY sq.timestamp_cued ASC, sq.id ASC
                DQL
            )->setParameter('station', $station)
                ->setMaxResults(1)
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->getSingleColumnResult();
            if ($next !== [$target['id']] || hrtime(true) >= $deadline) {
                $connection->rollBack();
                $reason = hrtime(true) >= $deadline ? 'replace_timeout' : 'replace_changed';

                return null;
            }
            $changed = $this->em->createQuery(
                <<<'DQL'
                    UPDATE App\Entity\StationQueue sq SET sq.media = :replacement,
                        sq.duration = :duration, sq.song_id = :song, sq.title = :title,
                        sq.artist = :artist, sq.album = :album, sq.text = :text
                    WHERE sq.station = :station AND sq.id = :id AND IDENTITY(sq.media) = :original
                    AND sq.playlist = :playlist AND sq.sent_to_autodj = 0 AND sq.is_played = 0
                    AND sq.request IS NULL AND sq.autodj_custom_uri IS NULL
                    AND sq.timestamp_played = :when
                DQL
            )->setParameters([
                'replacement' => $media, 'duration' => $replacement->duration, 'song' => $replacement->song_id,
                'title' => $replacement->title, 'artist' => $replacement->artist, 'album' => $replacement->album,
                'text' => $replacement->text, 'station' => $station, 'id' => $target['id'],
                'original' => $target['media_id'], 'playlist' => $playlist, 'when' => $when,
            ])->execute();
            if ($changed !== 1) {
                $connection->rollBack();
                $reason = 'replace_changed';

                return null;
            }
            $this->em->createQuery(
                'UPDATE App\Entity\StationPlaylistMedia spm SET spm.is_queued = true WHERE spm.id = :id'
            )->setParameter('id', $old->id)->execute();
            $this->em->createQuery(
                <<<'DQL'
                    UPDATE App\Entity\StationPlaylistMedia spm
                    SET spm.is_queued = false, spm.last_played = :when WHERE spm.id = :id
                DQL
            )->setParameter('id', $selected->spm_id)
                ->setParameter('when', $when->getTimestamp())
                ->execute();
            $this->em->refresh($old);
            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $exception;
        }
        $reason = null;

        return $selected->media_id;
    }

    /**
     * The remaining round in AzuraCast's native order, then this round's played songs, longest ago first.
     * Reshuffling stays with AzuraCast when the round ends.
     *
     * @return iterable<bool, StationPlaylistQueue> Keyed by whether the song is still queued in this round.
     */
    private function candidates(StationPlaylist $playlist): iterable
    {
        foreach ($this->playlistMedia->getQueue($playlist) as $candidate) {
            yield true => $candidate;
        }
        if ($playlist->order === PlaylistOrders::Random) {
            return;
        }
        $played = $this->em->createQuery(
            <<<'DQL'
                SELECT spm.id AS spm_id, spm.last_played, sm.id, sm.song_id, sm.artist, sm.title
                FROM App\Entity\StationPlaylistMedia spm JOIN spm.media sm
                WHERE spm.playlist = :playlist AND spm.is_queued = 0
                ORDER BY spm.last_played ASC, spm.weight ASC
            DQL
        )->setParameter('playlist', $playlist)->getArrayResult();
        foreach ($played as $row) {
            $candidate = new StationPlaylistQueue();
            $candidate->spm_id = $row['spm_id'];
            $candidate->media_id = $row['id'];
            $candidate->song_id = $row['song_id'];
            $candidate->artist = $row['artist'] ?? '';
            $candidate->title = $row['title'] ?? '';
            $candidate->last_played = $row['last_played'];
            yield false => $candidate;
        }
    }

    private static function withoutTarget(array $history, array $target): array
    {
        // Native history includes every unplayed queue row; the displaced row will no longer play.
        foreach ($history as $index => $track) {
            if (
                $track['song_id'] === $target['song_id'] && $track['title'] === $target['title']
                && $track['artist'] === $target['artist'] && $track['timestamp_played'] instanceof DateTimeInterface
                && $track['timestamp_played']->format('U.u') === $target['timestamp_played']->format('U.u')
            ) {
                array_splice($history, $index, 1);
                break;
            }
        }

        return $history;
    }

    private function reserved(Station $station, string $counted, bool $lock = false): array
    {
        $query = $this->em->createQuery(
            <<<'DQL'
                SELECT sq.song_id FROM App\Entity\StationQueue sq
                WHERE sq.station = :station AND (sq.is_played = 0 OR sq.id = :counted)
                ORDER BY sq.id ASC
            DQL
        )->setParameter('station', $station)->setParameter('counted', (int)$counted);
        if ($lock) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }

        return $query->getSingleColumnResult();
    }
}
