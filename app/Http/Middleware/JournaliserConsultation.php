<?php

namespace App\Http\Middleware;

use App\Services\Journal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Trace chaque page consultée par un utilisateur connecté. */
class JournaliserConsultation
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->user() && $request->isMethod('GET') && ! $request->routeIs('planning.evenements', 'pj.voir', 'dossier')) {
            Journal::ecrire('page', ['details' => ['route' => $request->route()?->getName()]]);
        }

        return $response;
    }
}
