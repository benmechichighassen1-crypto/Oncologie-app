<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminOnco
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->admin, 403, 'Réservé à l\'administrateur de l\'application.');

        return $next($request);
    }
}
