<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Tests\Middleware;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Zidbih\Deadlock\Attributes\Workaround;
use Zidbih\Deadlock\Exceptions\WorkaroundExpiredException;
use Zidbih\Deadlock\Middleware\DeadlockGuardMiddleware;
use Zidbih\Deadlock\Tests\TestCase;

final class DeadlockGuardMiddlewareTest extends TestCase
{
    public function test_throws_for_expired_controller_workaround_in_local(): void
    {
        $request = Request::create('/expired', 'GET');
        $request->setRouteResolver(fn () => new Route(['GET'], '/expired', [
            'controller' => ExpiredController::class.'@index',
        ]));

        $this->expectException(WorkaroundExpiredException::class);

        (new DeadlockGuardMiddleware)->handle($request, fn () => 'ok');
    }

    public function test_skips_in_non_local_environment(): void
    {
        $this->app['env'] = 'production';
        $this->app['config']->set('app.env', 'production');

        try {
            $request = Request::create('/expired', 'GET');
            $request->setRouteResolver(fn () => new Route(['GET'], '/expired', [
                'controller' => ExpiredController::class.'@index',
            ]));

            (new DeadlockGuardMiddleware)->handle($request, fn () => 'ok');

            $this->assertTrue(true);
        } finally {
            $this->app['env'] = 'local';
            $this->app['config']->set('app.env', 'local');
        }
    }

    public function test_skips_when_no_route_is_resolved(): void
    {
        $request = Request::create('/missing', 'GET');
        $request->setRouteResolver(fn () => null);

        (new DeadlockGuardMiddleware)->handle($request, fn () => 'ok');

        $this->assertTrue(true);
    }

    public function test_skips_when_route_has_no_controller_action(): void
    {
        $request = Request::create('/no-action', 'GET');
        $request->setRouteResolver(fn () => new Route(['GET'], '/no-action', []));

        (new DeadlockGuardMiddleware)->handle($request, fn () => 'ok');

        $this->assertTrue(true);
    }

    public function test_skips_when_action_is_not_a_controller_string(): void
    {
        $request = Request::create('/closure', 'GET');
        $request->setRouteResolver(fn () => new Route(['GET'], '/closure', [
            'controller' => 'not-a-controller',
        ]));

        (new DeadlockGuardMiddleware)->handle($request, fn () => 'ok');

        $this->assertTrue(true);
    }

    public function test_skips_when_controller_class_is_missing(): void
    {
        $request = Request::create('/missing-controller', 'GET');
        $request->setRouteResolver(fn () => new Route(['GET'], '/missing-controller', [
            'controller' => 'App\\MissingController@index',
        ]));

        (new DeadlockGuardMiddleware)->handle($request, fn () => 'ok');

        $this->assertTrue(true);
    }

    public function test_skips_when_controller_method_is_missing(): void
    {
        $request = Request::create('/missing-method', 'GET');
        $request->setRouteResolver(fn () => new Route(['GET'], '/missing-method', [
            'controller' => MissingMethodController::class.'@missing',
        ]));

        (new DeadlockGuardMiddleware)->handle($request, fn () => 'ok');

        $this->assertTrue(true);
    }
}

#[Workaround('Expired middleware workaround', '2020-01-01')]
final class ExpiredController
{
    public function index(): void {}
}

final class MissingMethodController
{
    public function index(): void {}
}
