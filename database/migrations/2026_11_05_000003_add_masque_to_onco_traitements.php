<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* Suppression d'un traitement non administré : masqué, jamais effacé, comme les notes et prescriptions. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onco_traitements', function (Blueprint $table) {
            $table->string('masque_par', 50)->nullable();
            $table->dateTime('masque_le', 0)->nullable();
        });
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('ALTER TABLE onco_traitements ALTER COLUMN masque_le datetime2(0) NULL');
        }
    }

    public function down(): void
    {
        Schema::table('onco_traitements', function (Blueprint $table) {
            $table->dropColumn(['masque_par', 'masque_le']);
        });
    }
};
