<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\ValueObject;

final class Arrangement
{
    /**
     * @param list<array{id: int, sent: bool, media_id: ?int, request_id: ?int, playlist_id: ?int,
     *     is_jingle: bool, playlist_order: ?string, planned: bool, excluded?: bool}> $rows
     *     In AzuraCast's play order.
     * @param array{songs_since_jingle: int, songs_since_sweeper: int, previous_song: bool, previous_clear: bool} $gap
     * @param callable(int): bool $usable
     * @param ?string $blocked Set when the next song needs a swap that is impossible: move_sequential,
     *     move_jingle or move_none.
     * @return array{0: int, 1: int}|null
     */
    public static function choose(
        array $rows,
        array $gap,
        Settings $settings,
        int $queueLength,
        callable $usable,
        ?string &$blocked = null
    ): ?array {
        $blocked = null;
        $sinceJingle = $gap['songs_since_jingle'];
        $sinceSweeper = $gap['songs_since_sweeper'];
        $previousSong = $gap['previous_song'];
        $previousClear = $gap['previous_clear'];
        $playlists = $settings->runtime()['jingle_playlists'];
        $isJingle = static fn(array $row): bool => ($settings->countJingleMode && $row['is_jingle'])
            || in_array($row['playlist_id'], $playlists, true);
        $isSong = static fn(array $row): bool => $row['media_id'] !== null && !$isJingle($row);

        // Must match sweepers.track_reason in the Liquidsoap module.
        foreach ($rows as $index => $row) {
            $song = $isSong($row);
            if ($isJingle($row)) {
                $sinceJingle = 0;
            } elseif (!$song) {
                $sinceJingle = 0;
                $sinceSweeper = 0;
            } else {
                $sinceJingle += $previousSong ? 1 : 0;
                $sinceSweeper += $previousClear ? 1 : 0;
            }
            $previousSong = $song;
            $previousClear = $song;
            $allowed = $song && $sinceJingle >= $settings->songsAfterJingle
                && $sinceSweeper >= $settings->songsAfterSweeper;

            if ($row['sent']) {
                // Already sent: its saved plan decides.
                if ($allowed && $row['planned']) {
                    $sinceSweeper = 0;
                    $previousClear = false;
                }
                continue;
            }

            if (!$allowed || ($row['excluded'] ?? false) || $usable((int)$row['media_id'])) {
                return null;
            }
            if (!self::movable($row, $song, $settings)) {
                $blocked = $row['playlist_order'] === 'sequential' ? 'move_sequential' : null;

                return null;
            }
            $count = count($rows);
            for ($next = $index + 1; $next < $count; $next++) {
                $candidate = $rows[$next];
                if (
                    $candidate['sent'] || !$isSong($candidate) || ($candidate['excluded'] ?? false)
                    || !$usable((int)$candidate['media_id'])
                ) {
                    continue;
                }
                if (!self::movable($candidate, true, $settings)) {
                    if ($candidate['playlist_order'] === 'sequential') {
                        $blocked ??= 'move_sequential';
                    }
                    continue;
                }
                $order = $rows;
                $order[$index] = $candidate;
                $order[$next] = $row;
                $lookahead = min($settings->songsBeforeJingle, $queueLength);
                $following = $lookahead > 0 ? array_slice($order, $index + 1, $lookahead) : [];
                if (array_filter($following, $isJingle) !== []) {
                    $blocked ??= 'move_jingle';
                    continue;
                }
                $blocked = null;

                return [$row['id'], $candidate['id']];
            }
            $blocked ??= 'move_none';

            return null;
        }

        return null;
    }

    private static function movable(array $row, bool $song, Settings $settings): bool
    {
        if (!$song || ($row['excluded'] ?? false)) {
            return false;
        }
        if ($row['request_id'] !== null) {
            return true;
        }

        return $row['playlist_id'] !== null
            && ($settings->swapSequential || $row['playlist_order'] !== 'sequential');
    }
}
