<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * onco_notes.modifie_le a été créé en « datetime » : avec le serveur en français, la date
 * envoyée (AAAA-MM-JJ) est lue AAAA-JJ-MM et la modification d'une note échoue après le 12.
 * datetime2(0), comme les autres colonnes de date de l'appli, ne dépend pas de la langue.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE onco_notes ALTER COLUMN modifie_le datetime2(0) NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE onco_notes ALTER COLUMN modifie_le datetime NULL');
    }
};
