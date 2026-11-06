<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\Service;

use App\Entity\Station;
use InvalidArgumentException;
use Plugin\AzuracastSweepers\ValueObject\Links;
use Plugin\AzuracastSweepers\ValueObject\Selection;
use Plugin\AzuracastSweepers\ValueObject\Settings;
use Throwable;

final readonly class Planner
{
    public const int LOG_RETENTION = 7 * 86400;
    public const int LOG_LIMIT = 10000;
    private const int DECISION_LIMIT = 64;

    public function __construct(
        private State $state,
        private Media $media,
        private Queue $queue,
        private Schedule $schedule
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

        $context = $this->queue->context($station, $token);
        $queuedMedia = $this->queue->queuedMedia($station);
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
            $context,
            $queuedMedia,
            &$result
        ): array {
            $settings = Settings::fromArray($data['settings'] ?? []);
            $result = self::emptyPlan($token, $settings->revision());
            $data['plans'] = array_filter(
                $data['plans'] ?? [],
                static fn(array $plan): bool => $plan['expires_at'] > time()
            );
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
            if ($context === null) {
                unset($data['plans'][$token]);

                return $decide('queue_context_missing');
            }
            $route = $settings->routeFor($context['playlist_id'], $context['request_id'] !== null);
            $result['route_key'] = $route->key;
            if (($intro === null || $intro <= $cueIn) && !$route->preRoll) {
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
            if ($route->followSpacing && $this->queue->blocked($station, [$token], $settings) !== []) {
                unset($data['plans'][$token]);

                return $decide('jingle_follows');
            }
            $active = $this->schedule->active($station, $route->playlistIds);
            if ($route->playlistIds !== [] && $active === []) {
                unset($data['plans'][$token]);

                return $decide('off_schedule');
            }
            $fingerprint = hash('sha256', json_encode([
                Media::identity($song), $intro, $context, $route->toArray(), $settings->toArray(),
                $measurements, $active,
            ], JSON_THROW_ON_ERROR));
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

            if ($route->cooldownSongs > 0 || $route->cooldownMinutes > 0) {
                foreach ($data['plans'] as $pendingToken => $pendingPlan) {
                    if (
                        $pendingToken !== $token && !($pendingPlan['played'] ?? false)
                        && ($pendingPlan['route_key'] ?? null) === $route->key
                    ) {
                        unset($data['plans'][$token]);

                        return $decide('route_reserved');
                    }
                }
                $cooldown = $data['route_cooldowns'][$route->key] ?? null;
                if (
                    is_array($cooldown) && (float)($cooldown['last_played_at'] ?? 0) > 0
                    && !$route->cooldownAllows(
                        (int)($cooldown['songs_since'] ?? 0),
                        max(0, time() - (int)$cooldown['last_played_at'])
                    )
                ) {
                    unset($data['plans'][$token]);

                    return $decide('route_cooldown');
                }
            }

            $jingles = $this->media->jingles($station, $active);
            $reserved = Selection::unavailable($data, $settings->sweeperGap, count($jingles));
            $pending = array_map('intval', array_column(array_filter(
                $data['plans'],
                static fn(array $plan): bool => !($plan['played'] ?? false)
            ), 'jingle_id'));
            $candidates = [];
            $fitting = 0;
            $measured = 0;
            $recent = 0;
            $preRollLimited = 0;
            $linkedQueued = 0;
            $linkedRecent = 0;
            $links = $settings->links->withShared($this->media->sharedTracks($station, $settings));
            $history = $data['history'] ?? [];
            $lastSweeperAt = self::lastSweeperAt($data['log'] ?? []);

            foreach ($jingles as $jingle) {
                if ($jingle->id === $song->id) {
                    continue;
                }
                $timing = $measurements[$jingle->id] ?? null;
                $measured += $timing === null ? 0 : 1;
                $fit = $timing?->fitWithPreRoll(
                    $intro,
                    $cueIn,
                    $cueOut,
                    $settings,
                    $route->preRoll
                );
                if ($fit === null) {
                    $availableIntro = $intro !== null && $intro > $cueIn && $intro <= $cueOut
                        ? max(0.0, $intro - $cueIn - $settings->margin) : 0.0;
                    if (
                        $timing !== null && $route->preRoll
                        && max(0.0, $timing->handover - $timing->cueIn - $availableIntro)
                            > Timing::MAX_PRE_ROLL_SECONDS
                    ) {
                        $preRollLimited++;
                    }
                    continue;
                }
                $fitting++;
                if (in_array($jingle->id, $reserved, true)) {
                    $recent += in_array($jingle->id, $pending, true) ? 0 : 1;
                    continue;
                }
                $linked = $links->members($jingle->id);
                if (array_intersect($linked, $queuedMedia) !== []) {
                    $linkedQueued++;
                    continue;
                }
                if (array_intersect($linked, $pending) !== []) {
                    continue;
                }
                if ($links->recent($history, $jingle->id, microtime(true))) {
                    $linkedRecent++;
                    continue;
                }

                $candidates[] = [
                    'id' => $jingle->id,
                    'last_played' => $links->lastPlayed($history, $jingle->id),
                    // Mirrors the jingle side: a group whose linked jingle followed the last sweeper waits.
                    'held' => $lastSweeperAt !== null && $links->heardSince($history, $jingle->id, $lastSweeperAt),
                    'body' => $fit['duck_duration'],
                    'jingle' => $jingle,
                    'timing' => $timing,
                    'fit' => $fit,
                ];
            }
            $availableIntro = $intro !== null && $intro > $cueIn
                ? max(0.0, $intro - $cueIn - $settings->margin) : 0.0;
            $candidates = Selection::order($candidates, $availableIntro, $settings);
            if ($candidates !== []) {
                $candidate = $candidates[0];
                $jingle = $candidate['jingle'];
                $timing = $candidate['timing'];
                $fit = $candidate['fit'];
                $uri = 'annotate:liq_disable_autocue="true",liq_cue_in="' . $timing->cueIn
                    . '",liq_cue_out="' . $timing->cueOut . '":media:' . ltrim($jingle->path, '/');
                $result = [
                    'token' => $token,
                    'route_key' => $route->key,
                    'song_delay' => $fit['song_delay'],
                    'pre_roll' => $fit['pre_roll'],
                    'follow_spacing' => $route->followSpacing,
                    'counts_for_spacing' => $route->countsForSpacing,
                    'jingle_id' => $jingle->id,
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
                    'route_key' => $route->key,
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
                $fitting === 0 => $preRollLimited > 0 ? 'pre_roll_limit' : 'no_fit',
                $recent === 0 && $linkedQueued > 0 => 'linked_queued',
                $recent === 0 && $linkedRecent > 0 => 'linked_recent',
                $recent === 0 => 'reserved',
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
     * @return array{settings: Settings, blocked: list<string>, played: list<array{media_id: int, at: float}>}
     *     played lists sweepers first confirmed audible by these events.
     */
    public function heartbeat(Station $station, array $tokens, array $events, mixed $version = null): array
    {
        if (count($tokens) > 16 || count($events) > 128) {
            throw new InvalidArgumentException('Too many reservation events.');
        }
        $played = [];
        $sharedTracks = $this->sharedTracksFor($station, $events);
        $data = $this->state->update($station->getRadioConfigDir(), static function (array $data) use (
            $tokens,
            $events,
            $version,
            $sharedTracks,
            &$played
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
                if (!is_array($event)) {
                    continue;
                }
                $token = (string)($event['token'] ?? '');
                $before = ($event['event'] ?? '') === 'played' ? self::playedSweeper($data, $token) : null;
                $data = self::apply($data, $event, $sharedTracks);
                if ($before === null && ($event['event'] ?? '') === 'played') {
                    $after = self::playedSweeper($data, $token);
                    if ($after !== null) {
                        $played[] = $after;
                    }
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
                && ($settings->route((string)($data['plans'][$token]['route_key'] ?? ''))?->followSpacing ?? true)
        ));

        // Recheck until activation: AzuraCast extends its upcoming queue after planning.
        return [
            'settings' => $settings,
            'blocked' => $settings->enabled ? $this->queue->blocked($station, $pending, $settings) : [],
            'played' => $played,
        ];
    }

    /**
     * @return list<int>
     */
    private function sharedTracksFor(Station $station, array $events): array
    {
        foreach ($events as $event) {
            if (is_array($event) && ($event['event'] ?? '') === 'start' && ($event['media_id'] ?? '') !== '') {
                // Playback events must be stored even if the lookup fails.
                try {
                    $data = $this->state->read($station->getRadioConfigDir());

                    return $this->media->sharedTracks($station, Settings::fromArray($data['settings'] ?? []));
                } catch (Throwable $exception) {
                    error_log('AzuraCast Sweepers: shared track lookup skipped: ' . $exception->getMessage());

                    return [];
                }
            }
        }

        return [];
    }

    /**
     * @return array{media_id: int, at: float}|null The sweeper this token played, once confirmed.
     */
    private static function playedSweeper(array $data, string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        foreach ($data['log'] ?? [] as $entry) {
            if (
                ($entry['token'] ?? null) === $token && ($entry['result'] ?? null) === 'played'
                && is_int($entry['jingle_id'] ?? null)
            ) {
                return [
                    'media_id' => $entry['jingle_id'],
                    'at' => (float)($entry['sweeper_started_at'] ?? $entry['at'] ?? microtime(true)),
                ];
            }
        }

        return null;
    }

    /**
     * @param list<int> $sharedTracks Tracks that are a sweeper and a jingle at once.
     */
    public static function apply(array $data, array $event, array $sharedTracks = []): array
    {
        $type = (string)($event['event'] ?? '');
        $token = (string)($event['token'] ?? '');
        $detail = substr((string)($event['detail'] ?? ''), 0, 32);
        $now = microtime(true);
        $at = $event['at'] ?? $now;
        if ((!is_int($at) && !is_float($at)) || !is_finite((float)$at) || $at <= 0 || $at > $now + 60) {
            throw new InvalidArgumentException('Invalid reservation event time.');
        }
        $plan = $token === '' ? null : ($data['plans'][$token] ?? null);
        $log = $data['log'] ?? [];
        $index = null;
        // Events are re-sent until acknowledged.
        foreach ($log as $key => $entry) {
            if ($token !== '' && ($entry['token'] ?? null) === $token) {
                $index = $key;
                break;
            }
        }

        if ($type === 'start') {
            $mediaId = (string)($event['media_id'] ?? '');
            if (
                preg_match('/^[1-9]\d{0,9}$/D', $mediaId)
                && Links::fromArray($data['settings']['link_groups'] ?? [], 0)
                    ->withShared($sharedTracks)->linked((int)$mediaId)
            ) {
                // A linked jingle counts as a play of its whole group in the sweeper rotation.
                $last = (float)($data['history'][(int)$mediaId] ?? 0);
                $data['history'][(int)$mediaId] = max($last, (float)$at);
            }
            if ($index !== null && isset($log[$index]['song_started_at'])) {
                return $data;
            }
            if ($index !== null) {
                $data['log'][$index]['song_started_at'] = (float)$at;
            }
            if (
                (string)($event['media_id'] ?? '') !== ''
                && !in_array($detail, ['jingle', 'other'], true)
            ) {
                foreach ($data['route_cooldowns'] ?? [] as $routeKey => $cooldown) {
                    if (isset($cooldown['carrying_token']) && $cooldown['carrying_token'] !== $token) {
                        unset($data['route_cooldowns'][$routeKey]['carrying_token']);
                    }
                }
            }
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
            $unmoved = $data['unmoved'][$token]['reason'] ?? null;
            unset($data['moved'][$token], $data['unmoved'][$token], $data['decisions'][$token]);
            $mediaId = (string)($event['media_id'] ?? '');
            if (preg_match('/^\d{1,10}$/D', $mediaId)) {
                unset($data['replacement_counts'][(int)$mediaId]);
            }
            $data['log'] = self::retain([[
                'at' => (float)$at,
                'token' => $token,
                'song_id' => preg_match('/^\d{1,10}$/D', $mediaId) ? (int)$mediaId : null,
                'title' => mb_substr((string)($event['title'] ?? ''), 0, 200),
                'artist' => mb_substr((string)($event['artist'] ?? ''), 0, 200),
                'result' => $result,
                'song_started_at' => (float)$at,
                ...(is_string($plan['route_key'] ?? $event['route_key'] ?? null)
                    ? ['route_key' => $plan['route_key'] ?? $event['route_key']] : []),
                ...($result === 'scheduled'
                    ? self::playback($event, $plan, null)
                    : ['jingle_id' => null]),
                ...($moved === null ? [] : [
                    ($moved['replacement'] ?? false) ? 'replaced_before' : 'moved_before' => $moved['before'] ?? null,
                ]),
                ...($unmoved === null ? [] : ['unmoved' => $unmoved]),
            ], ...$log]);

            return $data;
        }
        if ($type === 'end' && $token !== '') {
            if ($index === null || isset($log[$index]['song_ended_at'])) {
                return $data;
            }
            $data['log'][$index]['song_ended_at'] = (float)$at;
            foreach ($data['route_cooldowns'] ?? [] as $routeKey => $cooldown) {
                if (($cooldown['carrying_token'] ?? null) === $token) {
                    unset($data['route_cooldowns'][$routeKey]['carrying_token']);
                    continue;
                }
                $data['route_cooldowns'][$routeKey]['songs_since'] = min(
                    1000,
                    (int)($cooldown['songs_since'] ?? 0) + 1
                );
            }

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
        if ($type === 'played' && $token !== '') {
            if (($index !== null && $log[$index]['result'] === 'played') || ($plan['played'] ?? false)) {
                return $data;
            }
            $playback = self::playback($event, $plan, $index === null ? null : $log[$index]);
            if ($plan === null && $index === null && $playback['jingle_id'] === null) {
                return $data;
            }
            if ($playback['jingle_id'] !== null) {
                $id = $playback['jingle_id'];
                $data['history'][$id] = max((float)($data['history'][$id] ?? 0), (float)$at);
            }
            if ($plan !== null) {
                $data['plans'][$token]['played'] = true;
                $routeKey = $plan['route_key'] ?? '';
                if (is_string($routeKey) && $routeKey !== '' && $routeKey !== 'default') {
                    $cooldown = [
                        'last_played_at' => (float)$at,
                        'songs_since' => 0,
                        'carrying_token' => $token,
                    ];
                    $data['route_cooldowns'][$routeKey] = $cooldown;
                }
            }
            $played = [
                'result' => 'played',
                ...$playback,
                'sweeper_started_at' => (float)$at,
            ];
            if ($index !== null) {
                $data['log'][$index] = [...$log[$index], ...$played];

                return $data;
            }
            // Older modules send no start events.
            $moved = $data['moved'][$token] ?? null;
            unset($data['moved'][$token]);
            $data['log'] = self::retain([[
                'at' => (float)$at,
                'token' => $token,
                'song_id' => $plan['song_id'] ?? (
                    preg_match('/^\d{1,10}$/D', (string)($event['media_id'] ?? ''))
                        ? (int)$event['media_id'] : null
                ),
                'title' => mb_substr((string)($event['title'] ?? ''), 0, 200),
                'artist' => mb_substr((string)($event['artist'] ?? ''), 0, 200),
                'song_started_at' => null,
                ...$played,
                ...(is_string($plan['route_key'] ?? $event['route_key'] ?? null)
                    ? ['route_key' => $plan['route_key'] ?? $event['route_key']] : []),
                ...($moved === null ? [] : [
                    ($moved['replacement'] ?? false) ? 'replaced_before' : 'moved_before' => $moved['before'] ?? null,
                ]),
            ], ...$log]);
        } elseif ($type === 'release') {
            unset($data['plans'][$token]);
        }

        return $data;
    }

    private static function playback(array $event, ?array $plan, ?array $entry): array
    {
        $id = $event['jingle_id'] ?? 0;
        if (!is_int($id) || $id < 0) {
            throw new InvalidArgumentException('Invalid playback sweeper ID.');
        }
        $offset = $id > 0 ? ($event['offset'] ?? null) : ($plan['payload']['offset'] ?? $entry['offset'] ?? null);
        if (
            $offset !== null
            && ((!is_int($offset) && !is_float($offset)) || !is_finite((float)$offset) || $offset < 0)
        ) {
            throw new InvalidArgumentException('Invalid playback offset.');
        }
        if ($id > 0 && $offset === null) {
            throw new InvalidArgumentException('Missing playback offset.');
        }

        return [
            'jingle_id' => $id > 0 ? $id : ($plan['jingle_id'] ?? $entry['jingle_id'] ?? null),
            'offset' => $offset,
            'song_delay' => isset($plan['payload']['song_delay'])
                ? (float)$plan['payload']['song_delay'] : ($entry['song_delay'] ?? null),
            'pre_roll' => (bool)($plan['payload']['pre_roll'] ?? $entry['pre_roll'] ?? false),
            'route_key' => $plan['route_key'] ?? $entry['route_key'] ?? null,
        ];
    }

    /**
     * @param list<array> $log newest first
     */
    private static function lastSweeperAt(array $log): ?float
    {
        foreach ($log as $entry) {
            if (($entry['result'] ?? null) === 'played') {
                return (float)($entry['sweeper_started_at'] ?? $entry['at'] ?? 0);
            }
        }

        return null;
    }

    /**
     * @param list<array> $log newest first
     */
    private static function retain(array $log): array
    {
        $oldest = microtime(true) - self::LOG_RETENTION;
        $kept = array_filter($log, static fn(array $entry): bool => (float)($entry['at'] ?? 0) >= $oldest);

        return array_slice(array_values($kept), 0, self::LOG_LIMIT);
    }

    private static function emptyPlan(string $token, string $revision): array
    {
        return [
            'token' => $token,
            'jingle_id' => 0,
            'revision' => $revision,
            'uri' => '',
            'offset' => 0.0,
            'duck_duration' => 0.0,
            'gain' => 1.0,
            'duck_gain' => 1.0,
        ];
    }
}
