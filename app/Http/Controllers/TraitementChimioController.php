<?php

namespace App\Http\Controllers;

use App\Repositories\ChimioRepository;
use App\Repositories\TraitementsChimio as T;
use App\Services\Droits;
use App\Services\Journal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Traitement de chimiothérapie, comme dans le logiciel du cabinet de l'oncologue :
 * traitement > cures > jours > produits, puis fiche A4 paysage. Rien n'est écrit dans Clinisys.
 */
class TraitementChimioController extends Controller
{
    public function __construct(private ChimioRepository $chimio, private T $traitements, private Droits $droits, private DossierController $dossier)
    {
    }

    public function index(Request $request, string $identifiant): View
    {
        [$patient, $admissions] = $this->dossier->charger($request->user(), $identifiant);
        $traitements = $this->traitements->duPatient($identifiant);
        $fiche = DB::table('onco_fiches')->where('identifiant', $identifiant)->orderByDesc('version')->first();

        return view('chimio.index', [
            'id' => $identifiant, 'patient' => $patient, 'derniere' => $admissions[0] ?? null, 'traitements' => $traitements,
            'choisi' => collect($traitements)->firstWhere('id', (int) $request->query('t')) ?? ($traitements[0] ?? null),
            'protocoles' => $this->chimio->protocolesActifs(), 'fiche' => $fiche,
            'dernieresMesures' => $this->dernieresMesures($traitements),
            'peutSaisir' => $this->droits->peutSaisirChimio($request->user()),
        ]);
    }

    public function creer(Request $request, string $identifiant): RedirectResponse
    {
        abort_unless($this->droits->peutSaisirChimio($request->user()), 403);
        [, $admissions] = $this->dossier->charger($request->user(), $identifiant);
        $protocoles = $this->chimio->protocolesActifs();
        $d = $request->validate([
            'cod_prot' => ['required', 'integer', Rule::in(array_keys($protocoles))],
            'poids' => ['required', 'numeric', 'min:2', 'max:300'],
            'taille' => ['required', 'numeric', 'min:40', 'max:250'],
            'nb_cures' => ['required', 'integer', 'min:1', 'max:60'],
            'intercure' => ['required', 'integer', 'min:1', 'max:365'],
            'jours' => ['required', 'string', 'max:60', 'regex:/^\s*J?\d{1,3}(\s*[,; ]\s*J?\d{1,3})*\s*$/i'],
            'date_debut' => ['required', 'date'],
            'clairance' => ['nullable', 'numeric', 'min:5', 'max:250'],
            'rechute' => ['nullable', 'boolean'],
            'localisation' => ['nullable', 'string', 'max:150'], 'histologie' => ['nullable', 'string', 'max:150'],
            'stade' => ['nullable', 'string', 'max:40'], 'tnm' => ['nullable', 'string', 'max:40'],
            'allergies' => ['nullable', 'string', 'max:255'], 'observation' => ['nullable', 'string', 'max:2000'],
        ], ['jours.regex' => 'Jours du cycle : écrivez par exemple 1, 8, 15.']);
        preg_match_all('/\d+/', $d['jours'], $m);
        $jours = collect($m[0])->map(fn ($j) => (int) $j)->filter(fn ($j) => $j >= 1)->unique()->sort()->values()->all();
        if ($jours === []) {
            return back()->withErrors(['jours' => 'Indiquez au moins un jour.'])->withInput();
        }
        // Sinon le dernier jour d'une cure tomberait avec ou juste avant le J1 de la suivante (double dose).
        if (max($jours) >= (int) $d['intercure']) {
            return back()->withErrors(['jours' => 'Le dernier jour (J'.max($jours).') doit tomber avant la cure suivante (intercure de '.$d['intercure'].' jours). « J1J21 » dans un nom veut dire une cure tous les 21 jours : jours « 1 », intercure 21.'])->withInput();
        }
        // Lignes saisies à l'écran (doses arrondies, produits retirés ou ajoutés) ; sinon celles du protocole Clinisys.
        $saisies = $request->validate([
            'lignes' => ['nullable', 'array', 'max:60'],
            'lignes.*.medicament' => ['required', 'string', 'max:150'],
            'lignes.*.jour' => ['nullable', 'string', 'max:20'],
            'lignes.*.chimio' => ['nullable', 'boolean'],
            'lignes.*.type_dose' => ['required', Rule::in(T::TYPES_DOSE)],
            'lignes.*.dose_ref' => ['nullable', 'string', 'max:40'],
            'lignes.*.dose' => ['nullable', 'string', 'max:80'],
            'lignes.*.voie' => ['nullable', 'string', 'max:30'], 'lignes.*.solvant' => ['nullable', 'string', 'max:40'],
            'lignes.*.volume' => ['nullable', 'string', 'max:20'], 'lignes.*.duree' => ['nullable', 'string', 'max:40'],
        ])['lignes'] ?? null;
        // Clinisys contient des lignes vides (sans DCI ni dose) : elles ne deviennent pas des produits.
        $lignes = $saisies !== null ? array_values($saisies)
            : array_values(array_filter($this->chimio->lignesAPrescrire((int) $d['cod_prot']), fn ($l) => $l['medicament'] !== ''));
        if ($lignes === []) {
            return back()->withErrors(['cod_prot' => 'Ce protocole n\'a aucune ligne dans Clinisys.'])->withInput();
        }
        $user = $request->user();
        $id = $this->traitements->creer($d, $identifiant, $admissions[0]->NumDoss ?? null, $protocoles[$d['cod_prot']], $jours, $lignes, $user->userName, $user->nomAffiche());
        Journal::ecrire('traitement_chimio', ['identifiant' => $identifiant, 'details' => ['id' => $id, 'protocole' => $protocoles[$d['cod_prot']], 'cures' => $d['nb_cures']]]);
        $premiere = DB::table('onco_traitement_cures')->where('traitement_id', $id)->orderBy('numero')->value('id');

        return redirect()->route('chimio.cure', [$identifiant, $premiere])->with('statut', 'Traitement créé. Vérifiez les doses de chaque jour.');
    }

