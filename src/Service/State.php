<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\Service;

use RuntimeException;

final class State
{
    public function read(string $directory): array
    {
        return $this->update($directory, static fn(array $data): array => $data);
    }

    public function update(string $directory, callable $callback): array
    {
        $lock = fopen($directory . '/sweepers.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Cannot lock sweeper state.');
        }

        try {
            self::ownership($directory, $directory . '/sweepers.lock');
            $path = $directory . '/sweepers.json';
            $historyPath = $directory . '/sweepers-history.json';
            $stored = self::load($path);
            // The timeline keeps its own file so the frequently written state stays small;
            // a log still inside the state was written by an older version and is newer.
            $migrating = array_key_exists('log', $stored);
            $log = $migrating ? $stored['log'] : (is_file($historyPath) ? self::load($historyPath) : null);
            $data = $stored;
            unset($data['log']);
            if ($log !== null) {
                $data['log'] = $log;
            }

            $updated = $callback($data);
            $updatedState = $updated;
            unset($updatedState['log']);
            $updatedLog = $updated['log'] ?? null;
            if ($updatedLog === null && is_file($historyPath)) {
                unlink($historyPath);
            } elseif ($updatedLog !== null && ($migrating || $updatedLog !== $log)) {
                self::save($directory, $historyPath, $updatedLog, 0);
            }
            if ($updatedState !== $stored) {
                self::save($directory, $path, $updatedState, JSON_PRETTY_PRINT);
            }

            return $updated;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function load(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException('Cannot read sweeper state: ' . $path);
        }

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    private static function save(string $directory, string $path, array $data, int $flags): void
    {
        $temporary = tempnam($directory, 'sweepers-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot create sweeper state.');
        }
        try {
            self::ownership($directory, $temporary);
            $json = json_encode($data, $flags | JSON_THROW_ON_ERROR);
            if (file_put_contents($temporary, $json . "\n") === false || !rename($temporary, $path)) {
                throw new RuntimeException('Cannot save sweeper state.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private static function ownership(string $directory, string $path): void
    {
        // CLI maintenance must not leave state or its lock owned by root.
        $owner = fileowner($directory);
        $group = filegroup($directory);
        if (
            $owner === false || $group === false
            || (fileowner($path) !== $owner && !chown($path, $owner))
            || (filegroup($path) !== $group && !chgrp($path, $group))
        ) {
            throw new RuntimeException('Cannot set sweeper state ownership: ' . $path);
        }
    }
}
