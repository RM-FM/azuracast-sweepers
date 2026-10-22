<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\Middleware;

use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class SidebarNavigation implements MiddlewareInterface
{
    public function __construct(
        private string $basePath = ''
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $base = rtrim($this->basePath, '/');
        $path = $request->getUri()->getPath();
        if (!str_starts_with($path, $base . '/')) {
            return $response;
        }
        $path = substr($path, strlen($base));
        if (
            $request->getMethod() !== 'GET'
            || !preg_match('~^/(?:dashboard|profile|admin|station)(?:/|$)~', $path)
            || $response->getStatusCode() !== 200
            || !str_starts_with(strtolower($response->getHeaderLine('Content-Type')), 'text/html')
            || $response->hasHeader('Content-Encoding')
        ) {
            return $response;
        }

        $body = (string)$response->getBody();
        $position = strripos($body, '</body>');
        if ($position === false || str_contains($body, 'id="azuracast-sweepers-navigation"')) {
            return $response;
        }
        $source = htmlspecialchars($base . '/sweepers/assets/navigation.js', ENT_QUOTES);
        $script = '<script type="module" id="azuracast-sweepers-navigation" src="' . $source . '"></script>';
        $body = substr_replace($body, $script, $position, 0);

        return $response->withBody(Utils::streamFor($body))
            ->withoutHeader('Content-Length')
            ->withoutHeader('ETag');
    }
}
