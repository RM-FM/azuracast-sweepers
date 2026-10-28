<?php

declare(strict_types=1);

namespace {
    $autoload = getenv('SWEEPER_AZURACAST_AUTOLOAD') ?: '/var/azuracast/www/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('Run this test in the pinned AzuraCast image with an isolated sweepers_test database.');
    }
    require $autoload;
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Plugin\\AzuracastSweepers\\';
        if (str_starts_with($class, $prefix)) {
            require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        }
    });
}

namespace App\Doctrine {
    use Doctrine\ORM\EntityManagerInterface;

    abstract class Repository
    {
        public EntityManagerInterface $em;
    }
}

namespace App\Entity\Repository {
    abstract class AbstractStationBasedRepository extends \App\Doctrine\Repository
    {
    }

    final class StationPlaylistRepository
    {
    }
}

namespace App\Entity {
    use App\Entity\Enums\PlaylistOrders;
    use App\Entity\Enums\PlaylistSources;
    use Doctrine\Common\Collections\ArrayCollection;
    use Doctrine\Common\Collections\Collection;
    use Doctrine\ORM\Mapping as ORM;

    #[ORM\Entity, ORM\Table(name: 'replacement_station')]
    final class Station
    {
        #[ORM\Id, ORM\Column]
        public int $id = 1;

        public string $short_name = 'replacement_test';

        public object $backend_config;

        public object $media_storage_location;
    }

    #[ORM\Entity, ORM\Table(name: 'replacement_playlist')]
    final class StationPlaylist
    {
        #[ORM\Id, ORM\Column]
        public int $id = 1;

        #[ORM\ManyToOne]
        public Station $station;

        #[ORM\Column]
        public string $name = 'Music';

        #[ORM\Column]
        public bool $is_enabled = true;

        #[ORM\Column]
        public bool $is_jingle = false;

        #[ORM\Column]
        public bool $avoid_duplicates = false;

        #[ORM\Column]
        public bool $merge = false;

        #[ORM\Column(name: 'playback_order', enumType: PlaylistOrders::class)]
        public PlaylistOrders $order = PlaylistOrders::Shuffle;

        #[ORM\Column(enumType: PlaylistSources::class)]
        public PlaylistSources $source = PlaylistSources::Songs;

        #[ORM\OneToMany(mappedBy: 'playlist', targetEntity: StationPlaylistMedia::class)]
        public Collection $media_items;

        public function __construct(Station $station)
        {
            $this->station = $station;
            $this->media_items = new ArrayCollection();
        }

        public function backendMerge(): bool
        {
            return $this->merge;
        }
    }

    #[ORM\Entity, ORM\Table(name: 'replacement_media')]
    final class StationMedia implements Interfaces\SongInterface
    {
        use Traits\HasSongFields;

        #[ORM\Id, ORM\Column]
        public int $id;

        #[ORM\Column]
        public int $storage_location_id;

        #[ORM\Column]
        public float $length = 180.0;

        #[ORM\OneToMany(mappedBy: 'media', targetEntity: StationPlaylistMedia::class)]
        public Collection $playlists;

        public function __construct(Station $station, int $id)
        {
            $this->storage_location_id = $station->media_storage_location->id;
            $this->id = $id;
            $this->title = 'Title ' . $id;
            $this->artist = 'Artist ' . $id;
            $this->updateMetaFields();
            $this->playlists = new ArrayCollection();
        }

        public function getCalculatedLength(): float
        {
            return $this->length;
        }
    }

    #[ORM\Entity, ORM\Table(name: 'replacement_request')]
    final class StationRequest
    {
        #[ORM\Id, ORM\Column]
        public int $id;
    }

    #[ORM\Entity, ORM\Table(name: 'replacement_folder')]
    final class StationPlaylistFolder
    {
        #[ORM\Id, ORM\Column]
        public int $id;

        #[ORM\OneToMany(mappedBy: 'folder', targetEntity: StationPlaylistMedia::class)]
        public Collection $media_items;
    }
}

