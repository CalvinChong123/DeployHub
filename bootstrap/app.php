<?php

use App\Http\Middleware\AdminAccess;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireMfa;
use App\Http\Middleware\WorkspaceAccess;
use App\Services\Deployment\DeploymentException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trimStrings(except: ['private_key', 'passphrase']);
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias(['workspace' => WorkspaceAccess::class, 'admin' => AdminAccess::class, 'mfa' => RequireMfa::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'private_key', 'passphrase', 'code']);
        $exceptions->dontReport([DeploymentException::class]);
        $exceptions->render(function (DeploymentException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        });
    })->create();
