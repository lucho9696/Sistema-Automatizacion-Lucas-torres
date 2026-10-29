<?php

use App\Domain\Integrations\IntegrationException;
use App\Domain\Shared\BusinessRuleException;
use App\Http\Middleware\EnsureIdempotency;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->alias(['idempotent' => EnsureIdempotency::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (BusinessRuleException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => [($e->field ?? 'general') => [$e->getMessage()]],
            ], 422);
        });
        $exceptions->render(function (IntegrationException $e, Request $request) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['integration' => [$e->getMessage()]]], 422);
        });
        // Nunca exponer trazas en respuestas JSON (APP_DEBUG=false en producción).
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
    })->create();
