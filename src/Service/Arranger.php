<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\Service;

use App\Entity\Station;
use InvalidArgumentException;
use Plugin\AzuracastSweepers\ValueObject\Arrangement;
use Plugin\AzuracastSweepers\ValueObject\Selection;
use Plugin\AzuracastSweepers\ValueObject\Settings;
use Plugin\AzuracastSweepers\ValueObject\Timing;

/**
 * Moves a queued song with a usable intro forward when a sweeper is due on a song without one.
 */
final readonly class Arranger
{
    public function __construct(
        private State $state,
        private Media $media,
        private Queue $queue
    ) {
    }

    /**
     * @param array<int, Timing> $measurements Sweeper timings by media ID.
     * @return array{moved: string, before: string} Queue IDs; empty when nothing changed.
     */
    public function arrange(Station $station, array $gap, string $counted, array $measurements): array
    {
        $none = ['moved' => '', 'before' => ''];
        $data = $this->state->read($station->getRadioConfigDir());
        $settings = Settings::fromArray($data['settings'] ?? []);
        if (!$settings->enabled || !$settings->swapForIntro || $measurements === []) {
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
            $row['excluded'] = Queue::isExcluded(
                $row['playlist_id'],
                $row['playlist_chain'],
                $settings->excludedPlaylistIds,
                $excludedNames
            );
        }
        unset($row);
        $sweepers = count($this->media->jingles($station, $settings->playlistId));
        $timings = array_diff_key(
            $measurements,
            array_flip(Selection::unavailable($data, $settings->sweeperGap, $sweepers))
        );

        $known = [];
        $usable = function (int $mediaId) use ($station, $settings, $timings, &$known): bool {
            return $known[$mediaId] ??= $this->usable($station, $mediaId, $settings, $timings);
        };
        $choice = Arrangement::choose($rows, $gap, $settings, Queue::length($station), $usable);
        if ($choice === null || !$this->queue->exchange($station, $choice[0], $choice[1])) {
            return $none;
        }
        $media = array_column($rows, 'media_id', 'id');
        $this->state->update($station->getRadioConfigDir(), static function (array $data) use ($choice, $media): array {
            // Shown in the playback timeline if the moved song actually plays with a sweeper.
            $moved = array_filter(
                $data['moved'] ?? [],
                static fn(array $entry): bool => $entry['at'] > time() - 7200
            );
            $moved[(string)$choice[1]] = ['at' => time(), 'before' => $media[$choice[0]] ?? null];
            $data['moved'] = array_slice($moved, -50, preserve_keys: true);

            return $data;
        });

        return ['moved' => (string)$choice[1], 'before' => (string)$choice[0]];
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
        // AzuraCast's cue values for this file, as Liquidsoap will receive them; the file start without AutoCue.
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
     * @return array{0: float, 1: float} Cue-in and the end of the planning window, as the module computes them.
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
