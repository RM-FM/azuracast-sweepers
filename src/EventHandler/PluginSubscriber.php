<?php

declare(strict_types=1);

namespace Plugin\AzuracastSweepers\EventHandler;

use App\Enums\StationPermissions;
use App\Event\BuildRoutes;
use App\Event\Radio\WriteLiquidsoapConfiguration;
use App\Middleware;
use Plugin\AzuracastSweepers\Controller\InternalController;
use Plugin\AzuracastSweepers\Controller\PanelController;
use Plugin\AzuracastSweepers\Middleware\SidebarNavigation;
use Plugin\AzuracastSweepers\Service\Media;
use Plugin\AzuracastSweepers\Service\Module;
use Plugin\AzuracastSweepers\Service\State;
use Plugin\AzuracastSweepers\Service\Verifier;
use Plugin\AzuracastSweepers\ValueObject\Settings;
use Slim\Routing\RouteCollectorProxy;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

final readonly class PluginSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private Media $media,
        private State $state,
        private Verifier $verifier
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BuildRoutes::class => 'routes',
            WriteLiquidsoapConfiguration::class => [
                ['header', 32],
                ['mixer', 24],
                ['verify', -100],
            ],
        ];
    }

    public function routes(BuildRoutes $event): void
    {
        $app = $event->getApp();
        $app->add(new SidebarNavigation($app->getBasePath()));
        $app->get('/sweepers/assets/navigation.js', PanelController::class . ':navigationAction');
        $app->group('/sweepers/{station_id}', function (RouteCollectorProxy $group): void {
            $group->get('', PanelController::class . ':indexAction');
            $group->get('/assets/{name:app\\.(?:js|css)}', PanelController::class . ':assetAction');
            $group->get('/state', PanelController::class . ':stateAction');
            $group->get('/media', PanelController::class . ':mediaAction');
            $group->post('/intro/{id:[0-9]+}', PanelController::class . ':introAction');
            $group->post('/settings', PanelController::class . ':settingsAction')
                ->add(new Middleware\Permissions(StationPermissions::Broadcasting, true));
        })
            ->add(new Middleware\Permissions(StationPermissions::Media, true))
            ->add(Middleware\RequireStation::class)
            ->add(Middleware\GetStation::class)
            ->add(Middleware\RequireLogin::class)
            ->add(Middleware\Auth\StandardAuth::class)
            ->add(Middleware\InjectSession::class);

        $app->group(
            '/api/internal/{station_id}/liquidsoap/sweepers',
            function (RouteCollectorProxy $group): void {
                $group->post('/catalog', InternalController::class . ':catalogAction');
                $group->post('/plan', InternalController::class . ':planAction');
                $group->post('/heartbeat', InternalController::class . ':heartbeatAction');
                $group->post('/played', InternalController::class . ':playedAction');
            }
        )
            ->add(Middleware\RequireInternalConnection::class)
            ->add(Middleware\Module\Api::class)
            ->add(Middleware\RequireStation::class)
            ->add(Middleware\GetStation::class);

        $app->post('/api/station/{station_id}/sweepers/intro/{id:[0-9]+}', PanelController::class . ':importAction')
            ->add(new Middleware\Permissions(StationPermissions::Media, true))
            ->add(Middleware\RequireStation::class)
            ->add(Middleware\GetStation::class)
            ->add(Middleware\Module\Api::class)
            ->add(Middleware\Auth\ApiAuth::class)
            ->add(Middleware\InjectSession::class);
    }

    /**
     * Loads the module on every station, regardless of the Enable switch, so that enabling needs no restart.
     * Nothing here may prevent a station from starting.
     */
    public function header(WriteLiquidsoapConfiguration $event): void
    {
        if ($event->isForEditing()) {
            return;
        }
        try {
            $this->media->ensureField();
        } catch (Throwable $exception) {
            error_log('AzuraCast Sweepers: intro_end field not initialized: ' . $exception->getMessage());
        }
        if ($event->getBackendConfig()->use_manual_autodj) {
            $this->record($event, false, 'manual AutoDJ mode does not provide the AzuraCast queue');

            return;
        }

        $event->appendBlock(Module::includeLine());
        $event->appendBlock(Module::loadLine($this->settings($event)));
    }

    public function mixer(WriteLiquidsoapConfiguration $event): void
    {
        $lines = explode("\n", $event->buildConfiguration());
        if ($event->isForEditing() || !in_array(Module::includeLine(), $lines, true)) {
            return;
        }

        $crossfades = array_keys($lines, Module::CROSSFADE, true);
        $factories = array_keys($lines, Module::AUTODJ, true);
        if (count($crossfades) !== 1 || count($factories) !== 1) {
            $this->rollback($event, $lines);
            $this->record($event, false, 'this AzuraCast version generates an unsupported configuration');

            return;
        }
        // Single-line replacements keep AzuraCast's line indexes valid for later listeners.
        $event->replaceLine($factories[0], Module::WRAPPED_AUTODJ);
        $event->replaceLine($crossfades[0], Module::WRAPPED_CROSSFADE);
    }

    /**
     * Last listener: if Liquidsoap rejects the configuration only because of the module, start without it.
     */
    public function verify(WriteLiquidsoapConfiguration $event): void
    {
        $config = $event->buildConfiguration();
        $lines = explode("\n", $config);
        if (!$event->shouldWriteToDisk() || !in_array(Module::includeLine(), $lines, true)) {
            return;
        }

        $hash = $this->verifier->fingerprint($config);
        $data = $this->read($event);
        if (($data['module']['verified'] ?? null) === $hash) {
            $this->record($event, true, null, $hash);

            return;
        }
        $error = $this->verifier->check($config);
        if ($error === null) {
            $this->record($event, true, null, $hash);

            return;
        }

        $changes = Module::rollback($lines) ?? [];
        $original = array_replace($lines, $changes);
        if ($this->verifier->check(implode("\n", $original)) !== null) {
            // The configuration fails without the module too; removing it would not help.
            $this->record($event, true, 'the station configuration is invalid independently of sweepers');

            return;
        }
        $this->rollback($event, $lines);
        $summary = strtok($error, "\n");
        $this->record($event, false, 'Liquidsoap rejected the sweeper module (' . ($summary ?: $error) . ')');
        error_log('AzuraCast Sweepers: module not loaded for station ' . $event->getStation()->id . ': ' . $error);
    }

    /**
     * @param list<string> $lines
     */
    private function rollback(WriteLiquidsoapConfiguration $event, array $lines): void
    {
        foreach (Module::rollback($lines) ?? [] as $index => $line) {
            $event->replaceLine($index, $line);
        }
    }

    private function record(
        WriteLiquidsoapConfiguration $event,
        bool $loaded,
        ?string $reason,
        ?string $hash = null
    ): void {
        if (!$event->shouldWriteToDisk()) {
            return;
        }
        try {
            $this->state->update($event->getStation()->getRadioConfigDir(), static function (array $data) use (
                $loaded,
                $reason,
                $hash
            ): array {
                $data['module'] = [
                    'loaded' => $loaded,
                    'reason' => $reason,
                    'verified' => $hash,
                    'at' => time(),
                ];

                return $data;
            });
        } catch (Throwable $exception) {
            error_log('AzuraCast Sweepers: status not saved: ' . $exception->getMessage());
        }
    }

    private function read(WriteLiquidsoapConfiguration $event): array
    {
        try {
            return $this->state->read($event->getStation()->getRadioConfigDir());
        } catch (Throwable $exception) {
            error_log('AzuraCast Sweepers: state not readable: ' . $exception->getMessage());

            return [];
        }
    }

    private function settings(WriteLiquidsoapConfiguration $event): Settings
    {
        try {
            return Settings::fromArray($this->read($event)['settings'] ?? []);
        } catch (Throwable $exception) {
            // Load the module disabled; saving valid settings later enables it live.
            error_log('AzuraCast Sweepers: invalid saved settings: ' . $exception->getMessage());

            return new Settings();
        }
    }
}
