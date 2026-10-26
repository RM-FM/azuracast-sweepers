<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\ValueObject;

final class Selection
{
    /**
     * @return list<int>
     */
    public static function unavailable(array $data, int $gap, int $sweepers): array
    {
        $gap = min($gap, max(0, $sweepers - 1));
        $reserved = array_values(array_column(array_filter(
            $data['plans'] ?? [],
            static fn(array $plan): bool => !($plan['played'] ?? false)
        ), 'jingle_id'));
        $played = array_column(array_filter(
            $data['log'] ?? [],
            static fn(array $entry): bool => ($entry['result'] ?? 'played') === 'played'
        ), 'jingle_id');
        // Reserved sweepers play first, so they count towards the gap.
        $recent = array_slice($played, 0, max(0, $gap - count($reserved)));

        return array_values(array_unique(array_map('intval', [...$reserved, ...$recent])));
    }

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
