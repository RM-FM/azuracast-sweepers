<?php

declare(strict_types=1);

// Applies the plugin's configuration rollback to a configuration read from stdin.
require dirname(__DIR__) . '/src/Service/Module.php';

$lines = explode("\n", stream_get_contents(STDIN));
echo implode("\n", array_replace($lines, \Plugin\AzuracastSweepers\Service\Module::rollback($lines) ?? []));
