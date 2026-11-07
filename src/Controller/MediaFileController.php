<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\Controller;

use App\Flysystem\StationFilesystems;
use App\Http\Response;
use App\Http\ServerRequest;
use Plugin\AzuracastSweepers\Service\Media;
use Psr\Http\Message\ResponseInterface;

/**
 * Original media file for the Intro Detector when AzuraCast's own download fails.
 */
final readonly class MediaFileController
{
    public function __construct(
        private Media $media,
        private StationFilesystems $filesystems
    ) {
    }

    public function downloadAction(ServerRequest $request, Response $response, array $params): ResponseInterface
    {
        set_time_limit(600);
        $station = $request->getStation();
        $media = $this->media->get($station, (int)$params['id']);

        // AzuraCast's play route passes the path to nginx unencoded, which fails for names containing "%".
        return $response->streamFilesystemFile(
            $this->filesystems->getMediaFilesystem($station),
            $media->path,
            null,
            'attachment',
            false
        );
    }
}
