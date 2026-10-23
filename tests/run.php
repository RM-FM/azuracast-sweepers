<?php

declare(strict_types=1);

use Plugin\AzuracastSweepers\Service\Media;
use Plugin\AzuracastSweepers\Service\State;
use Plugin\AzuracastSweepers\ValueObject\Settings;
use Plugin\AzuracastSweepers\ValueObject\Timing;

spl_autoload_register(static function (string $class): void {
    $prefix = 'Plugin\\AzuracastSweepers\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rejects(callable $callback): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        check(true, 'Rejected invalid input.');

        return;
    }
    check(false, 'Invalid input was accepted.');
}

$settings = new Settings(enabled: true, playlistId: 1);
$timing = Timing::fromArray(['cue_in' => 1, 'cue_out' => 10, 'cross_start_next' => 7], 12);
check($timing !== null, 'Valid jingle timing.');
$fit = $timing->fit(12, 2, 180, $settings);
check(abs($fit['offset'] - 3.2) < 0.001, 'Subtract song cue-in once.');
check(abs($fit['duck_duration'] - 6) < 0.001, 'Use body handover, not jingle file end.');
check($timing->fit(30, 0, 180, $settings)['offset'] === 5.0, 'Cap the delay for a long intro.');
check($timing->fit(5, 0, 180, $settings) === null, 'Reject a jingle body that does not fit.');
check($timing->fit(12, 2, 13, $settings) === null, 'The tail must fit before the next transition.');
check($timing->fit(12, 2, 180, new Settings(maxWait: 0))['offset'] === 0.0, 'Allow an immediate overlay.');
check($timing->fit(NAN, 0, 100, $settings) === null, 'Reject nonfinite intro.');

foreach ([
    ['cue_in' => 2, 'cue_out' => 1],
    ['cue_in' => 0, 'cue_out' => 20],
    ['cue_in' => 0, 'cue_out' => 10, 'cross_start_next' => 11],
    ['cue_in' => 0, 'cue_out' => 10, 'amplify' => INF],
    [],
] as $invalid) {
    check(Timing::fromArray($invalid, 12) === null, 'Invalid AutoCue timing.');
}
check(Timing::fromArray(['cue_in' => 0, 'cue_out' => -2, 'fade_out' => 1], 12)->handover === 9.0, 'Relative cue-out.');
check(Timing::fromArray(['cue_in' => 0, 'cue_out' => 10, 'amplify' => '-3 dB'], 12)->gainDb === -3.0, 'dB gain.');
$metadata = [
    'liq_cue_in' => '',
    'liq_cue_out' => '',
    'liq_cross_start_next' => '',
    'liq_amplify' => '-3 dB',
    'autocue_cue_in' => '1',
    'autocue_cue_out' => '10',
    'autocue_start_next' => '7',
];
$cachedTiming = Timing::fromMetadata($metadata, 12);
check($cachedTiming->cueIn === 1.0 && $cachedTiming->handover === 7.0, 'Cached annotations need no cue fields.');
check($cachedTiming->gainDb === -3.0, 'Use annotation loudness.');
$metadata['liq_cue_in'] = '2';
check(Timing::fromMetadata($metadata, 12)->cueIn === 2.0, 'Resolved cue-in takes precedence.');
$metadata['autocue_start_next'] = '';
$metadata['autocue_fade_out'] = '2';
check(Timing::fromMetadata($metadata, 12)->handover === 8.0, 'Use effective fade when crossfade is absent.');
check(Timing::fromMetadata([], 12) === null, 'Missing annotations cannot invent a jingle window.');
$metadata['liq_amplify'] = '0.5';
check(abs(Timing::fromMetadata($metadata, 12)->gainDb + 6.0206) < 0.001, 'Linear amplification metadata.');
check(Settings::fromArray($settings->toArray())->toArray() === $settings->toArray(), 'Settings round trip.');
foreach ([['enabled' => 'false'], ['enabled' => true], ['duck_db' => -21], ['max_wait' => -1]] as $invalid) {
    rejects(static fn() => Settings::fromArray($invalid));
}

check(Media::parseIntro(null, 300) === null, 'Unknown is null.');
check(Media::parseIntro('0', 300) === 0.0, 'No intro is zero.');
check(Media::parseIntro('11.5', 300) === 11.5, 'Decimal cue.');
foreach ([-1, 301, NAN, 'vocals', true, '30s+'] as $invalid) {
    rejects(static fn() => Media::parseIntro($invalid, 300));
}

$directory = sys_get_temp_dir() . '/sweepers-test-' . bin2hex(random_bytes(6));
mkdir($directory);
$state = new State();
try {
    check($state->read($directory) === [], 'Empty state.');
    $state->update($directory, static fn() => ['history' => ['42' => 100]]);
    check($state->read($directory)['history'][42] === 100, 'State persists.');
    try {
        $state->update($directory, static fn() => throw new RuntimeException('Interrupted update.'));
    } catch (RuntimeException) {
    }
    check($state->read($directory)['history'][42] === 100, 'An interrupted update preserves the previous state.');
    check(glob($directory . '/sweepers-*') === [], 'No abandoned temporary state.');
} finally {
    foreach (glob($directory . '/*') as $path) {
        unlink($path);
    }
    rmdir($directory);
}

echo $checks . " checks passed.\n";
