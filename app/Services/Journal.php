<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Traçabilité : chaque action est enregistrée (qui, quand, quoi, sur quel
 * patient ou document, depuis quel poste). Le journal ne se modifie jamais.
 */
class Journal
{
    public static function ecrire(string $action, array $cible = [], ?string $userName = null): void
    {
        $request = request();

        DB::table('onco_journal')->insert([
            'user_name' => $userName ?? optional($request->user())->getAuthIdentifier(),
            'action' => $action,
            'identifiant_patient' => $cible['identifiant'] ?? null,
            'num_doss' => $cible['numdoss'] ?? null,
            'document' => $cible['document'] ?? null,
            'details' => empty($cible['details']) ? null : json_encode($cible['details'], JSON_UNESCAPED_UNICODE),
            'ip' => $request->ip(),
            'navigateur' => mb_substr((string) $request->userAgent(), 0, 255),
            'url' => mb_substr($request->fullUrl(), 0, 500),
            'cree_le' => now(),
        ]);
    }
}
