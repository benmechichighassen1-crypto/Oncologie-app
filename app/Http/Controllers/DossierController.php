<?php

namespace App\Http\Controllers;

use App\Auth\ClinisysUser;
use App\Repositories\ChimioRepository;
use App\Repositories\DossierRepository;
use App\Repositories\OncologieRepository;
use App\Repositories\PiecesJointesRepository;
use App\Repositories\PlanningRepository;
use App\Services\ClasseurPiecesJointes;
use App\Services\Droits;
use App\Services\Journal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dossier patient : en-tête, synthèse oncologique (saisie), antécédents,
 * documents par rubrique, rendez-vous et historique.
 */
class DossierController extends Controller
{
    public function __construct(
        private DossierRepository $dossiers,
        private PiecesJointesRepository $pj,
        private PlanningRepository $planning,
        private ClasseurPiecesJointes $classeur,
        private OncologieRepository $onco,
        private ChimioRepository $chimio,
        private Droits $droits,
    ) {
    }

    public function show(Request $request, string $identifiant): View
    {
        [$patient, $admissions, $seances] = $this->charger($request->user(), $identifiant);
        $numDoss = array_map(fn ($a) => $a->NumDoss, $admissions);
        $devis = $this->memo($identifiant, 'devis', fn () => $this->dossiers->devis($identifiant, $admissions));

        $documents = collect($this->pj->liste($identifiant, $numDoss, $devis));
        $programmes = $this->memo($identifiant, 'programmes', fn () => $this->dossiers->programmes($numDoss));
        $fiche = $this->onco->fiche($identifiant);
        $notes = $this->onco->notes($identifiant);
        $tumeursClinisys = $this->memo($identifiant, 'tumeurs', fn () => $this->onco->tumeursClinisys($identifiant));
        $versions = $this->onco->versionsFiche($identifiant);
        $antecedents = $this->onco->antecedents($identifiant);
        Journal::ecrire('ouverture_dossier', ['identifiant' => $identifiant]);

        $groupes = collect(ClasseurPiecesJointes::GROUPES)
            ->mapWithKeys(fn ($g) => [$g => $documents->where('groupe', $g)->values()]);

        return view('dossier.show', [
            'patient' => $patient,
            'admissions' => $admissions,
            'derniere' => $admissions[0] ?? null,
            'programmes' => collect($programmes)->groupBy('Numero_Dossier'),
            'seances' => collect($seances),
            'groupes' => $groupes,
            'pjIndisponibles' => $this->pj->indisponibles,
            'types' => $this->classeur->referentiel()['types'],
            'ressources' => $this->planning->ressources(),
            'etats' => config('oncologie.etats'),
            'natures' => array_flip(config('oncologie.natures')),
            'fiche' => $fiche,
            'versions' => $versions,
            'pathologies' => $this->onco->pathologies(),
            'tumeursClinisys' => $tumeursClinisys,
            'antecedentsClinisys' => $this->memo($identifiant, 'antecedents', fn () => $this->onco->antecedentsClinisys($identifiant, $numDoss)),
            'antecedents' => $antecedents,
            'notes' => $notes,
            'prescriptions' => $prescriptions = $this->onco->prescriptions($identifiant),
            'historique' => $this->historique($admissions, $seances, $programmes, $documents, $notes, $versions, $antecedents, $prescriptions),
            'consultations' => $this->consultations($admissions, $seances, $notes, $versions, $antecedents, $prescriptions),
            'dossierDuJour' => $this->typeDossier($admissions, $seances, date('Y-m-d')),
            'protocoles' => $this->protocoles($identifiant),
            'protocolesActifs' => $this->chimio->protocolesActifs(),
            'onglet' => $request->query('onglet', 'dossier'),
        ]);
    }

    public function document(Request $request, string $identifiant, string $source, string $docId): Response
    {
        $doc = $this->documentVerifie($request->user(), $identifiant, $source, $docId);
        abort_unless($doc->Doc !== null, 404);

        Journal::ecrire('ouverture_document', ['identifiant' => $identifiant, 'numdoss' => $doc->numdoss, 'document' => $source.':'.$docId]);

        $extension = strtolower(ltrim((string) $doc->Extension, '.'));
        $types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'doc' => 'application/msword'];
        $nom = preg_replace('/[^A-Za-z0-9_\-\. ]/', '_', (string) ($doc->Nom_doc ?: 'document')).($extension ? '.'.$extension : '');
        $inline = in_array($extension, ['pdf', 'jpg', 'jpeg', 'png'], true);

        // pdo_sqlsrv peut renvoyer un varbinary(max) sous forme de flux.
        $contenu = is_resource($doc->Doc) ? stream_get_contents($doc->Doc) : $doc->Doc;

