<?php

namespace App\Http\Controllers;

use App\Services\Journal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Prescription du scanner de dosimétrie, comme la fiche 1 du logiciel du cabinet. Rien n'est écrit dans Clinisys. */
class ScannerDosimetrieController extends Controller
{
    public const LOCALISATIONS = ['ORL', 'Abdomen', 'Sein', 'Membres', 'Thorax', 'Pelvis'];

    public function __construct(private DossierController $dossier)
    {
    }

    public function creer(Request $request, string $identifiant): RedirectResponse
    {
        [, $admissions] = $this->dossier->charger($request->user(), $identifiant);
        $d = $request->validate([
            'date_scanner' => ['nullable', 'date'],
            'localisations' => ['required', 'array', 'min:1'],
            'localisations.*' => [Rule::in(self::LOCALISATIONS)],
            'contraste_iv' => ['nullable', 'string', 'max:150'], 'contraste_oral' => ['nullable', 'string', 'max:150'],
            'contraste_vesical' => ['nullable', 'string', 'max:150'], 'contraste_rectal' => ['nullable', 'string', 'max:150'],
            'avertir_medecin' => ['required', 'boolean'], 'allergie_pc' => ['required', 'boolean'], 'immobilisation' => ['required', 'boolean'],
            'type_immobilisation' => ['nullable', 'string', 'max:150'], 'observations' => ['nullable', 'string', 'max:2000'],
            'limite_du' => ['nullable', 'string', 'max:150'], 'limite_jusqua' => ['nullable', 'string', 'max:150'], 'epaisseur_coupes' => ['nullable', 'string', 'max:40'],
        ], ['localisations.required' => 'Cochez au moins une localisation pour le scanner.']);
        $user = $request->user();
        $id = DB::table('onco_scanners')->insertGetId(['localisations' => implode(',', $d['localisations'])] + $d + [
            'identifiant' => $identifiant, 'num_doss' => $admissions[0]->NumDoss ?? null,
            'prescrit_par' => $user->userName, 'prescrit_nom' => $user->nomAffiche(), 'saisi_le' => now(),
        ]);
        Journal::ecrire('prescription_scanner', ['identifiant' => $identifiant, 'details' => ['id' => $id, 'localisations' => $d['localisations']]]);

        return redirect()->route('dossier', ['identifiant' => $identifiant, 'onglet' => 'prescriptions'])->with('statut', 'Scanner de dosimétrie prescrit.');
    }

    public function fiche(Request $request, string $identifiant, int $scanner): View
    {
        [$patient, $admissions] = $this->dossier->charger($request->user(), $identifiant);
        $s = DB::table('onco_scanners')->where('id', $scanner)->where('identifiant', $identifiant)->whereNull('masque_le')->first();
        abort_unless($s, 404);
        Journal::ecrire('impression_scanner', ['identifiant' => $identifiant, 'details' => ['id' => $scanner]]);

        return view('scanner.fiche', ['patient' => $patient, 'derniere' => $admissions[0] ?? null, 's' => $s]);
    }

    /** Retrait par son auteur seul : masqué, jamais effacé, et tracé au journal. */
    public function retirer(Request $request, string $identifiant, int $scanner): RedirectResponse
    {
        $this->dossier->charger($request->user(), $identifiant);
        $n = DB::table('onco_scanners')->where('id', $scanner)->where('identifiant', $identifiant)->where('prescrit_par', $request->user()->userName)
            ->whereNull('masque_le')->update(['masque_le' => now()]);
        abort_unless($n === 1, 403);
        Journal::ecrire('retrait_scanner', ['identifiant' => $identifiant, 'details' => ['id' => $scanner]]);

        return redirect()->route('dossier', ['identifiant' => $identifiant, 'onglet' => 'prescriptions'])->with('statut', 'Scanner de dosimétrie retiré.');
    }
}
