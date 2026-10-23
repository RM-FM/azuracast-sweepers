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
use Plugin\AzuracastSweepers\Service\State;
use Plugin\AzuracastSweepers\ValueObject\Settings;
use RuntimeException;
use Slim\Routing\RouteCollectorProxy;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class PluginSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private Media $media,
        private State $state
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BuildRoutes::class => 'routes',
            WriteLiquidsoapConfiguration::class => [
                ['header', 32],
                ['mixer', 24],
            ],
        ];
    }

    public function routes(BuildRoutes $event): void
    {
        $app = $event->getApp();
        $app->add(new SidebarNavigation($app->getBasePath()));
        $app->get('/sweepers/assets/navigation.js', PanelController::class . ':navigationAction');
        $app->group('/sweepers/{station_id}', static function (RouteCollectorProxy $group): void {
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
            static function (RouteCollectorProxy $group): void {
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

    public function header(WriteLiquidsoapConfiguration $event): void
    {
        $this->media->ensureField();
        $settings = $this->settings($event);
        if (!$settings->enabled || $event->isForEditing()) {
            return;
        }
        if ($event->getBackendConfig()->use_manual_autodj) {
            throw new RuntimeException('Sweepers require AzuraCast AutoDJ queue management.');
        }

        $path = dirname(__DIR__, 2) . '/liquidsoap/sweepers.liq';
        $event->appendBlock('%include ' . json_encode($path, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $event->appendBlock('sweepers.minimum_songs := ' . $settings->minimumSongs);
        $event->appendBlock('sweepers.count_jingle_mode := ' . ($settings->countJingleMode ? 'true' : 'false'));
        $event->appendBlock('sweepers.jingle_playlists := ' . json_encode(
            array_values(array_unique([$settings->playlistId, ...$settings->jinglePlaylistIds])),
            JSON_THROW_ON_ERROR
        ));
    }

    public function mixer(WriteLiquidsoapConfiguration $event): void
    {
        if (!$this->settings($event)->enabled || $event->isForEditing()) {
            return;
        }

        $lines = explode("\n", $event->buildConfiguration());
        $matches = array_keys($lines, 'radio = azuracast.apply_crossfade(radio)', true);
        if (count($matches) !== 1) {
            throw new RuntimeException('Unsupported Liquidsoap configuration: crossfade insertion point not found.');
        }

        $factories = array_keys($lines, 'radio = azuracast.enable_autodj(radio)', true);
        if (count($factories) !== 1) {
            throw new RuntimeException('Unsupported Liquidsoap configuration: AutoDJ insertion point not found.');
        }
        $event->replaceLine($factories[0], 'radio = sweepers.enable_autodj(radio)');

        $index = $matches[0];
        $event->replaceLine($index, $lines[$index] . "\nradio = sweepers.mix(radio)");
    }

    private function settings(WriteLiquidsoapConfiguration $event): Settings
    {
        $data = $this->state->read($event->getStation()->getRadioConfigDir());

        return Settings::fromArray($data['settings'] ?? []);
    }
}