        return response($contenu, 200, [
            'Content-Type' => $types[$extension] ?? 'application/octet-stream',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.$nom.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Correction manuelle du type d'un document (base de l'appli uniquement). */
    public function classer(Request $request, string $identifiant, string $source, string $docId): RedirectResponse
    {
        $data = $request->validate(['code_type' => ['required', Rule::in(array_keys($this->classeur->referentiel()['types']))]]);
        $this->documentVerifie($request->user(), $identifiant, $source, $docId);

        DB::table('onco_pj_categories')->updateOrInsert(
            ['source' => $source, 'doc_id' => $docId],
            ['code_type' => $data['code_type'], 'modifie_par' => $request->user()->userName, 'modifie_le' => now()]
        );
        Journal::ecrire('classement_document', [
            'identifiant' => $identifiant, 'document' => $source.':'.$docId,
            'details' => ['code_type' => $data['code_type']],
        ]);

        return $this->retour($identifiant, 'documents', 'Type du document enregistré.');
    }

    /** Nouvelle version de la synthèse (diagnostic, pathologie, stade…). */
    public function enregistrerFiche(Request $request, string $identifiant): RedirectResponse
    {
        $this->charger($request->user(), $identifiant);
        $data = $request->validate([
            'code_tumeur' => ['nullable', 'integer', Rule::in(array_keys($this->onco->tumeurs()))],
            'diagnostic' => ['nullable', 'string', 'max:500'],
            'date_diagnostic' => ['nullable', 'date', 'before_or_equal:today'],
            'localisation' => ['nullable', 'string', 'max:150'],
            'lateralite' => ['nullable', 'in:Droite,Gauche,Bilatérale,Médiane,Non applicable'],
            'histologie' => ['nullable', 'string', 'max:255'],
            'grade' => ['nullable', 'string', 'max:30'],
            'stade_t' => ['nullable', 'string', 'max:10'],
            'stade_n' => ['nullable', 'string', 'max:10'],
            'stade_m' => ['nullable', 'string', 'max:10'],
            'stade' => ['nullable', 'string', 'max:20'],
            'marqueurs' => ['nullable', 'string', 'max:255'],
            'statut' => ['nullable', Rule::in(array_keys(OncologieRepository::STATUTS))],
            'traitements' => ['nullable', 'array'],
            'traitements.*' => [Rule::in(OncologieRepository::TRAITEMENTS)],
            'ecog' => ['nullable', 'in:0,1,2,3,4'],
            'commentaire' => ['nullable', 'string', 'max:4000'],
        ]);

        DB::transaction(function () use ($identifiant, $data, $request) {
            $version = (int) DB::table('onco_fiches')->where('identifiant', $identifiant)->lockForUpdate()->max('version') + 1;
            DB::table('onco_fiches')->insert([
                ...$data,
                'identifiant' => $identifiant,
                'version' => $version,
                'pathologie' => ($data['code_tumeur'] ?? null) && isset($this->onco->tumeurs()[$data['code_tumeur']])
                    ? OncologieRepository::libellePrincipal($this->onco->tumeurs()[$data['code_tumeur']]) : null,
                'traitements' => implode(', ', $data['traitements'] ?? []),
                'saisi_par' => $request->user()->userName,
                'saisi_le' => now(),
            ]);
        });
        Journal::ecrire('saisie_fiche', ['identifiant' => $identifiant]);

        return $this->retour($identifiant, 'dossier', 'Synthèse oncologique enregistrée (nouvelle version).');
    }

    public function ajouterAntecedent(Request $request, string $identifiant): RedirectResponse
    {
        $this->charger($request->user(), $identifiant);
        $data = $request->validate([
            'famille' => ['required', Rule::in(array_keys(OncologieRepository::FAMILLES))],
            'libelle' => ['required', 'string', 'max:255'],
            'periode' => ['nullable', 'string', 'max:50'],
            'details' => ['nullable', 'string', 'max:2000'],
        ]);
        DB::table('onco_antecedents')->insert([...$data, 'identifiant' => $identifiant, 'saisi_par' => $request->user()->userName, 'saisi_le' => now()]);
        Journal::ecrire('saisie_antecedent', ['identifiant' => $identifiant, 'details' => ['libelle' => $data['libelle']]]);

        return $this->retour($identifiant, 'dossier', 'Antécédent ajouté.');
    }

    public function ajouterNote(Request $request, string $identifiant): RedirectResponse
    {
        $this->charger($request->user(), $identifiant);
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(OncologieRepository::NOTES))],
            'date_note' => ['required', 'date', 'before_or_equal:today'],
            'texte' => ['required', 'string', 'max:8000'],
        ]);
        DB::table('onco_notes')->insert([...$data, 'identifiant' => $identifiant, 'saisi_par' => $request->user()->userName, 'saisi_le' => now()]);
        Journal::ecrire('saisie_note', ['identifiant' => $identifiant, 'details' => ['type' => $data['type']]]);

        return $this->retour($identifiant, 'dossier', 'Note enregistrée.');
    }

    /**
     * Formulaire unique du dossier : selon le type, l'entrée devient une note
     * datée, une nouvelle version du diagnostic ou un antécédent.
     */
    public function saisir(Request $request, string $identifiant): RedirectResponse
    {
        [, $admissions, $seances] = $this->charger($request->user(), $identifiant);
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(OncologieRepository::SAISIES))],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'texte' => ['nullable', 'string', 'max:8000'],
            // Prescriptions du jour saisies avec la consultation.
            'lignes' => ['nullable', 'array', 'max:20'],
            'lignes.*.libelle' => ['nullable', 'string', 'max:255'],
            'lignes.*.dose' => ['nullable', 'string', 'max:60'],
            'lignes.*.voie' => ['nullable', Rule::in(OncologieRepository::VOIES)],
            'lignes.*.frequence' => ['nullable', 'string', 'max:80'],
            'lignes.*.duree' => ['nullable', 'string', 'max:60'],
            'code_tumeur' => ['nullable', 'integer', Rule::in(array_keys($this->onco->tumeurs()))],
            'stade' => ['nullable', 'string', 'max:20'],
            'statut' => ['nullable', Rule::in(array_keys(OncologieRepository::STATUTS))],
            'famille' => ['nullable', Rule::in(array_keys(OncologieRepository::FAMILLES))],
        ]);
        $user = $request->user()->userName;
        $lignes = array_values(array_filter($data['lignes'] ?? [], fn ($l) => trim((string) ($l['libelle'] ?? '')) !== ''));
        if ($data['type'] !== 'diagnostic' && trim((string) ($data['texte'] ?? '')) === '' && ($lignes === [] || $data['type'] === 'antecedent')) {
            throw \Illuminate\Validation\ValidationException::withMessages(['texte' => 'Saisissez un texte.']);
        }

        if ($data['type'] === 'diagnostic') {
            // On repart de la dernière version : le formulaire simplifié ne doit rien effacer.
            DB::transaction(function () use ($identifiant, $data, $user) {
                $precedente = (array) (DB::table('onco_fiches')->where('identifiant', $identifiant)->lockForUpdate()->orderByDesc('version')->first() ?? []);
                unset($precedente['id']);
                $tumeurs = $this->onco->tumeurs();
                $code = $data['code_tumeur'] ?? ($precedente['code_tumeur'] ?? null);
                DB::table('onco_fiches')->insert([
                    ...$precedente,
                    'identifiant' => $identifiant,
                    'version' => ($precedente['version'] ?? 0) + 1,
                    'code_tumeur' => $code,
                    'pathologie' => $code && isset($tumeurs[$code]) ? OncologieRepository::libellePrincipal($tumeurs[$code]) : null,
                    'diagnostic' => ($data['texte'] ?? null) ?: ($precedente['diagnostic'] ?? null),
                    'date_diagnostic' => $precedente['date_diagnostic'] ?? $data['date'],
                    'stade' => ($data['stade'] ?? null) ?: ($precedente['stade'] ?? null),
                    'statut' => ($data['statut'] ?? null) ?: ($precedente['statut'] ?? null),
                    'saisi_par' => $user,
                    'saisi_le' => now(),
                ]);
            });
        } elseif ($data['type'] === 'antecedent') {
            DB::table('onco_antecedents')->insert([
                'identifiant' => $identifiant, 'famille' => $data['famille'] ?? 'medical', 'libelle' => mb_substr($data['texte'], 0, 255),
                'details' => mb_strlen($data['texte']) > 255 ? $data['texte'] : null, 'periode' => null,
                'saisi_par' => $user, 'saisi_le' => now(),
            ]);
        } elseif (trim((string) ($data['texte'] ?? '')) !== '') {
            DB::table('onco_notes')->insert([
                'identifiant' => $identifiant, 'type' => $data['type'], 'date_note' => $data['date'], 'texte' => $data['texte'],
                'saisi_par' => $user, 'saisi_le' => now(),
            ]);
        }
        if ($data['type'] !== 'antecedent') {
            foreach ($lignes as $l) {
                DB::table('onco_prescriptions')->insert([
                    'identifiant' => $identifiant, 'num_doss' => $this->typeDossier($admissions, $seances, $data['date'])['numdoss'],
                    'date_prescription' => $data['date'], 'type' => 'medicament', 'libelle' => trim($l['libelle']),
                    'dose' => $l['dose'] ?? null, 'voie' => $l['voie'] ?? null, 'frequence' => $l['frequence'] ?? null, 'duree' => $l['duree'] ?? null,
                    'statut' => 'prescrite', 'prescrit_par' => $user, 'saisi_le' => now(),
                ]);
            }
        }
        Journal::ecrire('saisie_'.$data['type'], ['identifiant' => $identifiant, 'details' => ['prescriptions' => count($lignes)]]);

        return $this->retour($identifiant, 'dossier', OncologieRepository::SAISIES[$data['type']].' enregistré(e).');
    }

    public function ajouterPrescription(Request $request, string $identifiant): RedirectResponse
    {
        [, $admissions] = $this->charger($request->user(), $identifiant);
        $data = $request->validate([
            'date_prescription' => ['required', 'date'],
            'type' => ['required', Rule::in(array_keys(OncologieRepository::TYPES_PRESCRIPTION))],
            'libelle' => ['required', 'string', 'max:255'],
            'dose' => ['nullable', 'string', 'max:60'],
            'voie' => ['nullable', Rule::in(OncologieRepository::VOIES)],
            'frequence' => ['nullable', 'string', 'max:80'],
            'duree' => ['nullable', 'string', 'max:60'],
            'nb_cures' => ['nullable', 'integer', 'min:1', 'max:99'],
            'commentaire' => ['nullable', 'string', 'max:2000'],
        ]);
        DB::table('onco_prescriptions')->insert([
            ...$data,
            'identifiant' => $identifiant,
            'num_doss' => $admissions[0]->NumDoss ?? null,
            'statut' => 'prescrite',
            'prescrit_par' => $request->user()->userName,
            'saisi_le' => now(),
        ]);
        Journal::ecrire('saisie_prescription', ['identifiant' => $identifiant, 'details' => ['libelle' => $data['libelle']]]);

        return $this->retour($identifiant, 'prescriptions', 'Prescription enregistrée.');
    }

    public function statutPrescription(Request $request, string $identifiant, int $id): RedirectResponse
    {
        $this->charger($request->user(), $identifiant);
        $data = $request->validate(['statut' => ['required', Rule::in(array_keys(OncologieRepository::STATUTS_PRESCRIPTION))]]);
        $n = DB::table('onco_prescriptions')->where('id', $id)->where('identifiant', $identifiant)
            ->update(['statut' => $data['statut'], 'statut_par' => $request->user()->userName, 'statut_le' => now()]);
        abort_unless($n === 1, 404);
        Journal::ecrire('statut_prescription', ['identifiant' => $identifiant, 'details' => ['id' => $id, 'statut' => $data['statut']]]);

        return $this->retour($identifiant, 'prescriptions', 'Statut de la prescription mis à jour.');
    }

    /** Modifie une prescription (son auteur seulement) ; l'avant et l'après vont au journal. */
    public function modifierPrescription(Request $request, string $identifiant, int $id): RedirectResponse
    {
        $this->charger($request->user(), $identifiant);
        $p = $this->prescriptionDeLAuteur($request, $identifiant, $id);
        if ($p instanceof RedirectResponse) {
            return $p;
        }
        $data = $request->validate([
            'date_prescription' => ['required', 'date'],
            'libelle' => ['required', 'string', 'max:255'],
            'dose' => ['nullable', 'string', 'max:60'],
            'voie' => ['nullable', Rule::in(OncologieRepository::VOIES)],
            'frequence' => ['nullable', 'string', 'max:80'],
            'duree' => ['nullable', 'string', 'max:60'],
        ]);
        DB::table('onco_prescriptions')->where('id', $id)->update([...$data, 'modifie_par' => $request->user()->userName, 'modifie_le' => now()]);
        Journal::ecrire('modification_prescription', ['identifiant' => $identifiant, 'details' => [
            'id' => $id, 'type' => 'Prescription', 'date' => $p->date_prescription, 'auteur' => $p->prescrit_par,
            'avant' => self::textePrescription((array) $p), 'apres' => self::textePrescription($data),
        ]]);

        return $this->retour($identifiant, 'prescriptions', 'Prescription modifiée (ancienne version tracée dans le journal).');
    }

    /** Supprime une prescription (son auteur seulement) : elle est masquée et son contenu va au journal. */
    public function supprimerPrescription(Request $request, string $identifiant, int $id): RedirectResponse
    {
        $this->charger($request->user(), $identifiant);
        $p = $this->prescriptionDeLAuteur($request, $identifiant, $id);
        if ($p instanceof RedirectResponse) {
            return $p;
        }
        DB::table('onco_prescriptions')->where('id', $id)->update(['masque_par' => $request->user()->userName, 'masque_le' => now()]);
        Journal::ecrire('suppression_prescription', ['identifiant' => $identifiant, 'details' => [
            'id' => $id, 'type' => 'Prescription', 'date' => $p->date_prescription, 'texte' => self::textePrescription((array) $p),
        ]]);

        return $this->retour($identifiant, 'prescriptions', 'Prescription supprimée (conservée dans le journal).');
    }

    /** La prescription si l'utilisateur en est l'auteur, sinon un retour avec le message de refus. */
    private function prescriptionDeLAuteur(Request $request, string $identifiant, int $id): object
    {
        $p = DB::table('onco_prescriptions')->where('id', $id)->where('identifiant', $identifiant)->whereNull('masque_le')->first();
        abort_unless($p, 404);
        if (strcasecmp(trim((string) $p->prescrit_par), trim((string) $request->user()->userName)) !== 0) {
            Journal::ecrire('modification_prescription_refusee', ['identifiant' => $identifiant, 'details' => ['id' => $id, 'auteur' => $p->prescrit_par]]);

            return $this->retour($identifiant, 'prescriptions', 'Vous n\'êtes pas autorisé à modifier cette prescription.');
        }

        return $p;
    }

    private static function textePrescription(array $p): string
    {
        return collect([$p['libelle'] ?? null, $p['dose'] ?? null, $p['voie'] ?? null, $p['frequence'] ?? null,
            ($p['duree'] ?? null) ? 'pendant '.$p['duree'] : null])->filter()->implode(' · ')
            .' (le '.\Carbon\Carbon::parse($p['date_prescription'])->format('d/m/Y').')';
    }

    /** Ordonnance imprimable : prescriptions non arrêtées d'une date. */
    public function ordonnance(Request $request, string $identifiant): View
    {
        [$patient, $admissions] = $this->charger($request->user(), $identifiant);
        $date = $request->validate(['date' => ['required', 'date']])['date'];
        $lignes = DB::table('onco_prescriptions')->where('identifiant', $identifiant)
            ->whereDate('date_prescription', $date)->where('statut', '!=', 'arretee')->whereNull('masque_le')->orderBy('id')->get();
        abort_if($lignes->isEmpty(), 404, 'Aucune prescription active à cette date.');
        Journal::ecrire('impression_ordonnance', ['identifiant' => $identifiant, 'details' => ['date' => $date]]);

        return view('dossier.ordonnance', [
            'patient' => $patient, 'derniere' => $admissions[0] ?? null, 'lignes' => $lignes, 'date' => $date,
            'fiche' => $this->onco->fiche($identifiant), 'medecin' => $request->user()->nomAffiche(),
        ]);
    }

    /** Modifie le texte d'une note ; l'ancien et le nouveau texte sont tracés dans le journal. */
    public function modifierNote(Request $request, string $identifiant, int $id): RedirectResponse
    {
        $this->charger($request->user(), $identifiant);
        $data = $request->validate(['texte' => ['required', 'string', 'max:8000']]);
        $note = DB::table('onco_notes')->where('id', $id)->where('identifiant', $identifiant)->whereNull('masque_le')->first();
        abort_unless($note, 404);
        // Seul l'auteur de la note peut la modifier.
        if (strcasecmp(trim((string) $note->saisi_par), trim((string) $request->user()->userName)) !== 0) {
            Journal::ecrire('modification_note_refusee', ['identifiant' => $identifiant, 'details' => ['id' => $id, 'auteur' => $note->saisi_par]]);

            return $this->retour($identifiant, 'dossier', 'Vous n\'êtes pas autorisé à modifier cette note.');
        }
        if (trim($note->texte) === trim($data['texte'])) {
            return $this->retour($identifiant, 'dossier', 'Aucun changement.');
        }

        DB::table('onco_notes')->where('id', $id)->update([
            'texte' => $data['texte'], 'modifie_par' => $request->user()->userName, 'modifie_le' => now(),
        ]);
        Journal::ecrire('modification_note', ['identifiant' => $identifiant, 'details' => [
            'id' => $id, 'type' => OncologieRepository::NOTES[$note->type] ?? $note->type, 'date' => $note->date_note,
            'auteur' => $note->saisi_par, 'avant' => $note->texte, 'apres' => $data['texte'],
        ]]);

        return $this->retour($identifiant, 'dossier', 'Note modifiée (ancienne version tracée dans le journal).');
    }

    /** Masque (sans supprimer) un antécédent ou une note saisi dans l'appli. */
    public function masquer(Request $request, string $identifiant, string $table, int $id): RedirectResponse
    {
        $this->charger($request->user(), $identifiant);
        $tables = ['antecedent' => 'onco_antecedents', 'note' => 'onco_notes'];
        abort_unless(isset($tables[$table]), 404);

        $ligne = DB::table($tables[$table])->where('id', $id)->where('identifiant', $identifiant)->whereNull('masque_le')->first();
        abort_unless($ligne, 404);
        DB::table($tables[$table])->where('id', $id)->update(['masque_par' => $request->user()->userName, 'masque_le' => now()]);
        // Le contenu retiré est recopié dans le journal pour rester lisible.
        Journal::ecrire('masquage_'.$table, ['identifiant' => $identifiant, 'details' => $table === 'note'
            ? ['id' => $id, 'type' => OncologieRepository::NOTES[$ligne->type] ?? $ligne->type, 'date' => $ligne->date_note, 'texte' => $ligne->texte]
            : ['id' => $id, 'antecedent' => $ligne->libelle, 'details' => $ligne->details]]);

        return $this->retour($identifiant, 'dossier', 'Élément retiré (conservé dans le journal).');
    }

    /** Chronologie : saisies des médecins, admissions, séances, programmes, prescriptions et documents. */
    private function historique(array $admissions, array $seances, array $programmes, $documents, $notes, $versions, $antecedents, $prescriptions): array
    {
        $natures = array_flip(config('oncologie.natures'));
        $libNature = ['consultation' => 'Consultation', 'chimio' => 'Chimiothérapie', 'radio' => 'Radiothérapie'];
        $etats = config('oncologie.etats');
        $evenements = [];

        foreach ($admissions as $a) {
            $evenements[] = ['date' => $a->DatArr, 'type' => 'Admission', 'titre' => trim(($a->motif ?: 'Admission').' · '.$a->reception), 'detail' => 'Dossier '.$a->NumDoss.' · '.$a->medecin_traitant];
        }
        foreach ($seances as $s) {
            $type = $libNature[$natures[$s->Nature] ?? ''] ?? 'RDV';
            $evenements[] = ['date' => $s->Date_RDV, 'heure' => $s->Heure_RDV, 'type' => $type, 'titre' => $type.($s->Jour ? ' · '.$s->Jour : ''),
                'detail' => trim(($etats[(int) $s->etatRDV]['libelle'] ?? '').' · '.$s->NomMed, ' ·')];
        }
        foreach ($programmes as $p) {
            if ($p->Date_debut_traitement) {
                $type = $libNature[$natures[$p->Nature] ?? ''] ?? 'Programme';
                $evenements[] = ['date' => $p->Date_debut_traitement, 'type' => 'Programme', 'titre' => 'Début du programme '.mb_strtolower($type),
                    'detail' => $p->seances_faites.'/'.$p->nbreSeance.' séances'.($p->nbreCure ? ' · '.$p->nbreCure.' cures' : '')];
            }
        }
        foreach ($documents as $d) {
            $evenements[] = ['date' => $d->Date_doc, 'type' => 'Document', 'titre' => $d->type, 'detail' => $d->groupe, 'doc' => $d];
        }
        foreach ($notes as $n) {
            $evenements[] = ['date' => $n->date_note, 'type' => 'Saisie', 'titre' => OncologieRepository::NOTES[$n->type] ?? 'Note',
                'detail' => $n->saisi_par, 'texte' => $n->texte, 'masquer' => ['note', $n->id]];
        }
        foreach ($versions as $v) {
            $evenements[] = ['date' => $v->saisi_le, 'type' => 'Saisie', 'titre' => 'Diagnostic (v'.$v->version.')',
                'detail' => $v->saisi_par, 'texte' => collect([$v->pathologie, $v->stade ? 'Stade '.$v->stade : null,
                    OncologieRepository::STATUTS[$v->statut] ?? null, $v->diagnostic])->filter()->implode(' · ')];
        }
        foreach ($antecedents as $a) {
            $evenements[] = ['date' => $a->saisi_le, 'type' => 'Saisie', 'titre' => 'Antécédent '.mb_strtolower(OncologieRepository::FAMILLES[$a->famille] ?? ''),
                'detail' => $a->saisi_par, 'texte' => trim($a->libelle.' '.($a->details ?? '')), 'masquer' => ['antecedent', $a->id]];
        }
        foreach ($prescriptions as $p) {
            $evenements[] = ['date' => $p->date_prescription, 'type' => 'Prescription', 'titre' => $p->libelle,
                'detail' => collect([$p->dose, $p->voie, $p->frequence, OncologieRepository::STATUTS_PRESCRIPTION[$p->statut] ?? null, $p->prescrit_par])->filter()->implode(' · ')];
        }

        usort($evenements, fn ($x, $y) => strcmp((string) $y['date'], (string) $x['date']));

        return $evenements;
    }

    /** Médicaments proposés pendant la saisie d'une prescription. */
    public function rechercherMedicaments(Request $request)
    {
        $q = (string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:60']])['q'];

        return response()->json($this->chimio->rechercherMedicaments($q));
    }

    /** Lignes d'un protocole paramétré, pour préremplir le formulaire de prescription. */
    public function lignesProtocole(Request $request, int $codProt)
    {
        abort_unless(isset($this->chimio->protocolesActifs()[$codProt]), 404);

        return response()->json($this->chimio->lignesAPrescrire($codProt));
    }

    /** Prescription d'un protocole : enregistrée uniquement dans oncologie_app, jamais dans Clinisys. */
    public function prescrireProtocole(Request $request, string $identifiant): RedirectResponse
    {
        [, $admissions, $seances] = $this->charger($request->user(), $identifiant);
        $protocoles = $this->chimio->protocolesActifs();
        $data = $request->validate([
            'cod_prot' => ['required', 'integer', Rule::in(array_keys($protocoles))],
            'poids' => ['required', 'numeric', 'min:2', 'max:300'],
            'taille' => ['required', 'numeric', 'min:40', 'max:250'],
            'nb_cures' => ['nullable', 'integer', 'min:1', 'max:99'],
            'intervalle' => ['nullable', 'integer', 'min:1', 'max:365'],
            'date_debut' => ['nullable', 'date'],
            'observation' => ['nullable', 'string', 'max:2000'],
            'lignes' => ['required', 'array', 'min:1', 'max:60'],
            'lignes.*.medicament' => ['required', 'string', 'max:150'],
            'lignes.*.jour' => ['nullable', 'string', 'max:20'],
            'lignes.*.dose_m2' => ['nullable', 'numeric'],
            'lignes.*.dose_calculee' => ['nullable', 'string', 'max:80'],
            'lignes.*.dose' => ['nullable', 'string', 'max:80'],
            'lignes.*.dilution' => ['nullable', 'string', 'max:150'],
            'lignes.*.duree' => ['nullable', 'string', 'max:40'],
            'lignes.*.horaire' => ['nullable', 'string', 'max:40'],
        ]);
        $sc = ChimioRepository::surfaceCorporelle((float) $data['poids'], (float) $data['taille']);
        $user = $request->user();

        $id = DB::transaction(function () use ($data, $identifiant, $admissions, $seances, $protocoles, $sc, $user) {
            $id = DB::table('onco_cures')->insertGetId([
                'identifiant' => $identifiant,
                'num_doss' => $this->typeDossier($admissions, $seances, date('Y-m-d'))['numdoss'],
                'cod_prot' => $data['cod_prot'], 'nom_protocole' => $protocoles[$data['cod_prot']],
                'poids' => $data['poids'], 'taille' => $data['taille'],
                'bmi' => round($data['poids'] / (($data['taille'] / 100) ** 2), 2), 'surface_corporelle' => $sc,
                'nb_cures' => $data['nb_cures'] ?? null, 'intervalle' => $data['intervalle'] ?? null,
                'date_debut' => $data['date_debut'] ?? null, 'observation' => $data['observation'] ?? null,
                'statut' => 'prescrite', 'prescrit_par' => $user->userName, 'prescrit_nom' => $user->nomAffiche(), 'saisi_le' => now(),
            ]);
            foreach (array_values($data['lignes']) as $i => $l) {
                $dose = trim((string) ($l['dose'] ?? ''));
                DB::table('onco_cure_lignes')->insert([
                    'cure_id' => $id, 'ordre' => $i, 'jour' => $l['jour'] ?? null, 'medicament' => trim($l['medicament']),
                    'dose_m2' => $l['dose_m2'] ?? null, 'dose' => $dose ?: null,
                    'ajustee' => isset($l['dose_calculee']) && $l['dose_calculee'] !== '' && $dose !== trim($l['dose_calculee']),
                    'dilution' => $l['dilution'] ?? null, 'duree' => $l['duree'] ?? null, 'horaire' => $l['horaire'] ?? null,
                ]);
            }

            return $id;
        });
        Journal::ecrire('prescription_protocole', ['identifiant' => $identifiant, 'details' => [
            'id' => $id, 'protocole' => $protocoles[$data['cod_prot']], 'sc' => $sc, 'lignes' => count($data['lignes']),
        ]]);

        // Retour sur l'onglet d'où vient la prescription (dossier ou prescriptions).
        $onglet = $request->input('onglet') === 'prescriptions' ? 'prescriptions' : 'dossier';

        return $this->retour($identifiant, $onglet, 'Protocole '.$protocoles[$data['cod_prot']].' prescrit (SC '.number_format($sc, 3, ',', '').' m²).');
    }

    /** Arrête un protocole prescrit dans l'appli (la ligne reste visible et tracée). */
    public function arreterProtocole(Request $request, string $identifiant, int $id): RedirectResponse
    {
        $this->charger($request->user(), $identifiant);
        $n = DB::table('onco_cures')->where('id', $id)->where('identifiant', $identifiant)->where('statut', '!=', 'arretee')
            ->update(['statut' => 'arretee', 'statut_par' => $request->user()->userName, 'statut_le' => now()]);
        abort_unless($n === 1, 404);
        Journal::ecrire('arret_protocole', ['identifiant' => $identifiant, 'details' => ['id' => $id]]);

        return $this->retour($identifiant, 'dossier', 'Protocole arrêté.');
    }

    /** Impression d'un protocole prescrit dans l'appli (même présentation que Clinisys). */
    public function imprimerProtocoleAppli(Request $request, string $identifiant, int $id): View
    {
        [$patient, $admissions] = $this->charger($request->user(), $identifiant);
        $protocole = collect($this->chimio->curesAppli($identifiant))->firstWhere('id', $id);
        abort_unless($protocole, 404);
        Journal::ecrire('impression_protocole', ['identifiant' => $identifiant, 'details' => ['appli' => $id, 'protocole' => $protocole['nom']]]);

        return view('dossier.protocole', ['patient' => $patient, 'p' => $protocole, 'derniere' => $admissions[0] ?? null]);
    }

    /**
     * Historique des protocoles : ceux prescrits dans l'appli et ceux de Clinisys
     * (lecture seule), du plus récent au plus ancien.
     */
    private function protocoles(string $identifiant): array
    {
        $tous = array_merge($this->chimio->curesAppli($identifiant), $this->protocolesClinisys($identifiant));
        usort($tous, fn ($a, $b) => strcmp((string) $b['cure']->Date_cre, (string) $a['cure']->Date_cre));

        return $tous;
    }

    /** Protocoles de chimiothérapie prescrits dans Clinisys, avec leurs lignes. */
    private function protocolesClinisys(string $identifiant): array
    {
        [$cures, $doses, $seances] = $this->memo($identifiant, 'cures', function () use ($identifiant) {
            $cures = $this->chimio->cures($identifiant);
            $numCures = array_map(fn ($c) => (int) $c->numCure, $cures);

            return [$cures, $this->chimio->dosesPatient($numCures), $this->chimio->seances($numCures)];
        });
        $lignes = $this->chimio->lignesProtocoles(array_map(fn ($c) => $c->codProtCorespondent, $cures));

        return array_map(fn ($c) => [
            'source' => 'clinisys',
            'cure' => $c,
            'nom' => trim((string) ($c->DesProt ?: $c->Titre)) ?: 'Protocole',
            'lignes' => ChimioRepository::lignesCure($c, collect($lignes[$c->codProtCorespondent] ?? [])->all(), collect($doses[$c->numCure] ?? [])->all()),
            'seances' => collect($seances[$c->numCure] ?? [])->all(),
        ], $cures);
    }

    /** Prescription de chimiothérapie imprimable (même présentation que Clinisys). */
    public function imprimerCure(Request $request, string $identifiant, int $numCure): View
    {
        [$patient, $admissions] = $this->charger($request->user(), $identifiant);
        $protocole = collect($this->protocolesClinisys($identifiant))->first(fn ($p) => (int) $p['cure']->numCure === $numCure);
        abort_unless($protocole, 404);
        Journal::ecrire('impression_protocole', ['identifiant' => $identifiant, 'details' => ['cure' => $numCure, 'protocole' => $protocole['nom']]]);

        return view('dossier.protocole', ['patient' => $patient, 'p' => $protocole, 'derniere' => $admissions[0] ?? null]);
    }

    /**
     * Historique des consultations écrites par les médecins, regroupé par jour :
     * notes, diagnostics, antécédents et prescriptions, avec le type de dossier du jour.
     */
    private function consultations(array $admissions, array $seances, $notes, $versions, $antecedents, $prescriptions): array
    {
        $jours = [];
        $ajouter = function (string $date, string $cle, $element) use (&$jours, $admissions, $seances) {
            $jour = substr($date, 0, 10);
            $jours[$jour] ??= ['date' => $jour, ...$this->typeDossier($admissions, $seances, $jour),
                'notes' => [], 'diagnostics' => [], 'antecedents' => [], 'prescriptions' => []];
            $jours[$jour][$cle][] = $element;
        };
        foreach ($notes as $n) { $ajouter((string) $n->date_note, 'notes', $n); }
        foreach ($versions as $v) { $ajouter((string) $v->saisi_le, 'diagnostics', $v); }
        foreach ($antecedents as $a) { $ajouter((string) $a->saisi_le, 'antecedents', $a); }
        foreach ($prescriptions as $p) { $ajouter((string) $p->date_prescription, 'prescriptions', $p); }
        krsort($jours);

        return array_values($jours);
    }

    /**
     * Type et n° du dossier d'une date : la séance du jour (chimio, radio, consultation)
     * si elle existe, sinon la dernière admission à cette date ou avant.
     *
     * @return array{type: string, numdoss: ?string}
     */
    private function typeDossier(array $admissions, array $seances, string $date): array
    {
        $jour = substr($date, 0, 10);
        $natures = array_flip(config('oncologie.natures'));
        $libelles = ['consultation' => 'Consultation', 'chimio' => 'Chimiothérapie', 'radio' => 'Radiothérapie'];

        foreach ($seances as $s) {
            if (substr((string) $s->Date_RDV, 0, 10) === $jour && isset($natures[$s->Nature])) {
                return ['type' => $libelles[$natures[$s->Nature]], 'numdoss' => $s->NumDoss ?: null];
            }
        }
        foreach ($admissions as $a) { // triées de la plus récente à la plus ancienne
            if (substr((string) $a->DatArr, 0, 10) <= $jour) {
                $motif = mb_strtoupper((string) $a->motif);
                $type = str_contains($motif, 'CHIMIO') ? 'Chimiothérapie'
                    : (str_contains($motif, 'RADIO') ? 'Radiothérapie' : 'Consultation');

                return ['type' => $type, 'numdoss' => $a->NumDoss];
            }
        }

        return ['type' => 'Consultation', 'numdoss' => null];
    }

    private function retour(string $identifiant, string $onglet, string $message): RedirectResponse
    {
        return redirect()->route('dossier', ['identifiant' => $identifiant, 'onglet' => $onglet])->with('statut', $message);
    }

    private function documentVerifie(ClinisysUser $user, string $identifiant, string $source, string $docId): object
    {
        [, $admissions] = $this->charger($user, $identifiant);
        $doc = $this->pj->document($source, $docId, $identifiant, array_map(fn ($a) => $a->NumDoss, $admissions),
            $this->dossiers->devis($identifiant, $admissions));
        abort_unless($doc, 404);

        return $doc;
    }

    /** Charge le patient et refuse l'accès s'il n'est suivi par aucun médecin autorisé. */
    private function charger(ClinisysUser $user, string $identifiant): array
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{1,20}$/', $identifiant), 404);
        $patient = $this->memo($identifiant, 'patient', fn () => $this->dossiers->patient($identifiant));
        abort_unless($patient, 404);

        $admissions = $this->memo($identifiant, 'admissions', fn () => $this->dossiers->admissions($identifiant));
        $seances = $this->memo($identifiant, 'seances', fn () => $this->dossiers->seances(array_map(fn ($a) => $a->NumDoss, $admissions)));

        $autorises = $this->droits->medecinsAutorises($user);
        if ($autorises !== null) {
            $medecins = array_merge(
                array_map(fn ($a) => $a->MedTrait, $admissions),
                array_map(fn ($s) => $s->CodeMed, $seances),
            );
            if (! array_intersect($autorises, array_filter($medecins))) {
                Journal::ecrire('acces_refuse', ['identifiant' => $identifiant]);
                abort(403, 'Ce patient n\'est pas suivi par un médecin auquel vous avez accès.');
            }
        }

        return [$patient, $admissions, $seances];
    }

    /**
     * Lecture Clinisys gardée 2 minutes par patient : enregistrer une note, ouvrir un
     * document ou changer d'onglet ne relit pas Clinisys (serveur lié, lent) à chaque fois.
     * Les saisies de l'appli (notes, prescriptions…) sont toujours relues en direct.
     */
    private function memo(string $identifiant, string $cle, \Closure $lire): mixed
    {
        return \Illuminate\Support\Facades\Cache::remember("dossier:$identifiant:$cle", 120, $lire);
    }
}
