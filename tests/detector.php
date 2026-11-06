<?php

declare(strict_types=1);

use Plugin\AzuracastSweepers\Service\DetectorClient;
use Plugin\AzuracastSweepers\Service\DetectorError;

spl_autoload_register(static function (string $class): void {
    $prefix = 'Plugin\\AzuracastSweepers\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function failure(callable $callback): ?DetectorError
{
    try {
        $callback();
    } catch (DetectorError $error) {
        return $error;
    }

    return null;
}

$token = str_repeat('a', 40);
$directory = sys_get_temp_dir() . '/sweepers-detector-' . bin2hex(random_bytes(4));
mkdir($directory);
$router = $directory . '/router.php';
file_put_contents($router, <<<'PHP'
<?php
header('Content-Type: application/json');
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer ' . str_repeat('a', 40)) {
    http_response_code(401);
    echo '{"error":"Unauthorized"}';
    return;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/api/v1/stations/3' && $_SERVER['REQUEST_METHOD'] === 'PUT') {
    echo json_encode(['received' => json_decode(file_get_contents('php://input'), true)]);
} elseif ($path === '/api/v1/stations/3/process') {
    http_response_code(409);
    echo '{"error":"The Intro Detector is not enabled for this station"}';
} elseif ($path === '/api/v1/broken') {
    echo 'not json';
} else {
    echo '{"status":"ok"}';
}
PHP);
$port = 18000 + random_int(0, 999);
$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:$port", $router],
    [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
    $pipes
);
try {
    for ($attempt = 0; $attempt < 50 && @fsockopen('127.0.0.1', $port) === false; ++$attempt) {
        usleep(100000);
    }
    $file = $directory . '/' . DetectorClient::FILE;
    $client = new DetectorClient($file);
    check(!$client->installed(), 'Without a connection file the detector is not installed.');
    check(failure(fn() => $client->request('GET', '/api/v1/health'))?->status === 404, 'Missing file is reported.');

    file_put_contents($file, json_encode(['url' => 'https://elsewhere.example', 'token' => $token]));
    check(failure(fn() => $client->request('GET', '/api/v1/health'))?->status === 500, 'Only internal HTTP is used.');

    file_put_contents($file, json_encode(['url' => "http://127.0.0.1:$port", 'token' => $token]));
    check($client->installed(), 'The connection file marks the detector as installed.');
    check($client->request('GET', '/api/v1/health') === ['status' => 'ok'], 'Authorized requests succeed.');
    check(
        $client->request('PUT', '/api/v1/stations/3', ['enabled' => true]) === ['received' => ['enabled' => true]],
        'JSON bodies reach the detector.'
    );
    $error = failure(fn() => $client->request('POST', '/api/v1/stations/3/process'));
    check($error?->status === 409 && str_contains($error->getMessage(), 'not enabled'), 'Detector errors pass through.');
    check(failure(fn() => $client->request('GET', '/api/v1/broken'))?->status === 502, 'Invalid JSON is rejected.');

    file_put_contents($file, json_encode(['url' => "http://127.0.0.1:$port", 'token' => str_repeat('b', 40)]));
    check(failure(fn() => $client->request('GET', '/api/v1/health'))?->status === 401, 'A wrong token is refused.');

    file_put_contents($file, json_encode(['url' => 'http://127.0.0.1:1', 'token' => $token]));
    check(failure(fn() => $client->request('GET', '/api/v1/health'))?->status === 503, 'An unreachable detector is reported.');
    echo "Intro Detector client passed.\n";
} finally {
    proc_terminate($server);
    proc_close($server);
    array_map('unlink', glob($directory . '/{,.}*.*', GLOB_BRACE) ?: []);
    rmdir($directory);
}
