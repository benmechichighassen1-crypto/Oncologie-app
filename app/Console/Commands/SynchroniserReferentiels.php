<?php

namespace App\Console\Commands;

use App\Services\Referentiels;
use Illuminate\Console\Command;

/** Recopie les protocoles et médicaments de Clinisys dans oncologie_app. */
class SynchroniserReferentiels extends Command
{
    protected $signature = 'onco:referentiels';

    protected $description = 'Copie les protocoles de chimiothérapie et la liste des médicaments (DCI) de Clinisys dans oncologie_app';

    public function handle(Referentiels $referentiels): int
    {
        foreach ($referentiels->synchroniser('commande') as $ref => $n) {
            $this->line("$ref : $n");
        }

        return self::SUCCESS;
    }
}
