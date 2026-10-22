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
            $path = $directory . '/sweepers.json';
            $data = is_file($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];
            $updated = $callback($data);
            if ($updated !== $data) {
                $temporary = tempnam($directory, 'sweepers-');
                if ($temporary === false) {
                    throw new RuntimeException('Cannot create sweeper state.');
                }
                try {
                    $json = json_encode($updated, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
                    if (file_put_contents($temporary, $json . "\n") === false || !rename($temporary, $path)) {
                        throw new RuntimeException('Cannot save sweeper state.');
                    }
                } finally {
                    if (is_file($temporary)) {
                        unlink($temporary);
                    }
                }
            }

            return $updated;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
