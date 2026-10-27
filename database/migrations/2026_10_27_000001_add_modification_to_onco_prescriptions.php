<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Modification et suppression d'une prescription par son auteur. Une prescription
 * supprimée n'est pas effacée : masque_le est rempli et son contenu va au journal.
 * datetime2(0) : un « datetime » dépend de la langue (française) du serveur.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE onco_prescriptions ADD modifie_par nvarchar(50) NULL, modifie_le datetime2(0) NULL, masque_par nvarchar(50) NULL, masque_le datetime2(0) NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE onco_prescriptions DROP COLUMN modifie_par, modifie_le, masque_par, masque_le');
    }
};
