<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\ValueObject;

use InvalidArgumentException;
use Plugin\AzuracastSweepers\Service\Media;
use RuntimeException;

final class Review
{
    public static function prepare(array $body, bool $manual, array $recording, array $old): array
    {
        if (
            ($body['unique_id'] ?? null) !== $recording['unique_id']
            || ($body['mtime'] ?? null) !== $recording['mtime']
            || ($manual && ($body['identity'] ?? null) !== $recording['identity'])
            || (isset($body['size']) && $body['size'] !== $recording['size'])
        ) {
            throw new RuntimeException('The recording changed. Reload it before saving.', 409);
        }
        if (!$manual && ($old['manual'] ?? false) && ($old['identity'] ?? '') === $recording['identity']) {
            throw new RuntimeException('A manual intro review is locked for this recording.', 409);
        }
        $status = $body['status'] ?? 'confirmed';
        $kind = $body['kind'] ?? 'vocal';
        if (
            !in_array($status, ['confirmed', 'unknown', 'no_intro', 'veto'], true)
            || !in_array($kind, ['vocal', 'musical', '30s+'], true)
        ) {
            throw new InvalidArgumentException('Invalid intro status or type.');
        }
        $value = Media::parseIntro($body['intro_end'] ?? null, $recording['duration']);
        if ($status === 'confirmed' && ($value === null || ($kind === '30s+' && $value !== 30.0))) {
            throw new InvalidArgumentException('A confirmed intro requires a valid boundary.');
        }

        return [
            'identity' => $recording['identity'],
            'status' => $status,
            'kind' => $kind,
            'manual' => $manual && ($body['lock_manual'] ?? true) === true,
            'candidate' => $value,
            'updated_at' => time(),
        ];
    }

    public static function active(array $review): ?float
    {
        return match ($review['status']) {
            'confirmed' => $review['candidate'],
            'no_intro' => 0.0,
            default => null,
        };
    }
}
