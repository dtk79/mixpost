<?php

require_once __DIR__.'/../../../vendor/autoload.php';
require_once __DIR__.'/../AppServiceProvider.php';

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

final class HealthEndpointTest extends TestCase
{
    public function test_liveness_dispatches_without_sessions_or_backend_services(): void
    {
        $app = new Application(dirname(__DIR__, 3));
        $app->instance('files', new Illuminate\Filesystem\Filesystem());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        (new AppServiceProvider($app))->boot();

        $router = $app['router'];
        $request = Request::create('/api/health');
        $route = $router->getRoutes()->match($request);
        self::assertSame([], $route->gatherMiddleware());
        self::assertContains('HEAD', $route->methods());
        $response = $router->dispatch($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['ok' => true, 'service' => 'mixpost'], $response->getData(true));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertFalse($response->headers->has('Set-Cookie'));

        // Exercise Laravel's route-cache serialization, used by production startup.
        $route->prepareForSerialization();
        $restored = unserialize(serialize($route));
        $restored->setContainer($app);
        self::assertSame(200, $restored->run()->getStatusCode());
    }
}
