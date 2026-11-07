<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\Controller;

use App\Entity\Station;
use App\Enums\GlobalPermissions;
use App\Enums\StationPermissions;
use App\Http\Response;
use App\Http\ServerRequest;
use InvalidArgumentException;
use Plugin\AzuracastSweepers\Service\DetectorAccess;
use Plugin\AzuracastSweepers\Service\DetectorClient;
use Plugin\AzuracastSweepers\Service\DetectorError;
use Psr\Http\Message\ResponseInterface;

/**
 * Station pages for the Intro Detector. AzuraCast permissions are enforced here; the detector trusts the plugin.
 */
final readonly class DetectorController
{
    private const array STATION_FIELDS = [
        'enabled', 'auto_process', 'min_song_seconds', 'max_song_seconds', 'exclude_playlists', 'budget_usd',
        'idle_timeout_minutes', 'eu_only',
    ];

    public function __construct(
        private DetectorClient $detector,
        private DetectorAccess $access
    ) {
    }

    public function statusAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $station = $request->getStation();
        $capabilities = [
            'installed' => $this->detector->installed(),
            'can_manage' => $request->getAcl()->isAllowed(StationPermissions::Broadcasting, $station),
            // Only for the installation hint while the detector is missing.
            'can_admin' => $request->getAcl()->isAllowed(GlobalPermissions::Settings),
        ];
        if (!$capabilities['installed']) {
            return $this->json($response, $capabilities);
        }

        return $this->call($response, function () use ($station, $capabilities): array {
            $status = $this->detector->request('GET', $this->path($station));
            // Repairs access that was lost, e.g. after restoring an AzuraCast backup.
            if (($status['settings']['enabled'] ?? false) === true && !$this->access->granted($station)) {
                $this->access->grant($station);
            }

            return $capabilities + ['station' => $status];
        });
    }

    public function settingsAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $station = $this->verified($request);
        $changes = array_intersect_key($this->body($request), array_flip(self::STATION_FIELDS));

        return $this->call($response, function () use ($station, $changes): array {
            $enable = ($changes['enabled'] ?? null) === true;
            // The detector syncs a newly enabled station at once, so it needs access before the change.
            if ($enable) {
                $this->access->grant($station);
            }
            try {
                $status = $this->detector->request('PUT', $this->path($station), $changes);
            } catch (DetectorError $error) {
                if ($enable) {
                    $this->access->revoke($station);
                }
                throw $error;
            }
            if (($status['settings']['enabled'] ?? false) !== true) {
                $this->access->revoke($station);
            }

            return ['station' => $status];
        });
    }

    public function keyAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $station = $this->verified($request);
        $key = $this->body($request)['key'] ?? null;

        return $this->call($response, fn(): array => ['station' => is_string($key) && $key !== ''
            ? $this->detector->request('PUT', $this->path($station, '/key'), ['key' => $key])
            : $this->detector->request('DELETE', $this->path($station, '/key'))]);
    }

    public function actionAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $station = $this->verified($request);
        $action = $params['action'];
        $body = $this->body($request);
        $paths = [
            'process' => '/process', 'retry' => '/retry', 'backfill' => '/backfill', 'worker' => '/worker/retire',
        ];
        if (!isset($paths[$action])) {
            return $response->withStatus(404);
        }

        return $this->call($response, fn(): array => $this->detector->request(
            'POST',
            $this->path($station, $paths[$action]),
            $body
        ));
    }

    private function path(Station $station, string $suffix = ''): string
    {
        return '/api/v1/stations/' . $station->id . $suffix;
    }

    private function verified(ServerRequest $request): Station
    {
        $request->getCsrf()->verify($request->getHeaderLine('X-CSRF-Token'), 'sweepers');

        return $request->getStation();
    }

    /**
     * @return array<mixed>
     */
    private function body(ServerRequest $request): array
    {
        $body = json_decode((string)$request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($body)) {
            throw new InvalidArgumentException('Expected a JSON object.');
        }

        return $body;
    }

    private function call(Response $response, callable $callback): ResponseInterface
    {
        try {
            return $this->json($response, $callback());
        } catch (DetectorError $error) {
            return $this->json($response->withStatus($error->status), ['message' => $error->getMessage()]);
        }
    }

    private function json(Response $response, array $data): ResponseInterface
    {
        return $response->withHeader('Cache-Control', 'no-store')->withJson($data);
    }
}
