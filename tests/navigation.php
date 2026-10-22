<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Plugin\AzuracastSweepers\Middleware\SidebarNavigation;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

$checks = 0;
function verify(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function inject(string $path, Response $response, string $method = 'GET', string $base = ''): ResponseInterface
{
    $handler = new class ($response) implements RequestHandlerInterface {
        public function __construct(private readonly ResponseInterface $response)
        {
        }

        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            return $this->response;
        }
    };

    return new SidebarNavigation($base)->process(new ServerRequest($method, $path), $handler);
}

$html = '<html><body><main>Dashboard</main></body></html>';
$response = new Response(200, ['Content-Type' => 'text/html; charset=utf-8', 'Content-Length' => strlen($html)], $html);
foreach (['/dashboard', '/station/1', '/station/2/files', '/admin/settings', '/profile'] as $path) {
    $result = inject($path, $response);
    $body = (string)$result->getBody();
    verify(str_contains($body, 'src="/sweepers/assets/navigation.js"'), 'Inject in dashboard shell.');
    verify(strpos($body, '<script') < strpos($body, '</body>'), 'Load before closing body.');
    verify(!$result->hasHeader('Content-Length'), 'Remove stale body length.');
    verify(inject($path, $result) === $result, 'Do not inject twice.');
}
foreach (['/login', '/public/radio', '/api/station/1/files', '/sweepers/1', '/stationery'] as $path) {
    verify(inject($path, $response) === $response, 'Leave other routes unchanged.');
}
verify(inject('/station/1', $response, 'POST') === $response, 'Do not alter POST responses.');
foreach ([
    new Response(302, ['Location' => '/login']),
    new Response(403, ['Content-Type' => 'text/html'], $html),
    new Response(200, ['Content-Type' => 'application/json'], '{}'),
    new Response(200, ['Content-Type' => 'text/html', 'Content-Encoding' => 'gzip'], 'compressed'),
    new Response(200, ['Content-Type' => 'text/html'], '<main>partial</main>'),
] as $untouched) {
    verify(inject('/station/1', $untouched) === $untouched, 'Leave redirects, errors, JSON and fragments untouched.');
}
$body = (string)inject('/radio/station/1', $response, base: '/radio')->getBody();
verify(str_contains($body, 'src="/radio/sweepers/assets/navigation.js"'), 'Respect the application base path.');
verify(inject('/station/1', $response, base: '/radio') === $response, 'Do not inject outside the application base.');

echo $checks . " navigation checks passed.\n";
