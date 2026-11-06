<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\ValueObject;

use InvalidArgumentException;

// Groups of sweepers and jingles that count as the same announcement for rotation.
final readonly class Links
{
    public const int MAX_GROUPS = 200;
    public const int MAX_MEMBERS = 50;
    public const int MAX_WINDOW_MINUTES = 1440;

    /** @var array<int, int> Group index by media ID. */
    private array $groupByMedia;

    /** @var array<int, true> Tracks in both a sweeper pool and a jingle playlist, by media ID. */
    private array $shared;

    /**
     * @param list<array{name: string, media_ids: list<int>}> $groups
     * @param list<int> $sharedMediaIds Tracks that are a sweeper and a jingle at once; each is linked with itself.
     */
    public function __construct(public array $groups = [], public int $windowMinutes = 0, array $sharedMediaIds = [])
    {
        $this->shared = array_fill_keys($sharedMediaIds, true);
        if (
            !array_is_list($groups) || count($groups) > self::MAX_GROUPS
            || $windowMinutes < 0 || $windowMinutes > self::MAX_WINDOW_MINUTES
        ) {
            throw new InvalidArgumentException('Invalid linked sweepers and jingles.');
        }
        $groupByMedia = [];
        foreach ($groups as $index => $group) {
            $ids = $group['media_ids'] ?? null;
            if (
                !is_string($group['name'] ?? null) || mb_strlen($group['name']) > 100
                || !is_array($ids) || !array_is_list($ids) || count($ids) < 2 || count($ids) > self::MAX_MEMBERS
            ) {
                throw new InvalidArgumentException('A link group needs a name and 2 to 50 tracks.');
            }
            foreach ($ids as $id) {
                if (!is_int($id) || $id < 1 || isset($groupByMedia[$id])) {
                    throw new InvalidArgumentException('Each track can belong to one link group only.');
                }
                $groupByMedia[$id] = $index;
            }
        }
        $this->groupByMedia = $groupByMedia;
    }

    public static function fromArray(mixed $groups, mixed $windowMinutes): self
    {
        if (!is_array($groups) || !is_int($windowMinutes)) {
            throw new InvalidArgumentException('Invalid linked sweepers and jingles.');
        }

        return new self(array_map(static fn(mixed $group): array => is_array($group) ? [
            'name' => is_string($group['name'] ?? null) ? trim($group['name']) : $group['name'] ?? null,
            'media_ids' => $group['media_ids'] ?? null,
        ] : [], $groups), $windowMinutes);
    }

    /**
     * @param list<int> $mediaIds
     */
    public function withShared(array $mediaIds): self
    {
        return new self($this->groups, $this->windowMinutes, $mediaIds);
    }

    /**
     * @return list<int> Every media ID that belongs to a group.
     */
    public function mediaIds(): array
    {
        return array_keys($this->groupByMedia);
    }

    /**
     * @return list<int> The other tracks in the same group, and the track itself when it is also played in
     *     its other role.
     */
    public function members(int $mediaId): array
    {
        $index = $this->groupByMedia[$mediaId] ?? null;
        $members = $index === null ? [] : array_values(array_filter(
            $this->groups[$index]['media_ids'],
            static fn(int $id): bool => $id !== $mediaId
        ));

        return isset($this->shared[$mediaId]) ? [...$members, $mediaId] : $members;
    }

    public function linked(int $mediaId): bool
    {
        return isset($this->groupByMedia[$mediaId]) || isset($this->shared[$mediaId]);
    }

    /**
     * @param array<int|string, float|int> $history Last play time by media ID.
     */
    public function lastPlayed(array $history, int $mediaId): float
    {
        $last = (float)($history[$mediaId] ?? 0);
        foreach ($this->members($mediaId) as $id) {
            $last = max($last, (float)($history[$id] ?? 0));
        }

        return $last;
    }

    /**
     * @param array<int|string, float|int> $history
     */
    public function heardSince(array $history, int $mediaId, float $since): bool
    {
        foreach ($this->members($mediaId) as $id) {
            if ((float)($history[$id] ?? 0) > $since) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int|string, float|int> $history
     */
    public function recent(array $history, int $mediaId, float $now): bool
    {
        if ($this->windowMinutes === 0) {
            return false;
        }
        foreach ($this->members($mediaId) as $id) {
            $last = (float)($history[$id] ?? 0);
            if ($last > 0 && $now - $last < $this->windowMinutes * 60) {
                return true;
            }
        }

        return false;
    }

    public function toArray(): array
    {
        return ['link_groups' => $this->groups, 'link_window' => $this->windowMinutes];
    }
}
