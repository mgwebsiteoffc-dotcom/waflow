<?php

use App\Http\Middleware\ResolveShop;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [ResolveShop::class]);
        $middleware->alias(['shop' => ResolveShop::class, 'shop.connected' => App\Http\Middleware\EnsureShopConnected::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Laravel's default exception handling is retained.
    })->create();
