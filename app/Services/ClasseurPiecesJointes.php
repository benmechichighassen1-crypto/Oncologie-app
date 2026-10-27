<?php

namespace App\Services;

use App\Support\Clinisys as C;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Classe une pièce jointe dans les catégories Clinisys (Type_Doc -> CategorieType_Doc).
 *
 * Ordre : correction manuelle (onco_pj_categories) > type_pj > libellé identique
 * à un Type_Doc > synonyme > mot-clé contenu dans le libellé > « Non classé ».
 */
class ClasseurPiecesJointes
{
    public const NON_CLASSE = 'Non classé';

    /** @return array{types: array<string,array{Des_Type:string,categorie:?string}>, libelles: array<string,string>, categories: array<string,string>} */
    /** Mémorisé pour la requête en cours : appelé plusieurs fois par document. */
    private ?array $referentiel = null;

    public function referentiel(): array
    {
        return $this->referentiel ??= Cache::remember('onco.type_doc', 3600, function () {
            $types = collect(C::db()->select(
                'SELECT t.Code_Type, t.Des_Type, t.id_categorie, k.description AS categorie
                 FROM '.C::t('erp', 'dbo.Type_Doc').' t
                 LEFT JOIN '.C::t('erp', 'dbo.CategorieType_Doc').' k ON k.id_categorie = t.id_categorie'
            ))->mapWithKeys(fn ($t) => [trim((string) $t->Code_Type) => [
                // Tableaux simples : le cache refuse de désérialiser des objets.
                'Des_Type' => trim((string) $t->Des_Type),
                'categorie' => $t->categorie,
            ]]);

            return [
                'types' => $types->all(),
                'libelles' => $types->mapWithKeys(fn ($t, $code) => [self::normaliser($t['Des_Type']) => $code])->all(),
                'categories' => $types->mapWithKeys(fn ($t, $code) => [$code => trim((string) $t['categorie']) ?: self::NON_CLASSE])->all(),
            ];
        });
    }

    /** Code Type_Doc retenu pour un document, ou null. */
    public function codeType(?string $libelle, $typePj, ?string $correction = null): ?string
    {
        $ref = $this->referentiel();

        foreach ([$correction, $typePj !== null ? (string) $typePj : null] as $code) {
            if ($code !== null && isset($ref['types'][$code])) {
                return $code;
            }
        }

        $norme = self::normaliser($libelle);
        if ($norme === '') {
            return null;
        }
        if (isset($ref['libelles'][$norme])) {
            return $ref['libelles'][$norme];
        }

        $synonymes = config('oncologie.synonymes_pj');
        if (isset($synonymes[$norme])) {
            return $synonymes[$norme];
        }

        foreach (config('oncologie.mots_cles_pj') as $mot => $code) {
            // Début de mot uniquement : « CIN » ne doit pas trouver « MEDECIN ».
            if (preg_match('/\b'.preg_quote($mot, '/').'/', $norme)) {
                return $code;
            }
        }

        return null;
    }

    /** Rubriques de la GED oncologie de Clinisys, dans l'ordre d'affichage. */
    public const GROUPES = ['Imagerie', 'Bilans biologiques', 'Anapath', 'RCP', 'Autres documents'];

    /**
     * Rubrique oncologie d'un document : d'abord le type (Type_Doc), puis des
     * mots-clés du libellé (« TDM TAP », « SUITE BILAN »…), sinon « Autres documents ».
     */
    public function groupeOnco(?string $code, ?string $libelle): string
    {
        $parType = ['11' => 'Imagerie', '26' => 'Imagerie', '05' => 'Bilans biologiques', '25' => 'Anapath'];
        if ($code !== null && isset($parType[$code])) {
            return $parType[$code];
        }
        // Rapports médicaux (chimio, radiothérapie, hormono, CR opératoire, lettres) : pas de l'imagerie.
        if (in_array($code, ['04', '24', '32', '33', '34', '35'], true)) {
            return 'Autres documents';
        }

        $norme = self::normaliser($libelle);
        $motsCles = [
            'RCP' => ['RCP', 'CONCERTATION'],
            'Anapath' => ['ANAPATH', 'ANAPTH', 'PATH', 'BIOPSIE', 'HISTOLOG', 'IMMUNOHISTO', 'IHC', 'CYTOLOG'],
            'Imagerie' => ['TDM', 'SCANNER', 'IRM', 'ECHO', 'RX', 'RADIO', 'MAMMO', 'SCINTI', 'PET', 'TEP', 'TOMO', 'DOPPLER', 'IMAGERIE'],
            'Bilans biologiques' => ['BILAN', 'ANALYSE', 'BIOLOG', 'NFS', 'MARQUEUR', 'CA 15', 'CA 125', 'ACE', 'PSA', 'LABO'],
        ];
        foreach ($motsCles as $groupe => $mots) {
            foreach ($mots as $mot) {
                if (preg_match('/\b'.preg_quote($mot, '/').'/', $norme)) {
                    return $groupe;
                }
            }
        }

        return 'Autres documents';
    }

    public function categorie(?string $code): string
    {
        return $code === null ? self::NON_CLASSE : ($this->referentiel()['categories'][$code] ?? self::NON_CLASSE);
    }

    public function libelleType(?string $code): ?string
    {
        return $code === null ? null : (trim((string) ($this->referentiel()['types'][$code]['Des_Type'] ?? '')) ?: null);
    }

    /** « Compte(s)-rendu(s) radiologique(s) » => « COMPTE S RENDU S RADIOLOGIQUE S » */
    public static function normaliser(?string $texte): string
    {
        $texte = Str::upper(Str::ascii((string) $texte));
        $texte = preg_replace('/[^A-Z0-9]+/', ' ', $texte);

        return trim(preg_replace('/\s+/', ' ', $texte));
    }
}
