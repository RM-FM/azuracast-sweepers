<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\Service;

/**
 * Talks to the Intro Detector's management API on AzuraCast's Docker network.
 *
 * The connection file is written by the detector's setup; without it the detector is not installed.
 */
final readonly class DetectorClient
{
    public const string FILE = '.detector.json';
    public const int API_VERSION = 1;
    private const int TIMEOUT_SECONDS = 10;

    private string $file;

    public function __construct(?string $file = null)
    {
        $this->file = $file ?? dirname(__DIR__, 2) . '/' . self::FILE;
    }

    public function installed(): bool
    {
        return is_file($this->file);
    }

    /**
     * @return array<mixed>
     * @throws DetectorError when the detector rejects the request or cannot be reached.
     */
    public function request(string $method, string $path, ?array $body = null): array
    {
        [$url, $token] = $this->connection();
        $requestHeaders = implode("\r\n", [
            "Authorization: Bearer $token",
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => $requestHeaders,
            'content' => $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR),
            'timeout' => self::TIMEOUT_SECONDS,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);
        $response = @file_get_contents($url . $path, false, $context);
        $headers = http_get_last_response_headers() ?? [];
        if ($response === false || $headers === []) {
            throw new DetectorError('The Intro Detector is not reachable.', 503);
        }
        $status = preg_match('~^HTTP/\S+ (\d{3})~', $headers[0], $match) === 1 ? (int)$match[1] : 502;
        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new DetectorError('The Intro Detector sent an invalid response.', 502);
        }
        if ($status >= 400) {
            throw new DetectorError(is_string($data['error'] ?? null) ? $data['error'] : 'Request failed.', $status);
        }

        return $data;
    }

    /**
     * @return array{string, string}
     */
    private function connection(): array
    {
        if (!$this->installed()) {
            throw new DetectorError('The Intro Detector is not installed.', 404);
        }
        $data = json_decode((string)file_get_contents($this->file), true);
        $url = is_array($data) ? ($data['url'] ?? null) : null;
        $token = is_array($data) ? ($data['token'] ?? null) : null;
        if (
            !is_string($url) || preg_match('~^http://[a-z0-9.-]+(:\d+)?$~', $url) !== 1
            || !is_string($token) || preg_match('/^[A-Za-z0-9_-]{32,}$/', $token) !== 1
        ) {
            throw new DetectorError('The Intro Detector connection file is invalid.', 500);
        }

        return [$url, $token];
    }
}
