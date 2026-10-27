<?php

namespace App\Repositories;

use App\Support\Clinisys as C;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Séances de la période : consultations (centre 30), chimiothérapie
 * (centres 20 et 23, nature 20) et radiothérapie (nature 8).
 * Les dossiers administratifs « T » sont exclus.
 */
class PlanningRepository
{
    public const CENTRES_CHIMIO = ['20' => 'Chimiothérapie', '23' => 'Chimiothérapie annexe'];

    /**
     * @param string[]|null $medecins  null = tous
     * @param string[]      $types     CONSULTATION | CHIMIO | RADIO
     */
    public function seances(string $du, string $au, ?array $medecins, array $types, ?string $centreChimio = null, ?string $patient = null): array
    {
        // Recherche d'un patient : chaque mot doit se retrouver dans le nom, le n° de dossier ou l'identifiant.
        $mots = array_slice(array_filter(preg_split('/\s+/', trim((string) $patient))), 0, 4);
        $filtrePatient = function (array $colonnes) use ($mots): array {
            $sql = '';
            $b = [];
            foreach ($mots as $mot) {
                $sql .= ' AND ('.implode(' OR ', array_map(fn ($c) => "$c LIKE ?", $colonnes)).')';
                array_push($b, ...array_fill(0, count($colonnes), '%'.str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], $mot).'%'));
            }

