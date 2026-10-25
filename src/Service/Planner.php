<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\Service;

use App\Entity\Station;
use InvalidArgumentException;
use Plugin\AzuracastSweepers\ValueObject\Selection;
use Plugin\AzuracastSweepers\ValueObject\Settings;

final readonly class Planner
{
    public const int LOG_LIMIT = 200;

    public function __construct(
        private State $state,
        private Media $media,
        private Queue $queue
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
        $result = [];
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
            $result = self::emptyPlan($token, $settings->revision());
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
            if ($this->queue->blocked($station, [$token], $settings) !== []) {
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
                    'revision' => $settings->revision(),
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

    /**
     * @return array{settings: Settings, blocked: list<string>}
     */
    public function heartbeat(Station $station, array $tokens, array $events, mixed $version = null): array
    {
        if (count($tokens) > 16 || count($events) > 128) {
            throw new InvalidArgumentException('Too many reservation events.');
        }
        $data = $this->state->update($station->getRadioConfigDir(), static function (array $data) use (
            $tokens,
            $events,
            $version
        ): array {
            // Proves the module is running; limited to one state write per 30 seconds.
            if (
                is_string($version) && $version !== ''
                && (($data['heartbeat']['version'] ?? null) !== $version
                    || time() - (int)($data['heartbeat']['at'] ?? 0) >= 30)
            ) {
                $data['heartbeat'] = ['at' => time(), 'version' => substr($version, 0, 64)];
            }
            foreach ($events as $event) {
                $token = (string)($event['token'] ?? '');
                $plan = $data['plans'][$token] ?? null;
                if ($plan === null) {
                    continue;
                }
                if (($event['event'] ?? '') === 'played' && !($plan['played'] ?? false)) {
                    $now = microtime(true);
                    $data['history'][$plan['jingle_id']] = $now;
                    $data['plans'][$token]['played'] = true;
                    // Newest first; only sweepers that actually started playing.
                    $data['log'] = array_slice([[
                        'at' => $now,
                        'song_id' => $plan['song_id'] ?? null,
                        'jingle_id' => $plan['jingle_id'],
                        'offset' => $plan['payload']['offset'] ?? null,
                    ], ...($data['log'] ?? [])], 0, self::LOG_LIMIT);
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

        $settings = Settings::fromArray($data['settings'] ?? []);
        $pending = array_values(array_filter(
            array_map('strval', $tokens),
            static fn(string $token): bool => isset($data['plans'][$token])
                && !($data['plans'][$token]['played'] ?? false)
        ));

        // Recheck until activation: AzuraCast extends its upcoming queue after planning.
        return [
            'settings' => $settings,
            'blocked' => $settings->enabled ? $this->queue->blocked($station, $pending, $settings) : [],
        ];
    }

    private static function emptyPlan(string $token, string $revision): array
    {
        return [
            'token' => $token,
            'revision' => $revision,
            'uri' => '',
            'offset' => 0.0,
            'duck_duration' => 0.0,
            'gain' => 1.0,
            'duck_gain' => 1.0,
        ];
    }
}
