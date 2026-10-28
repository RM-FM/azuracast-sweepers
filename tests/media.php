<?php

declare(strict_types=1);

namespace App\Entity {
    use Doctrine\ORM\Mapping as ORM;

    final class Station
    {
        public int $media_storage_location = 1;
    }

    #[ORM\Entity]
    final class StationMedia
    {
        #[ORM\Id, ORM\Column(type: 'integer')]
        public int $id;

        #[ORM\Column]
        public string $unique_id = 'recording';

        #[ORM\Column]
        public int $mtime = 123;

        #[ORM\Column]
        public int $size = 456;

        #[ORM\Column]
        public float $length = 180.0;

        #[ORM\Column]
        public int $storage_location = 1;

        #[ORM\Column]
        public string $path = '';

        #[ORM\Column(nullable: true)]
        public ?string $title = null;

        #[ORM\Column(nullable: true)]
        public ?string $artist = null;
    }

    #[ORM\Entity]
    final class CustomField
    {
        #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
        public ?int $id = null;

        #[ORM\Column(unique: true)]
        public string $short_name;

        #[ORM\Column]
        public string $name;
    }

    #[ORM\Entity]
    final class StationMediaCustomField
    {
        #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
        public ?int $id = null;

        #[ORM\ManyToOne]
        public StationMedia $media;

        #[ORM\ManyToOne]
        public CustomField $field;

        #[ORM\Column(nullable: true)]
        public ?string $value = null;

        public function __construct(StationMedia $media, CustomField $field)
        {
            $this->media = $media;
            $this->field = $field;
        }
    }
}

namespace App\Entity\Repository {
    final class CustomFieldRepository
    {
    }
}

namespace {
    use App\Entity\CustomField;
    use App\Entity\StationMedia;
    use App\Entity\StationMediaCustomField;
    use Doctrine\DBAL\DriverManager;
    use Doctrine\ORM\EntityManager;
    use Doctrine\ORM\ORMSetup;
    use Doctrine\ORM\Tools\SchemaTool;
    use Plugin\AzuracastSweepers\Service\Media;
    use Psr\EventDispatcher\EventDispatcherInterface;

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
    $schema = new SchemaTool($em);
    $classes = array_map(static fn(string $name) => $em->getClassMetadata($name), [
        StationMedia::class,
        CustomField::class,
        StationMediaCustomField::class,
    ]);
    foreach (['StationMediaCustomField', 'CustomField', 'StationMedia'] as $name) {
        $connection->executeStatement('DROP TABLE IF EXISTS `' . $name . '`');
    }
    $schema->createSchema($classes);

    $dispatcher = new class implements EventDispatcherInterface {
        public function dispatch(object $event): object
        {
            return $event;
        }
    };
    $mediaService = new Media($em, new \App\Entity\Repository\CustomFieldRepository(), $dispatcher);
    $recording = new StationMedia();
    $recording->id = 1;
    $em->persist($recording);
    $em->flush();
    $other = new CustomField();
    $other->name = 'Category';
    $other->short_name = 'category';
    $em->persist($other);
    $entry = new StationMediaCustomField($recording, $other);
    $entry->value = 'rotation-a';
    $em->persist($entry);
    $em->flush();

    foreach ([21.5, 0.0, null, 14.0] as $value) {
        if ($mediaService->saveIntro($recording, $value) !== $value) {
            throw new RuntimeException('Unexpected cue response.');
        }
        $stored = $connection->fetchAllKeyValue('SELECT f.short_name, v.value '
            . 'FROM StationMediaCustomField v JOIN CustomField f ON f.id = v.field_id WHERE v.media_id = 1 ORDER BY f.short_name');
        $expected = $value === null ? null : (string)$value;
        if ($stored !== ['category' => 'rotation-a', 'intro_end' => $expected]) {
            throw new RuntimeException('Cue update changed another custom field or lost the cue.');
        }
        if ((int)$connection->fetchOne('SELECT COUNT(*) FROM StationMediaCustomField WHERE media_id = 1') !== 2) {
            throw new RuntimeException('Cue updates created duplicate fields.');
        }
    }
    $blank = new StationMedia();
    $blank->id = 2;
    $em->persist($blank);
    $em->flush();
    $mediaService->saveIntro($blank, null);
    if ($mediaService->intros([1, 2, 3]) !== [1 => 14.0] || $mediaService->intros([]) !== []) {
        throw new RuntimeException('Intro lookup returned ' . json_encode($mediaService->intros([1, 2, 3])));
    }
    for ($id = 3; $id <= 129; $id++) {
        $file = new StationMedia();
        $file->id = $id;
        $file->path = 'pagination-' . str_pad((string)$id, 3, '0', STR_PAD_LEFT) . '.mp3';
        $file->title = $id === 3 ? 'Title lookup' : null;
        $file->artist = $id === 4 ? 'Artist lookup' : null;
        $em->persist($file);
    }
    $foreign = new StationMedia();
    $foreign->id = 130;
    $foreign->storage_location = 2;
    $foreign->path = 'pagination-foreign.mp3';
    $foreign->title = 'Title lookup';
    $em->persist($foreign);
    $em->flush();

