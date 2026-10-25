<?php

declare(strict_types=1);

namespace Symfony\Component\EventDispatcher {
    interface EventSubscriberInterface
    {
        public static function getSubscribedEvents(): array;
    }
}

namespace App\Enums {
    enum StationPermissions
    {
        case Media;
        case Broadcasting;
    }
}

namespace App\Middleware {
    final class Permissions
    {
        public function __construct(mixed ...$args)
        {
        }
    }
}

namespace Slim\Routing {
    class RouteCollectorProxy
    {
        public array $routes = [];
        public int $groups = 0;

        public function getBasePath(): string
        {
            return '';
        }

        public function add(mixed $middleware): self
        {
            return $this;
        }

        public function get(string $path, string $handler): self
        {
            $this->routes[] = ['GET', $path, $handler];

            return $this;
        }

        public function post(string $path, string $handler): self
        {
            $this->routes[] = ['POST', $path, $handler];

            return $this;
        }

        public function group(string $path, callable $callback): self
        {
            ++$this->groups;
            $bound = $callback->bindTo(new \stdClass());
            if ($bound === null) {
                throw new \RuntimeException('Slim cannot bind a static route group closure.');
            }
            $bound($this);

            return $this;
        }
    }
}

namespace App\Event {
    final class BuildRoutes
    {
        public function __construct(private \Slim\Routing\RouteCollectorProxy $app)
        {
        }

        public function getApp(): \Slim\Routing\RouteCollectorProxy
        {
            return $this->app;
        }
    }
}

namespace {
    require dirname(__DIR__) . '/vendor/autoload.php';

    $app = new \Slim\Routing\RouteCollectorProxy();
    $subscriber = (new \ReflectionClass(\Plugin\AzuracastSweepers\EventHandler\PluginSubscriber::class))
        ->newInstanceWithoutConstructor();
    $subscriber->routes(new \App\Event\BuildRoutes($app));

    if ($app->groups !== 2 || count($app->routes) !== 13) {
        throw new \RuntimeException('Sweeper routes were not registered.');
    }
    echo '2 route groups bound; 13 routes registered.' . PHP_EOL;
}
