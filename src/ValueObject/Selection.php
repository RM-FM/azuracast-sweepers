<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\ValueObject;

final class Selection
{
    public static function order(array $candidates, float $available, Settings $settings): array
    {
        if ($settings->longestFit && $candidates !== []) {
            $minimum = $available * (1 - $settings->lengthTolerance / 100);
            $pool = array_filter($candidates, static fn(array $row): bool => $row['body'] >= $minimum - 0.000001);
            if ($pool === []) {
                $longest = max(array_column($candidates, 'body'));
                $pool = array_filter($candidates, static fn(array $row): bool => $row['body'] >= $longest - 0.000001);
            }
            $candidates = array_values($pool);
        }
        usort($candidates, static fn(array $a, array $b): int => [
            $a['last_played'], $a['id'],
        ] <=> [$b['last_played'], $b['id']]);

        return $candidates;
    }
}
