<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\Service;

use App\Radio\Backend\Liquidsoap;
use LogicException;
use Throwable;

final readonly class Verifier
{
    public function __construct(
        private Liquidsoap $liquidsoap
    ) {
    }

    /**
     * Type-checks a configuration with the station's Liquidsoap binary; returns the error, if any.
     */
    public function check(string $config): ?string
    {
        try {
            $this->liquidsoap->verifyConfig($config);

            return null;
        } catch (LogicException $exception) {
            return trim($exception->getMessage()) ?: 'Liquidsoap rejected the configuration';
        } catch (Throwable) {
            // AzuraCast's own check ignores failures to run Liquidsoap; so does this one.
            return null;
        }
    }

    /**
     * Identifies everything a check depends on: the configuration, the code it includes and the Liquidsoap
     * binary. An AzuraCast upgrade can change included runtime code while the configuration text stays the same.
     */
    public function fingerprint(string $config): string
    {
        $parts = [$config, Module::version()];
        preg_match_all('/^\s*%include\s+"([^"]+)"/m', $config, $includes);
        $directories = array_unique(array_map('dirname', $includes[1]));
        sort($directories);
        foreach ($directories as $directory) {
            $files = glob($directory . '/*.liq') ?: [];
            sort($files);
            foreach ($files as $file) {
                $parts[] = $file . ':' . hash_file('sha256', $file);
            }
        }
        try {
            $binary = $this->liquidsoap->getBinary();
            $parts[] = $binary . ':' . (@filesize($binary) ?: 0) . ':' . (@filemtime($binary) ?: 0);
        } catch (Throwable) {
            $parts[] = 'unknown binary';
        }

        return hash('sha256', implode("\n", $parts));
    }
}