    public function arreter(Request $request, string $identifiant, int $traitement): RedirectResponse
    {
        abort_unless($this->droits->peutSaisirChimio($request->user()), 403);
        $this->dossier->charger($request->user(), $identifiant);
        $n = DB::table('onco_traitements')->where('id', $traitement)->where('identifiant', $identifiant)->where('statut', 'actif')
            ->update(['statut' => 'arrete', 'arrete_par' => $request->user()->userName, 'arrete_le' => now()]);
        abort_unless($n === 1, 404);
        Journal::ecrire('arret_traitement_chimio', ['identifiant' => $identifiant, 'details' => ['id' => $traitement]]);

        return back()->with('statut', 'Traitement arrêté. Il reste visible dans l\'historique.');
    }

    /** Suppression par son auteur ou un administrateur, si aucun produit n'a été administré : masqué et tracé, jamais effacé. */
    public function supprimer(Request $request, string $identifiant, int $traitement): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->droits->peutSaisirChimio($user), 403);
        $this->dossier->charger($user, $identifiant);
        $t = DB::table('onco_traitements')->where('id', $traitement)->where('identifiant', $identifiant)->whereNull('masque_le')->first();
        abort_unless($t, 404);
        abort_unless($t->prescrit_par === $user->userName || $user->admin, 403, 'Seuls l\'auteur du traitement et un administrateur peuvent le supprimer.');
        $administre = DB::table('onco_traitement_produits as p')->join('onco_traitement_jours as j', 'j.id', '=', 'p.jour_id')
            ->join('onco_traitement_cures as c', 'c.id', '=', 'j.cure_id')->where('c.traitement_id', $traitement)->where('p.administre', true)->exists();
        if ($administre) {
            return back()->withErrors(['ok' => 'Suppression impossible : un produit de ce traitement a déjà été administré. Utilisez « Arrêter ».']);
        }
        DB::table('onco_traitements')->where('id', $traitement)->update(['masque_par' => $user->userName, 'masque_le' => now()]);
        Journal::ecrire('suppression_traitement_chimio', ['identifiant' => $identifiant, 'details' => ['id' => $traitement, 'protocole' => $t->nom_protocole]]);

        return redirect()->route('chimio.index', $identifiant)->with('statut', 'Traitement supprimé.');
    }

    public function cure(Request $request, string $identifiant, int $cure): View
    {
        [$patient, $admissions] = $this->dossier->charger($request->user(), $identifiant);
        [$c, $t, $jours, $produits] = $this->lireCure($identifiant, $cure);
        $choisi = $jours->firstWhere('id', (int) $request->query('jour')) ?? $jours->first();
        $user = $request->user();

        return view('chimio.cure', [
            'id' => $identifiant, 'patient' => $patient, 'derniere' => $admissions[0] ?? null, 'cure' => $c, 'traitement' => $t, 'jours' => $jours, 'jour' => $choisi,
            'produits' => collect($produits[$choisi->id] ?? []), 'statut' => T::statutCure($jours),
            'autresCures' => DB::table('onco_traitement_cures')->where('traitement_id', $t->id)->orderBy('numero')->get(['id', 'numero']),
            'manquantes' => $this->dosesManquantes($jours, $produits, $c),
            'peutSaisir' => $this->droits->peutSaisirChimio($user) && $t->statut === 'actif',
            'peutValider' => $this->droits->peutValiderChimio($user) && $t->statut === 'actif',
            'peutAdministrer' => $this->droits->peutAdministrerChimio($user) && $t->statut === 'actif',
        ]);
    }

    /** Nouveau poids ou taille pour cette cure : les doses calculées suivent. */
    public function mesures(Request $request, string $identifiant, int $cure): RedirectResponse
    {
        abort_unless($this->droits->peutSaisirChimio($request->user()), 403);
        $this->dossier->charger($request->user(), $identifiant);
        [$c, $t] = $this->lireCure($identifiant, $cure);
        abort_unless($t->statut === 'actif', 403);
        $d = $request->validate(['poids' => ['required', 'numeric', 'min:2', 'max:300'], 'taille' => ['required', 'numeric', 'min:40', 'max:250'],
            'clairance' => ['nullable', 'numeric', 'min:5', 'max:250']]);
        $clairance = isset($d['clairance']) ? (float) $d['clairance'] : null;
        $this->traitements->recalculer($c, (float) $d['poids'], (float) $d['taille'], $clairance);
        Journal::ecrire('mesures_cure_chimio', ['identifiant' => $identifiant, 'details' => ['cure' => $cure,
            'avant' => [$c->poids, $c->taille, $c->clairance ?? null], 'apres' => [$d['poids'], $d['taille'], $clairance]]]);

        return back()->with('statut', 'Mesures enregistrées, doses recalculées (les jours validés ne changent pas).');
    }

    public function enregistrerJour(Request $request, string $identifiant, int $jour): RedirectResponse
    {
        abort_unless($this->droits->peutSaisirChimio($request->user()), 403);
        $this->dossier->charger($request->user(), $identifiant);
        [$j, $c] = $this->lireJour($identifiant, $jour);
        abort_if((bool) $j->ok_chimio, 422, 'Ce jour est validé : retirez d\'abord le « OK chimio ».');
        $d = $request->validate([
            'date_prevue' => ['required', 'date'], 'observation' => ['nullable', 'string', 'max:500'],
            'tension' => ['nullable', 'string', 'max:20'], 'temperature' => ['nullable', 'string', 'max:10'], 'douleur' => ['nullable', 'string', 'max:10'],
            'produits' => ['nullable', 'array', 'max:60'],
            'produits.*.ordre' => ['required', 'integer', 'min:0', 'max:999'],
            'produits.*.dose_ref' => ['nullable', 'string', 'max:40'],
            'produits.*.type_dose' => ['required', Rule::in(T::TYPES_DOSE)],
            'produits.*.dose' => ['nullable', 'string', 'max:80'],
            'produits.*.voie' => ['nullable', 'string', 'max:30'], 'produits.*.solvant' => ['nullable', 'string', 'max:40'],
            'produits.*.volume' => ['nullable', 'string', 'max:20'], 'produits.*.duree' => ['nullable', 'string', 'max:40'],
        ]);
        $existants = DB::table('onco_traitement_produits')->where('jour_id', $jour)->get()->keyBy('id');
        DB::transaction(function () use ($d, $jour, $c, $existants) {
            DB::table('onco_traitement_jours')->where('id', $jour)->update(collect($d)->only(['date_prevue', 'observation', 'tension', 'temperature', 'douleur'])->all());
            foreach ($d['produits'] ?? [] as $pid => $p) {
                abort_unless($existants->has((int) $pid), 404);
                DB::table('onco_traitement_produits')->where('id', (int) $pid)->update([
                    'ordre' => $p['ordre'], 'dose_ref' => $p['dose_ref'] ?? null, 'type_dose' => $p['type_dose'],
                    'dose_calculee' => T::doseCalculee($p['type_dose'], $p['dose_ref'] ?? null, (float) $c->surface_corporelle, (float) $c->poids, $c->clairance !== null ? (float) $c->clairance : null),
                    'dose' => $p['dose'] ?? null, 'voie' => $p['voie'] ?? null, 'solvant' => $p['solvant'] ?? null,
                    'volume' => $p['volume'] ?? null, 'duree' => $p['duree'] ?? null,
                ]);
            }
        });
        Journal::ecrire('saisie_jour_chimio', ['identifiant' => $identifiant, 'details' => ['jour' => $jour, 'doses' => collect($d['produits'] ?? [])->map(fn ($p) => $p['dose'] ?? null)]]);

        return redirect()->route('chimio.cure', [$identifiant, $c->id, 'jour' => $jour])->with('statut', 'Jour enregistré.');
    }

    /** Donne ou retire le « OK chimio ». Retrait impossible si un produit a déjà été administré. */
    public function okChimio(Request $request, string $identifiant, int $jour): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->droits->peutValiderChimio($user), 403);
        $this->dossier->charger($user, $identifiant);
        [$j, $c] = $this->lireJour($identifiant, $jour);
        $retour = redirect()->route('chimio.cure', [$identifiant, $c->id, 'jour' => $jour]);
        $produits = DB::table('onco_traitement_produits')->where('jour_id', $jour)->get();
        if ($j->ok_chimio) {
            if ($produits->where('administre', true)->isNotEmpty()) {
                return $retour->withErrors(['ok' => 'Un produit de ce jour a déjà été administré : le « OK chimio » ne peut plus être retiré.']);
            }
            DB::table('onco_traitement_jours')->where('id', $jour)->update(['ok_chimio' => false, 'ok_par' => null, 'ok_nom' => null, 'ok_le' => null]);
            Journal::ecrire('retrait_ok_chimio', ['identifiant' => $identifiant, 'details' => ['jour' => $jour, 'avant' => [$j->ok_nom, $j->ok_le]]]);

            return $retour->with('statut', '« OK chimio » retiré.');
        }
        if ($produits->isEmpty() || $produits->contains(fn ($p) => trim((string) $p->dose) === '')) {
            return $retour->withErrors(['ok' => 'Toutes les doses de ce jour doivent être renseignées avant le « OK chimio ».']);
        }
        if ($c->clairance === null && $produits->contains(fn ($p) => $p->type_dose === 'AUC')) {
            return $retour->withErrors(['ok' => 'Un produit est dosé en AUC : indiquez la clairance de la créatinine de la cure avant le « OK chimio ».']);
        }
        DB::table('onco_traitement_jours')->where('id', $jour)->update(['ok_chimio' => true, 'ok_par' => $user->userName, 'ok_nom' => $user->nomAffiche(), 'ok_le' => now()]);
        Journal::ecrire('ok_chimio', ['identifiant' => $identifiant, 'details' => ['jour' => $jour, 'doses' => $produits->pluck('dose', 'medicament')]]);

        return $retour->with('statut', '« OK chimio » enregistré.');
    }

    public function ajouterProduit(Request $request, string $identifiant, int $jour): RedirectResponse
    {
        abort_unless($this->droits->peutSaisirChimio($request->user()), 403);
        $this->dossier->charger($request->user(), $identifiant);
        [$j, $c] = $this->lireJour($identifiant, $jour);
        abort_if((bool) $j->ok_chimio, 422);
        $d = $request->validate(['medicament' => ['required', 'string', 'max:150'], 'chimio' => ['nullable', 'boolean']]);
        DB::table('onco_traitement_produits')->insert(['jour_id' => $jour, 'medicament' => trim($d['medicament']), 'chimio' => (bool) ($d['chimio'] ?? false),
            'type_dose' => 'DT', 'ordre' => (int) DB::table('onco_traitement_produits')->where('jour_id', $jour)->max('ordre') + 1]);
        Journal::ecrire('ajout_produit_chimio', ['identifiant' => $identifiant, 'details' => ['jour' => $jour, 'medicament' => $d['medicament']]]);

        return redirect()->route('chimio.cure', [$identifiant, $c->id, 'jour' => $jour])->with('statut', 'Produit ajouté : indiquez sa dose.');
    }

    public function retirerProduit(Request $request, string $identifiant, int $produit): RedirectResponse
    {
        abort_unless($this->droits->peutSaisirChimio($request->user()), 403);
        $this->dossier->charger($request->user(), $identifiant);
        $p = DB::table('onco_traitement_produits')->find($produit);
        abort_unless($p, 404);
        [$j, $c] = $this->lireJour($identifiant, (int) $p->jour_id);
        abort_if((bool) $j->ok_chimio, 422);
        DB::table('onco_traitement_produits')->where('id', $produit)->delete();
        Journal::ecrire('retrait_produit_chimio', ['identifiant' => $identifiant, 'details' => ['jour' => $j->id, 'medicament' => $p->medicament, 'dose' => $p->dose]]);

        return redirect()->route('chimio.cure', [$identifiant, $c->id, 'jour' => $j->id])->with('statut', 'Produit retiré de ce jour.');
    }

    /** Signature de l'infirmier : seulement après le « OK chimio » du jour. */
    public function administrer(Request $request, string $identifiant, int $produit): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->droits->peutAdministrerChimio($user), 403);
        $this->dossier->charger($user, $identifiant);
        $p = DB::table('onco_traitement_produits')->find($produit);
        abort_unless($p, 404);
        [$j, $c] = $this->lireJour($identifiant, (int) $p->jour_id);
        $retour = redirect()->route('chimio.cure', [$identifiant, $c->id, 'jour' => $j->id]);
        if (! $j->ok_chimio) {
            return $retour->withErrors(['ok' => 'Pas d\'administration sans « OK chimio » du jour.']);
        }
        DB::table('onco_traitement_produits')->where('id', $produit)->update($p->administre
            ? ['administre' => false, 'administre_par' => null, 'administre_nom' => null, 'administre_le' => null]
            : ['administre' => true, 'administre_par' => $user->userName, 'administre_nom' => $user->nomAffiche(), 'administre_le' => now()]);
        Journal::ecrire($p->administre ? 'annulation_administration_chimio' : 'administration_chimio', ['identifiant' => $identifiant, 'details' => ['jour' => $j->id, 'medicament' => $p->medicament, 'dose' => $p->dose]]);

        return $retour;
    }

    /** Signe d'un coup tous les produits du jour pas encore administrés, seulement après le « OK chimio ». */
    public function administrerTout(Request $request, string $identifiant, int $jour): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->droits->peutAdministrerChimio($user), 403);
        $this->dossier->charger($user, $identifiant);
        [$j, $c] = $this->lireJour($identifiant, $jour);
        $retour = redirect()->route('chimio.cure', [$identifiant, $c->id, 'jour' => $jour]);
        if (! $j->ok_chimio) {
            return $retour->withErrors(['ok' => 'Pas d\'administration sans « OK chimio » du jour.']);
        }
        $restants = DB::table('onco_traitement_produits')->where('jour_id', $jour)->where('administre', false)->get();
        DB::table('onco_traitement_produits')->whereIn('id', $restants->pluck('id'))
            ->update(['administre' => true, 'administre_par' => $user->userName, 'administre_nom' => $user->nomAffiche(), 'administre_le' => now()]);
        Journal::ecrire('administration_chimio', ['identifiant' => $identifiant, 'details' => ['jour' => $jour, 'tous' => true, 'doses' => $restants->pluck('dose', 'medicament')]]);

        return $retour->with('statut', $restants->count().' produit(s) signé(s).');
    }

    /** Fiche A4 paysage de la cure. Refusée tant qu'une dose manque. */
    public function fiche(Request $request, string $identifiant, int $cure): View|RedirectResponse
    {
        [$patient, $admissions] = $this->dossier->charger($request->user(), $identifiant);
        [$c, $t, $jours, $produits] = $this->lireCure($identifiant, $cure);
        $manquantes = $this->dosesManquantes($jours, $produits, $c);
        if ($manquantes !== []) {
            return redirect()->route('chimio.cure', [$identifiant, $cure])
                ->withErrors(['ok' => 'Impression impossible : dose manquante pour '.implode(', ', $manquantes).'.']);
        }
        Journal::ecrire('impression_fiche_chimio', ['identifiant' => $identifiant, 'details' => ['cure' => $cure, 'protocole' => $t->nom_protocole]]);

        return view('chimio.fiche', ['patient' => $patient, 'derniere' => $admissions[0] ?? null, 'cure' => $c, 'traitement' => $t, 'jours' => $jours, 'produits' => $produits]);
    }

    /** @return array{0: object, 1: object, 2: \Illuminate\Support\Collection, 3: \Illuminate\Support\Collection} */
    private function lireCure(string $identifiant, int $cure): array
    {
        $c = DB::table('onco_traitement_cures')->find($cure);
        abort_unless($c, 404);
        $t = DB::table('onco_traitements')->where('id', $c->traitement_id)->where('identifiant', $identifiant)->whereNull('masque_le')->first();
        abort_unless($t, 404);
        $jours = DB::table('onco_traitement_jours')->where('cure_id', $cure)->orderBy('jour')->get();
        $produits = DB::table('onco_traitement_produits')->whereIn('jour_id', $jours->pluck('id'))->orderBy('ordre')->orderBy('id')->get()->groupBy('jour_id');

        return [$c, $t, $jours, $produits];
    }

    /** Le jour, sa cure : 404 s'il n'appartient pas à ce patient ; 403 si le traitement est arrêté. */
    private function lireJour(string $identifiant, int $jour): array
    {
        $j = DB::table('onco_traitement_jours')->find($jour);
        abort_unless($j, 404);
        [$c, $t] = $this->lireCure($identifiant, (int) $j->cure_id);
        abort_unless($t->statut === 'actif', 403);

        return [$j, $c];
    }

    /** Doses absentes, et doses AUC sans clairance (une dose AUC saisie seule, comme « 4 », serait fausse). */
    private function dosesManquantes($jours, $produits, object $cure): array
    {
        $manque = [];
        foreach ($jours as $j) {
            foreach ($produits[$j->id] ?? [] as $p) {
                if (trim((string) $p->dose) === '') {
                    $manque[] = ($p->medicament ?: 'ligne sans nom').' (J'.$j->jour.')';
                } elseif ($p->type_dose === 'AUC' && $cure->clairance === null) {
                    $manque[] = $p->medicament.' (J'.$j->jour.', clairance pour l\'AUC)';
                }
            }
        }

        return $manque;
    }

    /** Dernier poids et dernière taille connus, pour préremplir un nouveau traitement. */
    private function dernieresMesures(array $traitements): ?object
    {
        foreach ($traitements as $t) {
            if ($t->cures !== []) {
                return end($t->cures);
            }
        }

        return null;
    }
}
