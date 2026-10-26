<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\ValueObject;

final readonly class Timing
{
    public function __construct(
        public float $cueIn,
        public float $cueOut,
        public float $handover,
        public float $gainDb = 0.0
    ) {
    }

    public static function fromArray(array $data, float $duration): ?self
    {
        $cueIn = $data['cue_in'] ?? null;
        $cueOut = $data['cue_out'] ?? null;
        $fadeOut = $data['fade_out'] ?? 0.0;

        foreach ([$cueIn, $cueOut, $fadeOut, $duration] as $value) {
            if (!is_numeric($value) || !is_finite((float)$value)) {
                return null;
            }
        }

        $cueIn = (float)$cueIn;
        $cueOut = (float)$cueOut;
        if ($cueOut < 0) {
            $cueOut = $duration + $cueOut;
        }

        $handover = $data['cross_start_next'] ?? ($cueOut - (float)$fadeOut);
        $gain = str_replace(['dB', ' '], '', (string)($data['amplify'] ?? '0'));
        if (!is_numeric($handover) || !is_numeric($gain)) {
            return null;
        }

        $handover = (float)$handover;
        $gain = (float)$gain;
        if (
            !is_finite($handover) || !is_finite($gain)
            || $cueIn < 0 || $cueOut > $duration || $cueOut <= $cueIn
            || (float)$fadeOut < 0 || $handover <= $cueIn || $handover > $cueOut || abs($gain) > 40
        ) {
            return null;
        }

        return new self($cueIn, $cueOut, $handover, $gain);
    }

    public static function fromMetadata(array $metadata, float $duration): ?self
    {
        $value = static function (string $effective, string $cached) use ($metadata): mixed {
            $value = $metadata[$effective] ?? '';
            $value = $value !== '' ? $value : ($metadata[$cached] ?? null);

            return $value === '' ? null : $value;
        };
        $amplify = trim((string)($metadata['liq_amplify'] ?? ''));
        if ($amplify === '') {
            $gainDb = 0.0;
        } elseif (str_ends_with($amplify, 'dB')) {
            $gainDb = trim(substr($amplify, 0, -2));
        } elseif (is_numeric($amplify) && (float)$amplify > 0) {
            $gainDb = 20 * log10((float)$amplify);
        } else {
            return null;
        }

        return self::fromArray([
            'cue_in' => $value('liq_cue_in', 'autocue_cue_in'),
            'cue_out' => $value('liq_cue_out', 'autocue_cue_out'),
            'cross_start_next' => $value('liq_cross_start_next', 'autocue_start_next'),
            'fade_out' => $value('liq_fade_out', 'autocue_fade_out') ?: 0.0,
            'amplify' => $gainDb,
        ], $duration);
    }

    public function fit(float $introEnd, float $songCueIn, float $songCueOut, Settings $settings): ?array
    {
        foreach ([$introEnd, $songCueIn, $songCueOut] as $value) {
            if (!is_finite($value)) {
                return null;
            }
        }

        $body = $this->handover - $this->cueIn;
        $available = $introEnd - $songCueIn - $settings->margin;
        $offset = $available - $body;
        if ($settings->limitDelay && $offset > $settings->maxWait) {
            // Never later than the aligned start, so the body still fits.
            $offset = min($settings->earlyStart, $offset);
        }
        if (
            $songCueIn < 0 || $introEnd > $songCueOut || $offset < 0
            || $offset + $this->cueOut - $this->cueIn > $songCueOut - $songCueIn
        ) {
            return null;
        }

        return ['offset' => $offset, 'duck_duration' => $body];
    }
}
