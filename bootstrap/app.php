<?php

use App\Exceptions\DomainException;
use App\Http\Middleware\EnsureKetiApproved;
use App\Http\Middleware\EnsureRole;
use App\Support\HomeRoute;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: [
            __DIR__.'/../routes/public.php',
            __DIR__.'/../routes/auth.php',
        ],
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware(['web', 'auth', 'verified', 'role:keti', 'keti.approved'])
                ->prefix('keti')
                ->name('keti.')
                ->group(base_path('routes/keti.php'));

            Route::middleware(['web', 'auth', 'role:admin,super_admin'])
                ->prefix('admin')
                ->name('admin.')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureRole::class,
            'keti.approved' => EnsureKetiApproved::class,
        ]);

        $middleware->redirectGuestsTo(fn (): string => route('login'));
        $middleware->redirectUsersTo(fn (Request $request): string => HomeRoute::for($request->user()));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (DomainException $exception, Request $request) {
            if ($request->expectsJson()) {
                return response()->json($exception->toArray(), 422);
            }

            return back()
                ->withInput()
                ->with('error', $exception->getMessage())
                ->with('error_code', $exception->errorCode);
        });
    })->create();
