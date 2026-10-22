<?php

declare(strict_types=1);

namespace Plugin\AzuracastDropins\Service;

use RuntimeException;

final class State
{
    public function read(string $directory): array
    {
        return $this->update($directory, static fn(array $data): array => $data);
    }

    public function update(string $directory, callable $callback): array
    {
        $lock = fopen($directory . '/dropins.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Cannot lock drop-in state.');
        }

        try {
            $path = $directory . '/dropins.json';
            $data = is_file($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];
            $updated = $callback($data);
            if ($updated !== $data) {
                $temporary = tempnam($directory, 'dropins-');
                if ($temporary === false) {
                    throw new RuntimeException('Cannot create drop-in state.');
                }
                try {
                    $json = json_encode($updated, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
                    if (file_put_contents($temporary, $json . "\n") === false || !rename($temporary, $path)) {
                        throw new RuntimeException('Cannot save drop-in state.');
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
