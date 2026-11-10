<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Catalogue des examens de radiologie, copié de Clinisys (dbo.EXAMRAD) par onco:referentiels. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onco_ref_examens', function (Blueprint $table) {
            $table->string('code', 30)->primary();
            $table->string('libelle', 255);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onco_ref_examens');
    }
};
