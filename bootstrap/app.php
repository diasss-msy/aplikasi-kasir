<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Halaman kasir & PDA membaca pesan dari {"error": "..."}
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return match (true) {
                $e instanceof NotFoundHttpException, $e instanceof MethodNotAllowedHttpException => response()->json(['error' => 'Tidak ditemukan'], 404),
                $e instanceof UniqueConstraintViolationException, $e instanceof LockTimeoutException => response()->json(['error' => 'Data bentrok dengan transaksi lain. Coba lagi.'], 409),
                $e instanceof PDOException => response()->json(['error' => 'Database tidak bisa dihubungi'], 503),
                default => null,
            };
        });
    })->create();
