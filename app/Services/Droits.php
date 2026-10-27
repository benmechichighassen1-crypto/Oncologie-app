<?php

namespace App\Services;

use App\Auth\ClinisysUser;
use Illuminate\Support\Facades\DB;

/**
 * Médecins dont l'utilisateur peut voir les patients.
 *
 * Portées (table onco_acces, base de l'appli) :
 *  - propre : ses patients uniquement (défaut d'un médecin) ;
 *  - tous   : l'activité de tous les médecins ;
 *  - liste  : ses patients + les médecins de onco_acces_medecins.
 * Un compte sans médecin ni droit paramétré ne voit rien.
 */
class Droits
{
    public const PORTEES = [
        'propre' => 'Ses patients uniquement',
        'liste' => 'Ses patients et des médecins choisis',
        'tous' => 'Tous les médecins',
    ];

    /** Résultat mémorisé par utilisateur pour la requête en cours. */
    private array $memo = [];

    /** @return string[]|null  null = tous les médecins */
    public function medecinsAutorises(ClinisysUser $user): ?array
    {
        if (array_key_exists($user->userName, $this->memo)) {
            return $this->memo[$user->userName];
        }

        return $this->memo[$user->userName] = $this->calculer($user);
    }

    private function calculer(ClinisysUser $user): ?array
    {
        $acces = DB::table('onco_acces')->where('user_name', $user->userName)->first();
        // Sans paramétrage : un administrateur voit tout, un médecin voit ses patients.
        $portee = $acces->portee ?? ($user->admin ? 'tous' : 'propre');

        if ($portee === 'tous') {
            return null;
        }

        $codes = $user->codMed ? [$user->codMed] : [];
        if ($portee === 'liste') {
            $codes = array_merge($codes, DB::table('onco_acces_medecins')
                ->where('user_name', $user->userName)->pluck('cod_med')->all());
        }

        return array_values(array_unique($codes));
    }

    /** Accès aux statistiques, paramétré à part ; « identique » reprend l'accès aux patients. */
    public const PORTEES_STATS = [
        'identique' => 'Comme ses patients',
        'aucun' => 'Aucun accès aux statistiques',
        'propre' => 'Sa propre activité uniquement',
        'liste' => 'Son activité et des médecins choisis',
        'tous' => 'Tous les médecins',
    ];

    public function porteeStatistiques(ClinisysUser $user): string
    {
        $portee = DB::table('onco_acces')->where('user_name', $user->userName)->value('stats_portee');

        return isset(self::PORTEES_STATS[$portee]) ? $portee : 'identique';
    }

    public function voitStatistiques(ClinisysUser $user): bool
    {
        return $this->medecinsStatistiques($user) !== [];
    }

    /** @return string[]|null  médecins dont l'activité est visible dans les statistiques (null = tous, [] = aucun) */
    public function medecinsStatistiques(ClinisysUser $user): ?array
    {
        $cle = 'stats:'.$user->userName;
        if (array_key_exists($cle, $this->memo)) {
            return $this->memo[$cle];
        }

        $codes = $user->codMed ? [$user->codMed] : [];

        return $this->memo[$cle] = match ($this->porteeStatistiques($user)) {
            'aucun' => [],
            'tous' => null,
            'propre' => $codes,
            'liste' => array_values(array_unique(array_merge($codes, DB::table('onco_acces_stats_medecins')
                ->where('user_name', $user->userName)->pluck('cod_med')->all()))),
            default => $this->medecinsAutorises($user),
        };
    }

    public function voitMedecin(ClinisysUser $user, ?string $codMed): bool
    {
        $autorises = $this->medecinsAutorises($user);

        return $autorises === null || ($codMed !== null && in_array($codMed, $autorises, true));
    }
}
