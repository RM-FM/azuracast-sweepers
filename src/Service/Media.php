<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\Service;

use App\Entity\CustomField;
use App\Entity\Enums\PlaylistTypes;
use App\Entity\Repository\CustomFieldRepository;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationMediaCustomField;
use App\Entity\StationPlaylist;
use App\Event\Radio\AnnotateNextSong;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;

final readonly class Media
{
    public function __construct(
        private EntityManagerInterface $em,
        private CustomFieldRepository $customFields,
        private EventDispatcherInterface $dispatcher
    ) {
    }

    public function ensureField(): CustomField
    {
        $connection = $this->em->getConnection();
        if ((int)$connection->fetchOne("SELECT GET_LOCK('rmfm_sweepers_intro_end', 5)") !== 1) {
            throw new RuntimeException('Cannot initialize intro_end.');
        }

        try {
            $repository = $this->em->getRepository(CustomField::class);
            $field = $repository->findOneBy(['short_name' => 'intro_end']);
            if ($field === null) {
                $field = new CustomField();
                $field->name = 'Intro End';
                $field->short_name = 'intro_end';
                $this->em->persist($field);
                $this->em->flush();
            }

            return $field;
        } finally {
            $connection->executeQuery("SELECT RELEASE_LOCK('rmfm_sweepers_intro_end')");
        }
    }

    public function fieldId(): ?int
    {
        $id = $this->em->getConnection()->fetchOne("SELECT id FROM custom_field WHERE short_name = 'intro_end'");

        return $id === false ? null : (int)$id;
    }

    public function get(Station $station, int $id): StationMedia
    {
        $media = $this->em->find(StationMedia::class, $id);
        if ($media === null || $media->storage_location_id !== $station->media_storage_location->id) {
            throw new InvalidArgumentException('Media does not belong to this station.');
        }

        return $media;
    }

    public function intro(StationMedia $media): ?float
    {
        $raw = $this->customFields->getCustomFields($media)['intro_end'] ?? null;
        if ($raw === null || trim((string)$raw) === '') {
            return null;
        }

        try {
            return self::parseIntro($raw, $media->length);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public static function parseIntro(mixed $value, float $duration): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0 || (float)$value > $duration) {
            throw new InvalidArgumentException('Intro End must be seconds from the original file start.');
        }

        return (float)$value;
    }

    public function saveIntro(StationMedia $media, mixed $value): ?float
    {
        $field = $this->ensureField();

        return $this->em->wrapInTransaction(function () use ($media, $value, $field): ?float {
            $this->em->refresh($media, LockMode::PESSIMISTIC_WRITE);
            $intro = self::parseIntro($value, $media->length);
            $entry = $this->em->getRepository(StationMediaCustomField::class)->findOneBy([
                'media' => $media,
                'field' => $field,
            ]);

            if ($entry === null) {
                $entry = new StationMediaCustomField($media, $field);
                $this->em->persist($entry);
            }
            $entry->value = $intro === null ? null : (string)$intro;

            return $intro;
        });
    }

    public function playlists(Station $station, bool $advancedOnly = true): array
    {
        $criteria = ['station' => $station];
        if ($advancedOnly) {
            $criteria['type'] = PlaylistTypes::Advanced;
        }

        return $this->em->getRepository(StationPlaylist::class)->findBy($criteria, ['name' => 'ASC']);
    }

    public function validateJinglePlaylists(Station $station, array $ids): void
    {
        foreach ($ids as $id) {
            $playlist = $this->em->find(StationPlaylist::class, $id);
            if ($playlist === null || $playlist->station_id !== $station->id) {
                throw new InvalidArgumentException('Selected playlists must belong to this station.');
            }
        }
    }

    /**
     * @param list<int> $playlistIds
     * @return list<StationMedia> Each file once, even if it is in several playlists.
     */
    public function jingles(Station $station, array $playlistIds): array
    {
        $unique = [];
        foreach ($this->sweeperPlaylists($station, $playlistIds) as $playlist) {
            foreach ($playlist->media_items as $entry) {
                $unique[$entry->media_id] ??= $this->get($station, $entry->media_id);
            }
        }

        return array_values($unique);
    }

    /**
     * @param list<int> $ids
     * @return list<StationPlaylist> Deleted or changed playlists are skipped, so one cannot block the others.
     */
    public function sweeperPlaylists(Station $station, array $ids): array
    {
        $playlists = [];
        foreach ($ids as $id) {
            $playlist = $this->em->find(StationPlaylist::class, $id);
            if (
                $playlist !== null && $playlist->station_id === $station->id
                && $playlist->type === PlaylistTypes::Advanced
            ) {
                $playlists[] = $playlist;
            }
        }

        return $playlists;
    }

    /**
     * @param list<int> $ids
     */
    public function validateSweeperPlaylists(Station $station, array $ids): void
    {
        if (count($this->sweeperPlaylists($station, $ids)) !== count($ids)) {
            throw new InvalidArgumentException('Choose Advanced playlists belonging to this station.');
        }
    }

    public function timingIdentity(Station $station, StationMedia $media): string
    {
        return self::identity($media) . ':' . hash('sha256', $this->uri($station, $media));
    }

    /**
     * @return array<int, array{id: int, title: ?string, artist: ?string, path: string, intro: ?float}>
     */
    public function describe(Station $station, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }
        $rows = $this->em->createQueryBuilder()
            ->select('m.id, m.title, m.artist, m.path')
            ->from(StationMedia::class, 'm')
            ->where('m.storage_location = :storage')
            ->andWhere('m.id IN (:ids)')
            ->setParameter('storage', $station->media_storage_location)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getArrayResult();
        $intros = $this->intros($ids);
        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['id']] = [
                'id' => (int)$row['id'],
                'title' => $row['title'],
                'artist' => $row['artist'],
                'path' => $row['path'],
                'intro' => $intros[(int)$row['id']] ?? null,
            ];
        }

        return $result;
    }

    /**
     * @param list<int> $ids
     * @return array<int, float>
     */
    public function intros(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->em->createQuery(
            <<<'DQL'
                SELECT m.id, m.length, v.value FROM App\Entity\StationMediaCustomField v
                JOIN v.field f JOIN v.media m
                WHERE f.short_name = 'intro_end' AND m.id IN (:ids)
            DQL
        )->setParameter('ids', $ids)->getArrayResult();
        $intros = [];
        foreach ($rows as $row) {
            try {
                $intro = self::parseIntro($row['value'], (float)$row['length']);
            } catch (InvalidArgumentException) {
                $intro = null;
            }
            if ($intro !== null) {
                $intros[(int)$row['id']] = $intro;
            }
        }

        return $intros;
    }

    public function list(Station $station, string $search): array
    {
        $query = $this->em->createQueryBuilder()
            ->select('m')
            ->from(StationMedia::class, 'm')
            ->where('m.storage_location = :storage')
            ->setParameter('storage', $station->media_storage_location)
            ->orderBy('m.path', 'ASC')
            ->setMaxResults(100);

        if ($search !== '') {
            $query->andWhere('m.path LIKE :search OR m.title LIKE :search OR m.artist LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        return $query->getQuery()->getResult();
    }

    public function uri(Station $station, StationMedia $media): string
    {
        $event = AnnotateNextSong::fromStationMedia($station, $media);
        $this->dispatcher->dispatch($event);

        return $event->buildAnnotations();
    }

    public static function identity(StationMedia $media): string
    {
        return $media->unique_id . ':' . $media->mtime . ':' . $media->size;
    }
}
