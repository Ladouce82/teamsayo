<?php

use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\ResolveWorkspace;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\StagingGate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(StagingGate::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->trustProxies(at: '*');
        $middleware->alias(['workspace' => ResolveWorkspace::class, 'staff' => EnsureStaff::class]);
        $middleware->validateCsrfTokens(except: ['webhooks/*']);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('home'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api', 'api/*') || $request->expectsJson(),
        );
    })->create();
