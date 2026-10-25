<?php

declare(strict_types=1);

namespace {
    require dirname(__DIR__) . '/vendor/autoload.php';
}

namespace Symfony\Component\EventDispatcher {
    interface EventSubscriberInterface
    {
        public static function getSubscribedEvents(): array;
    }
}

namespace App\Entity {
    final class Station
    {
        public bool $needs_restart = false;
        public object $backend_config;

        public function __construct(private string $directory)
        {
            $this->backend_config = (object)['use_manual_autodj' => false];
        }

        public function getRadioConfigDir(): string
        {
            return $this->directory;
        }
    }
}

namespace App\Http {
    final class Response extends \GuzzleHttp\Psr7\Response
    {
        public function withJson(array $data): self
        {
            return new self(200, [], json_encode($data, JSON_THROW_ON_ERROR));
        }
    }

    final class ServerRequest
    {
        public function __construct(private \App\Entity\Station $station, private array $body)
        {
        }

        public function getStation(): \App\Entity\Station
        {
            return $this->station;
        }

        public function getBody(): string
        {
            return json_encode($this->body, JSON_THROW_ON_ERROR);
        }

        public function getHeaderLine(string $name): string
        {
            return 'test-csrf';
        }

        public function getCsrf(): object
        {
            return new class {
                public function verify(string $token, string $namespace): void
                {
                    if ($token !== 'test-csrf' || $namespace !== 'sweepers') {
                        throw new \RuntimeException('Invalid CSRF.');
                    }
                }
            };
        }
    }
}

namespace Plugin\AzuracastSweepers\Service {
    final class Media
    {
        public function ensureField(): void
        {
        }

        public function jingles(\App\Entity\Station $station, ?int $playlistId): array
        {
            if ($playlistId !== 7) {
                throw new \InvalidArgumentException('Choose an Advanced playlist.');
            }

            return [];
        }

        public function validateJinglePlaylists(\App\Entity\Station $station, array $ids): void
        {
            if (array_diff($ids, [7, 8]) !== []) {
                throw new \InvalidArgumentException('Foreign playlist.');
            }
        }
    }
}

namespace App\Event\Radio {
    final class WriteLiquidsoapConfiguration
    {
        public array $lines = ['radio = azuracast.enable_autodj(radio)', 'radio = azuracast.apply_crossfade(radio)'];

        public function __construct(private \App\Entity\Station $station, private bool $editing = false)
        {
        }

        public function getStation(): \App\Entity\Station
        {
            return $this->station;
        }

        public function getBackendConfig(): object
        {
            return $this->station->backend_config;
        }

        public function isForEditing(): bool
        {
            return $this->editing;
        }

        public function appendBlock(string $block): void
        {
            $this->lines[] = $block;
        }

        public function replaceLine(int $index, string $line): void
        {
            $this->lines[$index] = $line;
        }

        public function buildConfiguration(): string
        {
            return implode("\n", $this->lines);
        }
    }
}

namespace {
    use App\Entity\Station;
    use App\Event\Radio\WriteLiquidsoapConfiguration;
    use App\Http\Response;
    use App\Http\ServerRequest;
    use Plugin\AzuracastSweepers\Controller\PanelController;
    use Plugin\AzuracastSweepers\EventHandler\PluginSubscriber;
    use Plugin\AzuracastSweepers\Service\Media;
    use Plugin\AzuracastSweepers\Service\State;
    use Plugin\AzuracastSweepers\ValueObject\Settings;

    function expect(bool $value, string $message): void
    {
        if (!$value) {
            throw new RuntimeException($message);
        }
    }

    $directory = sys_get_temp_dir() . '/sweepers-settings-' . bin2hex(random_bytes(6));
    mkdir($directory);
    $station = new Station($directory);
    $state = new State();
    $media = new Media();
    $controller = new PanelController($media, $state);
    $subscriber = new PluginSubscriber($media, $state);
    $save = static function (array $settings) use ($controller, $station): array {
        $response = $controller->settingsAction(new ServerRequest($station, $settings), new Response(), []);

        return json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    };
    try {
        $state->update($directory, static fn() => ['history' => ['42' => 123.5], 'plans' => ['123' => ['played' => true]]]);
        $settings = (new Settings(enabled: true, playlistId: 7, minimumSongs: 3))->toArray();
        expect($save($settings) === ['success' => true, 'needs_restart' => false], 'Enabling requires no restart.');
        expect(!$station->needs_restart, 'Saving does not mark the station for restart.');
        expect($state->read($directory)['history']['42'] === 123.5, 'Saving preserves actual-play history.');
        expect($state->read($directory)['plans']['123']['played'], 'Saving preserves in-flight playback acknowledgment.');
        $station->needs_restart = true;
        expect($save(['enabled' => false])['needs_restart'], 'An unrelated restart flag is preserved.');
        expect($station->needs_restart, 'Saving must not clear unrelated pending changes.');

        foreach ([false, true] as $enabled) {
            $save(['enabled' => $enabled, 'playlist_id' => 7]);
            $event = new WriteLiquidsoapConfiguration($station);
            $subscriber->header($event);
            $subscriber->mixer($event);
            $script = $event->buildConfiguration();
            expect(str_contains($script, 'sweepers.liq'), 'Install the module even while disabled.');
            expect(str_contains($script, 'sweepers.configure({enabled=' . ($enabled ? 'true' : 'false')), 'Initial enabled state.');
            expect(str_contains($script, 'radio = sweepers.enable_autodj(radio)'), 'Always install the AutoDJ wrapper.');
            expect(str_contains($script, 'radio = sweepers.mix(radio)'), 'Always install the mixer.');
            expect(str_contains($script, 'revision="' . Settings::fromArray($state->read($directory)['settings'])->revision()), 'Initial revision matches the API.');
        }
        $event = new WriteLiquidsoapConfiguration($station, true);
        $subscriber->header($event);
        $subscriber->mixer($event);
        expect(!str_contains($event->buildConfiguration(), 'sweepers'), 'Do not inject the module into the editing view.');

        $station->backend_config->use_manual_autodj = true;
        try {
            $save($settings);
            throw new RuntimeException('Enabling manual AutoDJ was accepted.');
        } catch (InvalidArgumentException) {
        }
        $save(['enabled' => false]);
        $event = new WriteLiquidsoapConfiguration($station);
        $subscriber->header($event);
        $subscriber->mixer($event);
        expect(!str_contains($event->buildConfiguration(), 'sweepers'), 'Leave unsupported manual AutoDJ unchanged.');
        $station->backend_config->use_manual_autodj = false;
        foreach ([['enabled' => true, 'playlist_id' => 99], ['jingle_playlist_ids' => [99]]] as $invalid) {
            try {
                $save($invalid);
                throw new RuntimeException('Invalid playlist accepted.');
            } catch (InvalidArgumentException) {
            }
        }
        echo "Live settings, restart flags and installation hooks passed.\n";
    } finally {
        foreach (glob($directory . '/*') as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
}
