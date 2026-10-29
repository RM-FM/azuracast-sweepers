<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\Service;

use App\Entity\Station;
use InvalidArgumentException;
use Plugin\AzuracastSweepers\ValueObject\Arrangement;
use Plugin\AzuracastSweepers\ValueObject\Selection;
use Plugin\AzuracastSweepers\ValueObject\Settings;
use Plugin\AzuracastSweepers\ValueObject\Timing;

final readonly class Arranger
{
    public const float DEFAULT_BUDGET_SECONDS = 0.5;

    public function __construct(
        private State $state,
        private Media $media,
        private Queue $queue,
        private Schedule $schedule,
        private PlaylistReplacement $replacement
    ) {
    }

    /**
     * @param array<int, Timing> $measurements
     * @return array{moved: string, before: string, replaced: string, released?: list<string>}
     */
    public function arrange(
        Station $station,
        array $gap,
        string $counted,
        array $measurements,
        float $budgetSeconds = self::DEFAULT_BUDGET_SECONDS,
        bool $releaseSpacing = false
    ): array {
        $deadline = hrtime(true) + (int)($budgetSeconds * 1_000_000_000);
        $none = ['moved' => '', 'before' => '', 'replaced' => ''];
        $data = $this->state->read($station->getRadioConfigDir());
        $settings = Settings::fromArray($data['settings'] ?? []);
        if (
            !$settings->enabled || (!$settings->swapForIntro && !$settings->swapFromPlaylist)
            || $measurements === []
        ) {
            return $none;
        }
        $active = $this->media->jingles($station, $this->schedule->active($station, $settings->playlistIds));
        if ($active === []) {
            return $none;
        }
        $rows = $this->queue->upcoming($station, $counted);
        if ($rows === null) {
            return $none;
        }
        $plans = array_filter($data['plans'] ?? [], static fn(array $plan): bool => !($plan['played'] ?? false));
        $excludedNames = $this->queue->excludedNames($station, $settings);
        foreach ($rows as &$row) {
            $row['planned'] = isset($plans[(string)$row['id']]);
            $row['replacement_limited'] = $row['media_id'] !== null && $settings->replacementLimit > 0
                && ($data['replacement_counts'][$row['media_id']] ?? 0) >= $settings->replacementLimit;
            $row['excluded'] = Queue::isExcluded(
                $row['playlist_id'],
                $row['playlist_chain'],
                $settings->excludedPlaylistIds,
                $excludedNames
            );
        }
        unset($row);
        // Reservations the module will drop for spacing must not relax the repeat protection.
        $playing = Arrangement::playing($rows, $gap, $settings);
        if (isset($plans[$counted])) {
            if (
                !$releaseSpacing || ($gap['previous_song'] && (!$gap['previous_clear']
                    || ($gap['songs_since_jingle'] >= $settings->songsAfterJingle
                        && $gap['songs_since_sweeper'] >= $settings->songsAfterSweeper)))
            ) {
                $playing[] = $counted;
            }
        }
        if ($releaseSpacing) {
            $sent = array_column(array_filter($rows, static fn(array $row): bool => $row['sent']), 'id');
            $sent[] = $counted;
            $released = array_values(array_map('strval', array_diff(
                array_keys(array_intersect_key($plans, array_flip($sent))),
                $playing
            )));
            if ($released !== []) {
                $none['released'] = $released;
                $data['plans'] = array_diff_key($data['plans'] ?? [], array_flip($released));
            }
        }
        $timings = array_diff_key(
            array_intersect_key($measurements, array_flip(array_map(static fn($jingle): int => $jingle->id, $active))),
            array_flip(Selection::unavailable($data, $settings->sweeperGap, count($active), $playing))
        );

        $known = [];
        $usable = function (int $mediaId) use ($station, $settings, $timings, &$known): bool {
            return $known[$mediaId] ??= $this->usable($station, $mediaId, $settings, $timings);
        };
        $blocked = null;
        $choice = $settings->swapForIntro
            ? Arrangement::choose($rows, $gap, $settings, Queue::length($station), $usable, $blocked)
            : null;
        if ($choice !== null) {
            if (!$this->queue->exchange($station, $choice[0], $choice[1])) {
                return $none;
            }
            $media = array_column($rows, 'media_id', 'id');
            $this->remember($station, 'moved', (string)$choice[1], ['before' => $media[$choice[0]] ?? null]);

            return [...$none, 'moved' => (string)$choice[1], 'before' => (string)$choice[0]];
        }
        if ($settings->swapFromPlaylist) {
            $replacementReason = null;
            $target = Arrangement::replacement(
                $rows,
                $gap,
                $settings,
                Queue::length($station),
                $usable,
                $replacementReason
            );
            if ($target !== null) {
                $mediaId = $this->replacement->replace(
                    $station,
                    $target,
                    $counted,
                    $settings,
                    $usable,
                    $replacementReason,
                    $deadline
                );
                if ($mediaId !== null) {
                    $this->remember($station, 'moved', (string)$target['id'], [
                        'before' => $target['media_id'], 'replacement' => true,
                    ]);

                    return [...$none, 'replaced' => (string)$target['id']];
                }
            }
            $blocked = $replacementReason ?? $blocked;
        }
        if ($blocked !== null) {
            $next = current(array_filter($rows, static fn(array $row): bool => !$row['sent']));
            $this->remember($station, 'unmoved', (string)$next['id'], ['reason' => $blocked]);
        }
        return $none;
    }

    private function remember(Station $station, string $key, string $token, array $entry): void
    {
        $this->state->update($station->getRadioConfigDir(), static function (array $data) use ($key, $token, $entry) {
            $entries = array_filter(
                $data[$key] ?? [],
                static fn(array $old): bool => $old['at'] > time() - 7200
            );
            $entries[$token] = ['at' => time(), ...$entry];
            $data[$key] = array_slice($entries, -50, preserve_keys: true);
            if ($key === 'moved') {
                unset($data['unmoved'][$token], $data['decisions'][$token]);
                if ($entry['replacement'] ?? false) {
                    $mediaId = $entry['before'];
                    $data['replacement_counts'][$mediaId] = min(
                        1000,
                        ($data['replacement_counts'][$mediaId] ?? 0) + 1
                    );
                }
            }

            return $data;
        });
    }

    /**
     * @param array<int, Timing> $timings
     */
    private function usable(Station $station, int $mediaId, Settings $settings, array $timings): bool
    {
        try {
            $media = $this->media->get($station, $mediaId);
        } catch (InvalidArgumentException) {
            return false;
        }
        $intro = $this->media->intro($media);
        if ($intro === null) {
            return false;
        }
        [$cueIn, $cueOut] = self::window($this->media->uri($station, $media), $media->length);
        if ($intro <= $cueIn) {
            return false;
        }
        foreach ($timings as $timing) {
            if ($timing->fit($intro, $cueIn, $cueOut, $settings) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: float, 1: float} Cue-in and end of the planning window, as computed by the module.
     */
    public static function window(string $uri, float $duration): array
    {
        $value = static function (array $keys) use ($uri): ?float {
            foreach ($keys as $key) {
                if (preg_match('/(?:^|[:,])' . $key . '="(-?[0-9.]+)"/', $uri, $match)) {
                    return (float)$match[1];
                }
            }

            return null;
        };
        $cueIn = $value(['liq_cue_in', 'autocue_cue_in']) ?? 0.0;
        $cueOut = $value(['liq_cue_out', 'autocue_cue_out']) ?? $duration;
        $transition = $value(['liq_cross_start_next', 'autocue_start_next']) ?? $cueOut;

        return [$cueIn, $transition > $cueIn ? min($cueOut, $transition) : $cueOut];
    }
}
