<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mesure chaque requête : en-tête Server-Timing (visible dans l'onglet Réseau du
 * navigateur) et, au-delà du seuil, une ligne dans storage/logs/lenteurs.log
 * avec la page, l'utilisateur, la durée et les requêtes SQL les plus longues.
 */
class MesurerTemps
{
    private const SEUIL_MS = 2000;

    public function handle(Request $request, Closure $next): Response
    {
        $debut = microtime(true);
        $requetes = [];
        DB::listen(function ($q) use (&$requetes) {
            $requetes[] = [round($q->time), $q->connectionName, mb_substr(preg_replace('/\s+/', ' ', $q->sql), 0, 160)];
        });

        $response = $next($request);

        $ms = (int) round((microtime(true) - $debut) * 1000);
        $sql = (int) array_sum(array_column($requetes, 0));
        $response->headers->set('Server-Timing', "total;dur=$ms, sql;dur=$sql");

        if ($ms >= self::SEUIL_MS) {
            usort($requetes, fn ($a, $b) => $b[0] <=> $a[0]);
            $lignes = array_map(fn ($r) => "    {$r[0]} ms [{$r[1]}] {$r[2]}", array_slice($requetes, 0, 5));
            file_put_contents(storage_path('logs/lenteurs.log'), sprintf("[%s] %d ms (SQL %d ms, %d requêtes) %s %s · %s\n%s\n",
                now()->format('Y-m-d H:i:s'), $ms, $sql, count($requetes), $request->method(), $request->getRequestUri(),
                $request->user()?->userName ?? '-', implode("\n", $lignes)), FILE_APPEND | LOCK_EX);
        }

        return $response;
    }
}
