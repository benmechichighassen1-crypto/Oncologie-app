<?php

namespace App\Repositories;

use App\Services\ClasseurPiecesJointes;
use App\Support\Clinisys as C;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Pièces jointes d'un patient :
 *  - GED (lue par la vue ERP dbo.Documents, qui ajoute numdevis) : rangées dans
 *    l'arborescence par n° de dossier, par identifiant
 *    sous « SANS ADMISSION », par NumDos, ou par devis (pièces de prise en charge) ;
 *  - GED_DOC : comptes rendus de radiologie, rattachés par NumDos.
 * La colonne binaire Doc n'est lue qu'à l'ouverture d'un document.
 */
class PiecesJointesRepository
{
    /** Sources GED qui n'ont pas pu être lues lors du dernier appel à liste(). */
    public array $indisponibles = [];

    public function __construct(private ClasseurPiecesJointes $classeur)
    {
    }

    public function liste(string $identifiant, array $numDoss, array $devis = []): array
    {
        // Une base GED illisible (droits du serveur lié) ne doit pas bloquer le dossier.
        $documents = [];
        $this->indisponibles = [];
        foreach ($this->listesBrutes($identifiant, $numDoss, $devis) as $source => $liste) {
            if ($liste === null) {
                $this->indisponibles[] = $source;
            } else {
                // Copie des objets : la liste mise en cache ne doit pas être modifiée ci-dessous.
                array_push($documents, ...array_map(fn ($d) => clone $d, $liste));
            }
        }

        $corrections = DB::table('onco_pj_categories')
            ->whereIn('doc_id', array_map(fn ($d) => $d->doc_id, $documents) ?: [''])
            ->get()->mapWithKeys(fn ($c) => [$c->source.'|'.$c->doc_id => $c->code_type]);

        foreach ($documents as $d) {
            $correction = $corrections[$d->source.'|'.$d->doc_id] ?? null;
            $code = $d->source === 'GED_DOC' && $correction === null
                ? '11' // Compte(s)-rendu(s) radiologique(s)
                : $this->classeur->codeType($d->Description ?: $d->Nom_doc, $d->type_pj ?? null, $correction);
            $d->code_type = $code;
            $d->type = $this->classeur->libelleType($code) ?? ($d->Description ?: $d->Nom_doc);
            $d->categorie = $this->classeur->categorie($code);
            $d->groupe = $d->source === 'GED_DOC' && $correction === null
                ? 'Imagerie'
                : $this->classeur->groupeOnco($code, $d->Description ?: $d->Nom_doc);
            $d->corrige = $correction !== null;
        }

        usort($documents, fn ($a, $b) => strcmp((string) $b->Date_doc, (string) $a->Date_doc));

        return $documents;
    }

    /** Document unique, vérifié comme appartenant au patient ; contenu binaire inclus. */
    public function document(string $source, string $docId, string $identifiant, array $numDoss, array $devis = []): ?object
    {
        if (! preg_match('/^[0-9A-Fa-f\-]{36}$/', $docId)) {
            return null;
        }

        // Le document doit figurer dans la liste du patient (en cache) ; on lit ensuite
        // son contenu par sa seule clé, bien plus vite qu'en refiltrant tout le dossier.
        $liste = $this->listesBrutes($identifiant, $numDoss, $devis)[$source] ?? null;
        $ligne = collect($liste ?? [])->first(fn ($d) => strcasecmp($d->doc_id, $docId) === 0);
        if (! $ligne) {
            return null;
        }
        $base = $source === 'GED' ? 'erp' : 'ged_doc';
        $contenu = C::db()->selectOne('SELECT d.Doc FROM '.C::t($base, 'dbo.Documents').' d WHERE d.DocID = ?', [$docId]);
        if (! $contenu) {
            return null;
        }
        $doc = clone $ligne;
        $doc->Doc = $contenu->Doc;

        return $doc;
    }

    /**
     * Listes des documents du patient par source (sans contenu), gardées 2 minutes :
     * le dossier, l'enregistrement d'une note et l'ouverture d'un document ne relisent
     * pas la GED à chaque fois. null = source illisible.
     *
     * @return array<string, array|null>
     */
    private function listesBrutes(string $identifiant, array $numDoss, array $devis): array
    {
        $sources = ['GED' => fn () => $this->ged($identifiant, $numDoss, $devis)];
        if (config('oncologie.ged_doc_actif')) {
            $sources['GED_DOC'] = fn () => $this->gedDoc($numDoss);
        }
        $listes = [];
        foreach ($sources as $source => $lire) {
            $cle = 'pj:'.$source.':'.md5($identifiant.'|'.implode(',', $numDoss).'|'.implode(',', $devis));
            try {
                $listes[$source] = Cache::remember($cle, 120, $lire);
            } catch (QueryException $e) {
                report($e);
                $listes[$source] = null;
            }
        }

        return $listes;
    }

    private function ged(string $identifiant, array $numDoss, array $devis = [], ?string $docId = null, bool $contenu = false): array
    {
        $cles = array_values(array_filter([...$numDoss, $identifiant]));
        if ($cles === []) {
            return [];
        }
        $in = C::in($cles);

        return C::db()->select(
            "SELECT 'GED' AS source, CAST(d.DocID AS nvarchar(36)) AS doc_id, d.Nom_doc, d.Description, d.Extension,
                    d.Date_doc, d.Users, d.type_pj, ISNULL(NULLIF(d.NumDos, ''), a.Nom_arborescence) AS numdoss, d.numdevis"
                .($contenu ? ', d.Doc' : '').'
             FROM '.C::t('erp', 'dbo.Documents').' d
             LEFT JOIN '.C::t('ged', 'dbo.Arborescence')." a ON a.ArborescenceID = d.Numero_arborescence
             WHERE (a.Nom_arborescence IN ($in) OR d.NumDos IN ($in)".($devis ? ' OR d.numdevis IN ('.C::in($devis).')' : '').')'
                .($docId ? ' AND d.DocID = ?' : ''),
            [...$cles, ...$cles, ...$devis, ...($docId ? [$docId] : [])]
        );
    }

    private function gedDoc(array $numDoss, ?string $docId = null, bool $contenu = false): array
    {
        if ($numDoss === []) {
            return [];
        }

        return C::db()->select(
            "SELECT 'GED_DOC' AS source, CAST(d.DocID AS nvarchar(36)) AS doc_id, d.Nom_doc, d.Description, d.Extension,
                    d.Date_doc, d.Users, NULL AS type_pj, d.NumDos AS numdoss, NULL AS numdevis"
                .($contenu ? ', d.Doc' : '').'
             FROM '.C::t('ged_doc', 'dbo.Documents').' d
             WHERE d.NumDos IN ('.C::in($numDoss).')'
                .($docId ? ' AND d.DocID = ?' : ''),
            [...$numDoss, ...($docId ? [$docId] : [])]
        );
    }
}
