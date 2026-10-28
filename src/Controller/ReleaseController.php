<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\Controller;

use App\Http\Response;
use App\Http\ServerRequest;
use Plugin\AzuracastSweepers\Service\Release;
use Psr\Http\Message\ResponseInterface;

final readonly class ReleaseController
{
    public function __construct(
        private Release $release
    ) {
    }

    public function releaseAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        return $response->withHeader('Cache-Control', 'no-store')->withJson($this->release->check());
    }
}
