<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\Controller;

use App\Entity\Station;
use App\Http\Response;
use App\Http\ServerRequest;
use InvalidArgumentException;
use Plugin\AzuracastSweepers\Service\Arranger;
use Plugin\AzuracastSweepers\Service\LinkSync;
use Plugin\AzuracastSweepers\Service\Media;
use Plugin\AzuracastSweepers\Service\Planner;
use Plugin\AzuracastSweepers\Service\State;
use Plugin\AzuracastSweepers\ValueObject\Settings;
use Plugin\AzuracastSweepers\ValueObject\Timing;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final readonly class InternalController
{
    public function __construct(
        private Media $media,
        private Planner $planner,
        private State $state,
        private Arranger $arranger,
        private LinkSync $linkSync
    ) {
    }

    private function authorize(ServerRequest $request): void
    {
        $key = $request->getHeaderLine('X-Liquidsoap-Api-Key');
        if ($key === '' || !$request->getStation()->validateAdapterApiKey($key)) {
            throw new InvalidArgumentException('Invalid station adapter key.');
        }
    }

    private function payload(ServerRequest $request): array
    {
        $this->authorize($request);

        return json_decode((string)$request->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function catalogAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $this->authorize($request);
        $station = $request->getStation();
        $data = $this->state->read($station->getRadioConfigDir());
        $settings = Settings::fromArray($data['settings'] ?? []);
        $jobs = [];
        if ($settings->enabled) {
            foreach ($this->media->jingles($station, $settings->allSweeperPlaylistIds()) as $jingle) {
                $jobs[] = [
                    'media_id' => $jingle->id,
                    'identity' => $this->media->timingIdentity($station, $jingle),
                    'uri' => $this->media->uri($station, $jingle),
                ];
            }
        }

        return $response->withJson($jobs);
    }

    public function planAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $body = $this->payload($request);
        foreach (['cue_in', 'cue_out', 'media_id'] as $key) {
            if (!isset($body[$key]) || !is_numeric($body[$key]) || !is_finite((float)$body[$key])) {
                throw new InvalidArgumentException('Invalid ' . $key . '.');
            }
        }

        $station = $request->getStation();
        $data = $this->state->read($station->getRadioConfigDir());
        $settings = Settings::fromArray($data['settings'] ?? []);

        $plan = $this->planner->plan(
            $request->getStation(),
            (int)$body['media_id'],
            (string)($body['token'] ?? ''),
            (float)$body['cue_in'],
            (float)$body['cue_out'],
            $this->measurements($station, $settings, $body['jingles'] ?? [])
        );
        if ($settings->links->groups !== []) {
            // A short budget: the module waits for this response. The next heartbeat catches up.
            $this->reconcileLinks($station, $settings, 0.2);
        }

        return $response->withJson($plan);
    }

    public function arrangeAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $body = $this->payload($request);
        $gap = [];
        foreach (['songs_since_jingle', 'songs_since_sweeper'] as $key) {
            if (!is_int($body[$key] ?? null) || $body[$key] < 0) {
                throw new InvalidArgumentException('Invalid ' . $key . '.');
            }
            $gap[$key] = $body[$key];
        }
        foreach (['previous_song', 'previous_clear'] as $key) {
            if (!is_bool($body[$key] ?? null)) {
                throw new InvalidArgumentException('Invalid ' . $key . '.');
            }
            $gap[$key] = $body[$key];
        }
        $budget = $body['budget'] ?? Arranger::DEFAULT_BUDGET_SECONDS;
        if (!is_numeric($budget) || !is_finite((float)$budget) || $budget < 0.1 || $budget > 5) {
            throw new InvalidArgumentException('Invalid budget.');
        }
        $releaseSpacing = $body['release_spacing'] ?? false;
        if (!is_bool($releaseSpacing)) {
            throw new InvalidArgumentException('Invalid release_spacing.');
        }
        $station = $request->getStation();
        $started = hrtime(true);
        $settings = Settings::fromArray($this->state->read($station->getRadioConfigDir())['settings'] ?? []);
        if ($settings->enabled && $settings->links->groups !== []) {
            $this->syncLinks(
                $station,
                fn(int $deadline) => $this->linkSync->beforeSend($station, $settings, $deadline),
                (float)$budget / 2
            );
        }
        if (!$settings->enabled || (!$settings->swapForIntro && !$settings->swapFromPlaylist)) {
            return $response->withJson(['moved' => '', 'before' => '', 'replaced' => '']);
        }
        // Link sync and song matching share the budget the module waits for.
        $remaining = max(0.1, (float)$budget - (hrtime(true) - $started) / 1_000_000_000);

        return $response->withJson($this->arranger->arrange(
            $station,
            $gap,
            (string)($body['counted'] ?? ''),
            $this->measurements($station, $settings, $body['jingles'] ?? []),
            $remaining,
            $releaseSpacing
        ));
    }

    /**
     * @return array<int, Timing>
     */
    private function measurements(Station $station, Settings $settings, mixed $rows): array
    {
        $allowed = [];
        foreach ($this->media->jingles($station, $settings->allSweeperPlaylistIds()) as $jingle) {
            $allowed[$jingle->id] = $jingle;
        }
        $measurements = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $jingle = $allowed[$row['media_id'] ?? 0] ?? null;
            if ($jingle !== null && ($row['identity'] ?? '') === $this->media->timingIdentity($station, $jingle)) {
                $timing = Timing::fromMetadata(
                    (array)($row['metadata'] ?? []),
                    $jingle->length,
                    $station->backend_config->enable_replaygain_metadata
                );
                if ($timing !== null) {
                    $measurements[$jingle->id] = $timing;
                }
            }
        }

        return $measurements;
    }

    public function heartbeatAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $body = $this->payload($request);
        $station = $request->getStation();
        $result = $this->planner->heartbeat(
            $station,
            $body['tokens'] ?? [],
            $body['events'] ?? [],
            $body['version'] ?? null
        );
        $settings = $result['settings'];
        if ($settings->enabled && $result['played'] !== [] && $settings->links->groups !== []) {
            $this->syncLinks(
                $station,
                fn(int $deadline) => $this->linkSync->sweepersPlayed($station, $settings, $result['played'], $deadline),
                1.0
            );
        }
        // Also when sweepers or links were switched off: reserved jingles then return to their rotation.
        $this->reconcileLinks($station, $settings, 1.0);

        return $response->withJson([
            'success' => true,
            'settings' => $settings->runtime(),
            'blocked' => $result['blocked'],
        ]);
    }

    private function reconcileLinks(Station $station, Settings $settings, float $budgetSeconds): void
    {
        $this->syncLinks(
            $station,
            fn(int $deadline) => $this->linkSync->reconcile($station, $settings, $deadline),
            $budgetSeconds
        );
    }

    // Link sync is best effort: the station's playback must never depend on it.
    private function syncLinks(Station $station, callable $sync, float $budgetSeconds): void
    {
        try {
            $sync(hrtime(true) + (int)($budgetSeconds * 1_000_000_000));
        } catch (Throwable $exception) {
            error_log('AzuraCast Sweepers: linked jingle sync skipped for station ' . $station->id . ': '
                . $exception->getMessage());
        }
    }

    public function playedAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $body = $this->payload($request);
        $this->planner->played($request->getStation(), (string)($body['token'] ?? ''));

        return $response->withJson(['success' => true]);
    }
}