namespace {
    use App\Entity\Enums\PlaylistOrders;
    use App\Entity\Enums\PlaylistSources;
    use App\Entity\Repository\CustomFieldRepository;
    use App\Entity\Repository\StationPlaylistMediaRepository;
    use App\Entity\Repository\StationPlaylistRepository;
    use App\Entity\Repository\StationQueueRepository;
    use App\Entity\Station;
    use App\Entity\StationMedia;
    use App\Entity\StationPlaylist;
    use App\Entity\StationPlaylistFolder;
    use App\Entity\StationPlaylistMedia;
    use App\Entity\StationQueue;
    use App\Entity\StationRequest;
    use App\Lock\LockFactory;
    use App\Radio\AutoDJ\DuplicatePrevention;
    use Doctrine\DBAL\DriverManager;
    use Doctrine\ORM\EntityManager;
    use Doctrine\ORM\ORMSetup;
    use Doctrine\ORM\Tools\SchemaTool;
    use DoctrineExtensions\Query\Mysql\Rand;
    use Plugin\AzuracastSweepers\Service\Media;
    use Plugin\AzuracastSweepers\Service\PlaylistReplacement;
    use Plugin\AzuracastSweepers\ValueObject\Settings;
    use Symfony\Component\EventDispatcher\EventDispatcher;
    use Symfony\Component\Lock\Store\FlockStore;

    $connection = DriverManager::getConnection([
        'driver' => 'pdo_mysql',
        'host' => getenv('SWEEPER_TEST_DB_HOST') ?: '127.0.0.1',
        'user' => 'root',
        'password' => getenv('SWEEPER_TEST_DB_PASSWORD') ?: '',
        'dbname' => 'sweepers_test',
    ]);
    $configuration = ORMSetup::createAttributeMetadataConfiguration([], true);
    $configuration->enableNativeLazyObjects(true);
    $configuration->addCustomNumericFunction('RAND', Rand::class);
    $em = new EntityManager($connection, $configuration);
    $entities = [
        Station::class, StationPlaylist::class, StationMedia::class, StationPlaylistFolder::class,
        StationRequest::class, StationQueue::class, StationPlaylistMedia::class,
    ];
    $metadata = array_map($em->getClassMetadata(...), $entities);
    $schema = new SchemaTool($em);
    $schema->dropSchema($metadata);
    $schema->createSchema($metadata);

