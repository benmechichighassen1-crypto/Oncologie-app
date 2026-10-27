<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Saisies de l'oncologue (base de l'appli). Rien n'est modifié ni supprimé :
 * la fiche est versionnée, les antécédents et notes sont seulement masqués.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Diagnostic et pathologie : une ligne par version, la dernière fait foi.
        Schema::create('onco_fiches', function (Blueprint $table) {
            $table->id();
            $table->string('identifiant', 20)->index();
            $table->unsignedInteger('version');
            $table->integer('code_tumeur')->nullable();          // Clinisys dbo.Tumeurs.id
            $table->string('pathologie', 255)->nullable();       // libellé figé au moment de la saisie
            $table->string('diagnostic', 500)->nullable();
            $table->date('date_diagnostic')->nullable();
            $table->string('localisation', 150)->nullable();
            $table->string('lateralite', 20)->nullable();
            $table->string('histologie', 255)->nullable();
            $table->string('grade', 30)->nullable();
            $table->string('stade_t', 10)->nullable();
            $table->string('stade_n', 10)->nullable();
            $table->string('stade_m', 10)->nullable();
            $table->string('stade', 20)->nullable();
            $table->string('marqueurs', 255)->nullable();        // RH, HER2, Ki67, mutations…
            $table->string('statut', 30)->nullable();
            $table->string('traitements', 255)->nullable();      // liste séparée par des virgules
            $table->string('ecog', 5)->nullable();
            $table->text('commentaire')->nullable();
            $table->string('saisi_par', 50);
            $table->timestamp('saisi_le', 0);
            $table->unique(['identifiant', 'version']);
        });

        Schema::create('onco_antecedents', function (Blueprint $table) {
            $table->id();
            $table->string('identifiant', 20)->index();
            $table->string('famille', 20);                       // medical | chirurgical | familial | allergie | gyneco | habitude | autre
            $table->string('libelle', 255);
            $table->string('periode', 50)->nullable();
            $table->text('details')->nullable();
            $table->string('saisi_par', 50);
            $table->timestamp('saisi_le', 0);
            $table->string('masque_par', 50)->nullable();
            $table->timestamp('masque_le', 0)->nullable();
        });

        // Histoire de la maladie, notes d'évolution, décisions RCP.
        Schema::create('onco_notes', function (Blueprint $table) {
            $table->id();
            $table->string('identifiant', 20)->index();
            $table->string('type', 20);                          // histoire | evolution | rcp
            $table->date('date_note');
            $table->text('texte');
            $table->string('saisi_par', 50);
            $table->timestamp('saisi_le', 0);
            $table->string('masque_par', 50)->nullable();
            $table->timestamp('masque_le', 0)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onco_notes');
        Schema::dropIfExists('onco_antecedents');
        Schema::dropIfExists('onco_fiches');
    }
};
