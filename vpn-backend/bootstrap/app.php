<?php

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
        // Aplikasi ini murni API; tidak ada halaman login di sisi Laravel.
        // Tanpa ini, permintaan tanpa token membuat middleware mencoba
        // route('login') yang tidak ada, lalu melempar 500 alih-alih 401.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Jaring pengaman terakhir: exception yang lolos dari try/catch manapun
        // (bug, TypeError, dsb) tidak boleh membocorkan nama kelas/file/stack
        // trace ke admin, walau APP_DEBUG aktif di lab. Exception yang memang
        // sudah dirender aman oleh Laravel (validasi, 404, abort dengan pesan
        // Indonesia) dibiarkan lewat apa adanya.
        $exceptions->render(function (\Throwable $e, Request $request) {
            $aman = $e instanceof \Illuminate\Validation\ValidationException
                || $e instanceof \Illuminate\Auth\AuthenticationException
                || $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
                || $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

            if ($aman || (! $request->is('api/*') && ! $request->expectsJson())) {
                return null;
            }

            report($e);

            return response()->json([
                'message' => 'Terjadi kesalahan pada server. Coba lagi, atau hubungi admin bila berulang.',
            ], 500);
        });
    })->create();
