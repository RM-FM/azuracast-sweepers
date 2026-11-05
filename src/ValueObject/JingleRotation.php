<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\ValueObject;

// Ranks a jingle playlist's entries so the least recently heard link group plays next.
final readonly class JingleRotation
{
    public const int FREE = 0;
    public const int HELD = 1;
    public const int REPEAT = 2;

    /**
     * @param array<int|string, float|int> $history Last time each linked track was heard, by media ID.
     * @param list<int> $reserved Sweepers planned but not yet heard.
     * @param ?int $previous The jingle this playlist sent last.
     * @param ?float $previousAt When that jingle played.
     */
    public function __construct(
        private Links $links,
        private array $history,
        private array $reserved,
        private ?int $previous,
        private ?float $previousAt,
        private float $now,
        private bool $random
    ) {
    }

    /**
     * A lower key plays first. Unlinked entries keep AzuraCast's own order among equals, so the entry
     * AzuraCast chose is ranked with $chosen and only gives way to a better one.
     *
     * @param ?int $lastPlayed The entry's last play in this playlist; unknown for the entry AzuraCast chose.
     * @return array{int, int, float}
     */
    public function key(int $mediaId, bool $inRound, ?int $lastPlayed, bool $chosen = false): array
    {
        $heard = match (true) {
            $this->random => 0.0,
            $this->links->linked($mediaId) => $this->links->lastPlayed($this->history, $mediaId),
            $chosen || $lastPlayed === null => -INF,
            default => (float)$lastPlayed,
        };

        return [$inRound ? 0 : 1, $this->tier($mediaId), $heard];
    }

    // Held: a linked track was heard since this playlist's last jingle, is planned, or is within the window.
    public function tier(int $mediaId): int
    {
        if ($mediaId === $this->previous) {
            return self::REPEAT;
        }
        foreach ($this->links->members($mediaId) as $id) {
            if (
                in_array($id, $this->reserved, true)
                || ($this->previousAt !== null && (float)($this->history[$id] ?? 0) > $this->previousAt)
            ) {
                return self::HELD;
            }
        }

        return $this->links->recent($this->history, $mediaId, $this->now) ? self::HELD : self::FREE;
    }
}
