<?php

namespace App\Console\Commands;

use App\Auth\VerificateurMotDePasse;
use App\Support\Clinisys as C;
use Illuminate\Console\Command;

/**
 * Identifie le schéma de hachage Clinisys avec un compte dont on connaît le
 * mot de passe. Rien n'est écrit ni affiché, hormis le schéma trouvé.
 */
class TesterMotDePasse extends Command
{
    protected $signature = 'onco:tester-mot-de-passe {login}';

    protected $description = 'Vérifie un mot de passe Clinisys et affiche le schéma de hachage reconnu';

    public function handle(VerificateurMotDePasse $verificateur): int
    {
        $ligne = C::db()->selectOne(
            'SELECT hasched_password, salt, hash_iterations FROM '.C::t('erp', config('oncologie.auth.table')).' WHERE UserName = ?',
            [$this->argument('login')]
        );
        if (! $ligne) {
            $this->error('Compte introuvable.');

            return self::FAILURE;
        }
        if (! $ligne->hasched_password) {
            $this->error('Ce compte n\'a pas de mot de passe haché (hasched_password vide).');

            return self::FAILURE;
        }

        config(['oncologie.auth.hash' => 'auto']);
        $schema = $verificateur->verifier((string) $this->secret('Mot de passe'), $ligne->hasched_password, $ligne->salt, $ligne->hash_iterations);

        if ($schema === null) {
            $this->error('Aucun schéma connu ne correspond. Longueur du hash : '.strlen(base64_decode($ligne->hasched_password)).' octets, itérations : '.$ligne->hash_iterations.'.');

            return self::FAILURE;
        }

        $this->info("Schéma reconnu : $schema. Mettez CLINISYS_HASH=$schema dans .env.");

        return self::SUCCESS;
    }
}
