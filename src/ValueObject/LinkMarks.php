<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\ValueObject;

// Which linked jingles AzuraCast's rotation must hold back for sweepers that are reserved or have played.
final class LinkMarks
{
    // Played sweepers older than this no longer change the rotation; AzuraCast has moved on.
    public const int PLAYED_SECONDS = 3600;

    /**
     * $data['link_marks'] holds applied reservation marks by jingle media ID: the reserving token, the
     * played time written and the previous values of each jingle playlist entry.
     *
     * @return array{
     *     reserve: array<int, string>,
     *     finalize: list<array{token: string, media_ids: list<int>, at: int}>,
     *     restore: list<int>,
     *     drop: list<int>
     * } reserve maps jingle media IDs to the reserving token; restore lists marked jingles to put back;
     *     drop lists marks of finalised sweepers that only need to leave the state.
     */
    public static function plan(array $data, Links $links, bool $enabled, int $now): array
    {
        $pending = [];
        foreach ($enabled ? $data['plans'] ?? [] : [] as $token => $plan) {
            if (
                !($plan['played'] ?? false) && ($plan['expires_at'] ?? 0) > $now
                && is_int($plan['jingle_id'] ?? null)
            ) {
                $pending[(string)$token] = $plan['jingle_id'];
            }
        }
        $finalised = $data['link_finalised'] ?? [];
        $played = [];
        $finalize = [];
        foreach ($data['log'] ?? [] as $entry) {
            $token = (string)($entry['token'] ?? '');
            $at = (int)($entry['sweeper_started_at'] ?? $entry['at'] ?? 0);
            if (
                $token === '' || ($entry['result'] ?? null) !== 'played' || !is_int($entry['jingle_id'] ?? null)
                || $at < $now - self::PLAYED_SECONDS
            ) {
                continue;
            }
            $played[$token] = true;
            $members = $links->members($entry['jingle_id']);
            if ($members !== [] && !isset($finalised[$token])) {
                $finalize[] = ['token' => $token, 'media_ids' => $members, 'at' => $at];
            }
        }

        $applied = $data['link_marks'] ?? [];
        $restore = [];
        $drop = [];
        foreach ($applied as $mediaId => $mark) {
            $token = (string)($mark['token'] ?? '');
            $stillReserved = isset($pending[$token])
                && in_array((int)$mediaId, $links->members($pending[$token]), true);
            if (isset($played[$token]) && isset($finalised[$token])) {
                $drop[] = (int)$mediaId;
            } elseif (!$stillReserved && !isset($played[$token])) {
                // A played sweeper's mark becomes final through $finalize, never a restore.
                $restore[] = (int)$mediaId;
            }
        }
        $reserve = [];
        foreach ($pending as $token => $sweeper) {
            foreach ($links->members($sweeper) as $mediaId) {
                if (!isset($applied[$mediaId]) || in_array($mediaId, $restore, true)) {
                    // PHP turns numeric token keys into integers.
                    $reserve[$mediaId] ??= (string)$token;
                }
            }
        }
        $restore = array_values(array_filter($restore, static fn(int $id): bool => !isset($reserve[$id])));

        return ['reserve' => $reserve, 'finalize' => $finalize, 'restore' => $restore, 'drop' => $drop];
    }

    public static function empty(array $plan): bool
    {
        return $plan['reserve'] === [] && $plan['finalize'] === [] && $plan['restore'] === [] && $plan['drop'] === [];
    }
}
