<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Plugin\AzuracastSweepers\Service\Module;
use Plugin\AzuracastSweepers\Service\Release;

function expect(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}

$notes = static fn(string $restart): string => "Intro\n\n**AzuraCast Broadcast (Liquidsoap) restart: $restart** when upgrading.";
expect(Release::restart($notes('Not required')) === Release::RESTART_NOT_REQUIRED, 'Not required is parsed.');
expect(Release::restart($notes('Required for initial installation')) === Release::RESTART_REQUIRED, 'Required.');
expect(Release::restart("AzuraCast Broadcast (Liquidsoap) restart:\n**Required**") === Release::RESTART_REQUIRED, 'Bold.');
expect(Release::restart('No statement.') === Release::RESTART_UNKNOWN, 'A missing statement stays unknown.');

$release = static fn(string $tag, string $restart, array $extra = []): array => $extra + [
    'tag_name' => $tag,
    'html_url' => "https://github.com/RM-FM/azuracast-sweepers/releases/tag/$tag",
    'published_at' => '2026-11-01T10:00:00Z',
    'draft' => false,
    'prerelease' => false,
    'body' => $notes($restart),
];
$releases = Release::parse(json_encode([
    $release('1.2.0', 'Not required'),
    $release('1.3.0-beta', 'Required'),
    $release('1.4.0', 'Required', ['draft' => true]),
    $release('1.1.0', 'Not required'),
    $release('1.0.1', 'Not required'),
], JSON_THROW_ON_ERROR));
expect(array_column($releases, 'version') === ['1.2.0', '1.1.0', '1.0.1'], 'Drafts, pre-releases and odd tags skip.');
expect(Release::parse('{"message":"API rate limit exceeded"}') === null, 'An API error is no release list.');

$compare = Release::compare('1.0.1', $releases);
expect($compare['latest']['version'] === '1.2.0', 'The newest release is offered.');
expect(array_column($compare['updates'], 'version') === ['1.2.0', '1.1.0'], 'Every skipped release counts.');
expect($compare['restart'] === Release::RESTART_NOT_REQUIRED, 'No newer release needs a restart.');
$releases[1]['restart'] = Release::RESTART_REQUIRED;
expect(Release::compare('1.0.1', $releases)['restart'] === Release::RESTART_REQUIRED, 'Any required restart wins.');
expect(Release::compare('1.1.0', $releases)['restart'] === Release::RESTART_NOT_REQUIRED, 'Installed releases do not.');
$releases[0]['restart'] = Release::RESTART_UNKNOWN;
expect(Release::compare('1.1.0', $releases)['restart'] === Release::RESTART_UNKNOWN, 'Unknown is not hidden.');
$current = Release::compare('1.2.0', $releases);
expect($current['latest'] === null && $current['updates'] === [] && $current['restart'] === null, 'Up to date.');
expect(Release::compare(null, $releases)['latest'] === null, 'An unknown installed version offers nothing.');

$root = sys_get_temp_dir() . '/sweepers-release-' . bin2hex(random_bytes(6));
mkdir($root . '/.git/refs/heads', 0777, true);
try {
    file_put_contents($root . '/CHANGELOG.md', "# Release notes\n\n## 1.2.0 - Pending testing approval\n\n"
        . "## 1.1.0 - 2026-11-01\n\n## 1.0.0 - Baseline\n");
    file_put_contents($root . '/.git/HEAD', "ref: refs/heads/main\n");
    file_put_contents($root . '/.git/packed-refs', "# pack-refs\n" . str_repeat('a', 40) . " refs/heads/main\n");
    $installed = Release::installed($root);
    expect($installed === ['version' => '1.1.0', 'branch' => 'main', 'commit' => 'aaaaaaa'], 'Packed checkout.');
    file_put_contents($root . '/.git/refs/heads/main', str_repeat('b', 40) . "\n");
    expect(Release::installed($root)['commit'] === 'bbbbbbb', 'A loose ref overrides the packed one.');
    file_put_contents($root . '/.git/HEAD', str_repeat('c', 40) . "\n");
    expect(Release::installed($root)['branch'] === null && Release::installed($root)['commit'] === 'ccccccc', 'Detached.');
    unlink($root . '/.git/HEAD');
    expect(Release::installed($root) === ['version' => '1.1.0', 'branch' => null, 'commit' => null], 'No checkout.');
} finally {
    exec('rm -rf ' . escapeshellarg($root));
}

$module = "# comment\nlet x = 1\n\n  # indented comment\n  def f() =\n    x\n  end\n";
$reformatted = "# other words\r\nlet x = 1\r\ndef f() =\r\nx\r\nend";
expect(Module::fingerprint($module) === Module::fingerprint($reformatted), 'Comments and layout need no restart.');
expect(Module::fingerprint($module) !== Module::fingerprint(str_replace('1', '2', $module)), 'Code changes do.');
$now = 1_000_000;
foreach (['dc51bce33df9', 'a1fea449701c'] as $legacy) {
    $status = Module::status(['heartbeat' => ['at' => $now - 20, 'version' => $legacy]], $now)['state'];
    expect(
        $status === (Module::version() === '8d4ccec460ff' ? 'active' : 'outdated'),
        'Stations running the comment-only variant of the current module need no restart.'
    );
}

echo "Release notes, update check and module fingerprint passed.\n";
