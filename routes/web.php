<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DossierController;
use App\Http\Controllers\PatientsController;
use App\Http\Controllers\PlanningController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/planning');

Route::middleware('guest')->group(function (): void {
    Route::get('/connexion', [AuthController::class, 'create'])->name('login');
    Route::post('/connexion', [AuthController::class, 'store'])->name('login.store');
});

Route::middleware(['auth', 'journal'])->group(function (): void {
    Route::post('/deconnexion', [AuthController::class, 'destroy'])->name('logout');

    Route::get('/planning', [PlanningController::class, 'index'])->name('planning');
    Route::get('/planning/evenements', [PlanningController::class, 'evenements'])->name('planning.evenements');
    Route::get('/planning/recherche', [PlanningController::class, 'recherche'])->name('planning.recherche');

    Route::get('/statistiques', [\App\Http\Controllers\StatistiquesController::class, 'index'])->name('statistiques');
    Route::get('/patients', [PatientsController::class, 'index'])->name('patients');
    Route::get('/patients/{identifiant}', [DossierController::class, 'show'])->name('dossier');
    Route::get('/patients/{identifiant}/pj/{source}/{docId}', [DossierController::class, 'document'])
        ->whereIn('source', ['GED', 'GED_DOC'])->name('pj.voir');
    Route::post('/patients/{identifiant}/pj/{source}/{docId}/categorie', [DossierController::class, 'classer'])
        ->whereIn('source', ['GED', 'GED_DOC'])->name('pj.classer');
    Route::post('/patients/{identifiant}/saisie', [DossierController::class, 'saisir'])->name('dossier.saisie');
    Route::post('/patients/{identifiant}/fiche', [DossierController::class, 'enregistrerFiche'])->name('dossier.fiche');
    Route::post('/patients/{identifiant}/antecedents', [DossierController::class, 'ajouterAntecedent'])->name('dossier.antecedent');
    Route::post('/patients/{identifiant}/notes', [DossierController::class, 'ajouterNote'])->name('dossier.note');
    Route::put('/patients/{identifiant}/notes/{id}', [DossierController::class, 'modifierNote'])->whereNumber('id')->name('dossier.note.modifier');
    Route::post('/patients/{identifiant}/prescriptions', [DossierController::class, 'ajouterPrescription'])->name('dossier.prescription');
    Route::put('/patients/{identifiant}/prescriptions/{id}', [DossierController::class, 'modifierPrescription'])->whereNumber('id')->name('dossier.prescription.modifier');
    Route::delete('/patients/{identifiant}/prescriptions/{id}', [DossierController::class, 'supprimerPrescription'])->whereNumber('id')->name('dossier.prescription.supprimer');
    Route::patch('/patients/{identifiant}/prescriptions/{id}', [DossierController::class, 'statutPrescription'])->whereNumber('id')->name('dossier.prescription.statut');
    Route::get('/patients/{identifiant}/protocoles/{numCure}', [DossierController::class, 'imprimerCure'])->whereNumber('numCure')->name('dossier.protocole');
    Route::get('/medicaments/recherche', [DossierController::class, 'rechercherMedicaments'])->name('medicaments.recherche');
    Route::get('/protocoles/{codProt}/lignes', [DossierController::class, 'lignesProtocole'])->whereNumber('codProt')->name('protocole.lignes');
    Route::post('/patients/{identifiant}/protocoles', [DossierController::class, 'prescrireProtocole'])->name('dossier.protocole.prescrire');
    Route::get('/patients/{identifiant}/protocoles-appli/{id}', [DossierController::class, 'imprimerProtocoleAppli'])->whereNumber('id')->name('dossier.protocole.appli');
    Route::patch('/patients/{identifiant}/protocoles-appli/{id}/arret', [DossierController::class, 'arreterProtocole'])->whereNumber('id')->name('dossier.protocole.arret');
    Route::get('/patients/{identifiant}/ordonnance', [DossierController::class, 'ordonnance'])->name('dossier.ordonnance');
    Route::delete('/patients/{identifiant}/{table}/{id}', [DossierController::class, 'masquer'])
        ->whereIn('table', ['antecedent', 'note'])->whereNumber('id')->name('dossier.masquer');

    // Écrans d'administration : adresses sans « admin » (/acces, /tracabilite).
    Route::middleware('admin.onco')->name('admin.')->group(function (): void {
        Route::get('/acces', [AdminController::class, 'acces'])->name('acces');
        Route::get('/acces/{userName}', [AdminController::class, 'edit'])->name('acces.edit');
        Route::put('/acces/{userName}', [AdminController::class, 'update'])->name('acces.update');
        Route::get('/tracabilite', [AdminController::class, 'journal'])->name('journal');
        Route::post('/acces/referentiels', [AdminController::class, 'referentiels'])->name('referentiels');
    });
});
