<?php

declare(strict_types=1);

namespace Plugin\AzuracastDropins\Controller;

use App\Http\Response;
use App\Http\ServerRequest;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Plugin\AzuracastDropins\Service\Media;
use Plugin\AzuracastDropins\Service\State;
use Plugin\AzuracastDropins\ValueObject\Settings;
use Psr\Http\Message\ResponseInterface;

final readonly class PanelController
{
    public function __construct(
        private Media $media,
        private State $state,
        private EntityManagerInterface $em
    ) {
    }

    public function indexAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $this->media->ensureField();
        $base = rtrim($request->getUri()->getPath(), '/');
        $html = file_get_contents(dirname(__DIR__, 2) . '/frontend/index.html');
        $html = strtr($html, [
            '__BASE__' => htmlspecialchars($base, ENT_QUOTES),
            '__CSRF__' => htmlspecialchars($request->getCsrf()->generate('dropins'), ENT_QUOTES),
        ]);
        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
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
            'station' => ['id' => $station->id, 'name' => $station->name, 'needs_restart' => $station->needs_restart],
            'settings' => Settings::fromArray($data['settings'] ?? [])->toArray(),
            'playlists' => array_map(static fn($playlist): array => [
                'id' => $playlist->id,
                'name' => $playlist->name,
            ], $this->media->playlists($station)),
        ]);
    }

    public function settingsAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $request->getCsrf()->verify($request->getHeaderLine('X-CSRF-Token'), 'dropins');
        $settings = Settings::fromArray($this->body($request));
        $station = $request->getStation();
        if ($settings->enabled) {
            $this->media->jingles($station, $settings->playlistId);
        }

        $this->state->update($station->getRadioConfigDir(), static function (array $data) use ($settings): array {
            $data['settings'] = $settings->toArray();

            return $data;
        });
        $station->needs_restart = true;
        $this->em->flush();

        return $response->withJson(['success' => true, 'needs_restart' => true]);
    }

    public function mediaAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $station = $request->getStation();
        $data = $this->state->read($station->getRadioConfigDir());
        $search = substr((string)$request->getQueryParam('search', ''), 0, 200);
        $router = $request->getRouter();
        $rows = [];

        foreach ($this->media->list($station, $search) as $media) {
            $review = $data['intros'][$media->id] ?? null;
            $rows[] = [
                'id' => $media->id,
                'unique_id' => $media->unique_id,
                'mtime' => $media->mtime,
                'path' => $media->path,
                'duration' => $media->length,
                'intro_end' => $this->media->intro($media),
                'review' => $review,
                'stale' => $review !== null && $review['identity'] !== Media::identity($media),
                'timing' => $this->media->timing($media),
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

    public function introAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        $request->getCsrf()->verify($request->getHeaderLine('X-CSRF-Token'), 'dropins');

        return $this->saveIntro($request, $response, (int)$params['id'], true);
    }

    public function importAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $this->saveIntro($request, $response, (int)$params['id'], false);
    }

    private function saveIntro(ServerRequest $request, Response $response, int $id, bool $manual): ResponseInterface
    {
        $station = $request->getStation();
        $media = $this->media->get($station, $id);
        $body = $this->body($request);
        $identity = Media::identity($media);
        if (!$manual && (($body['unique_id'] ?? null) !== $media->unique_id || ($body['mtime'] ?? null) !== $media->mtime)) {
            return $response->withStatus(409)->withJson(['message' => 'The recording changed. Read its identity again.']);
        }

        $status = $body['status'] ?? 'confirmed';
        $kind = $body['kind'] ?? 'vocal';
        if (!in_array($status, ['confirmed', 'unknown', 'no_intro', 'veto'], true)
            || !in_array($kind, ['vocal', 'musical', '30s+'], true)) {
            throw new InvalidArgumentException('Invalid intro status or type.');
        }
        $value = Media::parseIntro($body['intro_end'] ?? null, $media->length);
        if ($status === 'confirmed' && ($value === null || ($kind === '30s+' && $value !== 30.0))) {
            throw new InvalidArgumentException('A confirmed intro requires a valid boundary.');
        }
        $blocked = false;
        $locked = $manual && ($body['lock_manual'] ?? true) === true;
        $this->state->update($station->getRadioConfigDir(), function (array $data) use (
            $manual,
            $media,
            $identity,
            $status,
            $kind,
            $value,
            $locked,
            &$blocked
        ): array {
            $old = $data['intros'][$media->id] ?? [];
            if (!$manual && ($old['manual'] ?? false) && ($old['identity'] ?? '') === $identity) {
                $blocked = true;

                return $data;
            }

            $active = match ($status) {
                'confirmed' => $value,
                'no_intro' => 0.0,
                default => null,
            };
            $this->media->saveIntro($media, $active);
            $data['intros'][$media->id] = [
                'identity' => $identity,
                'mtime' => $media->mtime,
                'status' => $status,
                'kind' => $kind,
                'manual' => $locked,
                'candidate' => $value,
                'updated_at' => time(),
            ];

            return $data;
        });

        return $blocked
            ? $response->withStatus(409)->withJson(['message' => 'A manual intro review is locked for this recording.'])
            : $response->withJson(['success' => true]);
    }

    private function body(ServerRequest $request): array
    {
        return json_decode((string)$request->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
