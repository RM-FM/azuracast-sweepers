<?php

declare(strict_types=1);

// Checks the AzuraCast APIs the plugin relies on; run inside an AzuraCast image.

$root = getenv('AZURACAST_ROOT') ?: '/var/azuracast/www';
if (!is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "Run this check inside an AzuraCast image.\n");
    exit(2);
}
require $root . '/vendor/autoload.php';

$plugin = dirname(__DIR__);
$failures = [];

function symbolExists(string $name): bool
{
    return class_exists($name) || interface_exists($name) || trait_exists($name) || enum_exists($name);
}

function phpFiles(string $directory): iterable
{
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() === 'php') {
            yield $file->getPathname();
        }
    }
}

foreach (phpFiles($plugin . '/src') as $file) {
    $code = (string)file_get_contents($file);
    preg_match_all('/^use (App\\\\[\w\\\\]+);/m', $code, $imports);
    foreach ($imports[1] as $import) {
        $alias = substr($import, strrpos($import, '\\') + 1);
        preg_match_all('/(?<![\w\\\\])' . $alias . '\\\\([\w\\\\]+)/', $code, $nested);
        $symbols = $nested[1] === [] ? [$import] : array_map(fn(string $rest): string => $import . '\\' . $rest, $nested[1]);
        foreach (array_unique($symbols) as $symbol) {
            if (!symbolExists($symbol)) {
                $failures[] = "Missing $symbol (used in " . basename($file) . ')';
            }
        }
    }
}

$methods = [
    'App\Event\BuildRoutes' => ['getApp'],
    'App\Event\Radio\WriteLiquidsoapConfiguration' => [
        'getStation', 'getBackendConfig', 'isForEditing', 'shouldWriteToDisk',
        'appendBlock', 'buildConfiguration', 'replaceLine',
    ],
    'App\Event\Radio\AnnotateNextSong' => ['fromStationMedia'],
    'App\Entity\Station' => ['getRadioConfigDir', 'validateAdapterApiKey'],
    'App\Entity\StationQueue' => ['fromMedia'],
    'App\Entity\Repository\StationPlaylistMediaRepository' => ['getQueue', 'findByPlaylistAndMedia'],
    'App\Entity\Repository\StationQueueRepository' => ['getRecentlyPlayedByTimeRange'],
    'App\Entity\Repository\CustomFieldRepository' => ['getCustomFields'],
    'App\Radio\AutoDJ\DuplicatePrevention' => ['preventDuplicates'],
    'App\Radio\AutoDJ\Scheduler' => ['isPlaylistScheduledToPlayNow'],
    'App\Radio\Backend\Liquidsoap' => ['verifyConfig', 'getBinary'],
    'App\Lock\LockFactory' => ['createLock'],
    'App\Http\ServerRequest' => ['getStation', 'getRouter', 'getCsrf', 'getAcl', 'getQueryParam'],
    'App\Http\Response' => ['withJson'],
    'App\Http\RouterInterface' => ['named', 'fromHere'],
    'App\Session\Csrf' => ['generate', 'verify'],
    'App\Acl' => ['isAllowed'],
];
foreach ($methods as $class => $names) {
    foreach ($names as $name) {
        if (!symbolExists($class) || !(new ReflectionClass($class))->hasMethod($name)) {
            $failures[] = "Missing method $class::$name()";
        }
    }
}

$properties = [
    'App\Entity\Station' => ['id', 'short_name'],
    'App\Entity\StationBackendConfiguration' => ['use_manual_autodj'],
    'App\Entity\StationMedia' => ['id', 'length'],
    'App\Entity\StationPlaylist' => ['station', 'name', 'type', 'order', 'source', 'is_enabled', 'is_jingle'],
    'App\Entity\StationPlaylistMedia' => ['id', 'is_queued'],
    'App\Entity\StationQueue' => [
        'id', 'playlist', 'media', 'song_id', 'title', 'artist', 'timestamp_cued', 'timestamp_played',
    ],
    'App\Entity\StationMediaCustomField' => ['field', 'media', 'value'],
    'App\Entity\CustomField' => ['short_name'],
];
foreach ($properties as $class => $names) {
    foreach ($names as $name) {
        if (!symbolExists($class) || !property_exists($class, $name)) {
            $failures[] = "Missing property $class::\$$name";
        }
    }
}

$constants = [
    'App\Enums\StationPermissions::Broadcasting',
    'App\Enums\StationPermissions::Media',
    'App\Entity\Enums\PlaylistTypes::Advanced',
    'App\Entity\Enums\PlaylistSources::Songs',
    'App\Entity\Enums\PlaylistOrders::Sequential',
    'App\Entity\Enums\PlaylistOrders::Random',
    'App\Middleware\Auth\ApiAuth::API_CSRF_NAMESPACE',
];
foreach ($constants as $constant) {
    if (!defined($constant)) {
        $failures[] = "Missing constant or enum case $constant";
    }
}

$backend = is_dir($root . '/backend') ? $root . '/backend' : $root;
$sources = '';
foreach ([$backend . '/config', $backend . '/src'] as $directory) {
    foreach (is_dir($directory) ? phpFiles($directory) : [] as $file) {
        $sources .= file_get_contents($file) . "\n";
    }
}
foreach (['api:stations:restart', 'api:stations:files:play', 'api:stations:media:waveform'] as $route) {
    if (!str_contains($sources, "'$route'")) {
        $failures[] = "Missing route name $route";
    }
}

require_once $plugin . '/src/Service/Module.php';
foreach ([Plugin\AzuracastSweepers\Service\Module::AUTODJ, Plugin\AzuracastSweepers\Service\Module::CROSSFADE] as $line) {
    if (!str_contains($sources, $line)) {
        $failures[] = "Liquidsoap configuration no longer contains: $line";
    }
}

if ($failures !== []) {
    echo implode("\n", array_map(fn(string $failure): string => "FAIL $failure", $failures)), "\n";
    exit(1);
}
echo "AzuraCast API compatibility: OK\n";
