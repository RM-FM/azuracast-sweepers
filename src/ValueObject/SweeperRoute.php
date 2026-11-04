<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\ValueObject;

use InvalidArgumentException;

final readonly class SweeperRoute
{
    public const string DEFAULT = 'default';
    public const string LISTENER_REQUEST = 'listener_request';

    // Whether the station-wide song spacing applies to this rule's sweepers.
    public bool $followSpacing;
    // Whether this rule's sweepers count as station sweepers for that spacing.
    public bool $countsForSpacing;

    public function __construct(
        public string $key,
        public array $playlistIds = [],
        public bool $preRoll = false,
        public int $cooldownSongs = 0,
        public int $cooldownMinutes = 0,
        ?bool $followSpacing = null,
        ?bool $countsForSpacing = null
    ) {
        if (!array_is_list($playlistIds)) {
            throw new InvalidArgumentException('Invalid route playlist IDs.');
        }
        foreach ($playlistIds as $playlistId) {
            if (!is_int($playlistId) || $playlistId < 1) {
                throw new InvalidArgumentException('Invalid route playlist ID.');
            }
        }
        $this->followSpacing = $followSpacing ?? $key !== self::LISTENER_REQUEST;
        $this->countsForSpacing = $countsForSpacing ?? $key !== self::LISTENER_REQUEST;
        if (
            !self::validKey($key)
            || $cooldownSongs < 0 || $cooldownSongs > 1000
            || $cooldownMinutes < 0 || $cooldownMinutes > 10080
            || ($key === self::DEFAULT && (
                $cooldownSongs !== 0 || $cooldownMinutes !== 0 || !$this->followSpacing || !$this->countsForSpacing
            ))
        ) {
            throw new InvalidArgumentException('Invalid sweeper route.');
        }
    }

    public static function fromArray(array $data): self
    {
        $key = $data['key'] ?? null;
        $playlistIds = $data['playlist_ids'] ?? [];
        $preRoll = $data['pre_roll'] ?? false;
        $cooldownSongs = $data['cooldown_songs'] ?? 0;
        $cooldownMinutes = $data['cooldown_minutes'] ?? 0;
        $followSpacing = $data['follow_spacing'] ?? null;
        $countsForSpacing = $data['counts_for_spacing'] ?? null;

        if (
            !is_string($key) || !is_array($playlistIds) || !array_is_list($playlistIds)
            || !is_bool($preRoll)
            || !is_int($cooldownSongs) || !is_int($cooldownMinutes)
            || !is_bool($followSpacing ?? false) || !is_bool($countsForSpacing ?? false)
        ) {
            throw new InvalidArgumentException('Invalid sweeper route.');
        }

        return new self(
            $key,
            $playlistIds,
            $preRoll,
            $cooldownSongs,
            $cooldownMinutes,
            $followSpacing,
            $countsForSpacing
        );
    }

    public function cooldownAllows(int $completedSongs, int $elapsedSeconds): bool
    {
        if ($completedSongs < 0 || $elapsedSeconds < 0) {
            throw new InvalidArgumentException('Invalid cooldown elapsed time.');
        }
        if ($this->cooldownSongs === 0 && $this->cooldownMinutes === 0) {
            return true;
        }

        return ($this->cooldownSongs > 0 && $completedSongs >= $this->cooldownSongs)
            || ($this->cooldownMinutes > 0 && $elapsedSeconds >= $this->cooldownMinutes * 60);
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'playlist_ids' => $this->playlistIds,
            'pre_roll' => $this->preRoll,
            'cooldown_songs' => $this->cooldownSongs,
            'cooldown_minutes' => $this->cooldownMinutes,
            'follow_spacing' => $this->followSpacing,
            'counts_for_spacing' => $this->countsForSpacing,
        ];
    }

    private static function validKey(string $key): bool
    {
        return in_array($key, [self::DEFAULT, self::LISTENER_REQUEST], true)
            || preg_match('/^playlist:[1-9]\d{0,9}$/D', $key) === 1;
    }
}
