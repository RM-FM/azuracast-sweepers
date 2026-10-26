<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\Service;

use Plugin\AzuracastSweepers\ValueObject\Settings;

final class Module
{
    public const string AUTODJ = 'radio = azuracast.enable_autodj(radio)';
    public const string CROSSFADE = 'radio = azuracast.apply_crossfade(radio)';
    public const string WRAPPED_AUTODJ = 'radio = sweepers.enable_autodj(radio)';
    public const string WRAPPED_CROSSFADE = 'radio = sweepers.mix(azuracast.apply_crossfade(radio))';
    public const string UNLOADED = '# AzuraCast Sweepers not loaded; see the Sweepers page.';
    private const string LOAD = 'sweepers.load(';
    // Heartbeats are recorded at most every 30 s and sent every 5 s.
    private const int ACTIVE_SECONDS = 90;

    public static function includeLine(): string
    {
        $path = dirname(__DIR__, 2) . '/liquidsoap/sweepers.liq';

        return '%include ' . json_encode($path, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function loadLine(Settings $settings): string
    {
        $runtime = $settings->runtime();

        return self::LOAD . 'version="' . self::version() . '", {'
            . 'enabled=' . ($settings->enabled ? 'true' : 'false')
            . ', revision="' . $runtime['revision'] . '"'
            . ', songs_after_jingle=' . $settings->songsAfterJingle
            . ', songs_after_sweeper=' . $settings->songsAfterSweeper
            . ', count_jingle_mode=' . ($settings->countJingleMode ? 'true' : 'false')
            . ', jingle_playlists=' . json_encode($runtime['jingle_playlists'], JSON_THROW_ON_ERROR)
            . '})';
    }

    public static function version(): string
    {
        $code = '';
        foreach (['sweepers.liq', 'sidechain.liq'] as $file) {
            $code .= (string)@file_get_contents(dirname(__DIR__, 2) . '/liquidsoap/' . $file);
        }

        return substr(hash('sha256', $code), 0, 12);
    }

    /**
     * @param list<string> $lines
     * @return array<int, string>|null Replacement lines by index; null when nothing changed.
     */
    public static function rollback(array $lines): ?array
    {
        $changes = [];
        foreach ($lines as $index => $line) {
            $original = match (true) {
                $line === self::includeLine(), str_starts_with($line, self::LOAD) => self::UNLOADED,
                $line === self::WRAPPED_AUTODJ => self::AUTODJ,
                $line === self::WRAPPED_CROSSFADE => self::CROSSFADE,
                default => null,
            };
            if ($original !== null) {
                $changes[$index] = $original;
            }
        }

        return $changes === [] ? null : $changes;
    }

    /**
     * @return array{state: string, message: string}
     */
    public static function status(array $data, ?int $now = null): array
    {
        $now ??= time();
        $heartbeat = $data['heartbeat'] ?? null;
        $module = $data['module'] ?? null;
        $recent = is_array($heartbeat) && $now - (int)($heartbeat['at'] ?? 0) <= self::ACTIVE_SECONDS;
        if ($recent && ($heartbeat['version'] ?? null) === self::version()) {
            return ['state' => 'active', 'message' => 'The sweeper module is running on this station.'];
        }
        if ($recent) {
            return [
                'state' => 'outdated',
                'message' => 'This station still runs an earlier plugin version. '
                    . 'Restart broadcasting once to load the update.',
            ];
        }
        if (is_array($module) && !($module['loaded'] ?? false)) {
            return [
                'state' => 'unavailable',
                'message' => 'The station started without sweepers: ' . ($module['reason'] ?? 'unknown reason') . '.',
            ];
        }

        return [
            'state' => 'restart',
            'message' => 'The sweeper module is not running. Restart broadcasting once after installing or '
                . 'updating the plugin, or start the station if it is offline.',
        ];
    }
}
