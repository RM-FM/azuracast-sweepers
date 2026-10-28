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
        public int $id = 3;
        public bool $needs_restart = false;
        public object $backend_config;

        public function __construct(private string $directory)
        {
            $this->backend_config = (object)['use_manual_autodj' => false, 'autodj_queue_length' => 3];
        }

        public function getRadioConfigDir(): string
        {
            return $this->directory;
        }
    }
}

namespace App\Radio\Backend {
    // Mirrors AzuraCast's verifyConfig(): a LogicException carries Liquidsoap's output.
    class Liquidsoap
    {
        public static array $checked = [];
        public static bool $rejectModule = false;
        public static bool $rejectAll = false;

        public function verifyConfig(string $config): void
        {
            self::$checked[] = $config;
            if (self::$rejectAll || (self::$rejectModule && str_contains($config, 'sweepers.'))) {
                throw new \LogicException("At line 12, char 4: this value has type\nmore details");
            }
        }

        public function getBinary(): string
        {
            return '/usr/local/bin/liquidsoap';
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
        public static bool $failField = false;

        public function ensureField(): void
        {
            if (self::$failField) {
                throw new \RuntimeException('Cannot initialize intro_end.');
            }
        }

        public function validateSweeperPlaylists(\App\Entity\Station $station, array $playlistIds): void
        {
            if (array_diff($playlistIds, [7, 9]) !== []) {
                throw new \InvalidArgumentException('Choose Advanced playlists.');
            }
        }

        public function validateJinglePlaylists(\App\Entity\Station $station, array $ids): void
        {
            if (array_diff($ids, [7, 8]) !== []) {
                throw new \InvalidArgumentException('Foreign playlist.');
            }
        }

        public function describe(\App\Entity\Station $station, array $ids): array
        {
            $files = [
                7 => ['title' => 'Voulez-Vous', 'artist' => 'ABBA', 'path' => 'abba.mp3', 'intro' => 12.5],
                42 => ['title' => 'Dropin 03', 'artist' => 'RadioMonster.FM', 'path' => 'dropin.mp3', 'intro' => null],
            ];

            return array_intersect_key($files, array_flip(array_map('intval', $ids)));
        }
    }
}

namespace App\Event\Radio {
    // Same line API as AzuraCast's AbstractConfigurationEvent and WriteLiquidsoapConfiguration.
    final class WriteLiquidsoapConfiguration
    {
        private array $configLines = [];

        public function __construct(
            private \App\Entity\Station $station,
            private bool $forEditing = false,
            private bool $writeToDisk = true
        ) {
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
            return $this->forEditing;
        }

        public function shouldWriteToDisk(): bool
        {
            return $this->writeToDisk && !$this->forEditing;
        }

        public function appendLines(array $lines): void
        {
            $this->configLines = array_merge($this->configLines, [''], $lines);
        }

        public function appendBlock(string $lines): void
        {
            $this->appendLines(explode("\n", $lines));
        }

        public function prependLines(array $lines): void
        {
            $this->configLines = array_merge($lines, [''], $this->configLines);
        }

        public function replaceLine(int $index, string $line): void
        {
            $this->configLines[$index] = $line;
        }

        public function buildConfiguration(): string
        {
            return implode("\n", $this->configLines);
        }
    }
}

namespace {
    use App\Entity\Station;
    use App\Event\Radio\WriteLiquidsoapConfiguration;
    use App\Http\Response;
    use App\Http\ServerRequest;
    use App\Radio\Backend\Liquidsoap;
    use Plugin\AzuracastSweepers\Controller\PanelController;
    use Plugin\AzuracastSweepers\EventHandler\PluginSubscriber;
    use Plugin\AzuracastSweepers\Service\Media;
    use Plugin\AzuracastSweepers\Service\Module;
    use Plugin\AzuracastSweepers\Service\State;
    use Plugin\AzuracastSweepers\Service\Verifier;
    use Plugin\AzuracastSweepers\ValueObject\Settings;

    function expect(bool $value, string $message): void
    {
        if (!$value) {
            throw new RuntimeException($message);
        }
    }

