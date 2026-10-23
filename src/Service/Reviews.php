<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\Service;

use App\Entity\StationMedia;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Plugin\AzuracastSweepers\ValueObject\Review;

final readonly class Reviews
{
    public function __construct(private EntityManagerInterface $em, private Media $media)
    {
    }

    public function initialize(): void
    {
        $this->em->getConnection()->executeStatement(
            'CREATE TABLE IF NOT EXISTS azuracast_sweeper_review ('
            . 'media_id INT NOT NULL PRIMARY KEY, review JSON NOT NULL) ENGINE=InnoDB'
        );
    }

    public function get(StationMedia $media, array $legacy = []): array
    {
        $connection = $this->em->getConnection();
        if ($legacy !== []) {
            $connection->executeStatement(
                'INSERT IGNORE INTO azuracast_sweeper_review (media_id, review) VALUES (?, ?)',
                [$media->id, json_encode($legacy, JSON_THROW_ON_ERROR)]
            );
        }
        $json = $connection->fetchOne('SELECT review FROM azuracast_sweeper_review WHERE media_id = ?', [$media->id]);

        return $json === false ? [] : json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    public function save(StationMedia $media, array $body, bool $manual, array $legacy = []): void
    {
        $field = $this->media->ensureField();
        $this->em->wrapInTransaction(function () use ($media, $body, $manual, $legacy, $field): void {
            $this->em->refresh($media, LockMode::PESSIMISTIC_WRITE);
            $review = Review::prepare($body, $manual, [
                'identity' => Media::identity($media),
                'unique_id' => $media->unique_id,
                'mtime' => $media->mtime,
                'size' => $media->size,
                'duration' => $media->length,
            ], $this->get($media, $legacy));
            $this->media->saveIntro($media, Review::active($review), $field);
            $this->em->getConnection()->executeStatement(
                'INSERT INTO azuracast_sweeper_review (media_id, review) VALUES (?, ?) '
                . 'ON DUPLICATE KEY UPDATE review = VALUES(review)',
                [$media->id, json_encode($review, JSON_THROW_ON_ERROR)]
            );
        });
    }
}