    $queue = new StationQueueRepository(new StationPlaylistRepository());
    $queue->em = $em;
    $playlistMedia = new StationPlaylistMediaRepository($queue);
    $playlistMedia->em = $em;
    $duplicates = new DuplicatePrevention();
    $duplicates->setLogger(new \Monolog\Logger('replacement-test'));
    $locks = new LockFactory(new FlockStore(), new \Monolog\Logger('replacement-lock-test'));
    $library = new Media($em, new CustomFieldRepository(), new EventDispatcher());
    $replacement = new PlaylistReplacement($em, $playlistMedia, $queue, $duplicates, $locks, $library);
    $station = new Station();
    $station->media_storage_location = (object)['id' => 1];
    $station->backend_config = new class {
        public int $duplicate_prevention_time_range = 30;

        public function getDuplicatePreventionArtistTimeRange(): int
        {
            return 60;
        }
    };
    $playlist = new StationPlaylist($station);
    $group = new StationPlaylist($station);
    $group->id = 2;
    $group->name = 'Rotation';
    $em->persist($station);
    $em->persist($playlist);
    $em->persist($group);
    $media = [];
    $slots = [];
    foreach (range(1, 7) as $id) {
        $media[$id] = new StationMedia($station, $id);
        $media[$id]->length += $id;
        $slots[$id] = new StationPlaylistMedia($playlist, $media[$id]);
        $slots[$id]->weight = $id * 10;
        $em->persist($media[$id]);
        $em->persist($slots[$id]);
    }
    $slots[1]->played(100);
    $slots[2]->played(200);
    $slots[5]->played(500);
    $slots[7]->played(700);
    $current = StationQueue::fromMedia($station, $media[2]);
    $current->playlist = $playlist;
    $current->is_played = true;
    $current->timestamp_played = new DateTimeImmutable('-1 minute');
    $target = StationQueue::fromMedia($station, $media[1]);
    $target->playlist = $playlist;
    $target->playlist_chain = ['Rotation', 'Music'];
    $target->timestamp_played = new DateTimeImmutable('+3 minutes');
    $upcoming = StationQueue::fromMedia($station, $media[5]);
    $upcoming->playlist = $playlist;
    $upcoming->timestamp_played = new DateTimeImmutable('+6 minutes');
    foreach ([$current, $target, $upcoming] as $entity) {
        $em->persist($entity);
    }
    $em->flush();
    $checks = 0;
    $check = static function (bool $valid, string $message) use (&$checks): void {
        $checks++;
        if (!$valid) {
            throw new RuntimeException($message);
        }
    };
    $settings = new Settings(swapFromPlaylist: true);
    $snapshot = static fn(): array => [
        'id' => $target->id, 'playlist_id' => $playlist->id, 'media_id' => $target->media_id,
        'playlist_chain' => $target->playlist_chain,
    ];
    $replace = static function (callable $usable, ?Settings $options = null) use (
        $replacement, $station, $snapshot, $current, $settings, &$reason
    ): ?int {
        return $replacement->replace($station, $snapshot(), (string)$current->id, $options ?? $settings, $usable, $reason);
    };
    $flags = static fn(): array => $connection->fetchAllAssociative(
        'SELECT id, weight, is_queued, last_played FROM station_playlist_media ORDER BY id'
    );

    $before = $flags();
    $nativeLock = $locks->createLock('nowplaying_' . $station->short_name, 60);
    $nativeLock->acquire();
    $check(
        $replace(static fn(): bool => true) === null && $reason === 'replace_busy',
        'The native NowPlaying queue-builder lock prevents concurrent rotation selection.'
    );
    $nativeLock->release();
    $check($replace(static function (): bool {
        usleep(510_000);

        return true;
    }) === null && $reason === 'replace_timeout', 'An expired scan never commits a late replacement.');
    $check($flags() === $before, 'Busy or timed-out selection consumes nothing.');
    $check($replace(static fn(): bool => false) === null && $reason === 'replace_none', 'No match is a no-op.');
    $check($flags() === $before, 'Unmatched candidates are not consumed.');
    $check($replace(static fn(int $id): bool => in_array($id, [1, 2, 5], true)) === null, 'Current and queued excluded.');
    $target->autodj_custom_uri = 'custom:protected';
    $em->flush();
    $check($replace(static fn(): bool => true) === null, 'Custom URI queue entries are never overwritten.');
    $target->autodj_custom_uri = null;
    $request = new StationRequest();
    $request->id = 1;
    $em->persist($request);
    $target->request = $request;
    $em->flush();
    $check($replace(static fn(): bool => true) === null, 'Listener requests are never replaced.');
    $target->request = null;
    $media[4]->storage_location_id = 99;
    $em->flush();
    $check($replace(static fn(int $id): bool => $id === 4) === null, 'Foreign storage is rejected by the media helper.');
    $media[4]->storage_location_id = 1;
    $em->flush();
    $urgent = StationQueue::fromMedia($station, $media[2]);
    $urgent->playlist = $playlist;
    $urgent->timestamp_cued = $target->timestamp_cued->modify('-1 minute');
    $urgent->timestamp_played = $target->timestamp_played;
    $check($replace(static function (int $id) use ($em, $urgent): bool {
        $em->persist($urgent);
        $em->flush();

        return $id === 3;
    }) === null && $reason === 'replace_changed', 'A newly inserted earlier queue entry invalidates the target.');
    $em->remove($urgent);
    $em->flush();
    $playlist->order = PlaylistOrders::Sequential;
    $em->flush();
    $check($replace(static fn(): bool => true) === null && $reason === 'replace_ineligible', 'Sequential needs opt-in.');
    $check(
        $replace(static fn(int $id): bool => $id >= 3, new Settings(swapSequential: true)) === 3,
        'Native sequential order chooses the first eligible rotation slot.'
    );
    $em->refresh($target);
    foreach ($slots as $slot) {
        $em->refresh($slot);
    }
    $check($target->media_id === 3 && $target->song_id === $media[3]->song_id, 'Native song identity is replaced.');
    $check($target->duration === 183.0 && $target->title === 'Title 3', 'Native duration and metadata are replaced.');
    $check($target->playlist_chain === ['Rotation', 'Music'] && !$target->sent_to_autodj, 'Slot and chain stay intact.');
    $check(
        $slots[1]->is_queued && $slots[1]->weight === 10 && $slots[1]->last_played === 100,
        'Displaced membership is restored without changing its weight or historical selection timestamp.'
    );
    $check(
        !$slots[3]->is_queued && $slots[3]->last_played === $target->timestamp_played->getTimestamp(),
        'Selected membership is consumed once at the target play time.'
    );
    $check($replace(static fn(int $id): bool => $id === 1, new Settings(swapSequential: true)) === 1, 'Restored returns.');
    $em->refresh($target);
    $check($replace(static fn(int $id): bool => $id === 3, new Settings(swapSequential: true)) === 3, 'No per-song cap.');
    $em->refresh($target);

