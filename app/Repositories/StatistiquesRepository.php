<?php

namespace App\Repositories;

use App\Support\Clinisys as C;
use InvalidArgumentException;

/**
 * Activité des centres (séances où le patient est venu), regroupée sur le
 * serveur Clinisys. Médecin : celui de la consultation, ou le médecin traitant
 * du dossier pour la chimio et la radio. Dossiers « T » exclus.
 *
 * Les valeurs injectées sont toutes validées (dates AAAAMMJJ, codes
 * alphanumériques, natures entières) car OPENQUERY n'accepte pas de paramètres.
 */
class StatistiquesRepository
{
    public const TYPES = ['CONSULTATION' => 'Consultation', 'CHIMIO' => 'Chimiothérapie', 'RADIO' => 'Radiothérapie'];

    /** Centre de chimiothérapie retenu (20 principal, 23 annexe) ; null = les deux. */
    private ?string $centreChimio = null;

    public function centreChimio(?string $code): static
    {
        // Les clés « 20 » et « 23 » deviennent des entiers en PHP : comparaison en texte.
        $codes = array_map('strval', array_keys(PlanningRepository::CENTRES_CHIMIO));
        $this->centreChimio = in_array((string) $code, $codes, true) ? (string) $code : null;

        return $this;
    }

    /** Activité par jour, semaine ou mois sur la période. @return object[] (type, periode, n) */
    public function parPeriode(string $du, string $au, string $pas, array $types, ?array $medecins, string $mode = 'seances'): array
    {
        $periode = match ($pas) {
            'jour' => 'CONVERT(char(8), x.Date_RDV, 112)',
            // Lundi de la semaine, indépendant du réglage DATEFIRST du serveur.
            'semaine' => 'CONVERT(char(8), DATEADD(day, -(DATEDIFF(day, 0, x.Date_RDV) % 7), CAST(x.Date_RDV AS date)), 112)',
            'mois' => 'CONVERT(char(6), x.Date_RDV, 112)',
            default => throw new InvalidArgumentException('Pas inconnu'),
        };

        return $this->agreger($this->date($du), $this->lendemain($au), $types, $medecins, "$periode AS periode", $periode, false, $mode);
    }

    /** Activité mensuelle de plusieurs années. @return object[] (type, annee, mois, n) */
    public function parMois(int $anneeDebut, int $anneeFin, array $types, ?array $medecins, string $mode = 'seances'): array
    {
        return $this->agreger($anneeDebut.'0101', ($anneeFin + 1).'0101', $types, $medecins,
            'YEAR(x.Date_RDV) AS annee, MONTH(x.Date_RDV) AS mois', 'YEAR(x.Date_RDV), MONTH(x.Date_RDV)', false, $mode);
    }

    /** Activité par médecin sur la période. @return object[] (type, cod_med, nom_med, n) */
    public function parMedecin(string $du, string $au, array $types, ?array $medecins, string $mode = 'seances', bool $parType = true): array
    {
        return $this->agreger($this->date($du), $this->lendemain($au), $types, $medecins,
            'x.cod_med, MAX(m.NomMed) AS nom_med', 'x.cod_med', true, $mode, $parType);
    }

    /**
     * Totaux de la période par centre (chaque centre compté à part).
     */
    public function totaux(string $du, string $au, array $types, ?array $medecins, string $mode = 'seances'): array
    {
        $parType = $this->agreger($this->date($du), $this->lendemain($au), $types, $medecins, '', '', false, $mode);
        return $parType;
    }

    private function agreger(string $du, string $auExclu, array $types, ?array $medecins, string $select, string $groupBy,
        bool $nomMedecin = false, string $mode = 'seances', bool $parType = true): array
    {
        $types = array_values(array_intersect(array_keys(self::TYPES), $types));
        if ($types === [] || $medecins === []) {
            return [];
        }
        $n = array_map('intval', config('oncologie.natures'));
        $cles = ['CONSULTATION' => 'consultation', 'CHIMIO' => 'chimio', 'RADIO' => 'radio'];
        $listeNatures = implode(',', array_map(fn ($t) => $n[$cles[$t]], $types));
        $col = C::collate();

        // Une ligne par RDV : date, type et médecin, uniquement si le patient est venu.
        $rdv = "SELECT r.Date_RDV,
                CASE ce.nature WHEN {$n['consultation']} THEN 'CONSULTATION' WHEN {$n['chimio']} THEN 'CHIMIO' ELSE 'RADIO' END AS type,
                CASE WHEN ce.nature = {$n['consultation']} THEN r.CodeMed $col ELSE c.MedTrait $col END AS cod_med,
                ISNULL(c.Identifiant $col, r.identifiant $col) AS patient
            FROM ".C::local('rdv', 'dbo.rdv').' r
            JOIN '.C::local('rdv', 'dbo.centre').' ce ON ce.code_centre = r.Code_Centre
            LEFT JOIN '.C::local('erp', 'dbo.Client')." c ON c.NumDoss = r.NumDoss $col
            WHERE ce.nature IN ($listeNatures) AND r.Date_RDV >= '$du' AND r.Date_RDV < '$auExclu'
              AND ISNULL(r.NumDoss, '') NOT LIKE '%T%'"
            .($this->centreChimio !== null ? " AND (ce.nature <> {$n['chimio']} OR ce.code_centre = {$this->centreChimio})" : '')
            ."
              AND (   (ce.nature = {$n['consultation']} AND ISNULL(r.NumDoss, '') <> '')
                   OR (ce.nature = {$n['chimio']} AND r.Datarr IS NOT NULL)
                   OR (ce.nature = {$n['radio']} AND ISNULL(r.Abscent, 0) = 0 AND (r.valide = 1 OR r.EnCours = 1 OR r.Enattente = 1)) )";

        $filtre = '';
        if ($medecins !== null) {
            foreach ($medecins as $code) {
                if (! preg_match('/^[A-Za-z0-9]{1,10}$/', (string) $code)) {
                    throw new InvalidArgumentException('Code médecin invalide');
                }
            }
            $filtre = " WHERE x.cod_med IN ('".implode("','", $medecins)."')";
        }

        // Séances : chaque RDV compte ; patients : chaque patient compte une fois par regroupement.
        $compte = $mode === 'patients' ? 'COUNT(DISTINCT x.patient)' : 'COUNT(*)';
        $colonnes = implode(', ', array_filter([$parType ? 'x.type' : null, $select ?: null, "$compte AS n"]));
        $groupes = implode(', ', array_filter([$parType ? 'x.type' : null, $groupBy ?: null]));
        $sql = "SELECT $colonnes FROM ($rdv) x"
            .($nomMedecin ? ' LEFT JOIN '.C::local('erp', 'dbo.Medecin')." m ON m.CodMed $col = x.cod_med" : '')
            .$filtre
.($groupes !== '' ? " GROUP BY $groupes" : '');

        return C::distant($sql);
    }

    private function date(string $date): string
    {
        $ts = strtotime($date);
        if ($ts === false) {
            throw new InvalidArgumentException('Date invalide');
        }

        return date('Ymd', $ts);
    }

    private function lendemain(string $date): string
    {
        return date('Ymd', strtotime($this->date($date).' +1 day'));
    }
}
