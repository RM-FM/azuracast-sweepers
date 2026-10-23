<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\Service;

use App\Entity\Station;
use InvalidArgumentException;
use Plugin\AzuracastSweepers\ValueObject\Selection;
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
            $fingerprint = hash('sha256', json_encode([
                Media::identity($song), $intro, $settings->toArray(), $measurements,
            ], JSON_THROW_ON_ERROR));
            if (!$settings->enabled || $intro === null || $intro <= $cueIn) {
                unset($data['plans'][$token]);

                return $data;
            }
            if (isset($data['plans'][$token])) {
                $saved = $data['plans'][$token];
                if (
                    $saved['song_id'] === $song->id
                    && (float)$saved['cue_in'] === $cueIn
                    && (float)$saved['cue_out'] === $cueOut
                    && ($saved['fingerprint'] ?? '') === $fingerprint
                ) {
                    $result = $saved['payload'];
                    $data['plans'][$token]['expires_at'] = time() + 30;

                    return $data;
                }
                unset($data['plans'][$token]);
            }

            $reserved = array_column(array_filter(
                $data['plans'],
                static fn(array $plan): bool => !($plan['played'] ?? false)
            ), 'jingle_id');
            $jingles = $this->media->jingles($station, $settings->playlistId);
            $candidates = [];

            foreach ($jingles as $jingle) {
                if (in_array($jingle->id, $reserved, true) || $jingle->id === $song->id) {
                    continue;
                }
                $timing = $measurements[$jingle->id] ?? null;
                $fit = $timing?->fit($intro, $cueIn, $cueOut, $settings);
                if ($fit === null) {
                    continue;
                }

                $candidates[] = [
                    'id' => $jingle->id,
                    'last_played' => $data['history'][$jingle->id] ?? 0,
                    'body' => $fit['duck_duration'],
                    'jingle' => $jingle,
                    'timing' => $timing,
                    'fit' => $fit,
                ];
            }
            $candidates = Selection::order($candidates, $intro - $cueIn - $settings->margin, $settings);
            if ($candidates !== []) {
                $candidate = $candidates[0];
                $jingle = $candidate['jingle'];
                $timing = $candidate['timing'];
                $fit = $candidate['fit'];
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
                    'expires_at' => time() + 30,
                    'fingerprint' => $fingerprint,
                    'played' => false,
                    'payload' => $result,
                ];
            }

            return $data;
        });

        return $result;
    }

    public function played(Station $station, string $token): void
    {
        $this->heartbeat($station, [], [['event' => 'played', 'token' => $token]]);
    }

    public function heartbeat(Station $station, array $tokens, array $events): void
    {
        if (count($tokens) > 16 || count($events) > 128) {
            throw new InvalidArgumentException('Too many reservation events.');
        }
        $this->state->update($station->getRadioConfigDir(), static function (array $data) use (
            $tokens,
            $events
        ): array {
            foreach ($events as $event) {
                $token = (string)($event['token'] ?? '');
                $plan = $data['plans'][$token] ?? null;
                if ($plan === null) {
                    continue;
                }
                if (($event['event'] ?? '') === 'played' && !($plan['played'] ?? false)) {
                    $data['history'][$plan['jingle_id']] = microtime(true);
                    $data['plans'][$token]['played'] = true;
                } elseif (($event['event'] ?? '') === 'release') {
                    unset($data['plans'][$token]);
                }
            }
            foreach ($tokens as $token) {
                if (isset($data['plans'][$token]) && $data['plans'][$token]['expires_at'] > time()) {
                    $data['plans'][$token]['expires_at'] = time() + 30;
                }
            }
            $data['plans'] = array_filter(
                $data['plans'] ?? [],
                static fn(array $plan): bool => $plan['expires_at'] > time()
            );

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
