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

        #[ORM\Column]
        public bool $is_played = false;

        #[ORM\Column]
        public bool $sent_to_autodj = false;

        #[ORM\Column(type: 'datetime_immutable', precision: 6)]
        public \DateTimeImmutable $timestamp_cued;
    }
}

namespace {
    use App\Entity\Station;
    use App\Entity\StationPlaylist;
    use App\Entity\StationQueue;
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
    foreach (['StationQueue', 'StationPlaylist', 'Station'] as $name) {
        $connection->executeStatement('DROP TABLE IF EXISTS `' . $name . '`');
    }
    (new SchemaTool($em))->createSchema(array_map(
        static fn(string $name) => $em->getClassMetadata($name),
        [Station::class, StationPlaylist::class, StationQueue::class]
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
    $add = static function (int $id, Station $owner, ?StationPlaylist $playlist, bool $sent, int $cued, bool $played = false)
        use ($em): void {
        $row = new StationQueue();
        $row->id = $id;
        $row->station = $owner;
        $row->playlist = $playlist;
        $row->sent_to_autodj = $sent;
        $row->is_played = $played;
        $row->timestamp_cued = new DateTimeImmutable('@' . $cued);
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
}
