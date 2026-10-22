<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\ValueObject;

use InvalidArgumentException;

final readonly class Settings
{
    public function __construct(
        public bool $enabled = false,
        public ?int $playlistId = null,
        public float $maxWait = 5.0,
        public float $margin = 0.8,
        public float $duckDb = -7.0,
        public float $jingleGain = 1.0
    ) {
        foreach ([$maxWait, $margin, $duckDb, $jingleGain] as $value) {
            if (!is_finite($value)) {
                throw new InvalidArgumentException('Settings must be finite.');
            }
        }

        if (
            $maxWait < 0 || $maxWait > 30 || $margin < 0 || $margin > 5
            || $duckDb > 0 || $duckDb < -20 || $jingleGain < 0 || $jingleGain > 2
            || ($enabled && ($playlistId === null || $playlistId < 1))
        ) {
            throw new InvalidArgumentException('Invalid sweeper settings.');
        }
    }

    public static function fromArray(array $data): self
    {
        $enabled = $data['enabled'] ?? false;
        if (!is_bool($enabled)) {
            throw new InvalidArgumentException('Enabled must be a boolean.');
        }

        $playlistId = $data['playlist_id'] ?? null;
        if ($playlistId !== null && (!is_int($playlistId) || $playlistId < 1)) {
            throw new InvalidArgumentException('Invalid playlist ID.');
        }

        $values = [];
        foreach (['max_wait' => 5.0, 'margin' => 0.8, 'duck_db' => -7.0, 'jingle_gain' => 1.0] as $key => $default) {
            $value = $data[$key] ?? $default;
            if (!is_int($value) && !is_float($value)) {
                throw new InvalidArgumentException('Invalid value for ' . $key . '.');
            }
            $values[] = (float)$value;
        }

        return new self($enabled, $playlistId, ...$values);
    }

    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'playlist_id' => $this->playlistId,
            'max_wait' => $this->maxWait,
            'margin' => $this->margin,
            'duck_db' => $this->duckDb,
            'jingle_gain' => $this->jingleGain,
        ];
    }
}
