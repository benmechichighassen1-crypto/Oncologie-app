<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* Prescriptions de scanner de dosimétrie (base de l'appli, rien dans Clinisys). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onco_scanners', function (Blueprint $table) {
            $table->id();
            $table->string('identifiant', 20)->index();
            $table->string('num_doss', 20)->nullable();
            $table->date('date_scanner')->nullable();
            $table->string('localisations', 100);          // ORL,Sein…
            $table->string('contraste_iv', 150)->nullable();
            $table->string('contraste_oral', 150)->nullable();
            $table->string('contraste_vesical', 150)->nullable();
            $table->string('contraste_rectal', 150)->nullable();
            $table->boolean('avertir_medecin')->default(false);
            $table->boolean('allergie_pc')->default(false);
            $table->boolean('immobilisation')->default(false);
            $table->string('type_immobilisation', 150)->nullable();
            $table->text('observations')->nullable();
            $table->string('limite_du', 150)->nullable();  // limites de l'acquisition
            $table->string('limite_jusqua', 150)->nullable();
            $table->string('epaisseur_coupes', 40)->nullable();
            $table->string('prescrit_par', 50);
            $table->string('prescrit_nom', 100)->nullable();
            $table->dateTime('saisi_le', 0);
            $table->dateTime('masque_le', 0)->nullable();
        });

        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('ALTER TABLE onco_scanners ALTER COLUMN saisi_le datetime2(0) NOT NULL');
            DB::statement('ALTER TABLE onco_scanners ALTER COLUMN masque_le datetime2(0) NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('onco_scanners');
    }
};
