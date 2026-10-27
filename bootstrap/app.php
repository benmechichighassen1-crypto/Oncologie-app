<?php

use App\Http\Middleware\AdminOnco;
use App\Http\Middleware\JournaliserConsultation;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'journal' => JournaliserConsultation::class,
            'admin.onco' => AdminOnco::class,
        ]);
        $middleware->prepend(\App\Http\Middleware\MesurerTemps::class);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('planning'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
