<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Tables propres à l'application (base de l'appli, jamais dans Clinisys) :
 * droits par utilisateur, journal de traçabilité et corrections de catégorie
 * des pièces jointes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onco_acces', function (Blueprint $table) {
            $table->string('user_name', 50)->primary();
            $table->string('portee', 10)->default('propre'); // propre | liste | tous
            $table->boolean('admin')->default(false);
            $table->string('modifie_par', 50)->nullable();
            $table->timestamp('modifie_le', 0)->nullable();
        });

        Schema::create('onco_acces_medecins', function (Blueprint $table) {
            $table->string('user_name', 50);
            $table->string('cod_med', 10);
            $table->primary(['user_name', 'cod_med']);
        });

        Schema::create('onco_journal', function (Blueprint $table) {
            $table->id();
            $table->string('user_name', 50)->nullable()->index();
            $table->string('action', 40)->index();
            $table->string('identifiant_patient', 20)->nullable()->index();
            $table->string('num_doss', 20)->nullable();
            $table->string('document', 60)->nullable();
            $table->text('details')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('navigateur', 255)->nullable();
            $table->string('url', 500)->nullable();
            $table->timestamp('cree_le', 0)->index();
        });

        Schema::create('onco_pj_categories', function (Blueprint $table) {
            $table->string('source', 10);          // GED | GED_DOC
            $table->string('doc_id', 40);          // Documents.DocID
            $table->string('code_type', 10);       // Type_Doc.Code_Type
            $table->string('modifie_par', 50);
            $table->timestamp('modifie_le', 0);
            $table->primary(['source', 'doc_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onco_pj_categories');
        Schema::dropIfExists('onco_journal');
        Schema::dropIfExists('onco_acces_medecins');
        Schema::dropIfExists('onco_acces');
    }
};
