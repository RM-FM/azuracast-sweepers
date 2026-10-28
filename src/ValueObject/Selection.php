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
        $minimum = $available * (1 - $settings->lengthTolerance / 100);
        usort($candidates, static fn(array $a, array $b): int => [
            $settings->longestFit && $a['body'] < $minimum - 0.000001 ? 1 : 0,
            $a['last_played'], $a['id'],
        ] <=> [
            $settings->longestFit && $b['body'] < $minimum - 0.000001 ? 1 : 0,
            $b['last_played'], $b['id'],
        ]);

        return $candidates;
    }
}