    $playlist->order = PlaylistOrders::Shuffle;
    $group->avoid_duplicates = true;
    $media[4]->artist = $media[2]->artist;
    $media[4]->text = null;
    $media[4]->updateMetaFields();
    $media[6]->artist = $media[3]->artist;
    $media[6]->text = null;
    $media[6]->updateMetaFields();
    $em->flush();
    $before = $flags();
    $check(
        $replace(static fn(int $id): bool => $id === 4) === null && $reason === 'replace_duplicates',
        'Actual native duplicate prevention honors the group artist rule.'
    );
    $check($flags() === $before, 'Rejected repetition leaves both rotation entries unchanged.');
    $check(
        $replace(static fn(int $id): bool => in_array($id, [4, 6], true)) === 6,
        'The next fitting nonduplicate wins; the displaced artist is not mistaken for actual playback history.'
    );
    $em->refresh($target);
    $group->avoid_duplicates = false;
    $playlist->avoid_duplicates = true;
    $em->flush();
    $check($replace(static fn(int $id): bool => $id === 4) === null, 'Leaf duplicate prevention also applies.');
    $playlist->avoid_duplicates = false;
    $em->flush();
    $check($replace(static fn(int $id): bool => $id === 4) === 4, 'Disabled duplicate prevention is not forced on.');
    $em->refresh($target);

    $playlist->order = PlaylistOrders::Random;
    $em->flush();
    $check($replace(static fn(int $id): bool => $id === 7) === 7, 'Native random includes consumed rotation slots.');
    $em->refresh($target);
    $group->merge = true;
    $em->flush();
    $check($replace(static fn(): bool => true) === null && $reason === 'replace_ineligible', 'Merged group skipped.');
    $group->merge = false;
    $playlist->merge = true;
    $em->flush();
    $check($replace(static fn(): bool => true) === null, 'Merged playlist skipped.');
    $playlist->merge = false;
    $playlist->source = PlaylistSources::RemoteUrl;
    $em->flush();
    $check($replace(static fn(): bool => true) === null, 'Remote playlists skipped.');
    $playlist->source = PlaylistSources::Songs;
    $em->flush();

