<?php

use App\Http\Middleware\CheckActiveUser;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\RequirePasswordChange;
use App\Http\Middleware\ResolveBranchContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Registered separately (rather than via withRouting's `channels:` param)
    // so /broadcasting/auth requires the same Sanctum session auth as every
    // other endpoint — the default `web` middleware doesn't apply here, this
    // app has no web guard. All current realtime events are public-channel
    // broadcasts (see App\Events\Hotel\RealtimeUpdate); this route only
    // matters if/when a private or presence channel is added.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'can_do' => CheckPermission::class,
            'check_active' => CheckActiveUser::class,
        ]);

        // The framework defaults unauthenticated requests to a `route('login')`
        // redirect, which no longer exists on a pure API — every guest gets a
        // plain 401 JSON response instead (handled below in withExceptions).
        $middleware->redirectGuestsTo(fn () => null);

        // Sanctum's SPA (cookie/session) auth must run before the guard
        // resolves the user, so it goes first; the rest mirror the old
        // `web` group's account-state checks, now JSON-responding.
        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->api(append: [
            RequirePasswordChange::class,
            ResolveBranchContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Every request through this app is an API request — always render
        // exceptions as JSON, never the framework's HTML error page.
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $e) => true);

        $exceptions->render(fn (ValidationException $e, Request $request) => response()->json([
            'message' => $e->getMessage(),
            'errors' => $e->errors(),
        ], $e->status));

        $exceptions->render(fn (AuthenticationException $e, Request $request) => response()->json([
            'message' => 'Unauthenticated.',
        ], 401));

        $exceptions->render(fn (AuthorizationException $e, Request $request) => response()->json([
            'message' => $e->getMessage() ?: 'This action is unauthorized.',
        ], 403));

        $exceptions->render(fn (ModelNotFoundException $e, Request $request) => response()->json([
            'message' => 'Resource not found.',
        ], 404));

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage() ?: match ($e->getStatusCode()) {
                    403 => 'This action is unauthorized.',
                    404 => 'Resource not found.',
                    default => 'An error occurred.',
                },
            ], $e->getStatusCode());
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if (app()->environment(['local', 'testing'])) {
                return null;
            }

            return response()->json(['message' => 'Server error.', 'error' => $e->getMessage()], 500);
        });
    })->create();
