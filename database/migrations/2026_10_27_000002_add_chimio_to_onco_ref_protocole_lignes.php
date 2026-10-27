<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Produits de chimiothérapie d'un protocole (Clinisys medicament_ProtocoleCure) :
 * chimio = la ligne est un produit de chimio (« PC », colorée dans Clinisys) ;
 * dt = dose totale (fixe) ; sinon dose_chimio est par m², multipliée par la surface corporelle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onco_ref_protocole_lignes', function (Blueprint $table) {
            $table->string('code_art', 30)->nullable();
            $table->boolean('chimio')->default(false);
            $table->boolean('dt')->default(false);
            $table->string('dose_chimio', 60)->nullable();
        });
        // Surface corporelle à 3 décimales, comme Clinisys (formule de Boyd).
        DB::statement('ALTER TABLE onco_cures ALTER COLUMN surface_corporelle decimal(6, 3) NULL');
    }

    public function down(): void
    {
        Schema::table('onco_ref_protocole_lignes', function (Blueprint $table) {
            $table->dropColumn(['code_art', 'chimio', 'dt', 'dose_chimio']);
        });
    }
};