            return [$sql, $b];
        };
        if ($medecins === []) {
            return [];
        }

        // Format AAAAMMJJ : seul format de date lu sans ambiguïté quelle que soit la langue du login SQL.
        $du = date('Ymd', strtotime($du));
        $au = date('Ymd', strtotime($au));

        $col = C::collate();
        $parties = [];
        $bindings = [];
        $filtreMed = fn (string $colonne) => $medecins === null ? '' : " AND $colonne IN (".C::in($medecins).')';

        foreach (['CHIMIO' => 'centre.RdvNormCHIMIO', 'RADIO' => 'centre.RdvNormRADIOTHERAPIE'] as $type => $vue) {
            if (! in_array($type, $types, true)) {
                continue;
            }
            $centre = '';
            if ($type === 'CHIMIO' && $centreChimio !== null && isset(self::CENTRES_CHIMIO[$centreChimio])) {
                $centre = ' AND r.Code_Centre = ?';
            }
            // Radiothérapie : un RDV ajouté quand la journée est pleine est placé en fin de journée
            // (22 h, 23 h…) alors que le patient passe plus tôt. Séance terminée : on la place à
            // l'heure réelle de début de traitement (centre.Rdv.debut_trait), l'heure prévue est gardée.
            [$debut, $fin, $prevu] = ['v.startDate', 'v.endDate', 'CAST(NULL AS nvarchar(30))'];
            if ($type === 'RADIO') {
                $reel = 'CASE WHEN v.etatRDV = 2 AND r.debut_trait IS NOT NULL AND CAST(r.debut_trait AS date) = CAST(v.date_rdv AS date) THEN r.debut_trait END';
                $debut = "ISNULL(CONVERT(varchar(19), $reel, 126), v.startDate)";
                $fin = "ISNULL(CONVERT(varchar(19), DATEADD(minute, DATEDIFF(minute, CAST(v.startDate AS datetime), CAST(v.endDate AS datetime)), $reel), 126), v.endDate)";
                $prevu = "CASE WHEN $reel IS NOT NULL THEN v.startDate END";
            }
            $parties[] = "SELECT '$type' AS type, ".$this->colonnes([
                'v.num_rdv' => 'num_rdv', 'v.numdoss' => 'numdoss', 'v.Identifiant' => 'identifiant', 'v.nom' => 'patient',
                $debut => 'startDate', $fin => 'endDate', 'v.Seance' => 'seance', 'v.CodeRessource' => 'ressource',
            ]).', v.etatRDV AS etat, '.$this->colonnes([
                'v.Organisme' => 'organisme', 'c.MedTrait' => 'cod_med', 'v.nomMedecin' => 'medecin',
                'CAST(r.Code_Centre AS nvarchar(10))' => 'centre', $prevu => 'prevu',
            ]).'
                FROM '.C::t('erp', $vue).' v
                JOIN '.C::t('erp', 'dbo.Client')." c ON c.NumDoss = v.numdoss $col
                LEFT JOIN ".C::t('erp', 'centre.Rdv').' r ON r.Num_RDV = v.num_rdv
                WHERE v.date_rdv >= ? AND v.date_rdv < DATEADD(day, 1, ?)'."
                  AND v.numdoss NOT LIKE '%T%'".$centre.$filtreMed('c.MedTrait');
            array_push($bindings, $du, $au);
            if ($centre !== '') {
                $bindings[] = $centreChimio;
            }
            if ($medecins !== null) {
                array_push($bindings, ...$medecins);
            }
            [$sqlPatient, $bPatient] = $filtrePatient(['v.nom', 'v.numdoss', 'v.Identifiant']);
            $parties[array_key_last($parties)] .= $sqlPatient;
            array_push($bindings, ...$bPatient);
        }

        if (in_array('CONSULTATION', $types, true)) {
            $parties[] = "SELECT 'CONSULTATION' AS type, ".$this->colonnes([
                'r.Num_RDV' => 'num_rdv', 'r.NumDoss' => 'numdoss', 'c.Identifiant' => 'identifiant',
                "r.Nom + ' ' + r.Prenom" => 'patient', 'r.startDate' => 'startDate', 'r.endDate' => 'endDate',
            ]).', NULL AS seance, '.$this->colonnes(['r.CodeRessource' => 'ressource']).', r.etatRDV AS etat, '.$this->colonnes([
                's.DesSoc' => 'organisme', 'r.CodeMed' => 'cod_med', 'm.NomMed' => 'medecin',
            ]).', NULL AS centre, NULL AS prevu
                FROM '.C::t('erp', 'centre.RdvNorm').' r
                LEFT JOIN '.C::t('erp', 'dbo.Client')." c ON c.NumDoss = r.NumDoss $col
                LEFT JOIN ".C::t('erp', 'dbo.Societe').' s ON s.NumSoc = c.NumSoc
                LEFT JOIN '.C::t('erp', 'dbo.Medecin')." m ON m.CodMed = r.CodeMed $col
                WHERE r.Nature = ? AND r.Date_RDV >= ? AND r.Date_RDV < DATEADD(day, 1, ?)
                  AND ISNULL(r.NumDoss, '') NOT LIKE '%T%'".$filtreMed("r.CodeMed $col");
            array_push($bindings, config('oncologie.natures.consultation'), $du, $au);
            if ($medecins !== null) {
                array_push($bindings, ...$medecins);
            }
            [$sqlPatient, $bPatient] = $filtrePatient(["(r.Nom + ' ' + r.Prenom)", 'r.NumDoss', 'c.Identifiant']);
            $parties[array_key_last($parties)] .= $sqlPatient;
            array_push($bindings, ...$bPatient);
        }

        if ($parties === []) {
            return [];
        }

        return C::db()->select(implode("\nUNION ALL\n", $parties).' ORDER BY startDate', $bindings);
    }

    /**
     * Colonnes texte ramenées à la collation de la base courante : les vues
     * Clinisys mélangent CP1256 et French_CI_AS, ce que UNION ALL refuse.
     */
    private function colonnes(array $expressions): string
    {
        return implode(', ', array_map(
            fn ($expr, $alias) => "$expr COLLATE DATABASE_DEFAULT AS $alias",
            array_keys($expressions),
            $expressions,
        ));
    }

    /** Libellés des chaises et appareils (code salle => libellé), mis en cache une heure. */
    public function ressources(): array
    {
        return Cache::remember('onco.ressources', 3600, function () {
            try {
                return collect(C::db()->select('SELECT codeSalle, Designation FROM '.C::t('erp', 'centre.Salle')))
                    ->mapWithKeys(fn ($s) => [(string) $s->codeSalle => trim((string) $s->Designation)])
                    ->all();
            } catch (Throwable) {
                return [];
            }
        });
    }

    /** Médecins proposés dans le filtre du calendrier. */
    public function medecins(?array $autorises): array
    {
        $sql = 'SELECT CodMed, NomMed FROM '.C::t('erp', 'dbo.Medecin').' WHERE Actif = 1';
        $bindings = [];
        if ($autorises !== null) {
            if ($autorises === []) {
                return [];
            }
            $sql .= ' AND CodMed IN ('.C::in($autorises).')';
            $bindings = $autorises;
        }

        return C::db()->select($sql.' ORDER BY NomMed', $bindings);
    }
}
