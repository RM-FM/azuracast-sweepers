<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\Controller;

use App\Http\Response;
use App\Http\ServerRequest;
use InvalidArgumentException;
use Plugin\AzuracastSweepers\Service\Media;
use Plugin\AzuracastSweepers\Service\Module;
use Plugin\AzuracastSweepers\Service\Queue;
use Plugin\AzuracastSweepers\Service\State;
use Plugin\AzuracastSweepers\ValueObject\Settings;
use Psr\Http\Message\ResponseInterface;

final readonly class PanelController
{
    public function __construct(
        private Media $media,
        private State $state
    ) {
    }

    public function indexAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $this->media->ensureField();
        $base = rtrim($request->getUri()->getPath(), '/');
        $html = file_get_contents(dirname(__DIR__, 2) . '/frontend/index.html');
        $html = strtr($html, [
            '__BASE__' => htmlspecialchars($base, ENT_QUOTES),
            '__CSRF__' => htmlspecialchars($request->getCsrf()->generate('sweepers'), ENT_QUOTES),
        ]);
        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function navigationAction(ServerRequest $request, Response $response): ResponseInterface
    {
        $response->getBody()->write(file_get_contents(dirname(__DIR__, 2) . '/frontend/navigation.js'));

        return $response->withHeader('Content-Type', 'text/javascript; charset=utf-8')
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    public function assetAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $name = $params['name'];
        if (!in_array($name, ['app.js', 'app.css'], true)) {
            return $response->withStatus(404);
        }
        $path = dirname(__DIR__, 2) . '/dist/' . $name;
        if (!is_file($path)) {
            $response->getBody()->write('Build the plugin frontend with npm install && npm run build.');

            return $response->withStatus(503)->withHeader('Content-Type', 'text/plain');
        }
        $response->getBody()->write(file_get_contents($path));

        return $response->withHeader('Content-Type', $name === 'app.js' ? 'text/javascript' : 'text/css')
            ->withHeader('Cache-Control', 'no-cache');
    }

    public function stateAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $station = $request->getStation();
        $data = $this->state->read($station->getRadioConfigDir());

        return $response->withHeader('Cache-Control', 'no-store')->withJson([
            'csrf' => $request->getCsrf()->generate('sweepers'),
            'station' => ['id' => $station->id, 'name' => $station->name, 'needs_restart' => $station->needs_restart],
            'queue_length' => Queue::length($station),
            'module' => Module::status($data),
            'settings' => Settings::fromArray($data['settings'] ?? [])->toArray(),
            'playlists' => array_map(static fn($playlist): array => [
                'id' => $playlist->id,
                'name' => $playlist->name,
            ], $this->media->playlists($station)),
            'jingle_playlists' => array_map(static fn($playlist): array => [
                'id' => $playlist->id,
                'name' => $playlist->name,
                'is_jingle' => $playlist->is_jingle,
            ], $this->media->playlists($station, false)),
        ]);
    }

    public function settingsAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $request->getCsrf()->verify($request->getHeaderLine('X-CSRF-Token'), 'sweepers');
        $settings = Settings::fromArray($this->body($request));
        $station = $request->getStation();
        if ($settings->songsBeforeJingle > Queue::length($station)) {
            throw new InvalidArgumentException(
                'Songs before a jingle cannot exceed the AutoDJ queue length (' . Queue::length($station) . ').'
            );
        }
        if ($settings->enabled) {
            if ($station->backend_config->use_manual_autodj) {
                throw new InvalidArgumentException('Sweepers require AzuraCast AutoDJ queue management.');
            }
            $this->media->jingles($station, $settings->playlistId);
        }

        $this->media->validateJinglePlaylists($station, $settings->jinglePlaylistIds);
        $directory = $station->getRadioConfigDir();
        $data = $this->state->update($directory, static function (array $data) use ($settings): array {
            $data['settings'] = $settings->toArray();

            return $data;
        });

        return $response->withJson([
            'success' => true,
            'needs_restart' => $station->needs_restart,
            'module' => Module::status($data),
        ]);
    }

    public function mediaAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $station = $request->getStation();
        $search = substr((string)$request->getQueryParam('search', ''), 0, 200);
        $router = $request->getRouter();
        $rows = [];

        foreach ($this->media->list($station, $search) as $media) {
            $rows[] = [
                'id' => $media->id,
                'path' => $media->path,
                'duration' => $media->length,
                'intro_end' => $this->media->intro($media),
                'audio_url' => $router->fromHere('api:stations:files:play', [
                    'station_id' => $station->id,
                    'id' => $media->id,
                ]),
                'waveform_url' => $router->fromHere('api:stations:media:waveform', [
                    'station_id' => $station->id,
                    'media_id' => $media->unique_id,
                    'timestamp' => $media->art_updated_at,
                ]),
            ];
        }

        return $response->withHeader('Cache-Control', 'no-store')->withJson(['rows' => $rows, 'limit' => 100]);
    }

    public function importAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $body = $this->body($request);
        if (count($body) !== 1 || !array_key_exists('intro_end', $body)) {
            throw new InvalidArgumentException('Supply only intro_end. Use null to clear it.');
        }
        $media = $this->media->get($request->getStation(), (int)$params['id']);
        $intro = $this->media->saveIntro($media, $body['intro_end']);

        return $response->withJson(['success' => true, 'intro_end' => $intro]);
    }

    public function introAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $request->getCsrf()->verify($request->getHeaderLine('X-CSRF-Token'), 'sweepers');

        return $this->importAction($request, $response, $params);
    }

    private function body(ServerRequest $request): array
    {
        $body = json_decode((string)$request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($body)) {
            throw new InvalidArgumentException('Expected a JSON object.');
        }

        return $body;
    }
}
