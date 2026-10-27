<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Protocoles de chimiothérapie prescrits depuis l'appli (base de l'appli uniquement,
 * rien n'est écrit dans Clinisys). Le protocole paramétré vient de ProtocoleCure ;
 * ses lignes sont copiées avec la dose calculée selon la surface corporelle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onco_cures', function (Blueprint $table) {
            $table->id();
            $table->string('identifiant', 20)->index();
            $table->string('num_doss', 20)->nullable();
            $table->integer('cod_prot')->nullable();       // ProtocoleCure.CodProt
            $table->string('nom_protocole', 150);
            $table->decimal('poids', 6, 2)->nullable();
            $table->decimal('taille', 6, 2)->nullable();
            $table->decimal('bmi', 6, 2)->nullable();
            $table->decimal('surface_corporelle', 5, 2)->nullable();
            $table->unsignedSmallInteger('nb_cures')->nullable();
            $table->unsignedSmallInteger('intervalle')->nullable(); // jours entre deux cures
            $table->date('date_debut')->nullable();
            $table->text('observation')->nullable();
            $table->string('statut', 20)->default('prescrite'); // prescrite | arretee
            $table->string('prescrit_par', 50);            // login
            $table->string('prescrit_nom', 100)->nullable(); // nom du médecin, pour l'affichage
            $table->dateTime('saisi_le', 0);
            $table->string('statut_par', 50)->nullable();
            $table->dateTime('statut_le', 0)->nullable();
        });

        // Précision 0 → Laravel crée un « datetime », qui dépend du format de date du serveur (français) :
        // on passe en datetime2(0), comme les autres tables de l'appli.
        DB::statement('ALTER TABLE onco_cures ALTER COLUMN saisi_le datetime2(0) NOT NULL');
        DB::statement('ALTER TABLE onco_cures ALTER COLUMN statut_le datetime2(0) NULL');

        Schema::create('onco_cure_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cure_id')->constrained('onco_cures');
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->string('jour', 20)->nullable();
            $table->string('medicament', 150);
            $table->string('dose_m2', 40)->nullable();     // dose du protocole par m², si elle en dépend
            $table->string('dose', 80)->nullable();        // dose retenue par le médecin
            $table->boolean('ajustee')->default(false);    // modifiée par rapport au calcul
            $table->string('dilution', 150)->nullable();
            $table->string('duree', 40)->nullable();
            $table->string('horaire', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onco_cure_lignes');
        Schema::dropIfExists('onco_cures');
    }
};
