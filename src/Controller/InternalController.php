<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\Controller;

use App\Http\Response;
use App\Http\ServerRequest;
use InvalidArgumentException;
use Plugin\AzuracastSweepers\Service\Media;
use Plugin\AzuracastSweepers\Service\Planner;
use Plugin\AzuracastSweepers\Service\State;
use Plugin\AzuracastSweepers\ValueObject\Settings;
use Plugin\AzuracastSweepers\ValueObject\Timing;
use Psr\Http\Message\ResponseInterface;

final readonly class InternalController
{
    public function __construct(
        private Media $media,
        private Planner $planner,
        private State $state
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
            foreach ($this->media->jingles($station, $settings->playlistId) as $jingle) {
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
        $allowed = [];
        foreach ($this->media->jingles($station, $settings->playlistId) as $jingle) {
            $allowed[$jingle->id] = $jingle;
        }
        $measurements = [];
        foreach ($body['jingles'] ?? [] as $row) {
            $jingle = $allowed[$row['media_id'] ?? 0] ?? null;
            if ($jingle !== null && ($row['identity'] ?? '') === $this->media->timingIdentity($station, $jingle)) {
                $timing = Timing::fromMetadata((array)($row['metadata'] ?? []), $jingle->length);
                if ($timing !== null) {
                    $measurements[$jingle->id] = $timing;
                }
            }
        }

        return $response->withJson($this->planner->plan(
            $request->getStation(),
            (int)$body['media_id'],
            (string)($body['token'] ?? ''),
            (float)$body['cue_in'],
            (float)$body['cue_out'],
            $measurements
        ));
    }

    public function heartbeatAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $body = $this->payload($request);
        $this->planner->heartbeat($request->getStation(), $body['tokens'] ?? [], $body['events'] ?? []);

        return $response->withJson(['success' => true]);
    }

    public function playedAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $body = $this->payload($request);
        $this->planner->played($request->getStation(), (string)($body['token'] ?? ''));

        return $response->withJson(['success' => true]);
    }
}
