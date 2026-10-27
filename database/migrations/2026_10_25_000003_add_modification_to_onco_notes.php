<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Dernière modification d'une note ; l'ancien et le nouveau texte sont gardés dans onco_journal. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onco_notes', function (Blueprint $table) {
            $table->string('modifie_par', 50)->nullable();
            $table->timestamp('modifie_le', 0)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('onco_notes', function (Blueprint $table) {
            $table->dropColumn(['modifie_par', 'modifie_le']);
        });
    }
};