    $station = new \App\Entity\Station();
    $ids = [];
    for ($page = 1; $page <= 6; $page++) {
        $result = $mediaService->list($station, 'pagination', $page);
        $expectedCount = $page === 6 ? 2 : 25;
        if ($result['total'] !== 127 || count($result['rows']) !== $expectedCount) {
            throw new RuntimeException('Media pagination returned an incorrect total or page size.');
        }
        array_push($ids, ...array_map(static fn(StationMedia $file): int => $file->id, $result['rows']));
    }
    if ($ids !== range(3, 129)) {
        throw new RuntimeException('Pagination lost, duplicated or reordered files, or leaked another storage location.');
    }
    foreach (['Title lookup' => 3, 'Artist lookup' => 4] as $search => $expectedId) {
        $result = $mediaService->list($station, $search);
        if ($result['total'] !== 1 || $result['rows'][0]->id !== $expectedId) {
            throw new RuntimeException('Media search did not match title or artist within the station.');
        }
    }
    $empty = $mediaService->list($station, 'pagination', 7);
    $all = $mediaService->list($station, '');
    if ($empty !== ['rows' => [], 'total' => 127] || $all['total'] !== 129) {
        throw new RuntimeException('Empty pages or unfiltered media totals are incorrect.');
    }
    $zero = $em->find(StationMedia::class, 3);
    $mediaService->saveIntro($zero, 0);
    $whitespace = new StationMediaCustomField($em->find(StationMedia::class, 4), $mediaService->ensureField());
    $whitespace->value = '   ';
    $em->persist($whitespace);
    $emptyCue = new StationMediaCustomField($em->find(StationMedia::class, 5), $mediaService->ensureField());
    $emptyCue->value = '';
    $em->persist($emptyCue);
    $em->flush();
    $unknownIds = [];
    for ($page = 1; $page <= 6; $page++) {
        $result = $mediaService->list($station, '', $page, true);
        if ($result['total'] !== 127) {
            throw new RuntimeException('Unknown filter must exclude positive and zero cues, not blank or missing ones.');
        }
        array_push($unknownIds, ...array_map(static fn(StationMedia $file): int => $file->id, $result['rows']));
    }
    if ($unknownIds !== [2, ...range(4, 129)]) {
        throw new RuntimeException('Unknown filter lost blank/missing cues or included tagged/foreign tracks.');
    }
    $filteredSearch = $mediaService->list($station, 'Title lookup', 1, true);
    if ($filteredSearch !== ['rows' => [], 'total' => 0]) {
        throw new RuntimeException('Unknown filter must combine with search before pagination.');
    }
    try {
        $mediaService->saveIntro($recording, 999.0);
        throw new RuntimeException('An out-of-file cue was accepted.');
    } catch (InvalidArgumentException) {
    }
    $value = $connection->fetchOne('SELECT v.value FROM StationMediaCustomField v '
        . "JOIN CustomField f ON f.id = v.field_id WHERE v.media_id = 1 AND f.short_name = 'intro_end'");
    if ($value !== '14') {
        throw new RuntimeException('Invalid cue changed the saved value.');
    }
    echo "Single-field intro updates preserve other custom fields; intro lookup lists only songs with one.\n";
    echo "Media pagination covers all 127 matches, with station-scoped path, title and artist search.\n";
}
