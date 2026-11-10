<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Clairance de la créatinine de la cure : dose des produits en AUC (carboplatine) par la formule de Calvert. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onco_traitement_cures', function (Blueprint $table) {
            $table->decimal('clairance', 6, 1)->nullable(); // ml/min
        });
    }

    public function down(): void
    {
        Schema::table('onco_traitement_cures', function (Blueprint $table) {
            $table->dropColumn('clairance');
        });
    }
};
