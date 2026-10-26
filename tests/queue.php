<?php

declare(strict_types=1);

namespace App\Entity {
    use Doctrine\ORM\Mapping as ORM;

    #[ORM\Entity]
    final class Station
    {
        #[ORM\Id, ORM\Column(type: 'integer')]
        public int $id;

        public object $backend_config;
    }

    #[ORM\Entity]
    final class StationPlaylist
    {
        #[ORM\Id, ORM\Column(type: 'integer')]
        public int $id;

        #[ORM\Column]
        public bool $is_jingle = false;

        #[ORM\ManyToOne]
        public ?Station $station = null;

        #[ORM\Column]
        public string $name = '';

        #[ORM\Column]
        public string $type = 'default';

        #[ORM\Column(name: 'playback_order')]
        public string $order = 'shuffle';
    }

    #[ORM\Entity, ORM\Table(name: 'queue_media')]
    final class StationMedia
    {
        #[ORM\Id, ORM\Column(type: 'integer')]
        public int $id;
    }

    #[ORM\Entity, ORM\Table(name: 'queue_request')]
    final class StationRequest
    {
        #[ORM\Id, ORM\Column(type: 'integer')]
        public int $id;
    }

    #[ORM\Entity]
    final class StationQueue
    {
        #[ORM\Id, ORM\Column(type: 'integer')]
        public int $id;

        #[ORM\ManyToOne]
        public Station $station;

        #[ORM\ManyToOne]
        public ?StationPlaylist $playlist = null;

        #[ORM\ManyToOne]
        public ?StationMedia $media = null;

        #[ORM\ManyToOne]
        public ?StationRequest $request = null;

        #[ORM\Column]
        public bool $is_played = false;

        #[ORM\Column]
        public bool $sent_to_autodj = false;

        #[ORM\Column(type: 'datetime_immutable', precision: 6)]
        public \DateTimeImmutable $timestamp_cued;

        #[ORM\Column(type: 'datetime_immutable', precision: 6)]
        public \DateTimeImmutable $timestamp_played;

        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $playlist_chain = null;
    }
}

namespace {
    use App\Entity\Station;
    use App\Entity\StationMedia;
    use App\Entity\StationPlaylist;
    use App\Entity\StationQueue;
    use App\Entity\StationRequest;
    use Doctrine\DBAL\DriverManager;
    use Doctrine\ORM\EntityManager;
    use Doctrine\ORM\ORMSetup;
    use Doctrine\ORM\Tools\SchemaTool;
    use Plugin\AzuracastSweepers\Service\Queue;
    use Plugin\AzuracastSweepers\ValueObject\Settings;

    require dirname(__DIR__) . '/vendor/autoload.php';

    $connection = DriverManager::getConnection([
        'driver' => 'pdo_mysql',
        'host' => getenv('SWEEPER_TEST_DB_HOST') ?: '127.0.0.1',
        'user' => 'root',
        'password' => getenv('SWEEPER_TEST_DB_PASSWORD') ?: '',
        'dbname' => 'sweepers_test',
    ]);
    $configuration = ORMSetup::createAttributeMetadataConfiguration([__DIR__], true);
    $configuration->enableNativeLazyObjects(true);
    $em = new EntityManager($connection, $configuration);
    foreach (['StationQueue', 'StationPlaylist', 'queue_media', 'queue_request', 'Station'] as $name) {
        $connection->executeStatement('DROP TABLE IF EXISTS `' . $name . '`');
    }
    (new SchemaTool($em))->createSchema(array_map(
        static fn(string $name) => $em->getClassMetadata($name),
        [Station::class, StationPlaylist::class, StationMedia::class, StationRequest::class, StationQueue::class]
    ));

    $station = new Station();
    $station->id = 1;
    $station->backend_config = (object)['autodj_queue_length' => 3];
    $other = new Station();
    $other->id = 2;
    $music = new StationPlaylist();
    $music->id = 1;
    $jingles = new StationPlaylist();
    $jingles->id = 5;
    $jingles->is_jingle = true;
    foreach ([$station, $other, $music, $jingles] as $entity) {
        $em->persist($entity);
    }
    $add = static function (
        int $id,
        Station $owner,
        ?StationPlaylist $playlist,
        bool $sent,
        int $cued,
        bool $played = false,
        ?StationMedia $media = null,
        ?StationRequest $request = null,
        ?array $chain = null
    ) use ($em): void {
        $row = new StationQueue();
        $row->id = $id;
        $row->station = $owner;
        $row->playlist = $playlist;
        $row->media = $media;
        $row->request = $request;
        $row->playlist_chain = $chain;
        $row->sent_to_autodj = $sent;
        $row->is_played = $played;
        $row->timestamp_cued = new DateTimeImmutable('@' . $cued);
        $row->timestamp_played = new DateTimeImmutable('@' . ($cued + 30));
        $em->persist($row);
    };
    $add(10, $station, $music, true, 100, true);
    $add(11, $station, $music, true, 200);
    $add(12, $station, null, false, 300);
    $add(13, $station, $jingles, false, 400);
    $add(14, $other, $jingles, false, 250);
    $em->flush();

