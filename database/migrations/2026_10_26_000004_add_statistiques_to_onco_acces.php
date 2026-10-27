<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Accès aux statistiques, paramétré à part de l'accès aux patients :
 * null = comme ses patients | aucun | propre | liste | tous.
 * Pour « liste », les médecins choisis sont dans onco_acces_stats_medecins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onco_acces', function (Blueprint $table) {
            $table->string('stats_portee', 10)->nullable();
        });

        Schema::create('onco_acces_stats_medecins', function (Blueprint $table) {
            $table->string('user_name', 50);
            $table->string('cod_med', 10);
            $table->primary(['user_name', 'cod_med']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onco_acces_stats_medecins');
        Schema::table('onco_acces', function (Blueprint $table) {
            $table->dropColumn('stats_portee');
        });
    }
};