    $directory = sys_get_temp_dir() . '/sweepers-settings-' . bin2hex(random_bytes(6));
    mkdir($directory);
    $runtime = sys_get_temp_dir() . '/azuracast-runtime-' . bin2hex(random_bytes(6));
    mkdir($runtime);
    file_put_contents($runtime . '/azuracast.liq', "# AzuraCast runtime 1\n");
    $station = new Station($directory);
    $state = new State();
    $media = new Media();
    $controller = new PanelController($media, $state);
    $subscriber = new PluginSubscriber($media, $state, new Verifier(new Liquidsoap()));
    $save = static function (array $settings) use ($controller, $station): array {
        $response = $controller->settingsAction(new ServerRequest($station, $settings), new Response(), []);

        return json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    };
    $body = "radio = azuracast.enable_autodj(radio)\nradio = playlist_fallback(radio)\nradio = azuracast.apply_crossfade(radio)";
    // Runs the plugin listeners in AzuraCast's priority order around representative generated blocks.
    $generate = static function (
        bool $editing = false,
        bool $disk = true,
        ?string $music = null
    ) use ($subscriber, $station, $body, $runtime): array {
        Liquidsoap::$checked = [];
        $event = new WriteLiquidsoapConfiguration($station, $editing, $disk);
        $event->prependLines(['# WARNING! This file is automatically generated by AzuraCast.']);
        $event->appendBlock("# AzuraCast Common Runtime Functions\n%include \"$runtime/azuracast.liq\"\n\nlog.level := 3");
        $subscriber->header($event);
        $event->appendBlock($music ?? $body);
        $subscriber->mixer($event);
        $event->appendBlock("output.icecast(%mp3, radio)\n# custom bottom");
        $subscriber->verify($event);

        return [$event->buildConfiguration(), count(Liquidsoap::$checked)];
    };
    $loaded = static fn(string $config): bool => str_contains($config, Module::includeLine())
        && str_contains($config, Module::WRAPPED_AUTODJ) && str_contains($config, Module::WRAPPED_CROSSFADE)
        && !str_contains($config, "\n" . Module::AUTODJ . "\n") && !str_contains($config, "\n" . Module::CROSSFADE . "\n");
    $unloaded = static fn(string $config): bool => !str_contains($config, 'sweepers.')
        && !str_contains($config, '%include "' . dirname(__DIR__))
        && str_contains($config, Module::AUTODJ) && str_contains($config, Module::CROSSFADE);
    $status = static fn(): array => Module::status($state->read($directory));
    try {
        // Fresh installation: no saved settings, so the defaults apply (enabled, gaps 1/1); the module is loaded.
        [$config, $checks] = $generate();
        expect($loaded($config), 'A fresh installation loads the module so that enabling needs no restart.');
        expect(
            str_contains($config, 'sweepers.load(version="' . Module::version() . '", {enabled=true')
                && str_contains($config, 'songs_after_jingle=1, songs_after_sweeper=1'),
            'A new installation starts enabled with the default song gaps.'
        );
        expect($checks === 1, 'The final configuration is checked by Liquidsoap.');
        expect($state->read($directory)['module']['loaded'] === true, 'Load status recorded.');
        expect($status()['state'] === 'restart', 'Without heartbeats the page asks for the one-time restart.');
        [$again, $checks] = $generate();
        expect($again === $config && $checks === 0, 'An unchanged configuration is not checked again.');
        // AzuraCast's own line indexes stay valid: no listener may insert a multi-line string.
        expect(substr_count($config, 'sweepers.mix(') === 1 && count(explode("\n", $config)) === 18, 'Single lines.');

        $state->update($directory, static fn(array $data) => ['history' => ['42' => 123.5], 'plans' => ['123' => ['played' => true]]] + $data);
        $settings = (new Settings(
            enabled: true,
            playlistIds: [7],
            songsAfterJingle: 3,
            songsAfterSweeper: 2,
            songsBeforeJingle: 1
        ))->toArray();
        $result = $save($settings);
        expect($result['success'] && $result['needs_restart'] === false, 'Enabling requires no restart.');
        expect($result['module']['state'] === 'restart', 'Saving reports whether the module runs.');
        expect(!$station->needs_restart, 'Saving does not mark the station for restart.');
        expect($state->read($directory)['history']['42'] === 123.5, 'Saving preserves actual-play history.');
        expect($state->read($directory)['plans']['123']['played'], 'Saving preserves in-flight playback acknowledgment.');
        $station->needs_restart = true;
        expect($save(['enabled' => false])['needs_restart'], 'An unrelated restart flag is preserved.');
        expect($station->needs_restart, 'Saving must not clear unrelated pending changes.');

        $save(['enabled' => true, 'playlist_ids' => [7, 9], 'songs_after_jingle' => 3, 'songs_after_sweeper' => 2]);
        [$config, $checks] = $generate();
        expect($loaded($config) && $checks === 1, 'Changed settings are checked once.');
        expect(str_contains($config, '{enabled=true, revision="'
            . Settings::fromArray($state->read($directory)['settings'])->revision()
            . '", songs_after_jingle=3, songs_after_sweeper=2'), 'Initial settings and revision match the API.');

        [$config, $checks] = $generate(editing: true);
        expect(!str_contains($config, 'sweepers') && $checks === 0, 'Do not inject the module into the editing view.');
        [$config, $checks] = $generate(disk: false);
        expect($loaded($config) && $checks === 0, 'Previews show the module without running Liquidsoap.');

        // An AzuraCast upgrade changes its runtime but not the configuration text: check again.
        [, $checks] = $generate();
        expect($checks === 0, 'The verified configuration is cached.');
        file_put_contents($runtime . '/azuracast.liq', "# AzuraCast runtime 2\n");
        Liquidsoap::$rejectModule = true;
        [$config, $checks] = $generate();
        expect($checks === 2, 'A changed AzuraCast runtime invalidates the cached check.');
        expect($unloaded($config), 'A rejected module is rolled back exactly.');
        expect(str_contains($config, Module::UNLOADED), 'The configuration explains the missing module.');
        expect(
            $status()['state'] === 'unavailable' && str_contains($status()['message'], 'At line 12, char 4'),
            'The page reports why the station started without sweepers.'
        );
        Liquidsoap::$rejectModule = false;
        [$config] = $generate();
        expect($loaded($config) && $state->read($directory)['module']['loaded'], 'The next restart retries the module.');

        // Liquidsoap rejects the station regardless of the module: removing it would not help.
        Liquidsoap::$rejectAll = true;
        $save(['enabled' => true, 'playlist_id' => 7, 'margin' => 1.0]);
        expect($state->read($directory)['settings']['playlist_ids'] === [7], 'A legacy single playlist is saved as a list.');
        [$config, $checks] = $generate();
        expect($loaded($config) && $checks === 2, 'An unrelated configuration error keeps the module.');
        Liquidsoap::$rejectAll = false;

        // Unexpected generated layout: never throw, even with sweepers enabled.
        [$config] = $generate(music: 'radio = custom(radio)');
        expect(!str_contains($config, 'sweepers.') && str_contains($config, 'radio = custom(radio)'), 'Unload when unsupported.');
        expect($status()['state'] === 'unavailable', 'Unsupported layout is reported.');

        // Manual AutoDJ has no AzuraCast queue: start normally without the module.
        $station->backend_config->use_manual_autodj = true;
        [$config] = $generate();
        expect(!str_contains($config, 'sweepers'), 'Manual AutoDJ stations start without the module.');
        expect(str_contains($status()['message'], 'manual AutoDJ'), 'Manual AutoDJ is reported.');
        try {
            $save(['enabled' => true, 'playlist_id' => 7]);
            throw new RuntimeException('Enabling manual AutoDJ was accepted.');
        } catch (InvalidArgumentException) {
        }
        $station->backend_config->use_manual_autodj = false;

        // Database or state failures never stop the station.
        Media::$failField = true;
        [$config] = $generate();
        expect($loaded($config), 'A failed custom-field initialization does not stop the station.');
        Media::$failField = false;
        $saved = file_get_contents($directory . '/sweepers.json');
        file_put_contents($directory . '/sweepers.json', '{');
        [$config] = $generate();
        expect($loaded($config) && str_contains($config, '{enabled=false'), 'Damaged state loads the module disabled.');
        file_put_contents($directory . '/sweepers.json', $saved);
        $state->update($directory, static fn(array $data) => ['settings' => ['enabled' => 'yes']] + $data);
        [$config] = $generate();
        expect($loaded($config) && str_contains($config, '{enabled=false'), 'Invalid settings load the module disabled.');

        $save(['songs_before_jingle' => 3]);
        expect(Settings::fromArray($state->read($directory)['settings'])->songsBeforeJingle === 3, 'Queue length is allowed.');
        $station->backend_config->autodj_queue_length = 1;
        $save(['songs_before_jingle' => 2]);
        expect(
            Settings::fromArray($state->read($directory)['settings'])->songsBeforeJingle === 2,
            'AzuraCast builds at least two queue entries, so 2 is allowed for length 1.'
        );
        $station->backend_config->autodj_queue_length = 3;
        try {
            $save(['songs_before_jingle' => 4]);
            throw new RuntimeException('A before-jingle gap beyond the AutoDJ queue was accepted.');
        } catch (InvalidArgumentException $exception) {
            expect(str_contains($exception->getMessage(), 'queue length (3)'), 'Explain the queue limit.');
        }
        foreach ([['enabled' => true, 'playlist_id' => 99], ['jingle_playlist_ids' => [99]]] as $invalid) {
            try {
                $save($invalid);
                throw new RuntimeException('Invalid playlist accepted.');
            } catch (InvalidArgumentException) {
            }
        }

        $state->update($directory, static fn(array $data) => ['log' => [
            ['at' => 300.0, 'token' => '8', 'song_id' => null, 'title' => 'Live show', 'artist' => '', 'result' => 'other'],
            ['at' => 200.5, 'song_id' => 7, 'jingle_id' => 42, 'offset' => 9.66, 'replaced_before' => 42],
            ['at' => 100.0, 'song_id' => 99, 'jingle_id' => 42, 'offset' => null, 'unmoved' => 'move_none'],
        ]] + $data);
        $history = json_decode((string)$controller->historyAction(
            new ServerRequest($station, []),
            new Response(),
            []
        )->getBody(), true, flags: JSON_THROW_ON_ERROR);
        expect($history['limit'] === 300 && count($history['rows']) === 3, 'History endpoint returns the log.');
        expect(
            $history['rows'][0]['song'] === ['title' => 'Live show', 'artist' => null, 'path' => '', 'intro' => null]
                && $history['rows'][0]['result'] === 'other' && $history['rows'][0]['sweeper'] === null,
            'Tracks outside the library keep their reported title and reason.'
        );
        expect($history['rows'][1]['song']['intro'] === 12.5, 'The song intro end is included.');
        expect($history['rows'][1]['song']['artist'] === 'ABBA' && $history['rows'][1]['offset'] === 9.66, 'Song and offset.');
        expect($history['rows'][1]['sweeper']['title'] === 'Dropin 03', 'Sweeper name.');
        expect($history['rows'][1]['result'] === 'played', 'Earlier entries are played sweepers.');
        expect(
            $history['rows'][1]['replaced'] && !$history['rows'][1]['moved']
                && $history['rows'][1]['replaced_before']['title'] === 'Dropin 03',
            'Same-playlist replacements resolve the displaced file without claiming a forward move.'
        );
        expect($history['rows'][2]['song'] === null && $history['rows'][2]['offset'] === null, 'Deleted files stay listed.');
        expect(
            $history['rows'][2]['unmoved'] === 'move_none' && $history['rows'][1]['unmoved'] === null,
            'Why a song could not be moved forward is included.'
        );

        $now = 1_000_000;
        $heartbeat = static fn(int $at, string $version): array => ['heartbeat' => ['at' => $at, 'version' => $version]];
        expect(Module::status($heartbeat($now - 20, Module::version()), $now)['state'] === 'active', 'Running.');
        expect(Module::status($heartbeat($now - 20, 'older'), $now)['state'] === 'outdated', 'Update needs a restart.');
        expect(Module::status($heartbeat($now - 600, Module::version()), $now)['state'] === 'restart', 'Stopped.');
        expect(Module::rollback(['radio = custom(radio)']) === null, 'Nothing to roll back.');
        echo "Module loading, fail-safe configuration and live settings passed.\n";
    } finally {
        foreach (glob($directory . '/*') as $path) {
            unlink($path);
        }
        rmdir($directory);
        unlink($runtime . '/azuracast.liq');
        rmdir($runtime);
    }
}
