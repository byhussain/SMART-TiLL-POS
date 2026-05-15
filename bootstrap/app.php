<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn (Request $request) => route('startup.index'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
//        // Suppress NativePHP's PreventRegularBrowserAccess 403 spam. These
//        // fire whenever something hits 127.0.0.1:<port> outside the Electron
//        // window (Windows DNS prefetch, link sniffers, antivirus URL scanners,
//        // etc.) — working as intended and not actionable, but they were
//        // flooding the log and hiding real errors.
//        $exceptions->dontReport([
//            HttpException::class,
//        ]);
    })->create();
