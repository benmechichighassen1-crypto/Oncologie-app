<?php

namespace App\Services;

use App\Support\Clinisys as C;
use Illuminate\Support\Facades\DB;

/**
 * Copie dans oncologie_app les référentiels Clinisys utiles à la prescription
 * (lecture seule côté Clinisys) : les écrans lisent la copie locale, plus rapide
 * que le serveur lié. Relancer après un nouveau paramétrage dans Clinisys.
 */
class Referentiels
{
    /** Familles d'articles de la pharmacie proposées à la prescription. */
    public const FAMILLES_MEDICAMENTS = ['F07', 'F18']; // MEDICAMENTS, SERUM

    /** @return array<string, int> nombre de lignes copiées par référentiel */
    public function synchroniser(?string $par = null): array
    {
        $protocoles = C::db()->select('SELECT CodProt, DesProt, Actif, MedecinDemandeur FROM '.C::t('erp', 'dbo.ProtocoleCure'));
        $lignes = C::db()->select(
            'SELECT CodProtcorespondent, Ordre, jour, Dci, Posologie, doseTotale, doseInitiale, Dilution, duree, Horaire, Code_Posologie
             FROM '.C::t('erp', 'dbo.ProduitProtocoleCure')
        );
        // Produits de chimio du protocole (colonne « PC » de Clinisys) : DT = dose totale, sinon dose par m².
        $chimio = collect(C::db()->select(
            'SELECT CodProtcorespondent, Codart, DT, doseInitiale, Protocole FROM '.C::t('erp', 'dbo.medicament_ProtocoleCure')
        ))->keyBy(fn ($c) => (int) $c->CodProtcorespondent.'|'.trim((string) $c->Codart));
        $dci = C::db()->select("SELECT DISTINCT LTRIM(RTRIM(DCI)) AS DCI FROM ".C::t('erp', 'dbo.DCI')." WHERE LTRIM(RTRIM(ISNULL(DCI, ''))) <> ''");
        $pharmacie = C::db()->select(
            "SELECT codart, MAX(desart) AS desart, MAX(DCI) AS DCI FROM ".C::t('erp', 'dbo.Stock')."
             WHERE FamArt IN (".C::in(self::FAMILLES_MEDICAMENTS).") AND actif = 1 AND LTRIM(RTRIM(ISNULL(desart, ''))) <> ''
             GROUP BY codart",
            self::FAMILLES_MEDICAMENTS
        );

        $t = fn ($v, $n) => ($v = trim((string) $v)) === '' ? null : mb_substr($v, 0, $n);
        DB::transaction(function () use ($protocoles, $lignes, $chimio, $dci, $pharmacie, $t, $par) {
            DB::table('onco_ref_protocole_lignes')->delete();
            DB::table('onco_ref_protocoles')->delete();
            // La liste AMMPS (onco:ammps) a sa propre mise à jour : on n'y touche pas ici.
            DB::table('onco_ref_medicaments')->whereIn('source', ['dci', 'pharmacie'])->delete();

            $this->inserer('onco_ref_protocoles', array_map(fn ($p) => [
                'cod_prot' => (int) $p->CodProt, 'nom' => $t($p->DesProt, 150) ?? 'Protocole '.$p->CodProt,
                'actif' => trim((string) $p->Actif) === 'O', 'medecin' => $t($p->MedecinDemandeur, 20),
            ], $protocoles));
            $this->inserer('onco_ref_protocole_lignes', array_map(function ($l) use ($t, $chimio) {
                $c = $chimio[(int) $l->CodProtcorespondent.'|'.trim((string) $l->Code_Posologie)] ?? null;

                return [
                    'cod_prot' => (int) $l->CodProtcorespondent, 'ordre' => (int) $l->Ordre, 'jour' => $t($l->jour, 20),
                    'dci' => $t($l->Dci, 150), 'posologie' => $t($l->Posologie, 150), 'dose_totale' => $t($l->doseTotale, 10),
                    'dose_initiale' => $t($l->doseInitiale, 150), 'dilution' => $t($l->Dilution, 255),
                    'duree' => $t($l->duree, 40), 'horaire' => $t($l->Horaire, 40),
                    'code_art' => $t($l->Code_Posologie, 30), 'chimio' => $c !== null, 'dt' => $c !== null && (int) $c->DT === 1,
                    // Dose de référence : celle de la fiche chimio, sinon la dose initiale de la ligne.
                    'dose_chimio' => $c ? ($t($c->doseInitiale, 60) ?? $t($l->doseInitiale, 60) ?? $t($c->Protocole, 60)) : null,
                ];
            }, $lignes));
            $this->inserer('onco_ref_medicaments', array_merge(
                array_map(fn ($d) => ['source' => 'dci', 'code' => null, 'libelle' => $t($d->DCI, 255), 'dci' => $t($d->DCI, 150)], $dci),
                array_map(fn ($a) => ['source' => 'pharmacie', 'code' => $t($a->codart, 30), 'libelle' => $t($a->desart, 255),
                    // Certains articles ont un code au lieu d'une DCI : on ne garde que du texte.
                    'dci' => preg_match('/[A-Za-z]/', (string) $a->DCI) ? $t($a->DCI, 150) : null], $pharmacie),
            ));

            foreach (['protocoles' => count($protocoles), 'protocole_lignes' => count($lignes), 'dci' => count($dci), 'pharmacie' => count($pharmacie)] as $ref => $n) {
                DB::table('onco_ref_synchro')->updateOrInsert(['referentiel' => $ref], ['lignes' => $n, 'par' => $par, 'synchro_le' => now()->format('Y-m-d H:i:s')]);
            }
        });

        return ['protocoles' => count($protocoles), 'lignes de protocole' => count($lignes), 'DCI' => count($dci), 'médicaments pharmacie' => count($pharmacie)];
    }

    /** Copie faite au moins une fois ? Sinon les écrans la déclenchent. */
    public function disponibles(): bool
    {
        return DB::table('onco_ref_synchro')->where('referentiel', 'protocoles')->exists();
    }

    public function derniereSynchro(): ?object
    {
        return DB::table('onco_ref_synchro')->where('referentiel', 'protocoles')->first();
    }

    /** Insertion par paquets (SQL Server : 2 100 paramètres au plus par requête). */
    private function inserer(string $table, array $lignes): void
    {
        if ($lignes === []) {
            return;
        }
        $taille = max(1, intdiv(2000, count($lignes[0])));
        foreach (array_chunk($lignes, $taille) as $paquet) {
            DB::table($table)->insert($paquet);
        }
    }
}
