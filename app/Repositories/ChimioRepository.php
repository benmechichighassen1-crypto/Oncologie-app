<?php

namespace App\Repositories;

use App\Support\Clinisys as C;
use Illuminate\Support\Facades\DB;

/**
 * Prescriptions de chimiothérapie saisies dans Clinisys (lecture seule).
 *
 *  - Cure_Prescription : une prescription de protocole pour un patient (poids, taille, SC, nb de cures…) ;
 *  - ProtocoleCure / ProduitProtocoleCure : le protocole paramétré et ses lignes (jour, DCI, dose, dilution, durée) ;
 *  - medicamentCurePresc : doses ajustées pour ce patient (ArtDet donne le nom du médicament) ;
 *  - DetailsCurePresc : les séances prévues (1-J01, 2-J01…).
 */
class ChimioRepository
{
    public function cures(string $identifiant): array
    {
        return C::db()->select(
            'SELECT c.numCure, c.Date_cre, c.User_Cre, c.Titre, p.DesProt, c.codProtCorespondent, c.Observation,
                    c.nbreCure, c.nbreSeance, c.intervalleInterCure, c.Date_debut_traitement, c.poids, c.taille, c.BMI,
                    c.Surface_corporelle, c.numDoss, c.etat, c.dateTraitement, c.userTraitement,
                    -- User_Cre est soit un code médecin (M0004), soit un login : on affiche le nom.
                    COALESCE(NULLIF(LTRIM(RTRIM(mc.NomMed)), \'\'), NULLIF(LTRIM(RTRIM(ma.NomMed)), \'\'),
                             NULLIF(LTRIM(RTRIM(a.Description)), \'\'), c.User_Cre) AS prescripteur
             FROM '.C::t('erp', 'dbo.Cure_Prescription').' c
             LEFT JOIN '.C::t('erp', 'dbo.ProtocoleCure').' p ON p.CodProt = c.codProtCorespondent
             LEFT JOIN '.C::t('erp', 'dbo.Medecin').' mc ON mc.CodMed = c.User_Cre
             LEFT JOIN '.C::t('erp', config('oncologie.auth.table')).' a ON a.UserName = c.User_Cre
             LEFT JOIN '.C::t('erp', 'dbo.Medecin').' ma ON ma.CodMed = a.Code_Medecin_Infirmier
             WHERE c.identifiant = ?
             ORDER BY c.Date_cre DESC, c.numCure DESC',
            [$identifiant]
        );
    }

    /** Lignes des protocoles : codProt => liste ordonnée. */
    public function lignesProtocoles(array $codProts): array
    {
        $codProts = array_values(array_unique(array_filter(array_map('intval', $codProts))));
        if ($codProts === []) {
            return [];
        }
        // Copie locale du référentiel (onco:referentiels), mêmes noms de colonnes que Clinisys.
        if ($this->referentielLocal()) {
            return DB::table('onco_ref_protocole_lignes')->whereIn('cod_prot', $codProts)->orderBy('cod_prot')->orderBy('jour')->orderBy('ordre')
                ->get(['cod_prot', 'jour', 'dci as Dci', 'posologie as Posologie', 'dose_initiale as doseInitiale', 'dose_totale as doseTotale',
                    'dilution as Dilution', 'duree', 'horaire as Horaire', 'ordre as Ordre', 'chimio', 'dt', 'dose_chimio'])
                ->groupBy('cod_prot')->all();
        }

        return collect(C::db()->select(
            'SELECT CodProtcorespondent AS cod_prot, jour, Dci, Posologie, doseInitiale, doseTotale, Dilution, duree, Horaire, Ordre
             FROM '.C::t('erp', 'dbo.ProduitProtocoleCure').'
             WHERE CodProtcorespondent IN ('.C::in($codProts).')
             ORDER BY CodProtcorespondent, jour, Ordre',
            $codProts
        ))->groupBy('cod_prot')->all();
    }

    /** Doses ajustées pour le patient : numCure => liste (médicament, dose, observation). */
    public function dosesPatient(array $numCures): array
    {
        if ($numCures === []) {
            return [];
        }

        return collect(C::db()->select(
            'SELECT m.numCure, m.codeArt, m.Protocole AS dose, m.observation, a.desart
             FROM '.C::t('erp', 'dbo.medicamentCurePresc').' m
             LEFT JOIN (SELECT codart, MAX(desart) AS desart FROM '.C::t('erp', 'dbo.ArtDet').' GROUP BY codart) a ON a.codart = m.codeArt
             WHERE m.numCure IN ('.C::in($numCures).')',
            array_map('intval', $numCures)
        ))->groupBy('numCure')->all();
    }

    /** Séances prévues : numCure => liste (code séance, date prévue). */
    public function seances(array $numCures): array
    {
        if ($numCures === []) {
            return [];
        }

        return collect(C::db()->select(
            'SELECT numCure, ordreCure, codeSeance, MIN(datePrevus) AS datePrevus
             FROM '.C::t('erp', 'dbo.DetailsCurePresc').'
             WHERE numCure IN ('.C::in($numCures).')
             GROUP BY numCure, ordreCure, codeSeance
             ORDER BY numCure, ordreCure, codeSeance',
            array_map('intval', $numCures)
        ))->groupBy('numCure')->all();
    }

    /** Protocoles paramétrés actifs (référentiel Clinisys) : CodProt => nom. */
    public function protocolesActifs(): array
    {
        if ($this->referentielLocal()) {
            return DB::table('onco_ref_protocoles')->where('actif', true)->whereRaw("LTRIM(RTRIM(nom)) <> ''")->orderBy('nom')
                ->pluck('nom', 'cod_prot')->map(fn ($n) => trim($n))->all();
        }

        return collect(C::db()->select(
            "SELECT CodProt, DesProt FROM ".C::t('erp', 'dbo.ProtocoleCure')."
             WHERE LTRIM(RTRIM(Actif)) = 'O' AND LTRIM(RTRIM(ISNULL(DesProt, ''))) <> ''
             ORDER BY DesProt"
        ))->mapWithKeys(fn ($p) => [(int) $p->CodProt => trim($p->DesProt)])->all();
    }

    /**
     * Lignes d'un protocole prêtes à prescrire. dose_m2 est renseignée quand la dose
     * dépend de la surface corporelle (doseTotale = 0 et doseInitiale numérique).
     */
    public function lignesAPrescrire(int $codProt): array
    {
        return array_map(function ($l) {
            // Comme Clinisys : un produit de chimio (PC) sans « dose totale » (DT) a une dose par m²,
            // multipliée par la surface corporelle. Une dose en AUC (carboplatine) n'est pas calculée.
            $chimio = (bool) ($l->chimio ?? false);
            $reference = trim((string) ($chimio ? ($l->dose_chimio ?? '') : $l->doseInitiale));
            $nombre = str_replace(',', '.', $reference);
            $parM2 = $chimio ? (! ($l->dt ?? false) && is_numeric($nombre))
                : ((string) $l->doseTotale === '0' && is_numeric($nombre));
            $auc = $chimio && preg_match('/^AUC\s*([\d.,]+)$/i', $reference, $m) ? (float) str_replace(',', '.', $m[1]) : null;
            // Dose de référence à 0 dans le paramétrage Clinisys : rien à calculer, le médecin saisit la dose.
            $sansDose = $parM2 && (float) $nombre == 0.0;
            $parM2 = $parM2 && ! $sansDose;

            return [
                'jour' => trim((string) $l->jour),
                'medicament' => trim((string) $l->Dci),
                'chimio' => $chimio,
                'dose_initiale' => $reference,
                'dose_m2' => $parM2 ? (float) $nombre : null,
                'auc' => $auc,
                'sans_dose' => $sansDose,
                'dose' => $parM2 || $auc || $sansDose ? '' : ($chimio && ($l->dt ?? false) ? $reference : trim((string) ($l->Posologie ?: $l->doseInitiale))),
                'dilution' => trim((string) $l->Dilution),
                'duree' => trim((string) $l->duree),
                'horaire' => trim((string) $l->Horaire),
            ];
        }, collect($this->lignesProtocoles([$codProt])[$codProt] ?? [])->all());
    }

    /** Protocoles prescrits dans l'appli (oncologie_app), du plus récent au plus ancien. */
    public function curesAppli(string $identifiant): array
    {
        $cures = \Illuminate\Support\Facades\DB::table('onco_cures')->where('identifiant', $identifiant)
            ->orderByDesc('saisi_le')->orderByDesc('id')->get();
        $lignes = \Illuminate\Support\Facades\DB::table('onco_cure_lignes')->whereIn('cure_id', $cures->pluck('id'))
            ->orderBy('ordre')->get()->groupBy('cure_id');

        return $cures->map(fn ($c) => [
            'source' => 'appli',
            'id' => $c->id,
            'statut' => $c->statut,
            'nom' => $c->nom_protocole,
            // Mêmes noms de champs que Cure_Prescription, pour partager l'affichage et l'impression.
            'cure' => (object) [
                'numCure' => null, 'Date_cre' => $c->saisi_le, 'numDoss' => $c->num_doss,
                'poids' => $c->poids, 'taille' => $c->taille, 'BMI' => $c->bmi, 'Surface_corporelle' => $c->surface_corporelle,
                'nbreCure' => $c->nb_cures, 'nbreSeance' => null, 'intervalleInterCure' => $c->intervalle,
                'Date_debut_traitement' => $c->date_debut, 'Observation' => $c->observation,
                'User_Cre' => $c->prescrit_par, 'prescripteur' => $c->prescrit_nom ?: $c->prescrit_par,
            ],
            'lignes' => collect($lignes[$c->id] ?? [])->map(fn ($l) => [
                'jour' => (string) $l->jour, 'medicament' => $l->medicament, 'dose' => (string) $l->dose,
                'dilution' => (string) $l->dilution, 'duree' => (string) $l->duree,
                // Zéros retirés seulement après la virgule : « 60 » reste 60, « 85.50 » devient 85.5.
                'observation' => $l->dose_m2 ? (str_contains($l->dose_m2, '.') ? rtrim(rtrim($l->dose_m2, '0'), '.') : $l->dose_m2).' mg/m²' : '',
                'ajustee' => (bool) $l->ajustee,
            ])->all(),
            'seances' => [],
        ])->all();
    }

    /**
     * Recherche de médicaments pour la saisie (copie locale) : pharmacie de la clinique d'abord,
     * puis liste marocaine AMMPS (commercialisés en tête), puis DCI seules.
     * Chaque mot doit se retrouver dans le nom ou la DCI.
     */
    public function rechercherMedicaments(string $q, int $max = 30): array
    {
        $mots = array_slice(array_filter(preg_split('/\s+/', trim($q))), 0, 4);
        if ($mots === [] || ! $this->referentielLocal()) {
            return [];
        }
        $requete = DB::table('onco_ref_medicaments');
        foreach ($mots as $mot) {
            $like = '%'.str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], $mot).'%';
            $requete->where(fn ($w) => $w->where('libelle', 'like', $like)->orWhere('dci', 'like', $like)->orWhere('dosage', 'like', $like));
        }

        return $requete->orderByRaw("CASE source WHEN 'pharmacie' THEN 0 WHEN 'ammps' THEN 1 ELSE 2 END")
            ->orderByRaw("CASE WHEN statut LIKE 'Commercialis%' THEN 0 ELSE 1 END")->orderBy('libelle')
            ->limit($max)->get(['source', 'libelle', 'dci', 'dosage', 'forme', 'presentation', 'statut', 'ppv'])
            ->map(function ($m) {
                $nom = trim(implode(' ', array_filter([$m->libelle, $m->dosage, $m->forme])));
                $detail = match ($m->source) {
                    'pharmacie' => 'Pharmacie clinique'.($m->dci ? ' · '.$m->dci : ''),
                    'ammps' => collect([$m->dci, $m->presentation, $m->ppv ? number_format((float) $m->ppv, 2, ',', ' ').' DH' : null,
                        $m->statut && ! str_starts_with($m->statut, 'Commercialis') ? $m->statut : null])->filter()->implode(' · '),
                    default => 'DCI',
                };

                return ['libelle' => $nom, 'detail' => $detail];
            })->unique('libelle')->values()->all();
    }

    /** Référentiels copiés dans oncologie_app ? La première fois, on fait la copie. */
    private function referentielLocal(): bool
    {
        static $ok = null;
        if ($ok === null) {
            $referentiels = app(\App\Services\Referentiels::class);
            try {
                if (! $referentiels->disponibles()) {
                    $referentiels->synchroniser('automatique');
                }
                $ok = true;
            } catch (\Throwable $e) {
                report($e);
                $ok = false; // on lit Clinisys directement
            }
        }

        return $ok;
    }

    /** Surface corporelle en m², à 3 décimales. */
    public static function surfaceCorporelle(float $poids, float $taille): float
    {
        // Formule de Boyd, celle de Clinisys (vérifiée sur ses cures : 100 kg / 180 cm = 2,265 m²).
        $grammes = $poids * 1000;

        // Clinisys tronque à 3 décimales (2,2656 → 2,265), il n'arrondit pas.
        return floor(0.0003207 * $taille ** 0.3 * $grammes ** (0.7285 - 0.0188 * log10($grammes)) * 1000) / 1000;
    }

    /**
     * Lignes à afficher pour une cure : celles du protocole, avec la dose ajustée
     * du patient quand elle existe (rapprochée par le nom du médicament).
     */
    public static function lignesCure(object $cure, array $lignesProtocole, array $doses): array
    {
        $parNom = collect($doses)->keyBy(fn ($d) => mb_strtoupper(trim((string) $d->desart)));
        $lignes = [];
        foreach ($lignesProtocole as $l) {
            $ajustee = $parNom[mb_strtoupper(trim((string) $l->Dci))] ?? null;
            $lignes[] = [
                'jour' => trim((string) $l->jour),
                'medicament' => trim((string) $l->Dci),
                'dose' => trim((string) ($ajustee->dose ?? '')) ?: trim((string) $l->Posologie),
                'dilution' => trim((string) $l->Dilution),
                'duree' => trim((string) $l->duree),
                // doseTotale = 0 : dose exprimée par m² (doseInitiale), à rapporter à la surface corporelle.
                'observation' => trim((string) ($ajustee->observation ?? ''))
                    ?: ((string) $l->doseTotale === '0' && $l->doseInitiale ? trim($l->doseInitiale).' mg/m²' : ''),
                'ajustee' => $ajustee !== null,
            ];
        }
        // Médicaments ajoutés pour ce patient et absents du protocole.
        $noms = collect($lignesProtocole)->map(fn ($l) => mb_strtoupper(trim((string) $l->Dci)))->all();
        foreach ($doses as $d) {
            if (! in_array(mb_strtoupper(trim((string) $d->desart)), $noms, true)) {
                $lignes[] = ['jour' => '', 'medicament' => trim((string) ($d->desart ?: $d->codeArt)), 'dose' => trim((string) $d->dose),
                    'dilution' => '', 'duree' => '', 'observation' => trim((string) $d->observation), 'ajustee' => true];
            }
        }

        return $lignes;
    }
}
