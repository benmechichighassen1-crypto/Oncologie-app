<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Copies locales des référentiels Clinisys (lecture seule côté Clinisys), pour des écrans
 * de prescription rapides : protocoles de chimiothérapie paramétrés et leurs lignes,
 * médicaments (liste DCI et médicaments de la pharmacie). Remplies par « php artisan onco:referentiels ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onco_ref_protocoles', function (Blueprint $table) {
            $table->integer('cod_prot')->primary();         // ProtocoleCure.CodProt
            $table->string('nom', 150);
            $table->boolean('actif');
            $table->string('medecin', 20)->nullable();      // MedecinDemandeur
        });

        Schema::create('onco_ref_protocole_lignes', function (Blueprint $table) {
            $table->id();
            $table->integer('cod_prot')->index();
            $table->integer('ordre')->default(0);
            $table->string('jour', 20)->nullable();
            $table->string('dci', 150)->nullable();
            $table->string('posologie', 150)->nullable();
            $table->string('dose_totale', 10)->nullable();  // 0 = dose par m² (doseInitiale)
            $table->string('dose_initiale', 150)->nullable();
            $table->string('dilution', 255)->nullable();
            $table->string('duree', 40)->nullable();
            $table->string('horaire', 40)->nullable();
        });

        Schema::create('onco_ref_medicaments', function (Blueprint $table) {
            $table->id();
            $table->string('source', 12);                   // dci | pharmacie
            $table->string('code', 30)->nullable();         // code article pour la pharmacie
            $table->string('libelle', 255)->index();
            $table->string('dci', 150)->nullable();
        });

        Schema::create('onco_ref_synchro', function (Blueprint $table) {
            $table->string('referentiel', 40)->primary();
            $table->integer('lignes');
            $table->string('par', 50)->nullable();
        });
        // datetime2(0) : un « datetime » dépend de la langue (française) du serveur.
        DB::statement('ALTER TABLE onco_ref_synchro ADD synchro_le datetime2(0) NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('onco_ref_synchro');
        Schema::dropIfExists('onco_ref_medicaments');
        Schema::dropIfExists('onco_ref_protocole_lignes');
        Schema::dropIfExists('onco_ref_protocoles');
    }
};
