<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Traitements de chimiothérapie, saisis comme dans le logiciel du cabinet :
 * traitement > cures > jours (J1, J15…) > produits. Base de l'appli uniquement,
 * rien n'est écrit dans Clinisys. Droits des agents dans onco_acces (écran Accès).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onco_traitements', function (Blueprint $table) {
            $table->id();
            $table->string('identifiant', 20)->index();
            $table->string('num_doss', 20)->nullable();
            $table->integer('cod_prot')->nullable();           // ProtocoleCure.CodProt
            $table->string('nom_protocole', 150);
            $table->unsignedSmallInteger('nb_cures');
            $table->unsignedSmallInteger('intercure');         // jours entre deux cures
            $table->string('jours_cycle', 60);                 // « 1,15 »
            $table->date('date_debut');
            $table->date('date_fin_prevue')->nullable();
            $table->boolean('rechute')->default(false);
            $table->string('localisation', 150)->nullable();
            $table->string('histologie', 150)->nullable();
            $table->string('stade', 40)->nullable();
            $table->string('tnm', 40)->nullable();
            $table->string('allergies', 255)->nullable();
            $table->text('observation')->nullable();
            $table->string('statut', 20)->default('actif');    // actif | arrete
            $table->string('prescrit_par', 50);
            $table->string('prescrit_nom', 100)->nullable();
            $table->dateTime('saisi_le', 0);
            $table->string('arrete_par', 50)->nullable();
            $table->dateTime('arrete_le', 0)->nullable();
        });

        Schema::create('onco_traitement_cures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('traitement_id')->constrained('onco_traitements');
            $table->unsignedSmallInteger('numero');
            $table->date('date_debut');
            $table->date('date_fin');
            $table->decimal('poids', 6, 2);
            $table->decimal('taille', 6, 2);
            $table->decimal('surface_corporelle', 5, 3);       // formule de Clinisys (Boyd)
            $table->text('observation')->nullable();
            $table->unique(['traitement_id', 'numero']);
        });

        Schema::create('onco_traitement_jours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cure_id')->constrained('onco_traitement_cures');
            $table->unsignedSmallInteger('jour');              // 1 pour J1
            $table->date('date_prevue');
            $table->string('observation', 500)->nullable();
            $table->string('tension', 20)->nullable();
            $table->string('temperature', 10)->nullable();
            $table->string('douleur', 10)->nullable();
            $table->boolean('ok_chimio')->default(false);
            $table->string('ok_par', 50)->nullable();
            $table->string('ok_nom', 100)->nullable();
            $table->dateTime('ok_le', 0)->nullable();
        });

        Schema::create('onco_traitement_produits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jour_id')->constrained('onco_traitement_jours');
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->string('medicament', 150);
            $table->boolean('chimio')->default(false);
            $table->string('dose_ref', 40)->nullable();        // 180 pour 180 mg/m²
            $table->string('type_dose', 10)->default('DT');    // mg/m² | mg/kg | AUC | DT
            $table->decimal('dose_calculee', 10, 2)->nullable();
            $table->string('dose', 80)->nullable();            // dose retenue, arrondie par le médecin
            $table->string('voie', 30)->nullable();
            $table->string('solvant', 40)->nullable();
            $table->string('volume', 20)->nullable();
            $table->string('duree', 40)->nullable();
            $table->boolean('administre')->default(false);
            $table->string('administre_par', 50)->nullable();
            $table->string('administre_nom', 100)->nullable();
            $table->dateTime('administre_le', 0)->nullable();
        });

        Schema::table('onco_acces', function (Blueprint $table) {
            $table->boolean('chimio_saisie')->default(false);
            $table->boolean('chimio_ok')->default(false);
            $table->boolean('chimio_administration')->default(false);
        });

        // Comme les autres tables : datetime2(0), indépendant du format de date français du serveur.
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('ALTER TABLE onco_traitements ALTER COLUMN saisi_le datetime2(0) NOT NULL');
            DB::statement('ALTER TABLE onco_traitements ALTER COLUMN arrete_le datetime2(0) NULL');
            DB::statement('ALTER TABLE onco_traitement_jours ALTER COLUMN ok_le datetime2(0) NULL');
            DB::statement('ALTER TABLE onco_traitement_produits ALTER COLUMN administre_le datetime2(0) NULL');
        }
    }

    public function down(): void
    {
        Schema::table('onco_acces', function (Blueprint $table) {
            $table->dropColumn(['chimio_saisie', 'chimio_ok', 'chimio_administration']);
        });
        Schema::dropIfExists('onco_traitement_produits');
        Schema::dropIfExists('onco_traitement_jours');
        Schema::dropIfExists('onco_traitement_cures');
        Schema::dropIfExists('onco_traitements');
    }
};
