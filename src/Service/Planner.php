<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\Service;

use App\Entity\Station;
use InvalidArgumentException;
use Plugin\AzuracastSweepers\ValueObject\Settings;

final readonly class Planner
{
    public function __construct(
        private State $state,
        private Media $media
    ) {
    }

    public function plan(
        Station $station,
        int $mediaId,
        string $token,
        float $cueIn,
        float $cueOut,
        array $measurements
    ): array {
        if (!preg_match('/^\d{1,20}$/D', $token)) {
            throw new InvalidArgumentException('A queue token is required.');
        }

        $song = $this->media->get($station, $mediaId);
        $intro = $this->media->intro($song);
        $directory = $station->getRadioConfigDir();
        $result = self::emptyPlan($token);
        $this->state->update($directory, function (array $data) use (
            $station,
            $song,
            $intro,
            $token,
            $cueIn,
            $cueOut,
            $measurements,
            &$result
        ): array {
            $settings = Settings::fromArray($data['settings'] ?? []);
            $data['plans'] = array_filter(
                $data['plans'] ?? [],
                static fn(array $plan): bool => $plan['expires_at'] > time()
            );
            if (isset($data['plans'][$token])) {
                $saved = $data['plans'][$token];
                if (
                    $saved['song_id'] === $song->id
                    && (float)$saved['cue_in'] === $cueIn
                    && (float)$saved['cue_out'] === $cueOut
                ) {
                    $result = $saved['payload'];
                }

                return $data;
            }

            $review = $data['intros'][$song->id] ?? [];
            $blocked = $review !== [] && (
                ($review['identity'] ?? '') !== Media::identity($song)
                || in_array($review['status'] ?? '', ['veto', 'unknown'], true)
            );
            if (!$settings->enabled || $intro === null || $intro <= $cueIn || $blocked) {
                return $data;
            }

            $reserved = array_column(array_filter(
                $data['plans'],
                static fn(array $plan): bool => !($plan['played'] ?? false)
            ), 'jingle_id');
            $jingles = $this->media->jingles($station, $settings->playlistId);
            usort($jingles, static fn($a, $b): int => [
                $data['history'][$a->id] ?? 0,
                $a->id,
            ] <=> [$data['history'][$b->id] ?? 0, $b->id]);

            foreach ($jingles as $jingle) {
                if (in_array($jingle->id, $reserved, true) || $jingle->id === $song->id) {
                    continue;
                }
                $timing = $measurements[$jingle->id] ?? null;
                $fit = $timing?->fit($intro, $cueIn, $cueOut, $settings);
                if ($fit === null) {
                    continue;
                }

                $uri = 'annotate:liq_disable_autocue="true",liq_cue_in="' . $timing->cueIn
                    . '",liq_cue_out="' . $timing->cueOut . '":media:' . ltrim($jingle->path, '/');
                $result = [
                    'token' => $token,
                    'uri' => $uri,
                    ...$fit,
                    'gain' => 10 ** ($timing->gainDb / 20) * $settings->jingleGain,
                    'duck_gain' => 10 ** ($settings->duckDb / 20),
                ];
                $data['plans'][$token] = [
                    'song_id' => $song->id,
                    'jingle_id' => $jingle->id,
                    'cue_in' => $cueIn,
                    'cue_out' => $cueOut,
                    'expires_at' => time() + 3600,
                    'played' => false,
                    'payload' => $result,
                ];
                break;
            }

            return $data;
        });

        return $result;
    }

    public function played(Station $station, string $token): void
    {
        $this->state->update($station->getRadioConfigDir(), static function (array $data) use ($token): array {
            $plan = $data['plans'][$token] ?? null;
            if ($plan !== null && !($plan['played'] ?? false) && $plan['expires_at'] > time()) {
                $data['history'][$plan['jingle_id']] = microtime(true);
                $data['plans'][$token]['played'] = true;
            }

            return $data;
        });
    }

    private static function emptyPlan(string $token): array
    {
        return [
            'token' => $token,
            'uri' => '',
            'offset' => 0.0,
            'duck_duration' => 0.0,
            'gain' => 1.0,
            'duck_gain' => 1.0,
        ];
    }
}
