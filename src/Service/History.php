<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\Service;

use DateTimeImmutable;
use Exception;
use InvalidArgumentException;

final class History
{
    public const array PAGE_SIZES = [10, 25, 50, 100, 250, 500, 0];

    public static function time(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (strlen($value) > 40) {
            throw new InvalidArgumentException('Invalid history time range.');
        }
        try {
            return (float)(new DateTimeImmutable($value))->format('U.u');
        } catch (Exception) {
            throw new InvalidArgumentException('Invalid history time range.');
        }
    }

    public static function pageSize(string $value): int
    {
        if (!preg_match('/^\d{1,3}$/D', $value) || !in_array((int)$value, self::PAGE_SIZES, true)) {
            throw new InvalidArgumentException('Invalid page size.');
        }

        return (int)$value;
    }

    public static function page(string $value): int
    {
        if (!preg_match('/^[1-9]\d{0,7}$/D', $value)) {
            throw new InvalidArgumentException('Invalid page.');
        }

        return (int)$value;
    }

    /**
     * @param list<array> $log newest first
     * @return list<array>
     */
    public static function select(array $log, ?float $start, ?float $end, bool $playedOnly): array
    {
        $selected = [];
        foreach ($log as $index => $entry) {
            $at = (float)($entry['at'] ?? 0);
            $result = (string)($entry['result'] ?? 'played');
            if (
                ($start !== null && $at < $start) || ($end !== null && $at > $end)
                || ($playedOnly && $result !== 'played')
            ) {
                continue;
            }
            // Only the most recent song can still be waiting for its sweeper.
            $selected[] = ['upcoming' => $index === 0 && $result === 'scheduled'] + $entry;
        }

        return $selected;
    }

    /**
     * @param list<array> $log
     * @return list<int>
     */
    public static function mediaIds(array $log): array
    {
        return [
            ...array_column($log, 'song_id'),
            ...array_column($log, 'jingle_id'),
            ...array_column($log, 'moved_before'),
            ...array_column($log, 'replaced_before'),
        ];
    }

    /**
     * @param list<array> $log
     * @param array<int, array> $files
     * @return list<array>
     */
    public static function rows(array $log, array $files): array
    {
        return array_map(static fn(array $entry): array => [
            'at' => (float)$entry['at'],
            // Live and remote tracks are not in the media library.
            'song' => $files[(int)($entry['song_id'] ?? 0)] ?? (($entry['title'] ?? '') !== '' ? [
                'title' => $entry['title'],
                'artist' => ($entry['artist'] ?? '') !== '' ? $entry['artist'] : null,
                'path' => '',
                'intro' => null,
            ] : null),
            'sweeper' => $files[(int)($entry['jingle_id'] ?? 0)] ?? null,
            'offset' => ($entry['result'] ?? 'played') === 'played' && isset($entry['offset'])
                ? (float)$entry['offset'] : null,
            // Older entries only recorded played sweepers.
            'result' => (string)($entry['result'] ?? 'played'),
            'upcoming' => (bool)($entry['upcoming'] ?? false),
            'moved' => array_key_exists('moved_before', $entry),
            'moved_before' => $files[(int)($entry['moved_before'] ?? 0)] ?? null,
            'replaced' => array_key_exists('replaced_before', $entry),
            'replaced_before' => $files[(int)($entry['replaced_before'] ?? 0)] ?? null,
            'unmoved' => $entry['unmoved'] ?? null,
        ], $log);
    }

    /**
     * @param list<array> $rows
     * @return list<array>
     */
    public static function search(array $rows, string $term): array
    {
        $term = trim($term);
        if ($term === '') {
            return $rows;
        }

        return array_values(array_filter($rows, static function (array $row) use ($term): bool {
            foreach (['song', 'sweeper', 'moved_before', 'replaced_before'] as $key) {
                foreach (['title', 'artist', 'path'] as $field) {
                    if (mb_stripos((string)($row[$key][$field] ?? ''), $term) !== false) {
                        return true;
                    }
                }
            }

            return false;
        }));
    }

    /**
     * @param list<array> $rows
     * @return list<array>
     */
    public static function slice(array $rows, int $page, int $perPage): array
    {
        return $perPage === 0 ? $rows : array_slice($rows, ($page - 1) * $perPage, $perPage);
    }
}