    $queue = new Queue($em);
    $expect = static function (array $actual, array $expected, string $message): void {
        if ($actual !== $expected) {
            throw new RuntimeException($message . ' ' . json_encode($actual));
        }
    };
    $expect($queue->blocked($station, ['11'], new Settings(songsBeforeJingle: 2)), ['11'], 'Jingle two tracks ahead.');
    $expect($queue->blocked($station, ['11'], new Settings(songsBeforeJingle: 1)), [], 'Request only one track ahead.');
    $expect($queue->blocked($station, ['10'], new Settings(songsBeforeJingle: 3)), ['10'], 'Playing song lookahead.');
    $expect($queue->blocked($station, ['10'], new Settings(songsBeforeJingle: 2)), [], 'Other stations are ignored.');
    $expect($queue->blocked($station, ['10', '11', 'x'], new Settings(songsBeforeJingle: 9)), ['10', '11'], 'Cap at queue.');
    $expect(
        $queue->blocked($station, ['11'], new Settings(countJingleMode: false, songsBeforeJingle: 2)),
        [],
        'Disabled Jingle Mode.'
    );
    $expect(
        $queue->blocked($station, ['11'], new Settings(countJingleMode: false, jinglePlaylistIds: [5], songsBeforeJingle: 2)),
        ['11'],
        'Selected jingle playlist.'
    );
    echo "Upcoming-queue jingle lookahead uses AzuraCast queue order.\n";

    $mover = new Station();
    $mover->id = 3;
    $mover->backend_config = (object)['autodj_queue_length' => 3];
    $songA = new StationMedia();
    $songA->id = 1;
    $songB = new StationMedia();
    $songB->id = 2;
    $request = new StationRequest();
    $request->id = 4;
    $group = new StationPlaylist();
    $group->id = 9;
    $group->name = '00 - Rotation';
    $group->station = $mover;
    foreach ([$mover, $songA, $songB, $request, $group] as $entity) {
        $em->persist($entity);
    }
    $add(30, $mover, $music, true, 1000, true, $songA);
    $add(31, $mover, $music, true, 1100, false, $songB);
    $add(32, $mover, $music, false, 1200, false, $songA, null, ['00 - Rotation', 'Full Day Mix']);
    $add(33, $mover, $jingles, false, 1300, false, $songB);
    $add(34, $mover, null, false, 1400, false, $songA, $request);
    $em->flush();
    $em->clear();

    $ids = static fn(?array $rows): ?array => $rows === null ? null : array_column($rows, 'id');
    $expect($ids($queue->upcoming($mover, '30')), [31, 32, 33, 34], 'Rows after the counted song in play order.');
    $rows = array_column($queue->upcoming($mover, '30'), null, 'id');
    $expect(
        [$rows[31]['sent'], $rows[32]['media_id'], $rows[33]['is_jingle'], $rows[34]['request_id'],
            $rows[32]['playlist_type'], $rows[32]['playlist_order'], $rows[34]['playlist_id']],
        [true, 1, true, 4, 'default', 'shuffle', null],
        'Queue rows carry what the decision needs.'
    );
    $expect([$queue->upcoming($mover, '99'), $queue->upcoming($mover, '')], [null, null], 'Unknown counted song.');
    $expect([$rows[32]['playlist_chain'], $rows[31]['playlist_chain']], [['00 - Rotation', 'Full Day Mix'], []], 'Chain.');
    $withoutGroup = new Settings(excludedPlaylistIds: [9]);
    $expect($queue->excludedNames($mover, $withoutGroup), ['00 - Rotation'], 'Excluded names of this station only.');
    $expect(
        [$queue->excluded($mover, '32', $withoutGroup), $queue->excluded($mover, '31', $withoutGroup),
            $queue->excluded($mover, '32', new Settings()), $queue->excluded($station, '32', $withoutGroup)],
        [true, false, false, false],
        'A selected group excludes the songs it played through.'
    );
    $expect($ids($queue->upcoming($mover, '31')), [32, 33, 34], 'A counted later song hides earlier rows.');

    if (!$queue->exchange($mover, 32, 34)) {
        throw new RuntimeException('Two unsent rows must exchange places.');
    }
    $expect($ids($queue->upcoming($mover, '30')), [31, 34, 33, 32], 'Exchanged rows play in the new order.');
    $times = $connection->fetchAllKeyValue(
        'SELECT id, UNIX_TIMESTAMP(timestamp_played) FROM StationQueue WHERE id IN (32, 34) ORDER BY id'
    );
    $expect(array_map('intval', $times), [32 => 1430, 34 => 1230], 'Expected play times move with the rows.');
    if ($queue->exchange($mover, 31, 33) || $queue->exchange($mover, 33, 99) || $queue->exchange($station, 33, 34)) {
        throw new RuntimeException('Sent, missing or foreign rows must not move.');
    }
    $expect($ids($queue->upcoming($mover, '30')), [31, 34, 33, 32], 'A refused exchange changes nothing.');
    echo "Queue rows exchange places only while unsent.\n";
}