    $before = $flags();
    $check($replace(static function (int $id) use ($connection, $target): bool {
        $connection->executeStatement('UPDATE station_queue SET sent_to_autodj = 1 WHERE id = ?', [$target->id]);

        return $id === 1;
    }) === null && $reason === 'replace_changed', 'Concurrent send refuses replacement.');
    $check($flags() === $before && $em->isOpen(), 'Send race preserves rotation and a usable entity manager.');
    $connection->executeStatement('UPDATE station_queue SET sent_to_autodj = 0 WHERE id = ?', [$target->id]);
    $check($replace(static function (int $id) use ($connection, $slots): bool {
        $connection->executeStatement(
            'UPDATE station_playlist_media SET is_queued = 0 WHERE id = ?',
            [$slots[1]->id]
        );

        return $id === 1;
    }, new Settings(swapSequential: true)) === 1, 'Random selection does not require the queued flag.');
    $em->refresh($target);
    $playlist->order = PlaylistOrders::Shuffle;
    $slots[3]->is_queued = true;
    $em->persist($slots[3]);
    $em->flush();
    $before = $flags();
    $check($replace(static function (int $id) use ($connection, $slots): bool {
        $connection->executeStatement(
            'UPDATE station_playlist_media SET is_queued = 0 WHERE id = ?',
            [$slots[3]->id]
        );

        return $id === 3;
    }) === null && $reason === 'replace_changed', 'Concurrent rotation consumption is refused.');
    $check($em->isOpen(), 'Rotation race leaves the entity manager usable.');
    $connection->executeStatement(
        <<<'SQL'
            CREATE TRIGGER replacement_abort BEFORE UPDATE ON station_playlist_media
            FOR EACH ROW BEGIN
                IF NEW.media_id = 6 AND NEW.is_queued = 0 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected rotation failure';
                END IF;
            END
        SQL
    );
    $before = $flags();
    $failed = false;
    try {
        $replace(static fn(int $id): bool => $id === 6);
    } catch (\Doctrine\DBAL\Exception\DriverException $exception) {
        if (!str_contains($exception->getMessage(), 'injected rotation failure')) {
            throw $exception;
        }
        $failed = true;
    } finally {
        $connection->executeStatement('DROP TRIGGER replacement_abort');
    }
    $check($failed, 'Database errors surface rather than pretending a replacement succeeded.');
    $check(
        $flags() === $before && (int)$connection->fetchOne(
            'SELECT media_id FROM station_queue WHERE id = ?',
            [$target->id]
        ) === $target->media_id && $em->isOpen(),
        'Queue identity and both rotation slots roll back together after a late database failure.'
    );
    $em->refresh($target);
    $connection->executeStatement('UPDATE station_playlist_media SET is_queued = (media_id = 6)');
    $check($replace(static fn(): bool => true) === 6, 'The remaining round is used before songs already played.');
    $em->refresh($target);
    $connection->executeStatement('UPDATE station_playlist_media SET is_queued = 0');
    $weights = array_column($flags(), 'weight', 'id');
    $oldest = (int)$connection->fetchOne(
        'SELECT media_id FROM station_playlist_media WHERE media_id NOT IN (?, 2, 5) ORDER BY last_played, weight LIMIT 1',
        [$target->media_id]
    );
    $check(
        $replace(static fn(): bool => true) === $oldest,
        'An exhausted round continues with the song played longest ago, without reshuffling.'
    );
    $check(
        (int)$connection->fetchOne('SELECT COUNT(*) FROM station_playlist_media WHERE is_queued = 1') === 1
            && array_column($flags(), 'weight', 'id') === $weights,
        'Only the displaced song returns to the round; AzuraCast still resets and reshuffles it.'
    );
    $em->refresh($target);
    $connection->executeStatement('UPDATE station_playlist_media SET is_queued = 0');
    $before = $flags();
    $check(
        $replace(static fn(int $id): bool => in_array($id, [2, 5], true)) === null && $reason === 'replace_none'
            && $flags() === $before,
        'Queued and current songs stay excluded when the round wraps.'
    );
    echo $checks . " replacement checks passed using native AzuraCast repositories, entities and duplicate prevention.\n";
}
