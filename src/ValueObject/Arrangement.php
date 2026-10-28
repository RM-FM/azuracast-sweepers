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
        $index = self::target($rows, $gap, $settings, $usable, $blocked);
        if ($index === null) {
            return null;
        }
        $row = $rows[$index];
        $playlists = $settings->runtime()['jingle_playlists'];
        $isJingle = static fn(array $candidate): bool => ($settings->countJingleMode && $candidate['is_jingle'])
            || in_array($candidate['playlist_id'], $playlists, true);
        $count = count($rows);
        for ($next = $index + 1; $next < $count; $next++) {
            $candidate = $rows[$next];
            if (
                $candidate['sent'] || $candidate['media_id'] === null || $isJingle($candidate)
                || ($candidate['excluded'] ?? false) || !$usable((int)$candidate['media_id'])
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
            if (self::nearJingle($order, $index, $settings, $queueLength)) {
                $blocked ??= 'move_jingle';
                continue;
            }
            $blocked = null;

            return [$row['id'], $candidate['id']];
        }
        $blocked ??= 'move_none';

        return null;
    }

    public static function replacement(
        array $rows,
        array $gap,
        Settings $settings,
        int $queueLength,
        callable $usable,
        ?string &$blocked = null
    ): ?array {
        $index = self::target($rows, $gap, $settings, $usable, $blocked);
        if ($index === null) {
            return null;
        }
        $row = $rows[$index];
        if ($row['request_id'] !== null || $row['playlist_id'] === null) {
            return null;
        }
        if (self::nearJingle($rows, $index, $settings, $queueLength)) {
            $blocked = 'move_jingle';

            return null;
        }

        return $row;
    }

    private static function nearJingle(array $rows, int $index, Settings $settings, int $queueLength): bool
    {
        $lookahead = min($settings->songsBeforeJingle, $queueLength);
        $playlists = $settings->runtime()['jingle_playlists'];
        foreach (array_slice($rows, $index + 1, $lookahead) as $row) {
            if (
                ($settings->countJingleMode && $row['is_jingle'])
                || in_array($row['playlist_id'], $playlists, true)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string> Tokens of sent songs whose reservation survives the spacing rules.
     */
    public static function playing(array $rows, array $gap, Settings $settings): array
    {
        $playing = [];
        foreach (self::walk($rows, $gap, $settings) as $step) {
            if ($step['row']['sent'] && $step['allowed'] && $step['row']['planned']) {
                $playing[] = (string)$step['row']['id'];
            }
        }

        return $playing;
    }

    /**
     * @return iterable<int, array{row: array, song: bool, allowed: bool}>
     */
    private static function walk(array $rows, array $gap, Settings $settings): iterable
    {
        $sinceJingle = $gap['songs_since_jingle'];
        $sinceSweeper = $gap['songs_since_sweeper'];
        $previousSong = $gap['previous_song'];
        $previousClear = $gap['previous_clear'];
        $playlists = $settings->runtime()['jingle_playlists'];
        $isJingle = static fn(array $row): bool => ($settings->countJingleMode && $row['is_jingle'])
            || in_array($row['playlist_id'], $playlists, true);

        // Must match sweepers.track_reason in the Liquidsoap module.
        foreach ($rows as $index => $row) {
            $song = $row['media_id'] !== null && !$isJingle($row);
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
            yield $index => ['row' => $row, 'song' => $song, 'allowed' => $allowed];
            // Already sent: its saved plan decides.
            if ($row['sent'] && $allowed && $row['planned']) {
                $sinceSweeper = 0;
                $previousClear = false;
            }
        }
    }

    private static function target(
        array $rows,
        array $gap,
        Settings $settings,
        callable $usable,
        ?string &$blocked
    ): ?int {
        $blocked = null;
        foreach (self::walk($rows, $gap, $settings) as $index => $step) {
            ['row' => $row, 'song' => $song, 'allowed' => $allowed] = $step;
            if ($row['sent']) {
                continue;
            }
            if (
                !$allowed || $row['planned'] || ($row['excluded'] ?? false)
                || $usable((int)$row['media_id'])
            ) {
                return null;
            }
            if (!self::movable($row, $song, $settings)) {
                $blocked = $row['playlist_order'] === 'sequential' ? 'move_sequential' : null;

                return null;
            }
            return $index;
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
