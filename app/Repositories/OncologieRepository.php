<?php

namespace App\Repositories;

use App\Support\Clinisys as C;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Données oncologiques : référentiels Clinisys (tumeurs, antécédents) en
 * lecture seule, et saisies de l'oncologue dans la base de l'appli.
 */
class OncologieRepository
{
    public const STATUTS = [
        'bilan' => 'Bilan initial', 'traitement' => 'Traitement en cours', 'surveillance' => 'Surveillance',
        'remission' => 'Rémission', 'recidive' => 'Récidive / progression', 'palliatif' => 'Soins palliatifs', 'decede' => 'Décédé',
    ];
    public const TRAITEMENTS = ['Chirurgie', 'Chimiothérapie', 'Radiothérapie', 'Curiethérapie', 'Hormonothérapie', 'Immunothérapie', 'Thérapie ciblée'];
    public const FAMILLES = [
        'medical' => 'Médical', 'chirurgical' => 'Chirurgical', 'familial' => 'Familial', 'allergie' => 'Allergie',
        'gyneco' => 'Gynéco-obstétrical', 'habitude' => 'Habitudes (tabac, alcool…)', 'autre' => 'Autre',
    ];
    public const TYPES_PRESCRIPTION = ['protocole' => 'Protocole', 'medicament' => 'Médicament', 'examen' => 'Examen', 'autre' => 'Autre'];
    public const STATUTS_PRESCRIPTION = ['prescrite' => 'Prescrite', 'en_cours' => 'En cours', 'arretee' => 'Arrêtée'];
    public const VOIES = ['IV', 'Per os', 'SC', 'IM', 'Intrathécale', 'Locale', 'Autre'];

    public function prescriptions(string $identifiant)
    {
        return DB::table('onco_prescriptions')->where('identifiant', $identifiant)->whereNull('masque_le')
            ->orderByDesc('date_prescription')->orderByDesc('id')->get();
    }

    public const NOTES = ['histoire' => 'Histoire de la maladie', 'consultation' => 'Consultation', 'evolution' => 'Note d\'évolution', 'rcp' => 'Décision RCP'];

    /** Types proposés dans le formulaire unique de saisie du dossier. */
    public const SAISIES = [
        'consultation' => 'Consultation', 'histoire' => 'Histoire de la maladie', 'evolution' => 'Évolution',
        'diagnostic' => 'Diagnostic / pathologie', 'antecedent' => 'Antécédent', 'rcp' => 'Décision RCP',
    ];

    /** Référentiel Clinisys des tumeurs (codes CIM) : id => libellé. */
    private ?array $tumeurs = null;

    public function tumeurs(): array
    {
        return $this->tumeurs ??= Cache::remember('onco.tumeurs', 3600, fn () => collect(C::db()->select(
            'SELECT id, description FROM '.C::t('erp', 'dbo.Tumeurs').' WHERE actif = 1 ORDER BY description'
        ))->mapWithKeys(fn ($t) => [(int) $t->id => trim((string) $t->description)])->all());
    }

    /**
     * Liste simplifiée pour la saisie : le libellé principal, sans le détail du
     * site (« Tumeur maligne du sein : Quadrant supéro-externe » => « Tumeur maligne du sein »).
     * Retourne libellé => id Clinisys représentatif (le plus petit id du groupe).
     */
    public function pathologies(): array
    {
        $liste = [];
        foreach ($this->tumeurs() as $id => $libelle) {
            $principal = self::libellePrincipal($libelle);
            $liste[$principal] = min($liste[$principal] ?? PHP_INT_MAX, $id);
        }
        ksort($liste, SORT_LOCALE_STRING);

        return $liste;
    }

    public static function libellePrincipal(string $libelle): string
    {
        return trim(explode(':', $libelle)[0]);
    }

    /** Tumeurs enregistrées dans Clinisys pour le patient. */
    public function tumeursClinisys(string $identifiant): array
    {
        return C::db()->select(
            'SELECT t.description, d.Heure_Exam, d.Utilisateur_Tumeur
             FROM '.C::t('erp', 'dbo.Details_Tumeur').' d
             JOIN '.C::t('erp', 'dbo.Tumeurs').' t ON t.id = d.Code_Tumeur
             WHERE d.Identifiant = ? ORDER BY d.Heure_Exam DESC',
            [$identifiant]
        );
    }

    /** Antécédents saisis dans Clinisys (par dossier ou par identifiant). */
    public function antecedentsClinisys(string $identifiant, array $numDoss): array
    {
        $cles = $numDoss ?: [''];

        return C::db()->select(
            'SELECT DISTINCT f.Designation AS famille, a.Designation AS antecedent, d.Observation, d.Heure_Exam, d.Utilisateur_Ant
             FROM '.C::t('erp', 'dbo.Details_Antecedent').' d
             JOIN '.C::t('erp', 'dbo.Antecedent').' a ON a.Code_Antecedent = d.Code_Antecdent
             LEFT JOIN '.C::t('erp', 'dbo.Famille_Antecedent').' f ON f.Code_Famille = a.Code_Famille
             WHERE d.Identifiant = ? OR d.Numero_Dossier IN ('.C::in($cles).')
             ORDER BY d.Heure_Exam DESC',
            [$identifiant, ...$cles]
        );
    }

    public function fiche(string $identifiant): ?object
    {
        return DB::table('onco_fiches')->where('identifiant', $identifiant)->orderByDesc('version')->first();
    }

    public function versionsFiche(string $identifiant)
    {
        return DB::table('onco_fiches')->where('identifiant', $identifiant)->orderByDesc('version')->get();
    }

    public function antecedents(string $identifiant)
    {
        return DB::table('onco_antecedents')->where('identifiant', $identifiant)->whereNull('masque_le')->orderBy('famille')->orderByDesc('saisi_le')->get();
    }

    public function notes(string $identifiant)
    {
        return DB::table('onco_notes')->where('identifiant', $identifiant)->whereNull('masque_le')->orderByDesc('date_note')->orderByDesc('id')->get();
    }
}
