<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Prescriptions de l'oncologue (base de l'appli). Une ligne arrêtée reste visible et tracée. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onco_prescriptions', function (Blueprint $table) {
            $table->id();
            $table->string('identifiant', 20)->index();
            $table->string('num_doss', 20)->nullable();
            $table->date('date_prescription');
            $table->string('type', 20);                 // medicament | protocole | examen | autre
            $table->string('libelle', 255);             // médicament, protocole (ex. AC60) ou examen
            $table->string('dose', 60)->nullable();     // ex. 60 mg/m², 500 mg
            $table->string('voie', 40)->nullable();     // IV, per os, SC…
            $table->string('frequence', 80)->nullable();
            $table->string('duree', 60)->nullable();
            $table->unsignedSmallInteger('nb_cures')->nullable();
            $table->string('statut', 20)->default('prescrite'); // prescrite | en_cours | arretee
            $table->text('commentaire')->nullable();
            $table->string('prescrit_par', 50);
            $table->timestamp('saisi_le', 0);
            $table->string('statut_par', 50)->nullable();
            $table->timestamp('statut_le', 0)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onco_prescriptions');
    }
};
