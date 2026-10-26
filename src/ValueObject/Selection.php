<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\ValueObject;

final class Selection
{
    /**
     * Sweepers that cannot be chosen now: those reserved for upcoming songs and the last $gap that played.
     * Reserved sweepers play before this one, so they count towards the gap first.
     *
     * @return list<int>
     */
    public static function unavailable(array $data, int $gap): array
    {
        $reserved = array_values(array_column(array_filter(
            $data['plans'] ?? [],
            static fn(array $plan): bool => !($plan['played'] ?? false)
        ), 'jingle_id'));
        $recent = array_slice(array_column($data['log'] ?? [], 'jingle_id'), 0, max(0, $gap - count($reserved)));

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
