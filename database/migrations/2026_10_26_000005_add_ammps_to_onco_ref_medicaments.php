<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Liste marocaine des médicaments (AMMPS, ministère de la Santé) dans onco_ref_medicaments,
 * source « ammps », remplie par « php artisan onco:ammps ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onco_ref_medicaments', function (Blueprint $table) {
            $table->string('dosage', 100)->nullable();
            $table->string('forme', 100)->nullable();
            $table->string('presentation', 255)->nullable();
            $table->string('classe', 150)->nullable();       // classe thérapeutique
            $table->string('laboratoire', 150)->nullable();  // EPI
            $table->string('statut', 60)->nullable();        // commercialisé, arrêt…
            $table->string('pp_gn', 20)->nullable();         // princeps / générique
            $table->decimal('ppv', 12, 2)->nullable();       // prix public de vente (DH)
            $table->decimal('ph', 12, 2)->nullable();        // prix hôpital (DH)
        });
    }

    public function down(): void
    {
        Schema::table('onco_ref_medicaments', function (Blueprint $table) {
            $table->dropColumn(['dosage', 'forme', 'presentation', 'classe', 'laboratoire', 'statut', 'pp_gn', 'ppv', 'ph']);
        });
    }
};
