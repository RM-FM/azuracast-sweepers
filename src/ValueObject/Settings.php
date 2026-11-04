<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\ValueObject;

use InvalidArgumentException;

final readonly class Settings
{
    public array $sweeperRoutes;
    public Links $links;

    public function __construct(
        public bool $enabled = true,
        public array $playlistIds = [],
        public float $maxWait = 10.0,
        public float $margin = 0.5,
        public float $duckDb = -7.0,
        public float $jingleGain = 1.0,
        public int $songsAfterJingle = 1,
        public bool $countJingleMode = true,
        public array $jinglePlaylistIds = [],
        public bool $longestFit = false,
        public float $lengthTolerance = 20.0,
        public int $songsAfterSweeper = 1,
        public int $songsBeforeJingle = 0,
        public float $earlyStart = 2.0,
        public bool $limitDelay = false,
        public bool $swapForIntro = false,
        public bool $reverb = false,
        public float $reverbIntensity = 25.0,
        public bool $swapSequential = false,
        public array $excludedPlaylistIds = [],
        public int $sweeperGap = 1,
        public bool $swapFromPlaylist = false,
        public int $replacementLimit = 2,
        array $sweeperRoutes = [],
        ?Links $links = null
    ) {
        $this->links = $links ?? new Links();
        foreach ([$maxWait, $margin, $duckDb, $jingleGain, $lengthTolerance, $earlyStart, $reverbIntensity] as $value) {
            if (!is_finite($value)) {
                throw new InvalidArgumentException('Settings must be finite.');
            }
        }

        // Enabled without a playlist is valid; nothing plays until one is chosen.
        if (
            $maxWait < 0 || $maxWait > 3600 || $margin < 0 || $margin > 5
            || $duckDb > 0 || $duckDb < -20 || $jingleGain < 0 || $jingleGain > 2
            || $songsAfterJingle < 0 || $songsAfterJingle > 1000 || $songsAfterSweeper < 0
            || $songsAfterSweeper > 1000 || $songsBeforeJingle < 0 || $songsBeforeJingle > 1000
            || $lengthTolerance < 0 || $lengthTolerance > 100 || $earlyStart < 0 || $earlyStart > 60
            || $reverbIntensity < 0 || $reverbIntensity > 100
            || $sweeperGap < 0 || $sweeperGap > 100
            || $replacementLimit < 0 || $replacementLimit > 1000
        ) {
            throw new InvalidArgumentException('Invalid sweeper settings.');
        }
        foreach ([...$playlistIds, ...$jinglePlaylistIds, ...$excludedPlaylistIds] as $id) {
            if (!is_int($id) || $id < 1) {
                throw new InvalidArgumentException('Invalid playlist ID.');
            }
        }
        if ($sweeperRoutes === []) {
            $sweeperRoutes = [new SweeperRoute(SweeperRoute::DEFAULT, $playlistIds)];
        }
        if (!array_is_list($sweeperRoutes)) {
            throw new InvalidArgumentException('Invalid sweeper routes.');
        }
        $routesByKey = [];
        foreach ($sweeperRoutes as $route) {
            if (!$route instanceof SweeperRoute || isset($routesByKey[$route->key])) {
                throw new InvalidArgumentException('Invalid or duplicate sweeper route.');
            }
            $routesByKey[$route->key] = $route;
        }
        if (
            !isset($routesByKey[SweeperRoute::DEFAULT])
            || $routesByKey[SweeperRoute::DEFAULT]->playlistIds !== $playlistIds
        ) {
            throw new InvalidArgumentException('The Default route must match the default playlist pool.');
        }
        $this->sweeperRoutes = $sweeperRoutes;
    }

    public static function fromArray(array $data): self
    {
        $booleans = [];
        $flags = [
            'enabled' => true, 'count_jingle_mode' => true, 'longest_fit' => false, 'swap_for_intro' => false,
            'reverb' => false, 'swap_sequential' => false, 'swap_from_playlist' => false,
        ];
        foreach ($flags as $key => $default) {
            $value = $data[$key] ?? $default;
            if (!is_bool($value)) {
                throw new InvalidArgumentException('Invalid boolean for ' . $key . '.');
            }
            $booleans[$key] = $value;
        }
        // Earlier versions stored a single sweeper playlist.
        $legacyPlaylist = $data['playlist_id'] ?? null;
        $sweeperIds = $data['playlist_ids'] ?? ($legacyPlaylist === null ? [] : [$legacyPlaylist]);
        if (!is_array($sweeperIds) || !array_is_list($sweeperIds)) {
            throw new InvalidArgumentException('Invalid sweeper playlists.');
        }
        $routes = [new SweeperRoute(SweeperRoute::DEFAULT, array_values(array_unique($sweeperIds, SORT_REGULAR)))];
        if (array_key_exists('sweeper_routes', $data)) {
            if (!is_array($data['sweeper_routes']) || !array_is_list($data['sweeper_routes'])) {
                throw new InvalidArgumentException('Invalid sweeper routes.');
            }
            $routes = array_map(SweeperRoute::fromArray(...), $data['sweeper_routes']);
            $default = null;
            foreach ($routes as $route) {
                if ($route->key === SweeperRoute::DEFAULT) {
                    $default = $route;
                    break;
                }
            }
            if ($default === null) {
                throw new InvalidArgumentException('The Default sweeper route is required.');
            }
            if (array_key_exists('playlist_ids', $data) || $legacyPlaylist !== null) {
                $default = new SweeperRoute(
                    SweeperRoute::DEFAULT,
                    array_values(array_unique($sweeperIds, SORT_REGULAR)),
                    $default->preRoll
                );
                $routes = array_map(
                    static fn(SweeperRoute $route): SweeperRoute => $route->key === SweeperRoute::DEFAULT
                        ? $default : $route,
                    $routes
                );
            } else {
                $sweeperIds = $default->playlistIds;
            }
        }
        // Earlier versions stored one shared minimum_songs gap.
        $legacy = $data['minimum_songs'] ?? 1;
        $defaults = [
            'songs_after_jingle' => $legacy, 'songs_after_sweeper' => $legacy, 'songs_before_jingle' => 0,
            'sweeper_gap' => 1,
        ];
        $gaps = [];
        foreach ($defaults as $key => $default) {
            $gaps[$key] = $data[$key] ?? $default;
            if (!is_int($gaps[$key])) {
                throw new InvalidArgumentException('Invalid song spacing.');
            }
        }
        $playlistIds = $data['jingle_playlist_ids'] ?? [];
        if (!is_array($playlistIds) || !array_is_list($playlistIds)) {
            throw new InvalidArgumentException('Invalid song spacing or jingle playlists.');
        }
        $excludedIds = $data['excluded_playlist_ids'] ?? [];
        if (!is_array($excludedIds) || !array_is_list($excludedIds)) {
            throw new InvalidArgumentException('Invalid playlists without sweepers.');
        }
        $replacementLimit = $data['replacement_limit'] ?? 2;
        if (!is_int($replacementLimit)) {
            throw new InvalidArgumentException('Invalid replacement limit.');
        }

        // Earlier versions stored a disabled delay limit as max_wait null and an enabled one as a number.
        $limitDelay = $data['limit_delay'] ?? (array_key_exists('max_wait', $data) && $data['max_wait'] !== null);
        if (!is_bool($limitDelay)) {
            throw new InvalidArgumentException('Invalid boolean for limit_delay.');
        }

        $values = [];
        $defaults = [
            'max_wait' => 10.0,
            'margin' => 0.5,
            'duck_db' => -7.0,
            'jingle_gain' => 1.0,
            'length_tolerance' => 20.0,
            'early_start' => 2.0,
            'reverb_intensity' => 25.0,
        ];
        foreach ($defaults as $key => $default) {
            $value = $data[$key] ?? $default;
            if (!is_int($value) && !is_float($value)) {
                throw new InvalidArgumentException('Invalid value for ' . $key . '.');
            }
            $values[$key] = (float)$value;
        }

        return new self(
            enabled: $booleans['enabled'],
            playlistIds: array_values(array_unique($sweeperIds, SORT_REGULAR)),
            maxWait: $values['max_wait'],
            margin: $values['margin'],
            duckDb: $values['duck_db'],
            jingleGain: $values['jingle_gain'],
            songsAfterJingle: $gaps['songs_after_jingle'],
            countJingleMode: $booleans['count_jingle_mode'],
            jinglePlaylistIds: $playlistIds,
            longestFit: $booleans['longest_fit'],
            lengthTolerance: $values['length_tolerance'],
            songsAfterSweeper: $gaps['songs_after_sweeper'],
            songsBeforeJingle: $gaps['songs_before_jingle'],
            earlyStart: $values['early_start'],
            limitDelay: $limitDelay,
            swapForIntro: $booleans['swap_for_intro'],
            reverb: $booleans['reverb'],
            reverbIntensity: $values['reverb_intensity'],
            swapSequential: $booleans['swap_sequential'],
            excludedPlaylistIds: $excludedIds,
            sweeperGap: $gaps['sweeper_gap'],
            swapFromPlaylist: $booleans['swap_from_playlist'],
            replacementLimit: $replacementLimit,
            sweeperRoutes: $routes,
            links: Links::fromArray($data['link_groups'] ?? [], $data['link_window'] ?? 0)
        );
    }

    public function revision(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_THROW_ON_ERROR));
    }

    public function runtime(): array
    {
        return [
            'enabled' => $this->enabled,
            'revision' => $this->revision(),
            'songs_after_jingle' => $this->songsAfterJingle,
            'songs_after_sweeper' => $this->songsAfterSweeper,
            'count_jingle_mode' => $this->countJingleMode,
            'jingle_playlists' => array_values(array_unique([
                ...$this->allSweeperPlaylistIds(), ...$this->jinglePlaylistIds,
            ])),
        ];
    }

    public function allSweeperPlaylistIds(): array
    {
        return array_values(array_unique(array_merge(
            ...array_map(static fn(SweeperRoute $route): array => $route->playlistIds, $this->sweeperRoutes)
        )));
    }

    public function route(string $key): ?SweeperRoute
    {
        foreach ($this->sweeperRoutes as $route) {
            if ($route->key === $key) {
                return $route;
            }
        }

        return null;
    }

    public function routeFor(?int $playlistId, bool $listenerRequest): SweeperRoute
    {
        $routesByKey = [];
        foreach ($this->sweeperRoutes as $route) {
            $routesByKey[$route->key] = $route;
        }
        if (
            $listenerRequest && isset($routesByKey[SweeperRoute::LISTENER_REQUEST])
            && $routesByKey[SweeperRoute::LISTENER_REQUEST]->playlistIds !== []
        ) {
            return $routesByKey[SweeperRoute::LISTENER_REQUEST];
        }
        if ($playlistId !== null && isset($routesByKey['playlist:' . $playlistId])) {
            return $routesByKey['playlist:' . $playlistId];
        }

        return $routesByKey[SweeperRoute::DEFAULT];
    }

    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'playlist_ids' => $this->playlistIds,
            'sweeper_routes' => array_map(
                static fn(SweeperRoute $route): array => $route->toArray(),
                $this->sweeperRoutes
            ),
            'limit_delay' => $this->limitDelay,
            'max_wait' => $this->maxWait,
            'margin' => $this->margin,
            'duck_db' => $this->duckDb,
            'jingle_gain' => $this->jingleGain,
            'songs_after_jingle' => $this->songsAfterJingle,
            'songs_after_sweeper' => $this->songsAfterSweeper,
            'songs_before_jingle' => $this->songsBeforeJingle,
            'count_jingle_mode' => $this->countJingleMode,
            'jingle_playlist_ids' => $this->jinglePlaylistIds,
            'longest_fit' => $this->longestFit,
            'length_tolerance' => $this->lengthTolerance,
            'early_start' => $this->earlyStart,
            'swap_for_intro' => $this->swapForIntro,
            'swap_from_playlist' => $this->swapFromPlaylist,
            'replacement_limit' => $this->replacementLimit,
            'reverb' => $this->reverb,
            'reverb_intensity' => $this->reverbIntensity,
            'swap_sequential' => $this->swapSequential,
            'excluded_playlist_ids' => $this->excludedPlaylistIds,
            'sweeper_gap' => $this->sweeperGap,
            ...$this->links->toArray(),
        ];
    }

    public function reverbLevel(): float
    {
        return $this->reverb && $this->reverbIntensity > 0 ? $this->reverbIntensity / 100 : 0.0;
    }
}
