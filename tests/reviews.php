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
    use Plugin\AzuracastSweepers\Service\Reviews;
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
    $connection->executeStatement('DROP TABLE IF EXISTS azuracast_sweeper_review');
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
    $reviews = new Reviews($em, $mediaService);
    $reviews->initialize();
    $recording = new StationMedia();
    $recording->id = 1;
    $em->persist($recording);
    $em->flush();
    $identity = Media::identity($recording);
    $input = [
        'identity' => $identity, 'unique_id' => 'recording', 'mtime' => 123, 'size' => 456,
        'status' => 'confirmed', 'kind' => 'vocal', 'intro_end' => 21.0,
    ];
    $reviews->save($recording, $input, true);
    $fieldValue = static fn(): ?string => $connection->fetchOne('SELECT value FROM StationMediaCustomField WHERE media_id = 1');
    if ($fieldValue() !== '21' || $reviews->get($recording)['manual'] !== true) {
        throw new RuntimeException('Manual cue or lock was not saved.');
    }

    $connection->executeStatement('CREATE TRIGGER reject_sweeper_review BEFORE UPDATE ON azuracast_sweeper_review '
        . "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected write failure'");
    try {
        $reviews->save($recording, [...$input, 'intro_end' => 25.0], true);
        throw new RuntimeException('Expected the review write to fail.');
    } catch (\Doctrine\DBAL\Exception\DriverException) {
    }
    if ($fieldValue() !== '21' || (float)$reviews->get($recording)['candidate'] !== 21.0) {
        throw new RuntimeException('Cue or review changed after a failed transaction.');
    }
    $connection->executeStatement('DROP TRIGGER reject_sweeper_review');
    echo "Transactional cue and review save passed.\n";
}
