<?php

declare(strict_types=1);

namespace App\Entity {
    use Doctrine\ORM\Mapping as ORM;

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
}
