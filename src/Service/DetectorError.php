<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\Service;

use RuntimeException;

final class DetectorError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}
