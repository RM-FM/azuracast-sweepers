<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\Service;

use App\Entity\Station;
use InvalidArgumentException;
use Plugin\AzuracastSweepers\ValueObject\Selection;
use Plugin\AzuracastSweepers\ValueObject\Settings;

final readonly class Planner
{
    public const int LOG_LIMIT = 300;
    private const int DECISION_LIMIT = 64;

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
            $decide = static function (string $reason) use (&$data, $token): array {
                $decisions = $data['decisions'] ?? [];
                unset($decisions[$token]);
                $decisions[$token] = $reason;
                $data['decisions'] = array_slice($decisions, -self::DECISION_LIMIT, preserve_keys: true);

                return $data;
            };
            if (!$settings->enabled) {
                unset($data['plans'][$token]);

                return $decide('disabled');
            }
            if ($intro === null || $intro <= $cueIn) {
                unset($data['plans'][$token]);

                return $decide(match (true) {
                    $intro === null => 'no_intro',
                    $intro === 0.0 => 'intro_zero',
                    default => 'intro_before_cue',
                });
            }
            if ($this->queue->excluded($station, $token, $settings)) {
                unset($data['plans'][$token]);

                return $decide('excluded');
            }
            if ($this->queue->blocked($station, [$token], $settings) !== []) {
                unset($data['plans'][$token]);

                return $decide('jingle_follows');
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

                    return $decide('planned');
                }
                unset($data['plans'][$token]);
            }

            $jingles = $this->media->jingles($station, $settings->playlistId);
            $reserved = Selection::unavailable($data, $settings->sweeperGap, count($jingles));
            $candidates = [];
            $fitting = 0;
            $measured = 0;

            foreach ($jingles as $jingle) {
                if ($jingle->id === $song->id) {
                    continue;
                }
                $timing = $measurements[$jingle->id] ?? null;
                $measured += $timing === null ? 0 : 1;
                $fit = $timing?->fit($intro, $cueIn, $cueOut, $settings);
                if ($fit === null) {
                    continue;
                }
                $fitting++;
                if (in_array($jingle->id, $reserved, true)) {
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
                    'reverb' => $settings->reverbLevel(),
                    'length' => $timing->cueOut - $timing->cueIn,
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

                return $decide('planned');
            }

            return $decide(match (true) {
                $measured === 0 => 'no_sweepers',
                $fitting === 0 => 'no_fit',
                default => 'repeat',
            });
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
                if (is_array($event)) {
                    $data = self::apply($data, $event);
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

    public static function apply(array $data, array $event): array
    {
        $type = (string)($event['event'] ?? '');
        $token = (string)($event['token'] ?? '');
        $detail = substr((string)($event['detail'] ?? ''), 0, 32);
        $plan = $token === '' ? null : ($data['plans'][$token] ?? null);
        $log = $data['log'] ?? [];
        $index = null;
        // Events are re-sent until acknowledged.
        foreach (array_slice($log, 0, 20, true) as $key => $entry) {
            if ($token !== '' && ($entry['token'] ?? null) === $token) {
                $index = $key;
                break;
            }
        }

        if ($type === 'start') {
            if ($index !== null) {
                return $data;
            }
            $result = match ($detail) {
                '', 'none' => $data['decisions'][$token] ?? 'not_planned',
                default => $detail,
            };
            // Planned, but the module held no reservation: loading the sweeper failed.
            $result = $result === 'planned' ? 'unavailable' : $result;
            $moved = $data['moved'][$token] ?? null;
            unset($data['moved'][$token], $data['decisions'][$token]);
            $mediaId = (string)($event['media_id'] ?? '');
            $data['log'] = array_slice([[
                'at' => microtime(true),
                'token' => $token,
                'song_id' => preg_match('/^\d{1,10}$/D', $mediaId) ? (int)$mediaId : null,
                'title' => mb_substr((string)($event['title'] ?? ''), 0, 200),
                'artist' => mb_substr((string)($event['artist'] ?? ''), 0, 200),
                'result' => $result,
                'jingle_id' => $result === 'scheduled' ? ($plan['jingle_id'] ?? null) : null,
                ...($moved === null ? [] : ['moved_before' => $moved['before'] ?? null]),
            ], ...$log], 0, self::LOG_LIMIT);

            return $data;
        }
        if ($type === 'release' && $detail !== '' && $token !== '') {
            if ($index === null) {
                // The track has not started yet; its start event reports "none".
                $data['decisions'][$token] = $detail;
            } elseif (($log[$index]['result'] ?? '') === 'scheduled') {
                $data['log'][$index]['result'] = $detail;
                $data['log'][$index]['jingle_id'] = null;
            }
        }
        if ($plan === null) {
            return $data;
        }
        if ($type === 'played' && !($plan['played'] ?? false)) {
            $now = microtime(true);
            $data['history'][$plan['jingle_id']] = $now;
            $data['plans'][$token]['played'] = true;
            $played = [
                'result' => 'played',
                'jingle_id' => $plan['jingle_id'],
                'offset' => $plan['payload']['offset'] ?? null,
            ];
            if ($index !== null) {
                $data['log'][$index] = [...$log[$index], ...$played];

                return $data;
            }
            // Older modules send no start events.
            $moved = $data['moved'][$token] ?? null;
            unset($data['moved'][$token]);
            $data['log'] = array_slice([[
                'at' => $now,
                'token' => $token,
                'song_id' => $plan['song_id'] ?? null,
                ...$played,
                ...($moved === null ? [] : ['moved_before' => $moved['before'] ?? null]),
            ], ...$log], 0, self::LOG_LIMIT);
        } elseif ($type === 'release') {
            unset($data['plans'][$token]);
        }

        return $data;
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
