<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\Service;

use GuzzleHttp\Client;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Throwable;

final readonly class Release
{
    public const string REPOSITORY = 'RM-FM/azuracast-sweepers';
    public const string RESTART_REQUIRED = 'required';
    public const string RESTART_NOT_REQUIRED = 'not_required';
    public const string RESTART_UNKNOWN = 'unknown';
    private const string CACHE_KEY = 'azuracast_sweepers_releases';
    private const int CACHE_SECONDS = 21600;
    private const int RETRY_SECONDS = 900;

    public function __construct(
        private Client $http,
        private CacheInterface $cache
    ) {
    }

    public function check(): array
    {
        $installed = self::installed(dirname(__DIR__, 2));
        $releases = $this->releases();

        return [
            'installed' => $installed,
            'checked' => $releases !== null,
            'repository_url' => 'https://github.com/' . self::REPOSITORY,
        ] + self::compare($installed['version'], $releases ?? []);
    }

    /**
     * @return list<array{version: string, url: string, published_at: ?string, restart: string}>|null
     */
    private function releases(): ?array
    {
        return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            try {
                $response = $this->http->get('https://api.github.com/repos/' . self::REPOSITORY . '/releases', [
                    'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'AzuraCast-Sweepers'],
                    'query' => ['per_page' => 30],
                ]);
                $releases = $response->getStatusCode() === 200 ? self::parse((string)$response->getBody()) : null;
            } catch (Throwable) {
                $releases = null;
            }
            $item->expiresAfter($releases === null ? self::RETRY_SECONDS : self::CACHE_SECONDS);

            return ['releases' => $releases];
        })['releases'];
    }

    /**
     * @return list<array{version: string, url: string, published_at: ?string, restart: string}>|null
     */
    public static function parse(string $json): ?array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !array_is_list($data)) {
            return null;
        }
        $releases = [];
        foreach ($data as $release) {
            $tag = (string)($release['tag_name'] ?? '');
            if (($release['draft'] ?? true) || ($release['prerelease'] ?? true) || !self::isVersion($tag)) {
                continue;
            }
            $releases[] = [
                'version' => $tag,
                'url' => (string)($release['html_url'] ?? ''),
                'published_at' => $release['published_at'] ?? null,
                'restart' => self::restart((string)($release['body'] ?? '')),
            ];
        }

        return $releases;
    }

    // Parses the line every release note must contain; see AGENTS.md.
    public static function restart(string $notes): string
    {
        if (!preg_match('/AzuraCast Broadcast \(Liquidsoap\) restart:\W*(Not required|Required)/i', $notes, $match)) {
            return self::RESTART_UNKNOWN;
        }

        return strcasecmp($match[1], 'Required') === 0 ? self::RESTART_REQUIRED : self::RESTART_NOT_REQUIRED;
    }

    /**
     * @param list<array{version: string, url: string, published_at: ?string, restart: string}> $releases
     */
    public static function compare(?string $installed, array $releases): array
    {
        $updates = $installed === null ? [] : array_values(array_filter(
            $releases,
            static fn(array $release): bool => version_compare($release['version'], $installed, '>')
        ));
        usort($updates, static fn(array $a, array $b): int => version_compare($b['version'], $a['version']));
        $restarts = array_column($updates, 'restart');

        return [
            'latest' => $updates[0] ?? null,
            'updates' => $updates,
            'restart' => match (true) {
                $updates === [] => null,
                in_array(self::RESTART_REQUIRED, $restarts, true) => self::RESTART_REQUIRED,
                in_array(self::RESTART_UNKNOWN, $restarts, true) => self::RESTART_UNKNOWN,
                default => self::RESTART_NOT_REQUIRED,
            },
        ];
    }

    /**
     * @return array{version: ?string, branch: ?string, commit: ?string}
     */
    public static function installed(string $root): array
    {
        $changelog = (string)@file_get_contents($root . '/CHANGELOG.md');
        preg_match_all('/^## (\d+\.\d+\.\d+)\b(.*)$/m', $changelog, $headings, PREG_SET_ORDER);
        $released = array_values(array_filter(
            $headings,
            static fn(array $heading): bool => stripos($heading[2], 'pending') === false
        ));

        return ['version' => $released[0][1] ?? null] + self::checkout($root . '/.git');
    }

    /**
     * @return array{branch: ?string, commit: ?string}
     */
    private static function checkout(string $git): array
    {
        $head = trim((string)@file_get_contents($git . '/HEAD'));
        if (!str_starts_with($head, 'ref: ')) {
            return ['branch' => null, 'commit' => preg_match('/^[0-9a-f]{40}$/D', $head) ? substr($head, 0, 7) : null];
        }
        $ref = substr($head, 5);
        $commit = trim((string)@file_get_contents($git . '/' . $ref));
        $packed = (string)@file_get_contents($git . '/packed-refs');
        if ($commit === '' && preg_match('/^([0-9a-f]{40}) ' . preg_quote($ref, '/') . '$/m', $packed, $match)) {
            $commit = $match[1];
        }

        return [
            'branch' => preg_replace('#^refs/heads/#', '', $ref),
            'commit' => preg_match('/^[0-9a-f]{40}$/D', $commit) ? substr($commit, 0, 7) : null,
        ];
    }

    private static function isVersion(string $tag): bool
    {
        return preg_match('/^\d+\.\d+\.\d+$/D', $tag) === 1;
    }
}
