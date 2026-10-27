<?php

namespace App\Repositories;

use App\Support\Clinisys as C;

/**
 * Dossier patient : identité, admissions (hors dossiers « T »), programmes de
 * traitement et séances. Tout est lu dans Clinisys, sans écriture.
 */
class DossierRepository
{
    public function patient(string $identifiant): ?object
    {
        return C::db()->selectOne(
            'SELECT TOP 1 c.Identifiant, c.NomCli, c.Prenom, c.DatNai, c.sex, c.NumCIN, c.NumTel, c.AdrCli
             FROM '.C::t('erp', 'dbo.Client')." c
             WHERE c.Identifiant = ? AND c.NumDoss NOT LIKE '%T%'
             ORDER BY c.DatArr DESC",
            [$identifiant]
        );
    }

    /** Admissions du patient, de la plus récente à la plus ancienne. */
    public function admissions(string $identifiant): array
    {
        return C::db()->select(
            'SELECT c.NumDoss, c.DatArr, c.HeuArr, c.DatDep, c.EtaCli, c.MedTrait,
                    mt.NomMed AS medecin_traitant, mo.des AS motif, r.NumRecep, r.LibRecep AS reception,
                    org.DesSoc AS organisme, mut.DesSoc AS mutuelle,
                    ca.NomCorre AS correspondant, ca.NatureArr AS nature_correspondant,
                    c.NumDevis, d.diagnostique
             FROM '.C::t('erp', 'dbo.Client').' c
             LEFT JOIN '.C::t('erp', 'dbo.Medecin').' mt ON mt.CodMed = c.MedTrait
             LEFT JOIN '.C::t('erp', 'dbo.motif').' mo ON mo.cod = c.TypAdm
             LEFT JOIN '.C::t('erp', 'dbo.chambre').' ch ON ch.NumCha = c.NumCha
             LEFT JOIN '.C::t('erp', 'dbo.Reception').' r ON r.NumSer = ch.NumSer
             LEFT JOIN '.C::t('erp', 'dbo.Societe').' org ON org.NumSoc = c.NumSoc
             LEFT JOIN '.C::t('erp', 'dbo.Societe').' mut ON mut.NumSoc = c.NumSocMutuelle
             LEFT JOIN '.C::t('erp', 'dbo.correspendanceAdmission').' ca ON ca.numdoss = c.NumDoss
             LEFT JOIN '.C::t('erp', 'dbo.Devis')." d ON d.numDevis = c.NumDevis
             WHERE c.Identifiant = ? AND c.NumDoss NOT LIKE '%T%'
             ORDER BY c.DatArr DESC, c.HeuArr DESC",
            [$identifiant]
        );
    }

    /** Devis du patient (rattachent les pièces de prise en charge sans dossier). */
    public function devis(string $identifiant, array $admissions): array
    {
        $liste = C::db()->select('SELECT numDevis FROM '.C::t('erp', 'dbo.Devis').' WHERE identifiant = ?', [$identifiant]);

        return array_values(array_unique(array_filter(array_merge(
            array_map(fn ($d) => trim((string) $d->numDevis), $liste),
            array_map(fn ($a) => trim((string) $a->NumDevis), $admissions),
        ))));
    }

    /** Programmes de traitement (chimio, radio…) des dossiers donnés. */
    public function programmes(array $numDoss): array
    {
        if ($numDoss === []) {
            return [];
        }

        return C::db()->select(
            'SELECT e.Code_Examen, e.Numero_Dossier, e.Nature, e.nbreCure, e.nbreSeance, e.Date_debut_traitement,
                    e.MedecinPrescripteur, e.FinTrait, e.TraitementInterrompu, e.programmeSuspendu, e.DoseTotal,
                    (SELECT COUNT(*) FROM '.C::t('rdv', 'dbo.rdv').' x
                     WHERE x.Numdemande = e.Code_Examen '.C::collate().' AND x.valide = 1) AS seances_faites
             FROM '.C::t('erp', 'dbo.Examen_Complementaire').' e
             WHERE e.Numero_Dossier IN ('.C::in($numDoss).') AND e.Nature IN (?, ?)
             ORDER BY e.Date_debut_traitement DESC',
            [...$numDoss, config('oncologie.natures.chimio'), config('oncologie.natures.radio')]
        );
    }

    /** Toutes les séances (consultation, chimio, radio) des dossiers donnés. */
    public function seances(array $numDoss): array
    {
        if ($numDoss === []) {
            return [];
        }
        $col = C::collate();

        return C::db()->select(
            "SELECT r.Num_RDV, r.NumDoss, r.Numdemande, r.Date_RDV, r.Heure_RDV, r.Nature, r.CodeMed, m.NomMed,
                    r.CodeRessource, r.etatRDV, r.position, r.Jour, r.ordreRdv, r.Code_Centre
             FROM ".C::t('erp', 'centre.RdvNorm').' r
             LEFT JOIN '.C::t('erp', 'dbo.Medecin')." m ON m.CodMed = r.CodeMed $col
             WHERE r.NumDoss $col IN (".C::in($numDoss).') AND r.Nature IN (?, ?, ?)
             ORDER BY r.Date_RDV DESC, r.Heure_RDV DESC',
            [...$numDoss, ...array_values(config('oncologie.natures'))]
        );
    }
}
